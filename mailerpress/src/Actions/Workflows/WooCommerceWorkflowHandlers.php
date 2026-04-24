<?php
/**
 * WooCommerce Workflow Handlers
 * 
 * This file demonstrates how to register handlers conditionally based on
 * whether a third-party plugin (like WooCommerce) is active.
 * 
 * @since 1.2.0
 */

namespace MailerPress\Actions\Workflows;

/**
 * WooCommerceWorkflowHandlers is a simple loader class
 * 
 * It registers the hook only if WooCommerce is active.
 * You can call this from anywhere in your plugin initialization.
 */
class WooCommerceWorkflowHandlers
{
    /**
     * Initialize WooCommerce workflow handlers
     * 
     * Call this method from your plugin's main file or kernel to enable
     * WooCommerce-specific workflow handlers.
     * 
     * Example in mailerpress.php:
     *   WooCommerceWorkflowHandlers::register();
     */
    public static function register(): void
    {
        add_action('mailerpress_register_step_handlers', function($manager) {
            // Only register if WooCommerce is active
            if (!function_exists('wc_get_products')) {
                return;
            }

            // Example: You could register WooCommerce-specific handlers here
            // $manager->registerStepHandler(new CreateProductNotificationHandler());
            // $manager->registerStepHandler(new UpdateInventoryHandler());
            // etc.
        });
    }
}

/**
 * Example handler for WooCommerce orders
 * 
 * This is just a template to show how you would structure a WooCommerce handler
 */
// Example handler class (uncomment to use):
/*
class WooCommerceOrderNotificationHandler implements \MailerPress\Core\Workflows\Handlers\StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'woo_order_notification';
    }

    public function handle(
        \MailerPress\Core\Workflows\Models\Step $step,
        \MailerPress\Core\Workflows\Models\AutomationJob $job,
        array $context = []
    ): \MailerPress\Core\Workflows\Results\StepResult {
        // Your WooCommerce logic here
        return \MailerPress\Core\Workflows\Results\StepResult::success($step->getNextStepId());
    }
}
*/

// Uncomment the line below in your plugin's main initialization to enable:
// WooCommerceWorkflowHandlers::register();
