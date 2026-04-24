<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * WooCommerce Subscription Renewed Trigger
 * 
 * Fires when a WooCommerce Subscription is renewed.
 * This trigger captures subscription renewal events and extracts relevant
 * subscription and customer data to be used in workflow automations.
 * 
 * The trigger listens to the 'woocommerce_subscription_renewal_payment_completed' hook
 * which fires when a subscription renewal payment is completed.
 * 
 * Data available in the workflow context:
 * - subscription_id: The unique identifier of the subscription
 * - subscription_status: The subscription status
 * - user_id: The WordPress user ID associated with the subscription
 * - customer_email: The customer's email address
 * - customer_first_name: The customer's first name
 * - customer_last_name: The customer's last name
 * - order_id: The renewal order ID
 * - next_payment_date: The next payment date (if applicable)
 * - billing_period: The billing period (day, week, month, year)
 * - billing_interval: The billing interval (e.g., 1 for monthly, 2 for bi-monthly)
 * 
 * @since 1.2.0
 */
class SubscriptionRenewed
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_subscription_renewed';

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
            'label' => __('Subscription Renewed', 'mailerpress'),
            'description' => __('Triggered when a WooCommerce subscription is renewed. Perfect for sending renewal confirmations, thank you emails, or special offers for loyal subscribers.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [],
        ];

        // Register trigger for subscription renewal hook
        // This hook fires: do_action('woocommerce_subscription_renewal_payment_completed', $subscription, $renewal_order);
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_subscription_renewal_payment_completed',
            function ($subscription, $renewalOrder = null) {
                $context = SubscriptionStatusChanged::contextBuilder($subscription, $subscription->get_status(), '');
                
                // Add renewal order information if available
                if ($renewalOrder instanceof \WC_Order) {
                    $context['renewal_order_id'] = $renewalOrder->get_id();
                    $context['renewal_order_number'] = $renewalOrder->get_order_number();
                    $context['renewal_order_total'] = $renewalOrder->get_total();
                    $context['renewal_order_date'] = $renewalOrder->get_date_created() ? $renewalOrder->get_date_created()->format('Y-m-d H:i:s') : '';
                }
                
                return $context;
            },
            $definition
        );
    }
}

