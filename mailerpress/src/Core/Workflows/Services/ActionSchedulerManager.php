<?php

namespace MailerPress\Core\Workflows\Services;

class ActionSchedulerManager
{
    private WorkflowExecutor $executor;

    public function __construct(?WorkflowExecutor $executor = null)
    {
        // Use provided executor or create a new one (for backward compatibility)
        $this->executor = $executor ?? new WorkflowExecutor();
        $this->registerActions();
    }

    private function registerActions(): void
    {
        add_action('mailerpress_continue_workflow', [$this, 'continueWorkflow'], 10, 1);
    }

    public function continueWorkflow($args): void
    {
        if (!is_array($args)) {
            $args = ['job_id' => $args];
        }

        $jobId = $args['job_id'] ?? null;
        $nextStepId = $args['next_step_id'] ?? null;

        if (!$jobId) {
            return;
        }

        $this->executor->continueWorkflow($jobId, $nextStepId);
    }


    public function scheduleAction(int $timestamp, string $hook, array $args = [], string $group = 'mailerpress_workflows'): void
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, $hook, $args, $group);
        }
    }

    public function cancelJobActions(int $jobId): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(
                'mailerpress_continue_workflow',
                ['job_id' => $jobId],
                'mailerpress_workflows'
            );
        }
    }
}