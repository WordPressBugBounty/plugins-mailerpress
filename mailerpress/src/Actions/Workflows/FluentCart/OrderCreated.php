<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\FluentCart;

\defined('ABSPATH') || exit;

/**
 * Fluent Cart Order Created Trigger
 *
 * Fires when a new order is placed in Fluent Cart (at checkout, before payment).
 * Hook: fluent_cart/order_created
 * Parameter: array{ order: Order, prev_order: ?Order, customer: ?Customer, transaction: ?OrderTransaction }
 *
 * @since 1.3.0
 */
class OrderCreated
{
    public const TRIGGER_KEY = 'fluentcart_order_created';

    public static function register($manager): void
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $paymentStatusOptions = [
            ['value' => '', 'label' => __('Any payment status', 'mailerpress')],
            ['value' => 'pending', 'label' => __('Pending', 'mailerpress')],
            ['value' => 'paid', 'label' => __('Paid', 'mailerpress')],
            ['value' => 'partially_paid', 'label' => __('Partially Paid', 'mailerpress')],
            ['value' => 'failed', 'label' => __('Failed', 'mailerpress')],
            ['value' => 'refunded', 'label' => __('Refunded', 'mailerpress')],
            ['value' => 'authorized', 'label' => __('Authorized', 'mailerpress')],
        ];

        $orderStatusOptions = [
            ['value' => '', 'label' => __('Any order status', 'mailerpress')],
            ['value' => 'processing', 'label' => __('Processing', 'mailerpress')],
            ['value' => 'completed', 'label' => __('Completed', 'mailerpress')],
            ['value' => 'on-hold', 'label' => __('On Hold', 'mailerpress')],
            ['value' => 'canceled', 'label' => __('Canceled', 'mailerpress')],
            ['value' => 'failed', 'label' => __('Failed', 'mailerpress')],
        ];

        $definition = [
            'label' => __('Order Created', 'mailerpress'),
            'description' => __('Triggered when a new order is placed in Fluent Cart. Fires at checkout before payment is processed. Ideal for sending order received confirmations or starting pre-payment workflows.', 'mailerpress'),
            'icon' => 'fluentcart',
            'category' => 'fluentcart',
            'settings_schema' => [
                [
                    'key' => 'order_status',
                    'label' => __('Order Status', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => $orderStatusOptions,
                    'help' => __('Only trigger when the order has this status (leave empty for any)', 'mailerpress'),
                ],
                [
                    'key' => 'payment_status',
                    'label' => __('Payment Status', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => $paymentStatusOptions,
                    'help' => __('Only trigger when the payment has this status (leave empty for any)', 'mailerpress'),
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
                ['key' => 'order_status', 'label' => __('Order Status', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'payment_status', 'label' => __('Payment Status', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'payment_method', 'label' => __('Payment Method', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_date', 'label' => __('Order Date', 'mailerpress'), 'type' => 'date', 'group' => 'order'],
                ['key' => 'billing_address.name', 'label' => __('Billing Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.city', 'label' => __('Billing City', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.country', 'label' => __('Billing Country', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'fluent_cart/order_created',
            [self::class, 'contextBuilder'],
            $definition
        );
    }

    public static function contextBuilder($eventData): array
    {
        if (empty($eventData) || !is_array($eventData)) {
            return [];
        }

        return OrderPaid::buildOrderContext($eventData);
    }
}
