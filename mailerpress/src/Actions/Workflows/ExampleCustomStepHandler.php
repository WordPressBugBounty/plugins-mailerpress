<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows;

\defined('ABSPATH') || exit;

use MailerPress\Core\Workflows\Handlers\StepHandlerInterface;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;

/**
 * Example custom step handler that logs a message
 * 
 * This file demonstrates how to create a custom step handler
 * for the MailerPress Workflow System. 
 * 
 * To register this handler, use the 'mailerpress_register_step_handlers' hook
 * in another file (e.g., in an Action class with #[Action] attribute).
 * 
 * Example:
 * ```php
 * #[Action('mailerpress_register_step_handlers')]
 * public function registerWorkflowHandlers($manager): void
 * {
 *     $manager->registerStepHandler(new ExampleCustomStepHandler());
 * }
 * ```
 * 
 * @since 1.2.0
 */
class ExampleCustomStepHandler implements StepHandlerInterface
{
    /**
     * Check if this handler supports the given step key
     * 
     * @param string $key The step key to check
     * @return bool True if this handler supports the key
     */
    public function supports(string $key): bool
    {
        return $key === 'example_custom_action';
    }

    /**
     * Handle the step execution
     * 
     * @param Step $step The step to execute
     * @param AutomationJob $job The automation job context
     * @param array $context Additional context data
     * @return StepResult The result of the step execution
     */
    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        $settings = $step->getSettings();
        $user = get_userdata($job->getUserId());

        if (!$user) {
            return StepResult::failed('User not found');
        }

        // Return success and move to the next step
        return StepResult::success($step->getNextStepId(), [
            'action_executed' => true,
            'user_id' => $user->ID,
            'user_email' => $user->user_email,
        ]);
    }
}
