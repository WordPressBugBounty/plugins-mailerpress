<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\FluentCart;

\defined('ABSPATH') || exit;

/**
 * Fluent Cart Subscription Activated Trigger
 *
 * Fires when a subscription is activated in Fluent Cart.
 * Hook: fluent_cart/subscription_activated
 * Parameter: array{ subscription: Subscription, order: ?Order, customer: ?Customer, meta: array }
 *
 * @since 1.3.0
 */
class SubscriptionActivated
{
    public const TRIGGER_KEY = 'fluentcart_subscription_activated';

    public static function register($manager): void
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $definition = [
            'label' => __('Subscription Activated', 'mailerpress'),
            'description' => __('Triggered when a subscription is activated in Fluent Cart. Perfect for sending welcome emails, onboarding sequences, or activation confirmations.', 'mailerpress'),
            'icon' => 'fluentcart',
            'category' => 'fluentcart',
            'settings_schema' => [],
            'output_fields' => self::getSubscriptionOutputFields(),
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'fluent_cart/subscription_activated',
            [self::class, 'contextBuilder'],
            $definition
        );
    }

    public static function contextBuilder($eventData): array
    {
        if (empty($eventData) || !is_array($eventData)) {
            return [];
        }

        return self::buildSubscriptionContext($eventData);
    }

    /**
     * Shared subscription context builder used by other FluentCart subscription triggers.
     *
     * @param array $eventData
     * @return array
     */
    public static function buildSubscriptionContext(array $eventData): array
    {
        $subscription = $eventData['subscription'] ?? null;
        $order = $eventData['order'] ?? null;
        $customer = $eventData['customer'] ?? null;

        if (empty($subscription)) {
            return [];
        }

        try {
            // Extract subscription properties
            $subscriptionId = $subscription->id ?? 0;
            $status = $subscription->status ?? '';
            $itemName = $subscription->item_name ?? '';
            $billingInterval = $subscription->billing_interval ?? '';
            $recurringAmount = $subscription->recurring_amount ?? 0;
            $recurringTotal = $subscription->recurring_total ?? 0;
            $signupFee = $subscription->signup_fee ?? 0;
            $trialDays = $subscription->trial_days ?? 0;
            $trialEndsAt = $subscription->trial_ends_at ?? '';
            $nextBillingDate = $subscription->next_billing_date ?? '';
            $expireAt = $subscription->expire_at ?? '';
            $billTimes = $subscription->bill_times ?? 0;
            $billCount = $subscription->bill_count ?? 0;
            $productId = $subscription->product_id ?? 0;
            $parentOrderId = $subscription->parent_order_id ?? 0;
            $createdAt = $subscription->created_at ?? '';

            // Extract customer data
            $customerEmail = '';
            $firstName = '';
            $lastName = '';
            $fullName = '';
            $customerId = 0;
            $userId = 0;

            if ($customer) {
                $customerEmail = $customer->email ?? '';
                $firstName = $customer->first_name ?? '';
                $lastName = $customer->last_name ?? '';
                $fullName = trim(($firstName . ' ' . $lastName));
                $customerId = $customer->id ?? 0;
                $userId = $customer->user_id ?? 0;
            }

            // Fallback: try from subscription's customer relation
            if (empty($customerEmail) && isset($subscription->customer_id) && class_exists('FluentCart\App\Models\Customer')) {
                try {
                    $fcCustomer = \FluentCart\App\Models\Customer::find($subscription->customer_id);
                    if ($fcCustomer) {
                        $customerEmail = $fcCustomer->email ?? '';
                        $firstName = $fcCustomer->first_name ?? '';
                        $lastName = $fcCustomer->last_name ?? '';
                        $fullName = trim(($firstName . ' ' . $lastName));
                        $customerId = $fcCustomer->id ?? 0;
                        $userId = $fcCustomer->user_id ?? 0;
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            if (empty($customerEmail)) {
                return [];
            }

            // Extract order data if available
            $orderId = 0;
            $orderTotal = 0;
            $orderCurrency = '';

            if ($order) {
                $orderId = $order->id ?? 0;
                $orderTotal = $order->total_amount ?? 0;
                $orderCurrency = $order->currency ?? '';
            }

            return [
                'user_id' => $userId ?: 0,
                'customer_email' => $customerEmail,
                'customer_first_name' => $firstName,
                'customer_last_name' => $lastName,
                'customer_name' => $fullName,
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'subscription_status' => $status,
                'item_name' => $itemName,
                'product_id' => $productId,
                'billing_interval' => $billingInterval,
                'recurring_amount' => $recurringAmount,
                'recurring_total' => $recurringTotal,
                'signup_fee' => $signupFee,
                'trial_days' => $trialDays,
                'trial_ends_at' => $trialEndsAt ? (string) $trialEndsAt : '',
                'next_billing_date' => $nextBillingDate ? (string) $nextBillingDate : '',
                'expire_at' => $expireAt ? (string) $expireAt : '',
                'bill_times' => $billTimes,
                'bill_count' => $billCount,
                'order_id' => $orderId ?: $parentOrderId,
                'order_total' => $orderTotal,
                'order_currency' => $orderCurrency ? strtoupper($orderCurrency) : '',
                'subscription_date' => $createdAt ? (string) $createdAt : '',
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Shared output fields for subscription triggers.
     */
    public static function getSubscriptionOutputFields(): array
    {
        return [
            ['key' => 'customer_email', 'label' => __('Customer Email', 'mailerpress'), 'type' => 'email', 'group' => 'customer'],
            ['key' => 'customer_first_name', 'label' => __('Customer First Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
            ['key' => 'customer_last_name', 'label' => __('Customer Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
            ['key' => 'customer_name', 'label' => __('Customer Full Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
            ['key' => 'customer_id', 'label' => __('Customer ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
            ['key' => 'user_id', 'label' => __('WordPress User ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
            ['key' => 'subscription_id', 'label' => __('Subscription ID', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
            ['key' => 'subscription_status', 'label' => __('Subscription Status', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
            ['key' => 'item_name', 'label' => __('Product Name', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
            ['key' => 'billing_interval', 'label' => __('Billing Interval', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
            ['key' => 'recurring_amount', 'label' => __('Recurring Amount', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
            ['key' => 'recurring_total', 'label' => __('Recurring Total', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
            ['key' => 'next_billing_date', 'label' => __('Next Billing Date', 'mailerpress'), 'type' => 'date', 'group' => 'subscription'],
            ['key' => 'trial_days', 'label' => __('Trial Days', 'mailerpress'), 'type' => 'number', 'group' => 'subscription'],
            ['key' => 'order_id', 'label' => __('Order ID', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
            ['key' => 'order_total', 'label' => __('Order Total', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
            ['key' => 'order_currency', 'label' => __('Order Currency', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
        ];
    }
}
