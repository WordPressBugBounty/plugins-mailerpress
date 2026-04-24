<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;
use MailerPress\Core\Workflows\Repositories\AutomationLogRepository;
use MailerPress\Core\Workflows\Handlers\StepHandlerRegistry;
use MailerPress\Core\Workflows\Handlers\ConditionStepHandler;
use MailerPress\Core\Workflows\Handlers\DelayStepHandler;
use MailerPress\Core\Workflows\Handlers\SendEmailStepHandler;
use MailerPress\Core\Workflows\Handlers\AddTagStepHandler;
use MailerPress\Core\Workflows\Handlers\AddToListStepHandler;
use MailerPress\Core\Workflows\Handlers\RemoveTagStepHandler;
use MailerPress\Core\Workflows\Handlers\RemoveFromListStepHandler;
use MailerPress\Core\Workflows\Services\ConditionEvaluator;

class WorkflowExecutor
{
    private AutomationRepository $automationRepo;
    private StepRepository $stepRepo;
    private AutomationJobRepository $jobRepo;
    private AutomationLogRepository $logRepo;
    private StepHandlerRegistry $handlerRegistry;

    public function __construct()
    {
        $this->automationRepo = new AutomationRepository();
        $this->stepRepo = new StepRepository();
        $this->jobRepo = new AutomationJobRepository();
        $this->logRepo = new AutomationLogRepository();
        $this->handlerRegistry = new StepHandlerRegistry();

        $this->registerDefaultHandlers();
    }

    private function registerDefaultHandlers(): void
    {
        $this->handlerRegistry->register(new DelayStepHandler());
        $this->handlerRegistry->register(new ConditionStepHandler());
        $this->handlerRegistry->register(new SendEmailStepHandler());
        $this->handlerRegistry->register(new AddTagStepHandler());
        $this->handlerRegistry->register(new AddToListStepHandler());
        $this->handlerRegistry->register(new RemoveTagStepHandler());
        $this->handlerRegistry->register(new RemoveFromListStepHandler());
    }

    public function getHandlerRegistry(): StepHandlerRegistry
    {
        return $this->handlerRegistry;
    }

    public function executeJob(int $jobId, array $context = []): bool
    {
        $job = $this->jobRepo->find($jobId);

        if (!$job) {
            return false;
        }

        if (!$job->isActive()) {
            return false;
        }

        // If context is empty, try to retrieve it from the trigger log
        if (empty($context)) {
            $triggerContext = $this->logRepo->getTriggerContext(
                $job->getAutomationId(),
                $job->getUserId()
            );
            if ($triggerContext) {
                $context = $triggerContext;
            }
        }

        try {
            $maxIterations = 50; // sécurité pour éviter les boucles infinies
            $iterations = 0;

            while ($iterations < $maxIterations) {
                $iterations++;

                // Re-fetch job to get latest state (important after re-evaluation)
                $job = $this->jobRepo->find($jobId);
                if (!$job) {
                    return false;
                }

                $job->setStatus('PROCESSING');
                $this->jobRepo->update($job);

                $nextStepId = $job->getNextStepId();

                if (!$nextStepId) {
                    // If job is WAITING, don't complete it - it's waiting for re-evaluation
                    if ($job->getStatus() === 'WAITING') {
                        return true;
                    }
                    $job->setStatus('COMPLETED');
                    $this->jobRepo->update($job);

                    // If this is an abandoned cart automation, delete the cart tracking entry
                    $this->cleanupAbandonedCartTracking($job);

                    return true;
                }

                $step = $this->stepRepo->findByStepId($nextStepId);

                if (!$step) {
                    // Marquer le job en échec et journaliser proprement
                    $job->setStatus('FAILED');
                    $this->jobRepo->update($job);

                    $this->logRepo->log(
                        $job->getAutomationId(),
                        $nextStepId,
                        $job->getUserId(),
                        'EXITED',
                        ['error' => __('Step not found', 'mailerpress'), 'step_id' => $nextStepId]
                    );

                    return false;
                }


                // Log the step with context - this preserves context for later retrieval
                $this->logRepo->log(
                    $job->getAutomationId(),
                    $step->getStepId(),
                    $job->getUserId(),
                    'PROCESSING',
                    $context
                );

                $stepKey = $step->getKey();

                // Log all handlers and their supported keys
                $allHandlers = $this->handlerRegistry->getHandlers();
                $handlerInfo = [];
                foreach ($allHandlers as $h) {
                    $handlerInfo[] = get_class($h);
                    // Try to detect supported keys by checking common keys
                    $testKeys = ['send_email', 'add_tag', 'delay', 'condition', 'ab_test', 'create_campaign'];
                    foreach ($testKeys as $testKey) {
                        if ($h->supports($testKey)) {
                            $handlerInfo[count($handlerInfo) - 1] .= " (supports: {$testKey})";
                            break;
                        }
                    }
                }

                $handler = $this->handlerRegistry->getHandler($stepKey);

                if (!$handler) {
                    // Pas de handler : on avance simplement au prochain step
                    $job->setNextStepId($step->getNextStepId());
                    $job->setStatus('ACTIVE');
                    $this->jobRepo->update($job);

                    $this->logRepo->log(
                        $job->getAutomationId(),
                        $step->getStepId(),
                        $job->getUserId(),
                        'COMPLETED',
                        ['skipped' => true, 'reason' => 'No handler found for key: ' . $stepKey]
                    );

                    // continuer la boucle immédiatement
                    continue;
                }

                // Pass context to handler - it will be preserved through the loop
                $result = $handler->handle($step, $job, $context);

                // Merge any new context data from the result back into context
                $resultData = $result->getData();
                if (is_array($resultData) && !empty($resultData)) {
                    $context = array_merge($context, $resultData);
                }

                if (!$result->isSuccess()) {
                    $job->setStatus('FAILED');
                    $this->jobRepo->update($job);

                    $this->logRepo->log(
                        $job->getAutomationId(),
                        $step->getStepId(),
                        $job->getUserId(),
                        'EXITED',
                        ['error' => $result->getError()]
                    );

                    return false;
                }

                // Mettre à jour le prochain step d'après le résultat
                $nextStepIdFromResult = $result->getNextStepId();
                $job->setNextStepId($nextStepIdFromResult);

                // Preserve WAITING status if handler set it (for future-dependent conditions)
                if ($job->getStatus() !== 'WAITING') {
                    $job->setStatus('ACTIVE');
                }

                $this->jobRepo->update($job);

                $this->logRepo->log(
                    $job->getAutomationId(),
                    $step->getStepId(),
                    $job->getUserId(),
                    'COMPLETED',
                    $result->getData()
                );

                // Si le job est en attente (WAITING), on s'arrête ici
                if ($job->getStatus() === 'WAITING') {
                    return true;
                }

                // Si un délai a été programmé (DelayStepHandler définit scheduledAt), on s'arrête ici
                if ($job->getScheduledAt()) {
                    return true;
                }
            }

            // Si on atteint la limite, on s'arrête proprement
            return true;
        } catch (\Exception $e) {
            $job->setStatus('FAILED');
            $this->jobRepo->update($job);

            return false;
        }
    }

