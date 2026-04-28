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
use MailerPress\Core\Workflows\Handlers\CreateContactStepHandler;
use MailerPress\Core\Workflows\Handlers\CreateWordPressUserStepHandler;
use MailerPress\Core\Workflows\Services\ConditionEvaluator;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Exceptions\NonRetryableException;
use MailerPress\Services\Logger;

class WorkflowExecutor
{
    private AutomationRepository $automationRepo;
    private StepRepository $stepRepo;
    private AutomationJobRepository $jobRepo;
    private AutomationLogRepository $logRepo;
    private StepHandlerRegistry $handlerRegistry;

    public function __construct(
        ?AutomationRepository $automationRepo = null,
        ?StepRepository $stepRepo = null,
        ?AutomationJobRepository $jobRepo = null,
        ?AutomationLogRepository $logRepo = null,
        ?StepHandlerRegistry $handlerRegistry = null
    ) {
        $this->automationRepo = $automationRepo ?? new AutomationRepository();
        $this->stepRepo = $stepRepo ?? new StepRepository();
        $this->jobRepo = $jobRepo ?? new AutomationJobRepository();
        $this->logRepo = $logRepo ?? new AutomationLogRepository();
        $this->handlerRegistry = $handlerRegistry ?? new StepHandlerRegistry();

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
        $this->handlerRegistry->register(new CreateContactStepHandler());
        $this->handlerRegistry->register(new CreateWordPressUserStepHandler());
    }

    public function getHandlerRegistry(): StepHandlerRegistry
    {
        return $this->handlerRegistry;
    }

