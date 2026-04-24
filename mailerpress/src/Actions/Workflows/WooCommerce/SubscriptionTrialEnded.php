<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * WooCommerce Subscription Trial Ended Trigger
 * 
 * Fires when a WooCommerce Subscription trial period ends.
 * This trigger captures subscription trial end events and extracts relevant
 * subscription and customer data to be used in workflow automations.
 * 
 * The trigger listens to the 'woocommerce_scheduled_subscription_trial_end' hook
 * which fires when a subscription trial period ends.
 * 
 * Data available in the workflow context:
 * - subscription_id: The unique identifier of the subscription
 * - subscription_status: The subscription status
 * - user_id: The WordPress user ID associated with the subscription
 * - customer_email: The customer's email address
 * - customer_first_name: The customer's first name
 * - customer_last_name: The customer's last name
 * - order_id: The parent order ID
 * - trial_end_date: The trial end date
 * - billing_period: The billing period (day, week, month, year)
 * - billing_interval: The billing interval (e.g., 1 for monthly, 2 for bi-monthly)
 * 
 * @since 1.2.0
 */
class SubscriptionTrialEnded
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_subscription_trial_ended';

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
            'label' => __('Subscription Trial Ended', 'mailerpress'),
            'description' => __('Triggered when a WooCommerce subscription trial period ends. Perfect for sending conversion emails, payment reminders, or special offers to convert trial users.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [],
        ];

        // Register trigger for subscription trial ended hook
        // This hook fires: do_action('woocommerce_scheduled_subscription_trial_end', $subscription_id);
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_scheduled_subscription_trial_end',
            function ($subscriptionId) {
                if (!function_exists('wcs_get_subscription')) {
                    return [];
                }
                
                $subscription = wcs_get_subscription((int) $subscriptionId);
                if (!($subscription instanceof \WC_Subscription)) {
                    return [];
                }
                
                $context = SubscriptionStatusChanged::contextBuilder($subscription, $subscription->get_status(), '');
                $context['trial_end_date'] = $subscription->get_date('trial_end');
                $context['trial_length'] = $subscription->get_trial_length();
                $context['trial_period'] = $subscription->get_trial_period();
                
                return $context;
            },
            $definition
        );
    }
}

