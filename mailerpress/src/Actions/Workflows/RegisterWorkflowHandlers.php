<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Actions\Workflows\MailerPress\Actions\CreateCampaignStepHandler;

/**
 * Register custom workflow step handlers
 * 
 * This class is responsible for registering all custom step handlers
 * for the MailerPress Workflow System. Add your custom handlers here.
 * 
 * @since 1.2.0
 */
class RegisterWorkflowHandlers
{
    /**
     * Register all custom step handlers
     * 
     * This method is called via the 'mailerpress_register_step_handlers' hook
     * which is triggered in mailerpress.php during workflow system initialization.
     * 
     * @param mixed $manager The step handler manager instance
     */
    #[Action('mailerpress_register_step_handlers')]
    public function registerHandlers($manager): void
    {
        // Only register if we're in the workflow system context
        if (!class_exists('MailerPress\Core\Workflows\WorkflowSystem')) {
            return;
        }

        // Register the example custom step handler
        // Uncomment the line below to enable the example handler:
        // $manager->registerStepHandler(new ExampleCustomStepHandler());

        // Register MailerPress action handlers
        $createCampaignHandler = new CreateCampaignStepHandler();
        $manager->registerStepHandler($createCampaignHandler);

        // Add more custom handlers here as needed:
        // $manager->registerStepHandler(new YetAnotherHandler());
    }
}
