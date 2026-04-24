<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * WooCommerce Subscription Expired Trigger
 * 
 * Fires when a WooCommerce Subscription expires.
 * This trigger captures subscription expiration events and extracts relevant
 * subscription and customer data to be used in workflow automations.
 * 
 * The trigger listens to the 'woocommerce_subscription_status_expired' hook
 * which fires when a subscription expires.
 * 
 * Data available in the workflow context:
 * - subscription_id: The unique identifier of the subscription
 * - subscription_status: The subscription status (expired)
 * - user_id: The WordPress user ID associated with the subscription
 * - customer_email: The customer's email address
 * - customer_first_name: The customer's first name
 * - customer_last_name: The customer's last name
 * - order_id: The parent order ID
 * - billing_period: The billing period (day, week, month, year)
 * - billing_interval: The billing interval (e.g., 1 for monthly, 2 for bi-monthly)
 * 
 * @since 1.2.0
 */
class SubscriptionExpired
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_subscription_expired';

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
            'label' => __('Subscription Expired', 'mailerpress'),
            'description' => __('Triggered when a WooCommerce subscription expires. Perfect for sending reactivation offers, win-back campaigns, or final notifications.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [],
        ];

        // Register trigger for subscription expired hook
        // This hook fires: do_action('woocommerce_subscription_status_expired', $subscription);
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_subscription_status_expired',
            function ($subscription) {
                return SubscriptionStatusChanged::contextBuilder($subscription, 'expired', '');
            },
            $definition
        );
    }
}

