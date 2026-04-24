<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

class OrderStatusChanged
{
    public const TRIGGER_KEY = 'woocommerce_order_status_changed';

    public static function register($manager): void
    {
        // Only register if WooCommerce is active
        if (!function_exists('wc_get_order_statuses')) {
            return;
        }

        // Get WooCommerce order statuses
        $statuses = wc_get_order_statuses();
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
            'label' => __('Order Status Changed', 'mailerpress'),
            'description' => __('Triggered when a WooCommerce order status changes (e.g., from "Pending" to "Completed"). Ideal for sending confirmation, tracking, or notification emails based on the order state.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [
                [
                    'key' => 'order_status',
                    'label' => __('Order Status', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => $statusOptions,
                    'help' => __('Only trigger when order changes to this status (leave empty for any status)', 'mailerpress'),
                ],
            ],
        ];

        // Register trigger for the generic order status change hook
        // This hook fires: do_action('woocommerce_order_status_changed', $order_id, $old_status, $new_status, $order);
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_order_status_changed',
            function ($orderId, $oldStatus, $newStatus, $order = null) {
                return self::contextBuilder($newStatus, $orderId, $order);
            },
            $definition
        );

        // Also register for new orders
        // This hook fires: do_action('woocommerce_new_order', $order_id, $order);
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_new_order',
            function ($orderId, $order = null) {
                return self::contextBuilder('pending', $orderId, $order);
            },
            $definition
        );
    }

    public static function contextBuilder(string $newStatus, $orderIdOrOrder, $orderOrNull = null): array
    {
        // Handle different argument formats:
        // 1. From woocommerce_order_status_changed: (orderId, oldStatus, newStatus, order)
        // 2. From woocommerce_new_order: (orderId, order)
        $order = null;
        $orderId = 0;

        if ($orderIdOrOrder instanceof \WC_Order) {
            $order = $orderIdOrOrder;
            $orderId = $order->get_id();
        } elseif (is_int($orderIdOrOrder) || is_numeric($orderIdOrOrder)) {
            $orderId = (int) $orderIdOrOrder;
            if ($orderOrNull instanceof \WC_Order) {
                $order = $orderOrNull;
            }
        }

        if (!$orderId) {
            return [];
        }

        if (!function_exists('wc_get_order')) {
            return [];
        }

        try {
            // Get order if not provided
            if (!$order) {
                $order = wc_get_order($orderId);
            }

            if (!$order) {
                return [];
            }

            $orderItems = [];
            foreach ($order->get_items() as $itemId => $item) {
                $product = $item->get_product();
                $orderItems[] = [
                    'item_id' => $itemId,
                    'product_id' => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id(),
                    'product_name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'subtotal' => $item->get_subtotal(),
                    'total' => $item->get_total(),
                    'sku' => $product ? $product->get_sku() : '',
                ];
            }

            $billingAddress = [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'company' => $order->get_billing_company(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ];

            $shippingAddress = [
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'company' => $order->get_shipping_company(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ];

            $customerId = $order->get_customer_id();

            $context = [
                'user_id' => $customerId ?: 0,
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'customer_email' => $order->get_billing_email(),
                'customer_first_name' => $order->get_billing_first_name(),
                'customer_last_name' => $order->get_billing_last_name(),
                'customer_id' => $customerId,
                'order_total' => $order->get_total(),
                'order_currency' => $order->get_currency(),
                'order_date' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : '',
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'order_items' => $orderItems,
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'order_status' => $newStatus,
                'order_key' => $order->get_order_key(),
            ];

            if ($newStatus === 'completed' && $order->get_date_completed()) {
                $context['completed_date'] = $order->get_date_completed()->format('Y-m-d H:i:s');
            }

            if ($newStatus === 'refunded') {
                $refunds = $order->get_refunds();
                $totalRefunded = 0;
                foreach ($refunds as $refund) {
                    $totalRefunded += abs($refund->get_amount());
                }
                $context['refunded_amount'] = $totalRefunded;
                $context['refunded_date'] = \current_time('mysql');
            }

            return $context;
        } catch (\Exception $e) {
            return [];
        }
    }
}
