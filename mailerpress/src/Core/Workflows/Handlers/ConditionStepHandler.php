<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Workflows\Services\ConditionEvaluator;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;

class ConditionStepHandler implements StepHandlerInterface
{
    private ConditionEvaluator $evaluator;
    private StepRepository $stepRepo;
    private AutomationJobRepository $jobRepo;

    public function __construct()
    {
        $this->evaluator = new ConditionEvaluator();
        $this->stepRepo = new StepRepository();
        $this->jobRepo = new AutomationJobRepository();
    }

    public function supports(string $key): bool
    {
        return $key === 'condition' || $key === 'if';
    }

    public function getDefinition(): array
    {
        return [
            'key' => 'condition',
            'label' => __('Condition', 'mailerpress'),
            'description' => __('Split the workflow into different paths based on a condition. If the condition is true, the workflow continues down one path; otherwise, it takes another path. Enables you to create smart and personalized workflows.', 'mailerpress'),
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="M8.95 11.25H4v1.5h4.95v4.5H13V18c0 1.1.9 2 2 2h3c1.1 0 2-.9 2-2v-3c0-1.1-.9-2-2-2h-3c-1.1 0-2 .9-2 2v.75h-2.55v-7.5H13V9c0 1.1.9 2 2 2h3c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3c-1.1 0-2 .9-2 2v.75H8.95v4.5ZM14.5 15v3c0 .3.2.5.5.5h3c.3 0 .5-.2.5-.5v-3c0-.3-.2-.5-.5-.5h-3c-.3 0-.5.2-.5.5Zm0-6V6c0-.3.2-.5.5-.5h3c.3 0 .5.2.5.5v3c0 .3-.2.5-.5.5h-3c-.3 0-.5-.2-.5-.5Z" clip-rule="evenodd"></path></svg>',
            'category' => 'logic',
            'type' => 'CONDITION',
            'settings_schema' => [
                [
                    'key' => 'condition',
                    'label' => 'Condition',
                    'type' => 'condition_builder',
                    'required' => true,
                ],
            ],
        ];
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        $settings = $step->getSettings();
        $condition = $settings['condition'] ?? [];

        // Check if this is a re-evaluation of a waiting condition
        // IMPORTANT: We detect re-evaluation by checking if:
        // 1. The job is currently WAITING (meaning it was put in waiting state previously)
        // 2. AND email_sent_at is in context (from reevaluateWaitingJobs)
        // 
        // If email_sent_at is in context but job is NOT WAITING, this is the FIRST evaluation
        // (just after email was sent), and we should put it in WAITING if condition fails
        $isReevaluation = $job->getStatus() === 'WAITING' && isset($context['email_sent_at']) && !empty($context['email_sent_at']);
        $alternativeStepIdFromLog = null;

        if ($isReevaluation) {
            // Try to get alternative_step_id from the last waiting log
            $logRepo = new \MailerPress\Core\Workflows\Repositories\AutomationLogRepository();
            $waitingLog = $logRepo->getLastWaitingLogForJob($job->getAutomationId(), $job->getUserId());
            if ($waitingLog) {
                $logData = json_decode($waitingLog['data'] ?? '{}', true);
                $alternativeStepIdFromLog = $logData['alternative_step_id'] ?? null;
            }
        }

        // Save email_sent_step_id from context BEFORE it gets overwritten
        // This is the step_id from SendEmailStepHandler that sent the email
        $emailSentStepId = $context['step_id'] ?? null;

        // Add job_id, step_id, and user_id to context for condition evaluation
        // This allows conditions to verify if events happened in THIS workflow instance
        // user_id is especially important for non-subscribers who don't have a contact_id
        // IMPORTANT: Always use the current condition step's ID, not the step_id from context
        // (context might have step_id from a previous step like "Send Email")
        $context = array_merge($context, [
            'job_id' => $job->getId(),
            'step_id' => $step->getStepId(), // Use condition step ID, not context step_id
            'user_id' => $job->getUserId(),
        ]);
        $conditionMet = $this->evaluator->evaluate($condition, $job->getUserId(), $context);
        // Check if condition contains future-dependent rules (email opened/clicked)
        $waitingInfo = $this->checkForFutureDependentConditions($condition, $conditionMet);
        // If this is a re-evaluation, handle it specially
        if ($isReevaluation) {
            // Re-activate the job if it was WAITING
            // This is important so the workflow can continue after re-evaluation
            if ($job->getStatus() === 'WAITING') {
                $job->setStatus('ACTIVE');
                $this->jobRepo->update($job);
            }

            // If condition now passes, go to success path
            if ($conditionMet) {
                $successStepId = $step->getNextStepId();

                if ($successStepId) {
                    return StepResult::success($successStepId, [
                        'condition_met' => true,
                        'reevaluated' => true,
                        'reason' => 'Condition met after re-evaluation - proceeding to success path',
                        'next_step_id' => $successStepId,
                    ]);
                } else {
                    // No success path - workflow ends
                    return StepResult::success(null, [
                        'condition_met' => true,
                        'reevaluated' => true,
                        'reason' => 'Condition met but no success path configured',
                    ]);
                }
            }

            // If condition still fails after re-evaluation, execute "No" branch (if it exists) or exit
            if (!$conditionMet) {
                $alternativeStepId = $alternativeStepIdFromLog ?? $step->getAlternativeStepId();

                if ($alternativeStepId) {
                    return StepResult::success($alternativeStepId, [
                        'condition_met' => false,
                        'reevaluated' => true,
                        'reason' => 'Condition still not met after re-evaluation - executing "No" branch',
                        'next_step_id' => $alternativeStepId,
                    ]);
                } else {
                    return StepResult::success(null, [
                        'condition_met' => false,
                        'reevaluated' => true,
                        'reason' => 'Condition still not met after re-evaluation - workflow EXITING (no No branch)',
                    ]);
                }
            }
        }

        // If condition failed and contains future-dependent rules:
        // - If there IS an alternative path (No branch): go to it normally (no re-evaluation)
        // - If there is NO alternative path (No branch): EXIT and wait for re-evaluation
        // - Create a WAITING log ONLY if there is no alternative path
        // - When email is opened and condition passes, create a new job for the "Yes" path
        if (!$conditionMet && $waitingInfo && !$isReevaluation) {
            $alternativeStepId = $step->getAlternativeStepId();

            // If there IS an alternative path (No branch): go to it normally
            // No need to wait for re-evaluation - just execute the "No" branch
            if ($alternativeStepId) {
                return StepResult::success($alternativeStepId, [
                    'condition_met' => false,
                    'reason' => 'Condition failed - going to alternative path',
                ]);
            }

            $waitingData = [
                'waiting_for_field' => $waitingInfo['field'],
                'waiting_for_campaign_id' => $waitingInfo['campaign_id'],
                'condition_met' => false,
                'step_id' => $step->getStepId(), // Condition step ID
                'email_sent_step_id' => $emailSentStepId, // Email step ID (saved before context was overwritten)
                'alternative_step_id' => null, // No alternative path
                'success_step_id' => $step->getNextStepId(), // Store success path (Yes) for later
                'job_id' => $job->getId(),
                'automation_id' => $job->getAutomationId(),
                'user_id' => $job->getUserId(),
            ];

            // Log the waiting state (this allows reevaluateWaitingJobs to find it later)
            $logRepo = new \MailerPress\Core\Workflows\Repositories\AutomationLogRepository();
            $logCreated = $logRepo->log(
                $job->getAutomationId(),
                $step->getStepId(),
                $job->getUserId(),
                'WAITING',
                $waitingData
            );

            // Verify the log was created by trying to retrieve it
            if ($logCreated) {
                $verifyLogs = $logRepo->getAllWaitingLogsForUser($job->getUserId(), $waitingInfo['field'], $waitingInfo['campaign_id']);
            }

            // Set job status to WAITING so it can be re-evaluated later
            // This prevents the job from being completed
            $job->setStatus('WAITING');
            $this->jobRepo->update($job);

            // EXIT the workflow - wait for re-evaluation result
            // If condition passes after re-evaluation, execute "Yes" branch
            // If condition still fails after re-evaluation, workflow exits (no "No" branch)
            return StepResult::success(null, [
                'condition_met' => false,
                'waiting_log_created' => true,
                'reason' => 'Condition failed with future-dependent rules and no alternative path - workflow EXITING and waiting for re-evaluation. Will wait for "Yes" branch result.',
            ]);
        }

        $branches = $this->stepRepo->findBranchesByStepId($step->getId());

        if (!empty($branches)) {
            foreach ($branches as $branch) {
                $branchCondition = $branch->getCondition();
                $branchMet = $this->evaluator->evaluate($branchCondition, $job->getUserId(), $context);

                // Check if branch condition contains future-dependent rules
                $branchWaitingInfo = $this->checkForFutureDependentConditions($branchCondition, $branchMet);

                if ($branchMet) {
                    return StepResult::success($branch->getNextStepId(), [
                        'condition_met' => true,
                        'branch_taken' => $branch->getId(),
                    ]);
                } elseif ($branchWaitingInfo) {
                    // Branch condition failed but contains future-dependent rules
                    $job->setStatus('WAITING');
                    $job->setNextStepId($branch->getNextStepId());

                    $waitingData = [
                        'waiting_for_field' => $branchWaitingInfo['field'],
                        'waiting_for_campaign_id' => $branchWaitingInfo['campaign_id'],
                        'condition_met' => false,
                        'step_id' => $step->getStepId(),
                        'branch_id' => $branch->getId(),
                    ];

                    return StepResult::success($branch->getNextStepId(), $waitingData);
                }
            }

            return StepResult::success($step->getAlternativeStepId(), [
                'condition_met' => false,
            ]);
        }

        $nextStepId = $conditionMet
            ? $step->getNextStepId()
            : $step->getAlternativeStepId();

        return StepResult::success($nextStepId, [
            'condition_met' => $conditionMet,
        ]);
    }

