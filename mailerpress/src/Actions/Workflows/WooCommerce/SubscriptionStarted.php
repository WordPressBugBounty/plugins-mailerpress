<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * WooCommerce Subscription Started Trigger
 * 
 * Fires when a WooCommerce Subscription period starts.
 * This trigger captures subscription start events and extracts relevant
 * subscription and customer data to be used in workflow automations.
 * 
 * The trigger listens to the 'woocommerce_subscription_status_active' hook
 * which fires when a subscription becomes active.
 * 
 * Data available in the workflow context:
 * - subscription_id: The unique identifier of the subscription
 * - subscription_status: The subscription status (active)
 * - user_id: The WordPress user ID associated with the subscription
 * - customer_email: The customer's email address
 * - customer_first_name: The customer's first name
 * - customer_last_name: The customer's last name
 * - order_id: The parent order ID
 * - next_payment_date: The next payment date (if applicable)
 * - billing_period: The billing period (day, week, month, year)
 * - billing_interval: The billing interval (e.g., 1 for monthly, 2 for bi-monthly)
 * 
 * @since 1.2.0
 */
class SubscriptionStarted
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_subscription_started';

    /**
     * Register the custom trigger
     * 
     * @param mixed $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        // Only register if WooCommerce Subscriptions is active
        if (!class_exists('WC_Subscriptions')) {
            return;
        }

        $definition = [
            'label' => __('Subscription Started', 'mailerpress'),
            'description' => __('Triggered when a WooCommerce subscription period starts. Perfect for sending welcome emails, onboarding sequences, or activation confirmations.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [],
        ];

        // Register trigger for subscription started hook
        // This hook fires: do_action('woocommerce_subscription_status_active', $subscription);
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_subscription_status_active',
            function ($subscription) {
                return SubscriptionStatusChanged::contextBuilder($subscription, 'active', '');
            },
            $definition
        );
    }
}
