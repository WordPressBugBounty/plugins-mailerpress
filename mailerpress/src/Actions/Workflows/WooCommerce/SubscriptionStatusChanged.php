<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * WooCommerce Subscriptions Status Changed Trigger
 * 
 * Fires when a WooCommerce Subscription status changes.
 * This trigger captures subscription events and extracts relevant
 * subscription and customer data to be used in workflow automations.
 * 
 * The trigger listens to the 'woocommerce_subscription_status_updated' hook
 * which fires when a subscription status changes.
 * 
 * Data available in the workflow context:
 * - subscription_id: The unique identifier of the subscription
 * - subscription_status: The new status of the subscription
 * - old_status: The previous status of the subscription
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
class SubscriptionStatusChanged
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_subscription_status_changed';

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

        // Get WooCommerce Subscriptions statuses
        // Use wcs_get_subscription_statuses() if available, otherwise use default statuses
        if (function_exists('wcs_get_subscription_statuses')) {
            $statuses = wcs_get_subscription_statuses();
        } else {
            // Fallback to default subscription statuses
            $statuses = [
                'active' => __('Active', 'mailerpress'),
                'on-hold' => __('On Hold', 'mailerpress'),
                'cancelled' => __('Cancelled', 'mailerpress'),
                'expired' => __('Expired', 'mailerpress'),
                'pending-cancel' => __('Pending Cancellation', 'mailerpress'),
                'switched' => __('Switched', 'mailerpress'),
            ];
        }
        
        $statusOptions = [];

        foreach ($statuses as $statusKey => $statusLabel) {
            // Remove 'wc-' prefix if present
            $cleanKey = str_replace('wc-', '', $statusKey);
            $statusOptions[] = [
                'value' => $cleanKey,
                'label' => $statusLabel,
            ];
        }

        // Add "Any status" option
        array_unshift($statusOptions, [
            'value' => '',
            'label' => __('Any status', 'mailerpress'),
        ]);

        $definition = [
            'label' => __('Subscription Status Changed', 'mailerpress'),
            'description' => __('Triggered when a WooCommerce subscription status changes (e.g., from "Active" to "Cancelled"). Perfect for managing subscription lifecycles, sending reactivation emails, or retention offers.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [
                [
                    'key' => 'subscription_status',
                    'label' => __('Subscription Status', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => $statusOptions,
                    'help' => __('Only trigger when subscription changes to this status (leave empty for any status)', 'mailerpress'),
                ],
            ],
            'output_fields' => [
                // Customer fields
                ['key' => 'customer_email', 'label' => __('Customer Email', 'mailerpress'), 'type' => 'email', 'group' => 'customer'],
                ['key' => 'customer_first_name', 'label' => __('Customer First Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_last_name', 'label' => __('Customer Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
                // Subscription fields
                ['key' => 'subscription_id', 'label' => __('Subscription ID', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
                ['key' => 'subscription_status', 'label' => __('Subscription Status', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
                ['key' => 'old_status', 'label' => __('Old Status', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
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

        // Register trigger for subscription status change hook
        // This hook fires: do_action('woocommerce_subscription_status_updated', $subscription, $new_status, $old_status);
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_subscription_status_updated',
            function ($subscription, $newStatus, $oldStatus) {
                return self::contextBuilder($subscription, $newStatus, $oldStatus);
            },
            $definition
        );

        // Also register for new subscriptions
        // This hook fires: do_action('woocommerce_subscription_created', $subscription);
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_subscription_created',
            function ($subscription) {
                return self::contextBuilder($subscription, $subscription->get_status(), 'new');
            },
            $definition
        );
    }

    /**
     * Build context from hook parameters
     * 
     * @param \WC_Subscription $subscription The subscription object
     * @param string $newStatus The new subscription status
     * @param string $oldStatus The old subscription status
     * @return array Context data for the workflow
     */
    public static function contextBuilder($subscription, string $newStatus, string $oldStatus = ''): array
    {
        // Ensure we have a subscription object
        if (!($subscription instanceof \WC_Subscription)) {
            // Try to get subscription by ID if we received an ID
            if (is_numeric($subscription) && function_exists('wcs_get_subscription')) {
                $subscription = wcs_get_subscription((int) $subscription);
            }
            
            if (!($subscription instanceof \WC_Subscription)) {
                return [];
            }
        }

        $subscriptionId = $subscription->get_id();
        $userId = $subscription->get_user_id();
        $orderId = $subscription->get_parent_id();

        // Get customer information
        $customerEmail = $subscription->get_billing_email();
        $customerFirstName = $subscription->get_billing_first_name();
        $customerLastName = $subscription->get_billing_last_name();

        // Get subscription details
        $nextPaymentDate = $subscription->get_date('next_payment');
        $billingPeriod = $subscription->get_billing_period();
        $billingInterval = $subscription->get_billing_interval();

        // Get subscription items
        $items = [];
        foreach ($subscription->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $items[] = [
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'product_name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total(),
            ];
        }

        // Find or create MailerPress contact
        $contactId = null;
        if ($customerEmail) {
            $contactsModel = new \MailerPress\Models\Contacts();
            $contact = $contactsModel->getContactByEmail($customerEmail);
            
            if ($contact) {
                $contactId = (int) $contact->contact_id;
            } else {
                // Create contact if doesn't exist
                global $wpdb;
                $contactTable = $wpdb->prefix . 'mailerpress_contact';
                
                $wpdb->insert($contactTable, [
                    'email' => $customerEmail,
                    'first_name' => $customerFirstName,
                    'last_name' => $customerLastName,
                    'subscription_status' => 'subscribed',
                    'opt_in_source' => 'woocommerce_subscription',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
                
                $contactId = (int) $wpdb->insert_id;
                
                // Trigger contact_created hook
                do_action('mailerpress_contact_created', $contactId);
            }
        }

        return [
            'subscription_id' => $subscriptionId,
            'subscription_status' => $newStatus,
            'old_status' => $oldStatus,
            'user_id' => $userId ?: $contactId,
            'contact_id' => $contactId,
            'customer_email' => $customerEmail,
            'customer_first_name' => $customerFirstName,
            'customer_last_name' => $customerLastName,
            'order_id' => $orderId,
            'next_payment_date' => $nextPaymentDate,
            'billing_period' => $billingPeriod,
            'billing_interval' => $billingInterval,
            'subscription_items' => $items,
            'subscription_total' => $subscription->get_total(),
            'subscription_currency' => $subscription->get_currency(),
        ];
    }
}