    /**
     * Check if condition contains future-dependent rules (email opened/clicked)
     * Returns waiting info if condition failed and contains such rules, null otherwise
     * 
     * @param array|null $condition
     * @param bool $conditionMet
     * @return array|null Array with 'field' and 'campaign_id', or null
     */
    private function checkForFutureDependentConditions(?array $condition, bool $conditionMet): ?array
    {
        // Only check if condition failed (we don't wait if it already passed)
        if ($conditionMet) {
            return null;
        }

        // No condition or empty condition means nothing to wait for
        if ($condition === null || empty($condition) || !is_array($condition)) {
            return null;
        }

        $rules = $condition['rules'] ?? [];
        if (empty($rules) || !is_array($rules)) {
            return null;
        }

        // Check each rule for future-dependent fields
        foreach ($rules as $rule) {
            // Handle nested conditions
            if (isset($rule['rules'])) {
                $nestedResult = $this->checkForFutureDependentConditions($rule, false);
                if ($nestedResult) {
                    return $nestedResult;
                }
                continue;
            }

            $field = $rule['field'] ?? '';

            // Check if this is a future-dependent field
            if (in_array($field, ['mp_email_opened', 'mp_email_clicked'], true)) {
                $value = $rule['value'] ?? null;
                $campaignId = is_array($value) ? (int) ($value[0] ?? 0) : (int) $value;

                if ($campaignId > 0) {
                    return [
                        'field' => $field,
                        'campaign_id' => $campaignId,
                    ];
                }
            }
        }

        return null;
    }
}
