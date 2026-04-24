<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * WooCommerce Subscription Trial Started Trigger
 * 
 * Fires when a WooCommerce Subscription trial period starts.
 * This trigger captures subscription trial start events and extracts relevant
 * subscription and customer data to be used in workflow automations.
 * 
 * The trigger listens to the 'woocommerce_subscription_status_active' hook
 * and checks if the subscription has a trial period.
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
class SubscriptionTrialStarted
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_subscription_trial_started';

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
            'label' => __('Subscription Trial Started', 'mailerpress'),
            'description' => __('Triggered when a WooCommerce subscription trial period starts. Perfect for sending trial welcome emails, onboarding sequences, or trial usage tips.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [],
        ];

        // Register trigger for subscription trial started
        // We use the status_active hook and filter for subscriptions with trial
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_subscription_status_active',
            function ($subscription) {
                // Only trigger if subscription has a trial period
                if (!($subscription instanceof \WC_Subscription)) {
                    return [];
                }
                
                $trialEnd = $subscription->get_date('trial_end');
                if (empty($trialEnd)) {
                    return []; // No trial period, skip
                }
                
                $context = SubscriptionStatusChanged::contextBuilder($subscription, 'active', '');
                $context['trial_end_date'] = $trialEnd;
                $context['trial_length'] = $subscription->get_trial_length();
                $context['trial_period'] = $subscription->get_trial_period();
                
                return $context;
            },
            $definition
        );
    }
}