    public function executeJob(int $jobId, array $context = []): bool
    {
        global $wpdb;

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

        do_action('mailerpress_workflow_job_started', $job, $context);

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

                $nextStepId = $job->getNextStepId();

                if (!$nextStepId) {
                    // If job is WAITING, don't complete it - it's waiting for re-evaluation
                    if ($job->getStatus() === 'WAITING') {
                        return true;
                    }
                    $job->setStatus('COMPLETED');
                    $this->jobRepo->update($job);

                    do_action('mailerpress_workflow_job_completed', $job);

                    // If this is an abandoned cart automation, delete the cart tracking entry
                    $this->cleanupAbandonedCartTracking($job);

                    return true;
                }

                $step = $this->stepRepo->findByStepId($nextStepId);

                if (!$step) {
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

                // Execute step within a DB transaction
                $wpdb->query('START TRANSACTION');

                try {
                    $job->setStatus('PROCESSING');
                    $this->jobRepo->update($job);

                    // Log the step with context - this preserves context for later retrieval
                    $this->logRepo->log(
                        $job->getAutomationId(),
                        $step->getStepId(),
                        $job->getUserId(),
                        'PROCESSING',
                        $context
                    );

                    $stepKey = $step->getKey();
                    $handler = $this->handlerRegistry->getHandler($stepKey);

                    if (!$handler) {
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

                        $wpdb->query('COMMIT');
                        continue;
                    }

                    // Pass context to handler
                    $result = $handler->handle($step, $job, $context);

                    // Merge any new context data from the result back into context
                    $resultData = $result->getData();
                    if (is_array($resultData) && !empty($resultData)) {
                        $context = array_merge($context, $resultData);
                    }

                    if (!$result->isSuccess()) {
                        if ($result->isRetryable() && $job->canRetry()) {
                            $this->logRepo->log(
                                $job->getAutomationId(),
                                $step->getStepId(),
                                $job->getUserId(),
                                'EXITED',
                                ['error' => $result->getError(), 'retry' => true, 'attempt' => $job->getRetryCount() + 1]
                            );

                            $wpdb->query('COMMIT');
                            $this->scheduleRetry($job, $result->getError());
                            return true;
                        }

                        $job->setStatus('FAILED');
                        $job->setLastError($result->getError());
                        $this->jobRepo->update($job);

                        $this->logRepo->log(
                            $job->getAutomationId(),
                            $step->getStepId(),
                            $job->getUserId(),
                            'EXITED',
                            ['error' => $result->getError()]
                        );

                        $wpdb->query('COMMIT');

                        do_action('mailerpress_workflow_job_failed', $job, $result->getError());

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

                    $wpdb->query('COMMIT');

                    do_action('mailerpress_workflow_step_executed', $job, $step, $result, $context);
                } catch (\Exception $stepException) {
                    $wpdb->query('ROLLBACK');
                    throw $stepException;
                }

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
            Logger::error('WorkflowExecutor: Job execution failed', [
                'job_id' => $jobId,
                'automation_id' => $job->getAutomationId(),
                'step_id' => $job->getNextStepId(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->logRepo->log(
                $job->getAutomationId(),
                $job->getNextStepId() ?? 'unknown',
                $job->getUserId(),
                'EXITED',
                [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            if (!($e instanceof NonRetryableException) && $job->canRetry()) {
                $this->scheduleRetry($job, $e->getMessage());
                return true;
            }

            $job->setStatus('FAILED');
            $job->setLastError($e->getMessage());
            $this->jobRepo->update($job);

            do_action('mailerpress_workflow_job_failed', $job, $e->getMessage());

            return false;
        }
    }

    private function scheduleRetry(AutomationJob $job, string $error): void
    {
        $attempt = $job->getRetryCount() + 1;
        $job->setRetryCount($attempt);
        $job->setLastError($error);
        $job->setStatus('ACTIVE');

        // Exponential backoff: 1min, 5min, 25min (base 5^n minutes, cap 30min)
        $delaySeconds = (int) min(pow(5, $attempt) * 60, 30 * 60);
        $scheduledAt = gmdate('Y-m-d H:i:s', time() + $delaySeconds);
        $job->setScheduledAt($scheduledAt);
        $this->jobRepo->update($job);

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + $delaySeconds,
                'mailerpress_continue_workflow',
                [['job_id' => $job->getId()]],
                'mailerpress_workflows'
            );
        }

        Logger::info('WorkflowExecutor: Job scheduled for retry', [
            'job_id' => $job->getId(),
            'attempt' => $attempt,
            'max_retries' => $job->getMaxRetries(),
            'delay_seconds' => $delaySeconds,
            'error' => $error,
        ]);

        do_action('mailerpress_workflow_job_retry_scheduled', $job, $attempt);
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
        $waitingJobs = $this->jobRepo->findWaitingByUser($userId);
        $allWaitingLogs = $this->logRepo->getAllWaitingLogsForUser($userId, $eventType, $campaignId);

        if (empty($waitingJobs) && empty($allWaitingLogs)) {
            return 0;
        }

        $reevaluated = 0;
        $reevaluated += $this->processOrphanedWaitingLogs($allWaitingLogs, $waitingJobs, $userId, $campaignId);
        $reevaluated += $this->processWaitingJobs($waitingJobs, $allWaitingLogs, $userId, $campaignId, $eventType);

        return $reevaluated;
    }

    /**
     * Process waiting logs that are not associated with any WAITING job.
     * These are logs created when condition went to "No" path but we still want to re-evaluate.
     */
    private function processOrphanedWaitingLogs(array $allWaitingLogs, array $waitingJobs, int $userId, int $campaignId): int
    {
        $reevaluated = 0;

        foreach ($allWaitingLogs as $waitingLog) {
            $logData = json_decode($waitingLog['data'] ?? '{}', true);
            $automationId = $logData['automation_id'] ?? $waitingLog['automation_id'] ?? null;
            $conditionStepId = $logData['step_id'] ?? null;
            $successStepId = $logData['success_step_id'] ?? null;
            $waitingLogCreatedAt = $waitingLog['created_at'] ?? null;

            if (!$automationId || !$conditionStepId || !$successStepId) {
                continue;
            }

            if ($this->isLogAssociatedWithWaitingJob($logData, $waitingJobs, $userId)) {
                continue;
            }

            $emailSentStepId = $logData['email_sent_step_id'] ?? null;
            $context = $this->buildReevaluationContext($automationId, $userId, $campaignId, $waitingLogCreatedAt, $emailSentStepId);

            if (empty($context)) {
                continue;
            }

            $step = $this->stepRepo->findByStepId($conditionStepId);
            if (!$step) {
                continue;
            }

            $evaluator = new ConditionEvaluator();
            $evaluationContext = array_merge($context, [
                'step_id' => $conditionStepId,
                'user_id' => $userId,
            ]);

            $conditionMet = $evaluator->evaluate($step->getSettings()['condition'] ?? [], $userId, $evaluationContext);

            if ($conditionMet) {
                $successStep = $this->stepRepo->findByStepId($successStepId);
                if ($successStep) {
                    $newJob = $this->jobRepo->create($automationId, $userId, $successStepId);
                    if ($newJob) {
                        $this->executeJob($newJob->getId(), $context);
                        $reevaluated++;
                    }
                }
            }
        }

        return $reevaluated;
    }

    /**
     * Process jobs in WAITING status by re-evaluating their conditions.
     */
    private function processWaitingJobs(array $waitingJobs, array $allWaitingLogs, int $userId, int $campaignId, string $eventType): int
    {
        $reevaluated = 0;

        foreach ($waitingJobs as $job) {
            $waitingLogs = $this->resolveWaitingLogsForJob($job, $allWaitingLogs, $eventType, $campaignId);

            if (empty($waitingLogs)) {
                continue;
            }

            // Sort waiting logs by created_at ASC to process them in chronological order
            usort($waitingLogs, function ($a, $b) {
                $timeA = strtotime($a['created_at'] ?? '1970-01-01');
                $timeB = strtotime($b['created_at'] ?? '1970-01-01');
                return $timeA <=> $timeB;
            });

            foreach ($waitingLogs as $waitingLog) {
                $logData = json_decode($waitingLog['data'] ?? '{}', true);
                $conditionStepId = $logData['step_id'] ?? null;
                $waitingLogCreatedAt = $waitingLog['created_at'] ?? null;

                $currentJob = $this->jobRepo->find($job->getId());
                if (!$currentJob) {
                    break;
                }

                $emailSentStepId = $logData['email_sent_step_id'] ?? null;
                $context = $this->buildReevaluationContext(
                    $job->getAutomationId(),
                    $job->getUserId(),
                    $campaignId,
                    $waitingLogCreatedAt,
                    $emailSentStepId,
                    $job->getId()
                );

                // Set job back to WAITING temporarily, then re-activate and set next_step_id
                $currentJob->setStatus('WAITING');
                $currentJob->setNextStepId($conditionStepId);
                $this->jobRepo->update($currentJob);

                $currentJob->setStatus('ACTIVE');
                $this->jobRepo->update($currentJob);

                $this->executeJob($job->getId(), $context);
                $reevaluated++;
            }
        }

        return $reevaluated;
    }

    /**
     * Build evaluation context from log data for re-evaluation.
     */
    private function buildReevaluationContext(int $automationId, int $userId, int $campaignId, ?string $waitingLogCreatedAt, ?string $emailSentStepId, ?int $jobId = null): array
    {
        $emailSentData = $this->logRepo->getEmailSentLog(
            $automationId,
            $userId,
            $campaignId,
            $waitingLogCreatedAt,
            $emailSentStepId
        );

        if (!$emailSentData) {
            if ($jobId) {
                $context = $this->logRepo->getTriggerContext($automationId, $userId) ?? [];
                return array_merge($context, [
                    'job_id' => $jobId,
                    'campaign_id' => $campaignId,
                ]);
            }
            return [];
        }

        $context = $this->logRepo->getTriggerContext($automationId, $userId) ?? [];
        return array_merge($context, [
            'email_sent_at' => $emailSentData['email_sent_at'] ?? null,
            'job_id' => $emailSentData['job_id'] ?? $jobId,
            'step_id' => $emailSentData['step_id'] ?? null,
            'campaign_id' => $campaignId,
            'contact_id' => $emailSentData['contact_id'] ?? null,
        ]);
    }

    /**
     * Check if a waiting log is associated with a WAITING job.
     */
    private function isLogAssociatedWithWaitingJob(array $logData, array $waitingJobs, int $userId): bool
    {
        $automationId = $logData['automation_id'] ?? null;
        $jobIdFromLog = $logData['job_id'] ?? null;

        foreach ($waitingJobs as $job) {
            if ($job->getAutomationId() == $automationId && $job->getUserId() == $userId) {
                if ($jobIdFromLog && $job->getId() == $jobIdFromLog) {
                    return true;
                } elseif (!$jobIdFromLog) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve all waiting logs relevant to a specific job, with multiple fallback strategies.
     */
    private function resolveWaitingLogsForJob(AutomationJob $job, array $allWaitingLogs, string $eventType, int $campaignId): array
    {
        // Strategy 1: Get waiting logs for this specific event
        $waitingLogs = $this->logRepo->getAllWaitingLogsForEvent(
            $job->getAutomationId(),
            $job->getUserId(),
            $eventType,
            $campaignId
        );

        if (!empty($waitingLogs)) {
            return $waitingLogs;
        }

        // Strategy 2: Fallback to last waiting log (backward compatibility)
        $waitingLog = $this->logRepo->getLastWaitingLogForJob($job->getAutomationId(), $job->getUserId());
        if ($waitingLog) {
            $logData = json_decode($waitingLog['data'] ?? '{}', true);
            $waitingForField = $logData['waiting_for_field'] ?? null;
            $waitingForCampaignId = $logData['waiting_for_campaign_id'] ?? null;

            if ($waitingForField === $eventType && $waitingForCampaignId == $campaignId) {
                return [$waitingLog];
            }
        }

        // Strategy 3: Match from allWaitingLogs by automation/job/event
        $matched = [];
        foreach ($allWaitingLogs as $log) {
            $logData = json_decode($log['data'] ?? '{}', true);
            $logJobId = $logData['job_id'] ?? null;
            $logAutomationId = $logData['automation_id'] ?? $log['automation_id'] ?? null;
            $waitingForField = $logData['waiting_for_field'] ?? null;
            $waitingForCampaignId = $logData['waiting_for_campaign_id'] ?? null;

            if (
                $logAutomationId == $job->getAutomationId() &&
                ($logJobId == $job->getId() || !$logJobId) &&
                $waitingForField === $eventType &&
                $waitingForCampaignId == $campaignId
            ) {
                $matched[] = $log;
            }
        }

        return $matched;
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
            $cartRepo->deleteCartsByUserId($job->getUserId());
        } catch (\Exception $e) {
            Logger::warning('WorkflowExecutor: Failed to cleanup abandoned cart tracking', [
                'job_id' => $job->getId(),
                'user_id' => $job->getUserId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
