<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\SureCart;

\defined('ABSPATH') || exit;

/**
 * SureCart Purchase Revoked Trigger
 * 
 * Fires when a purchase is revoked in SureCart.
 * This trigger captures purchase revocation events and extracts relevant
 * purchase, subscription, and customer data to be used in workflow automations.
 * 
 * Based on SureCart Purchase Actions Reference:
 * https://developer.surecart.com/docs/purchase-actions-reference#surecartpurchase_revoked
 * 
 * Hook: surecart/purchase_revoked
 * 
 * A purchase can be revoked when:
 * - An order's line-item is refunded
 * - A subscription is canceled or expired
 * 
 * This is useful for:
 * - Sending cancellation confirmation emails
 * - Displaying banners to inform users they are losing access
 * - Storing revocation status locally for faster access
 * - Triggering retention campaigns
 * - Handling refund notifications
 * 
 * Note: This action must be enabled in your SureCart developer settings (Manage Webhooks)
 * 
 * @since 1.2.0
 */
class SubscriptionSetToCancel
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'surecart_purchase_revoked';

    /**
     * Register the custom trigger
     * 
     * @param mixed $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        // Only register if SureCart is active
        if (!class_exists('\SureCart\Models\Purchase') && !function_exists('surecart')) {
            return;
        }

        $definition = [
            'label' => __('Purchase Revoked', 'mailerpress'),
            'description' => __('Triggered when a purchase is revoked in SureCart. This can happen when an order line-item is refunded, or a subscription is canceled or expired. Perfect for sending cancellation confirmations, refund notifications, or retention campaigns.', 'mailerpress'),
            'icon' => 'surecart',
            'category' => 'surecart',
            'settings_schema' => [],
            'output_fields' => [
                // Customer fields
                ['key' => 'customer_email', 'label' => __('Customer Email', 'mailerpress'), 'type' => 'email', 'group' => 'customer'],
                ['key' => 'customer_first_name', 'label' => __('Customer First Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_last_name', 'label' => __('Customer Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_name', 'label' => __('Customer Full Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_id', 'label' => __('SureCart Customer ID', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'user_id', 'label' => __('WordPress User ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
                // Purchase/Order fields
                ['key' => 'purchase_id', 'label' => __('Purchase ID', 'mailerpress'), 'type' => 'string', 'group' => 'purchase'],
                ['key' => 'order_id', 'label' => __('Order ID', 'mailerpress'), 'type' => 'string', 'group' => 'purchase'],
                ['key' => 'order_total', 'label' => __('Order Total', 'mailerpress'), 'type' => 'number', 'group' => 'purchase'],
                ['key' => 'order_currency', 'label' => __('Order Currency', 'mailerpress'), 'type' => 'string', 'group' => 'purchase'],
                ['key' => 'order_status', 'label' => __('Order Status', 'mailerpress'), 'type' => 'string', 'group' => 'purchase'],
                ['key' => 'revoked', 'label' => __('Revoked', 'mailerpress'), 'type' => 'boolean', 'group' => 'purchase'],
                // Subscription fields (if purchase is linked to a subscription)
                ['key' => 'subscription_id', 'label' => __('Subscription ID', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
                ['key' => 'subscription_status', 'label' => __('Subscription Status', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
                // Product fields
                ['key' => 'product_id', 'label' => __('Product ID', 'mailerpress'), 'type' => 'string', 'group' => 'product'],
                ['key' => 'product_name', 'label' => __('Product Name', 'mailerpress'), 'type' => 'string', 'group' => 'product'],
            ],
        ];

        // Register for purchase_revoked webhook
        // Reference: https://developer.surecart.com/docs/purchase-actions-reference#surecartpurchase_revoked
        // Hook format: do_action('surecart/purchase_revoked', $purchase, $webhook_data)
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'surecart/purchase_revoked',
            [self::class, 'contextBuilder'],
            $definition
        );
    }

    /**
     * Build context from purchase object
     * 
     * @param mixed $purchase The SureCart purchase object
     * @param mixed $webhookData The full webhook data (optional, second parameter from webhook)
     * @return array Context data for the workflow
     */
    public static function contextBuilder($purchase, $webhookData = null): array
    {
        if (empty($purchase)) {
            return [];
        }

        try {
            // Use OrderCreated's contextBuilder as base (it handles Purchase objects)
            $context = OrderCreated::contextBuilder($purchase);

            // Add purchase-specific fields
            $purchaseId = '';
            $revoked = false;
            $subscriptionId = '';
            $subscriptionStatus = '';

            if (is_object($purchase)) {
                $purchaseId = $purchase->id ?? '';
                $revoked = $purchase->revoked ?? false;

                // Try to get subscription information if purchase is linked to a subscription
                if (isset($purchase->subscription)) {
                    $subscription = $purchase->subscription;
                    if (is_object($subscription)) {
                        $subscriptionId = $subscription->id ?? '';
                        $subscriptionStatus = $subscription->status ?? '';
                    } elseif (is_array($subscription)) {
                        $subscriptionId = $subscription['id'] ?? '';
                        $subscriptionStatus = $subscription['status'] ?? '';
                    } elseif (is_string($subscription)) {
                        $subscriptionId = $subscription;
                    }
                }
            } elseif (is_array($purchase)) {
                $purchaseId = $purchase['id'] ?? '';
                $revoked = $purchase['revoked'] ?? false;

                if (isset($purchase['subscription'])) {
                    $subscription = $purchase['subscription'];
                    if (is_array($subscription)) {
                        $subscriptionId = $subscription['id'] ?? '';
                        $subscriptionStatus = $subscription['status'] ?? '';
                    } elseif (is_string($subscription)) {
                        $subscriptionId = $subscription;
                    }
                }
            }

            // Add purchase-specific fields to context
            $context['purchase_id'] = $purchaseId;
            $context['revoked'] = $revoked;

            // Add subscription fields if available
            if (!empty($subscriptionId)) {
                $context['subscription_id'] = $subscriptionId;
            }
            if (!empty($subscriptionStatus)) {
                $context['subscription_status'] = $subscriptionStatus;
            }

            return $context;
        } catch (\Exception $e) {
            return [];
        }
    }
}
