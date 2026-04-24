<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows;

\defined('ABSPATH') || exit;

use MailerPress\Actions\Workflows\MailerPress\Triggers\ContactOptinTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\ContactTagAddedTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\ContactCustomFieldUpdatedTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\BirthdayCheckTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\CustomTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\WebhookReceivedTrigger;
use MailerPress\Actions\Workflows\WooCommerce\OrderStatusChanged;
use MailerPress\Actions\Workflows\WooCommerce\ProductPurchased;
use MailerPress\Actions\Workflows\WooCommerce\CustomerFirstOrder;
use MailerPress\Actions\Workflows\WooCommerce\AbandonedCartTrigger;
use MailerPress\Actions\Workflows\WooCommerce\SubscriptionStatusChanged;
use MailerPress\Actions\Workflows\WooCommerce\SubscriptionStarted;
use MailerPress\Actions\Workflows\WooCommerce\SubscriptionRenewed;
use MailerPress\Actions\Workflows\WooCommerce\SubscriptionPaymentFailed;
use MailerPress\Actions\Workflows\WooCommerce\SubscriptionExpired;
use MailerPress\Actions\Workflows\WooCommerce\SubscriptionTrialStarted;
use MailerPress\Actions\Workflows\WooCommerce\SubscriptionTrialEnded;
use MailerPress\Core\Attributes\Action;

/**
 * Register custom workflow triggers
 * 
 * This class is responsible for registering all custom triggers
 * for the MailerPress Workflow System. Add your custom triggers here.
 * 
 * @since 1.2.0
 */
class RegisterCustomTriggers
{
    /**
     * Register all custom triggers
     * 
     * This method is called via the 'mailerpress_register_custom_triggers' hook
     * which is triggered in mailerpress.php during workflow system initialization.
     * 
     * @param mixed $manager The trigger manager instance
     */
    #[Action('mailerpress_register_custom_triggers')]
    public function registerTriggers($manager): void
    {
        // Only register if we're in the workflow system context
        if (!class_exists('MailerPress\Core\Workflows\WorkflowSystem')) {
            return;
        }



        ContactOptinTrigger::register($manager);
        ContactTagAddedTrigger::register($manager);
        ContactCustomFieldUpdatedTrigger::register($manager);
        BirthdayCheckTrigger::register($manager);
        CustomTrigger::register($manager);
        WebhookReceivedTrigger::register($manager);

        // Only register WooCommerce triggers if WooCommerce is active
        if (function_exists('wc_get_order_statuses')) {
            OrderStatusChanged::register($manager);
            ProductPurchased::register($manager);
            CustomerFirstOrder::register($manager);
            AbandonedCartTrigger::register($manager);
        }

        // Only register WooCommerce Subscriptions triggers if WooCommerce Subscriptions is active
        if (class_exists('WC_Subscriptions')) {
            SubscriptionStatusChanged::register($manager);
            SubscriptionStarted::register($manager);
            SubscriptionRenewed::register($manager);
            SubscriptionPaymentFailed::register($manager);
            SubscriptionExpired::register($manager);
            SubscriptionTrialStarted::register($manager);
            SubscriptionTrialEnded::register($manager);
        }
    }
}
