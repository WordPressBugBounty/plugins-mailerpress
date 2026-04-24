<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;


\defined('ABSPATH') || exit;

/**
 * WooCommerce Customer First Order Trigger
 * 
 * Fires when a customer completes their first order.
 * This is useful for welcome sequences, first-purchase thank you emails,
 * and onboarding new customers.
 * 
 * The trigger listens to the 'woocommerce_order_status_completed' hook
 * and checks if this is the customer's first completed order.
 * 
 * Data available in the workflow context:
 * - order_id: The unique identifier of the first order
 * - order_number: The order number
 * - customer_email: The customer's email address
 * - customer_first_name: The customer's first name
 * - customer_last_name: The customer's last name
 * - customer_id: The WordPress user ID (0 for guests)
 * - order_total: The total amount of the first order
 * - order_currency: The currency used for the order
 * - order_date: The date when the order was created
 * - completed_date: The date when the order was completed
 * - billing_address: Array with billing address details
 * - shipping_address: Array with shipping address details
 * - order_items: Array of order items with product details
 * - payment_method: The payment method used
 * - payment_method_title: The human-readable payment method name
 * - is_first_order: Always true for this trigger
 * 
 * @since 1.2.0
 */
class CustomerFirstOrder
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_customer_first_order';

    /**
     * WordPress hook name to listen to
     */
    public const HOOK_NAME = 'woocommerce_order_status_completed';

    /**
     * Register the custom trigger
     * 
     * @param mixed $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        // Only register if WooCommerce is active
        if (!function_exists('wc_get_order')) {
            return;
        }

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            self::HOOK_NAME,
            self::contextBuilder(...),
            [
                'label' => __('Customer First Order', 'mailerpress'),
                'description' => __('Triggered when a customer places their first order. Ideal for sending welcome emails, personalized thank you messages, or starting onboarding sequences for new customers.', 'mailerpress'),
                'icon' => 'woocommerce',
                'category' => 'woocommerce',
            ]
        );
    }

    /**
     * Build context from hook parameters
     * 
     * This checks if the current order is the customer's first completed order
     * by counting previous completed orders for this customer.
     * 
     * @param mixed ...$args Hook arguments passed by WordPress (first arg is order_id)
     * @return array Context data for the workflow (empty if not first order)
     */
    public static function contextBuilder(...$args): array
    {
        $orderId = (int)($args[0] ?? 0);

        if (!$orderId) {
            return [];
        }

        if (!function_exists('wc_get_order')) {
            return [];
        }

        try {
            $order = wc_get_order($orderId);

            if (!$order) {
                return [];
            }

            // Get customer ID (user ID for registered users, email for guests)
            $customerId = $order->get_customer_id();
            $customerEmail = $order->get_billing_email();

            // Count previous completed orders
            if (!function_exists('wc_get_orders')) {
                return [];
            }

            $previousOrders = wc_get_orders([
                'customer_id' => $customerId ?: $customerEmail,
                'status' => ['completed'],
                'limit' => -1,
                'exclude' => [$orderId], // Exclude current order
            ]);

            // If there are previous completed orders, this is not the first order
            if (!empty($previousOrders)) {
                return []; // Return empty to skip this trigger
            }

            // This is the first order - build full context
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

            return [
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
                'completed_date' => $order->get_date_completed() ? $order->get_date_completed()->format('Y-m-d H:i:s') : \current_time('mysql'),
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'order_items' => $orderItems,
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'order_status' => $order->get_status(),
                'order_key' => $order->get_order_key(),
                'is_first_order' => true,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