    public function continueWorkflow(int $jobId, ?string $nextStepId = null, array $context = []): bool
    {
        $job = $this->jobRepo->find($jobId);

        if (!$job) {
            return false;
        }

        $job->setScheduledAt(null);
        $job->setStatus('ACTIVE');

        if ($nextStepId) {
            $job->setNextStepId($nextStepId);
        }

        $this->jobRepo->update($job);

        return $this->executeJob($jobId, $context);
    }

    /**
     * Re-evaluate waiting jobs for a specific user and campaign
     * Called when an email is opened or clicked
     * 
     * @param int $userId
     * @param int $campaignId
     * @param string $eventType 'mp_email_opened' or 'mp_email_clicked'
     * @return int Number of jobs re-evaluated
     */
    public function reevaluateWaitingJobs(int $userId, int $campaignId, string $eventType): int
    {
        // Get all waiting jobs for this user (jobs that are in WAITING status)
        $waitingJobs = $this->jobRepo->findWaitingByUser($userId);

        // Also get all WAITING logs for this user (even if job is not in WAITING status)
        // This handles cases where the job went to "No" path but we still want to re-evaluate
        $allWaitingLogs = $this->logRepo->getAllWaitingLogsForUser($userId, $eventType, $campaignId);
        if (empty($waitingJobs) && empty($allWaitingLogs)) {
            return 0;
        }

        $reevaluated = 0;

        // Process waiting logs that are not associated with WAITING jobs
        // These are logs created when condition went to "No" path but we still want to re-evaluate
        foreach ($allWaitingLogs as $waitingLog) {
            $logData = json_decode($waitingLog['data'] ?? '{}', true);
            $automationId = $logData['automation_id'] ?? $waitingLog['automation_id'] ?? null;
            $conditionStepId = $logData['step_id'] ?? null;
            $successStepId = $logData['success_step_id'] ?? null;
            $waitingLogCreatedAt = $waitingLog['created_at'] ?? null;
            $jobIdFromLog = $logData['job_id'] ?? null;

            if (!$automationId || !$conditionStepId || !$successStepId) {
                continue;
            }

            // Check if this log is already associated with a WAITING job
            $associatedWaitingJob = null;
            foreach ($waitingJobs as $job) {
                if ($job->getAutomationId() == $automationId && $job->getUserId() == $userId) {
                    // Also check if job_id matches if available
                    if ($jobIdFromLog && $job->getId() == $jobIdFromLog) {
                        $associatedWaitingJob = $job;
                        break;
                    } elseif (!$jobIdFromLog) {
                        // If no job_id in log, match by automation and user
                        $associatedWaitingJob = $job;
                        break;
                    }
                }
            }

            // If associated with a WAITING job, process it in the job loop below
            // Otherwise, evaluate condition and create new job if it passes
            if ($associatedWaitingJob) {
                continue;
            }

            // If not associated with a WAITING job, evaluate condition and create new job if it passes
            if (!$associatedWaitingJob) {
                // Get email_sent_at from the log entry when the email was sent
                // Try to use email_sent_step_id from waiting log if available
                $emailSentStepId = $logData['email_sent_step_id'] ?? null;
                $emailSentData = $this->logRepo->getEmailSentLog(
                    $automationId,
                    $userId,
                    $campaignId,
                    $waitingLogCreatedAt,
                    $emailSentStepId
                );

                if ($emailSentData) {
                    // Get context from logs
                    $context = $this->logRepo->getTriggerContext($automationId, $userId) ?? [];
                    $context = array_merge($context, [
                        'email_sent_at' => $emailSentData['email_sent_at'] ?? null,
                        'job_id' => $emailSentData['job_id'] ?? null,
                        'step_id' => $emailSentData['step_id'] ?? null,
                        'campaign_id' => $campaignId,
                        'contact_id' => $emailSentData['contact_id'] ?? null,
                    ]);

                    // Get the condition step to evaluate
                    $step = $this->stepRepo->findByStepId($conditionStepId);
                    if ($step) {
                        // Instead of creating a temporary job, directly evaluate the condition
                        // We'll use the ConditionEvaluator directly to check if condition passes
                        $evaluator = new ConditionEvaluator();

                        // Add step_id to context for condition evaluation
                        $evaluationContext = array_merge($context, [
                            'step_id' => $conditionStepId,
                            'user_id' => $userId,
                        ]);

                        $conditionMet = $evaluator->evaluate($step->getSettings()['condition'] ?? [], $userId, $evaluationContext);
                        // If condition passes, create a new job for the "Yes" path
                        if ($conditionMet) {
                            // Verify the success step exists
                            $successStep = $this->stepRepo->findByStepId($successStepId);
                            if (!$successStep) {
                            } else {
                                $newJob = $this->jobRepo->create($automationId, $userId, $successStepId);
                                if ($newJob) {
                                    $this->executeJob($newJob->getId(), $context);
                                    $reevaluated++;
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($waitingJobs as $job) {
            // Get ALL waiting logs for this event (not just the last one)
            // This handles cases where multiple conditions are waiting for the same email
            $waitingLogs = $this->logRepo->getAllWaitingLogsForEvent(
                $job->getAutomationId(),
                $job->getUserId(),
                $eventType,
                $campaignId
            );

            if (empty($waitingLogs)) {
                // Fallback: try to get the last waiting log (for backward compatibility)
                $waitingLog = $this->logRepo->getLastWaitingLogForJob($job->getAutomationId(), $job->getUserId());
                if ($waitingLog) {
                    $logData = json_decode($waitingLog['data'] ?? '{}', true);
                    $waitingForField = $logData['waiting_for_field'] ?? null;
                    $waitingForCampaignId = $logData['waiting_for_campaign_id'] ?? null;

                    if ($waitingForField === $eventType && $waitingForCampaignId == $campaignId) {
                        $waitingLogs = [$waitingLog];
                    }
                }
            }

            // If still empty, try to find logs from $allWaitingLogs that match this job
            if (empty($waitingLogs)) {
                foreach ($allWaitingLogs as $waitingLog) {
                    $logData = json_decode($waitingLog['data'] ?? '{}', true);
                    $logJobId = $logData['job_id'] ?? null;
                    $logAutomationId = $logData['automation_id'] ?? $waitingLog['automation_id'] ?? null;
                    $waitingForField = $logData['waiting_for_field'] ?? null;
                    $waitingForCampaignId = $logData['waiting_for_campaign_id'] ?? null;

                    if (
                        $logAutomationId == $job->getAutomationId() &&
                        ($logJobId == $job->getId() || !$logJobId) &&
                        $waitingForField === $eventType &&
                        $waitingForCampaignId == $campaignId
                    ) {
                        $waitingLogs[] = $waitingLog;
                    }
                }
            }

            if (empty($waitingLogs)) {
                continue;
            }

            // Sort waiting logs by created_at ASC to process them in chronological order
            // This ensures we re-evaluate conditions in the order they were created
            usort($waitingLogs, function ($a, $b) {
                $timeA = strtotime($a['created_at'] ?? '1970-01-01');
                $timeB = strtotime($b['created_at'] ?? '1970-01-01');
                return $timeA <=> $timeB;
            });

            // Re-evaluate each waiting condition
            // We need to re-evaluate ALL conditions that are waiting for this event
            foreach ($waitingLogs as $waitingLog) {
                $logData = json_decode($waitingLog['data'] ?? '{}', true);
                $waitingForField = $logData['waiting_for_field'] ?? null;
                $waitingForCampaignId = $logData['waiting_for_campaign_id'] ?? null;
                $conditionStepId = $logData['step_id'] ?? null;
                $waitingLogCreatedAt = $waitingLog['created_at'] ?? null;

                // Re-fetch the job to get the latest state before each re-evaluation
                $currentJob = $this->jobRepo->find($job->getId());
                if (!$currentJob) {
                    break;
                }

                // Check if this specific condition is still relevant
                // If the job is no longer WAITING, it might have already passed a previous condition
                // But we still want to re-evaluate this condition if it's the one we're waiting for

                // Get context from logs
                $context = $this->logRepo->getTriggerContext($job->getAutomationId(), $job->getUserId()) ?? [];

                // Get email_sent_at from the log entry when the email was sent
                // IMPORTANT: Only get emails sent BEFORE the condition was evaluated (waitingLogCreatedAt)
                // This ensures we get the correct email for this specific condition, not a later one
                // Try to use email_sent_step_id from waiting log if available
                $emailSentStepId = $logData['email_sent_step_id'] ?? null;
                $emailSentData = $this->logRepo->getEmailSentLog(
                    $job->getAutomationId(),
                    $job->getUserId(),
                    $campaignId,
                    $waitingLogCreatedAt,  // Only get emails sent before this condition was evaluated
                    $emailSentStepId
                );

                if ($emailSentData) {
                    // Add email_sent_at, job_id, step_id, and contact_id to context for condition evaluation
                    // contact_id is CRITICAL for non-subscribers - it should be user_id for them
                    // NOTE: step_id here is from email sent log (Send Email step), but ConditionStepHandler will override it with condition step ID
                    $context = array_merge($context, [
                        'email_sent_at' => $emailSentData['email_sent_at'] ?? null,
                        'job_id' => $emailSentData['job_id'] ?? $job->getId(),
                        'step_id' => $emailSentData['step_id'] ?? null, // This will be overridden by ConditionStepHandler
                        'campaign_id' => $campaignId,
                        'contact_id' => $emailSentData['contact_id'] ?? null, // Pass contact_id from email sent log
                    ]);
                } else {
                    // Still add job_id and step_id for context
                    $context = array_merge($context, [
                        'job_id' => $job->getId(),
                        'campaign_id' => $campaignId,
                    ]);
                }

                // Set job back to WAITING temporarily, then re-activate and set next_step_id
                // This ensures we can properly re-evaluate the condition
                $currentJob->setStatus('WAITING'); // Temporary, will be set to ACTIVE below
                $currentJob->setNextStepId($conditionStepId);
                $this->jobRepo->update($currentJob);

                // Now set to ACTIVE and re-evaluate
                $currentJob->setStatus('ACTIVE');
                $this->jobRepo->update($currentJob);

                // Continue execution - this will re-evaluate the condition
                // The condition should now pass because the email has been opened AFTER it was sent
                $this->executeJob($job->getId(), $context);

                $reevaluated++;
            }
        }

        return $reevaluated;
    }

    /**
     * Clean up abandoned cart tracking entry when automation is completed
     * 
     * @param \MailerPress\Core\Workflows\Models\AutomationJob $job
     * @return void
     */
    private function cleanupAbandonedCartTracking($job): void
    {
        try {
            // Check if this automation uses the abandoned cart trigger
            $steps = $this->stepRepo->findByAutomationId($job->getAutomationId());
            $trigger = null;

            foreach ($steps as $step) {
                if ($step->isTrigger() && $step->getKey() === 'woocommerce_abandoned_cart') {
                    $trigger = $step;
                    break;
                }
            }

            if (!$trigger) {
                return; // Not an abandoned cart automation
            }

            // Delete the active cart tracking entry for this user
            $cartRepo = new \MailerPress\Core\Workflows\Repositories\CartTrackingRepository();
            $deleted = $cartRepo->deleteCartsByUserId($job->getUserId());
        } catch (\Exception $e) {
        }
    }
}
