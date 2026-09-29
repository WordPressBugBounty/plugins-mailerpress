<?php

namespace MailerPress\Core\Workflows\Services;

class ActionSchedulerManager
{
    public const HOOK_CONTINUE = 'mailerpress_continue_workflow';
    public const HOOK_GOAL_TIMEOUT = 'mailerpress_workflow_goal_timeout';
    public const GROUP = 'mailerpress_workflows';

    private WorkflowExecutor $executor;

    /**
     * The continuation hook must be bound exactly once per request: a second
     * listener (bound by another instance) would run every scheduled action
     * twice, i.e. execute the same workflow step twice.
     */
    private static bool $actionsRegistered = false;

    public function __construct(?WorkflowExecutor $executor = null)
    {
        // Use provided executor or create a new one (for backward compatibility)
        $this->executor = $executor ?? new WorkflowExecutor();
        $this->registerActions();
    }

    private function registerActions(): void
    {
        if (self::$actionsRegistered) {
            return;
        }

        self::$actionsRegistered = true;

        add_action(self::HOOK_CONTINUE, [$this, 'continueWorkflow'], 10, 1);
    }

    public function continueWorkflow($args): void
    {
        $args = self::normalizeArgs($args);

        $jobId = $args['job_id'] ?? null;
        $nextStepId = $args['next_step_id'] ?? null;

        if (!$jobId) {
            return;
        }

        $this->executor->continueWorkflow((int) $jobId, $nextStepId);
    }

    public function scheduleAction(int $timestamp, string $hook, array $args = [], string $group = self::GROUP): void
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, $hook, $args, $group);
        }
    }

    /**
     * Schedule the continuation of a job.
     *
     * Args are always wrapped in a single-element array so that the payload
     * reaches the callback as one associative array instead of being spread
     * over positional parameters. Every scheduler call in the engine must go
     * through here, otherwise cancelJobActions() cannot find the action back.
     */
    public static function scheduleContinue(int $timestamp, int $jobId, ?string $nextStepId = null): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        as_schedule_single_action(
            $timestamp,
            self::HOOK_CONTINUE,
            [self::buildJobArgs($jobId, $nextStepId)],
            self::GROUP
        );
    }

    /**
     * Schedule the expiry of an essential goal.
     */
    public static function scheduleGoalTimeout(int $timestamp, int $jobId, string $stepId): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        as_schedule_single_action(
            $timestamp,
            self::HOOK_GOAL_TIMEOUT,
            [self::buildJobArgs($jobId, null, ['step_id' => $stepId])],
            self::GROUP
        );
    }

    /**
     * Cancel every pending workflow action belonging to a job.
     *
     * as_unschedule_all_actions() matches arguments by exact JSON equality, so
     * passing a partial payload such as ['job_id' => 12] never matches an action
     * that also carries a next_step_id. We therefore enumerate the pending
     * actions of the group and cancel the ones whose payload targets this job.
     */
    public function cancelJobActions(int $jobId, string $hook = self::HOOK_CONTINUE): void
    {
        self::cancelActionsForJob($jobId, $hook);
    }

    /**
     * Cancel the pending timeout action of a goal step for a given job.
     */
    public function cancelGoalTimeout(int $jobId): void
    {
        self::cancelActionsForJob($jobId, self::HOOK_GOAL_TIMEOUT);
    }

    /**
     * Static form of cancelJobActions(), so callers do not have to build an
     * instance (which would drag a WorkflowExecutor along) just to cancel.
     */
    public static function cancelActionsForJob(int $jobId, string $hook = self::HOOK_CONTINUE): void
    {
        if (!function_exists('as_get_scheduled_actions') || !function_exists('as_unschedule_action')) {
            return;
        }

        $query = [
            'hook' => $hook,
            'group' => self::GROUP,
            'per_page' => -1,
        ];

        if (class_exists('\ActionScheduler_Store')) {
            $query['status'] = \ActionScheduler_Store::STATUS_PENDING;
        }

        $actions = as_get_scheduled_actions($query);

        if (empty($actions) || !is_array($actions)) {
            return;
        }

        foreach ($actions as $action) {
            if (!is_object($action) || !method_exists($action, 'get_args')) {
                continue;
            }

            $args = $action->get_args();
            $normalized = self::normalizeArgs($args);

            if ((int) ($normalized['job_id'] ?? 0) !== $jobId) {
                continue;
            }

            as_unschedule_action($hook, $args, self::GROUP);
        }
    }

    /**
     * Build the canonical scheduler payload for a job.
     */
    public static function buildJobArgs(int $jobId, ?string $nextStepId = null, array $extra = []): array
    {
        $args = ['job_id' => $jobId];

        if ($nextStepId !== null && $nextStepId !== '') {
            $args['next_step_id'] = $nextStepId;
        }

        return array_merge($args, $extra);
    }

    /**
     * Normalize a scheduler payload into an associative array.
     *
     * Handles the three shapes found in the wild:
     *  - ['job_id' => 12, 'next_step_id' => 'abc']  (canonical, single arg)
     *  - [['job_id' => 12]]                          (nested by the scheduler)
     *  - 12                                          (legacy flat args spread
     *    over positional parameters, still pending on upgraded sites)
     *
     * @param mixed $args
     */
    public static function normalizeArgs($args): array
    {
        if (is_numeric($args)) {
            return ['job_id' => (int) $args];
        }

        if (!is_array($args)) {
            return [];
        }

        // Nested payload: the first element carries the real arguments.
        if (isset($args[0]) && is_array($args[0])) {
            return $args[0];
        }

        if (isset($args[0]) && is_numeric($args[0]) && !isset($args['job_id'])) {
            return ['job_id' => (int) $args[0]];
        }

        return $args;
    }
}
