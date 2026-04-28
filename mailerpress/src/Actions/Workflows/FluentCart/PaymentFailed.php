<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\FluentCart;

\defined('ABSPATH') || exit;

/**
 * Fluent Cart Payment Failed Trigger
 *
 * Fires when an order payment fails in Fluent Cart.
 * Hook: fluent_cart/order_payment_failed
 * Parameter: array{ order: Order, customer: ?Customer, transaction: ?OrderTransaction, reason: ?string }
 *
 * @since 1.3.0
 */
class PaymentFailed
{
    public const TRIGGER_KEY = 'fluentcart_payment_failed';

    public static function register($manager): void
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $definition = [
            'label' => __('Payment Failed', 'mailerpress'),
            'description' => __('Triggered when an order payment fails in Fluent Cart. Perfect for sending payment retry reminders or support notifications.', 'mailerpress'),
            'icon' => 'fluentcart',
            'category' => 'fluentcart',
            'settings_schema' => [],
            'output_fields' => [
                ['key' => 'customer_email', 'label' => __('Customer Email', 'mailerpress'), 'type' => 'email', 'group' => 'customer'],
                ['key' => 'customer_first_name', 'label' => __('Customer First Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_last_name', 'label' => __('Customer Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_name', 'label' => __('Customer Full Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_id', 'label' => __('Customer ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
                ['key' => 'user_id', 'label' => __('WordPress User ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
                ['key' => 'order_id', 'label' => __('Order ID', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'order_number', 'label' => __('Receipt Number', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_total', 'label' => __('Order Total', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'order_currency', 'label' => __('Order Currency', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'payment_method', 'label' => __('Payment Method', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'failure_reason', 'label' => __('Failure Reason', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_date', 'label' => __('Order Date', 'mailerpress'), 'type' => 'date', 'group' => 'order'],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'fluent_cart/order_payment_failed',
            [self::class, 'contextBuilder'],
            $definition
        );
    }

    public static function contextBuilder($eventData): array
    {
        if (empty($eventData) || !is_array($eventData)) {
            return [];
        }

        $context = OrderPaid::buildOrderContext($eventData);

        if (empty($context)) {
            return [];
        }

        $context['failure_reason'] = $eventData['reason'] ?? '';

        return $context;
    }
}
