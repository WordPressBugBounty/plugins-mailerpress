<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows;

\defined('ABSPATH') || exit;

use MailerPress\Actions\Workflows\MailerPress\Triggers\ContactOptinTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\ContactTagAddedTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\ContactListAddedTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\ContactCustomFieldUpdatedTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\BirthdayCheckTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\CustomTrigger;
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
use MailerPress\Actions\Workflows\SureCart\OrderCreated as SureCartOrderCreated;
use MailerPress\Actions\Workflows\SureCart\SubscriptionSetToCancel as SureCartSubscriptionSetToCancel;
use MailerPress\Actions\Workflows\FluentCart\OrderCreated as FluentCartOrderCreated;
use MailerPress\Actions\Workflows\FluentCart\OrderPaid as FluentCartOrderPaid;
use MailerPress\Actions\Workflows\FluentCart\OrderRefunded as FluentCartOrderRefunded;
use MailerPress\Actions\Workflows\FluentCart\PaymentFailed as FluentCartPaymentFailed;
use MailerPress\Actions\Workflows\FluentCart\SubscriptionActivated as FluentCartSubscriptionActivated;
use MailerPress\Actions\Workflows\FluentCart\SubscriptionRenewed as FluentCartSubscriptionRenewed;
use MailerPress\Actions\Workflows\FluentCart\SubscriptionCanceled as FluentCartSubscriptionCanceled;
use MailerPress\Actions\Workflows\FluentCart\SubscriptionExpired as FluentCartSubscriptionExpired;
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
        ContactListAddedTrigger::register($manager);
        ContactCustomFieldUpdatedTrigger::register($manager);
        BirthdayCheckTrigger::register($manager);
        CustomTrigger::register($manager);

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

        // Only register SureCart triggers if SureCart is active
        if ($this->isSureCartActive()) {
            SureCartOrderCreated::register($manager);
            SureCartSubscriptionSetToCancel::register($manager);
        }

        // Only register Fluent Cart triggers if Fluent Cart is active
        if ($this->isFluentCartActive()) {
            FluentCartOrderCreated::register($manager);
            FluentCartOrderPaid::register($manager);
            FluentCartOrderRefunded::register($manager);
            FluentCartPaymentFailed::register($manager);
            FluentCartSubscriptionActivated::register($manager);
            FluentCartSubscriptionRenewed::register($manager);
            FluentCartSubscriptionCanceled::register($manager);
            FluentCartSubscriptionExpired::register($manager);
        }
    }

    /**
     * Check if SureCart is active
     *
     * @return bool
     */
    private function isSureCartActive(): bool
    {
        // Check for SureCart plugin
        if (class_exists('\SureCart\SureCart') || class_exists('\SureCart\Models\Purchase')) {
            return true;
        }

        // Check for surecart function
        if (function_exists('surecart')) {
            return true;
        }

        // Check if plugin constants exist
        if (defined('SURECART_PLUGIN_FILE') || defined('SURECART_VERSION')) {
            return true;
        }

        return false;
    }

    /**
     * Check if Fluent Cart is active
     *
     * @return bool
     */
    private function isFluentCartActive(): bool
    {
        if (defined('FLUENTCART_PLUGIN_PATH')) {
            return true;
        }

        if (class_exists('FluentCart\App\App')) {
            return true;
        }

        return false;
    }
}
