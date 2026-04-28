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
            'output_fields' => [
                // Customer fields
                ['key' => 'customer_email', 'label' => __('Customer Email', 'mailerpress'), 'type' => 'email', 'group' => 'customer'],
                ['key' => 'customer_first_name', 'label' => __('Customer First Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_last_name', 'label' => __('Customer Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
                // Subscription fields
                ['key' => 'subscription_id', 'label' => __('Subscription ID', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
                ['key' => 'subscription_status', 'label' => __('Subscription Status', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
                ['key' => 'order_id', 'label' => __('Parent Order ID', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
                ['key' => 'billing_period', 'label' => __('Billing Period', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
                ['key' => 'billing_interval', 'label' => __('Billing Interval', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
                ['key' => 'next_payment_date', 'label' => __('Next Payment Date', 'mailerpress'), 'type' => 'date', 'group' => 'subscription'],
                ['key' => 'subscription_total', 'label' => __('Subscription Total', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
                // Billing address
                ['key' => 'billing_address.first_name', 'label' => __('Billing First Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.last_name', 'label' => __('Billing Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.email', 'label' => __('Billing Email', 'mailerpress'), 'type' => 'email', 'group' => 'billing'],
            ],
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
