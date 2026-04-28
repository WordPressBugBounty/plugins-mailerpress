<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\FluentCart;

\defined('ABSPATH') || exit;

/**
 * Fluent Cart Order Refunded Trigger
 *
 * Fires when an order is refunded (fully or partially) in Fluent Cart.
 * Hook: fluent_cart/order_refunded
 * Parameter: array{ order: Order, refunded_items: array, refunded_amount: int, type: string, customer: Customer, ... }
 *
 * @since 1.3.0
 */
class OrderRefunded
{
    public const TRIGGER_KEY = 'fluentcart_order_refunded';

    public static function register($manager): void
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $definition = [
            'label' => __('Order Refunded', 'mailerpress'),
            'description' => __('Triggered when an order is refunded in Fluent Cart. Perfect for sending refund confirmations or follow-up emails.', 'mailerpress'),
            'icon' => 'fluentcart',
            'category' => 'fluentcart',
            'settings_schema' => [
                [
                    'key' => 'refund_type',
                    'label' => __('Refund Type', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => '', 'label' => __('Any refund', 'mailerpress')],
                        ['value' => 'full', 'label' => __('Full refund', 'mailerpress')],
                        ['value' => 'partial', 'label' => __('Partial refund', 'mailerpress')],
                    ],
                    'help' => __('Only trigger for this refund type (leave empty for any)', 'mailerpress'),
                ],
            ],
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
                ['key' => 'refunded_amount', 'label' => __('Refunded Amount', 'mailerpress'), 'type' => 'number', 'group' => 'refund'],
                ['key' => 'refund_type', 'label' => __('Refund Type', 'mailerpress'), 'type' => 'string', 'group' => 'refund'],
                ['key' => 'order_date', 'label' => __('Order Date', 'mailerpress'), 'type' => 'date', 'group' => 'order'],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'fluent_cart/order_refunded',
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

        // Refunded amount is in smallest currency unit (cents)
        $refundedAmount = $eventData['refunded_amount'] ?? 0;
        $context['refunded_amount'] = is_numeric($refundedAmount) ? $refundedAmount / 100 : 0;
        $context['refund_type'] = $eventData['type'] ?? 'partial';

        return $context;
    }
}
