<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;


\defined('ABSPATH') || exit;

/**
 * WooCommerce Product Purchased Trigger
 * 
 * Fires when a specific product is purchased in a WooCommerce order.
 * This trigger checks if a particular product (or any product in a category)
 * was included in a completed order.
 * 
 * The trigger listens to the 'woocommerce_order_status_completed' hook
 * and filters orders based on product configuration.
 * 
 * Data available in the workflow context:
 * - order_id: The unique identifier of the order
 * - order_number: The order number
 * - customer_email: The customer's email address
 * - customer_first_name: The customer's first name
 * - customer_last_name: The customer's last name
 * - customer_id: The WordPress user ID (0 for guests)
 * - order_total: The total amount of the order
 * - order_currency: The currency used for the order
 * - order_date: The date when the order was created
 * - completed_date: The date when the order was completed
 * - purchased_product_id: The ID of the specific product purchased
 * - purchased_product_name: The name of the product
 * - purchased_product_quantity: The quantity purchased
 * - billing_address: Array with billing address details
 * - shipping_address: Array with shipping address details
 * - order_items: Array of all order items
 * - payment_method: The payment method used
 * 
 * @since 1.2.0
 */
class ProductPurchased
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_product_purchased';

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

        $definition = [
            'label' => __('Product Purchased', 'mailerpress'),
            'description' => __('Triggered when a specific product is purchased in an order. Perfect for sending product follow-up emails, usage guides, or complementary offers based on the purchase.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [
                [
                    'key' => 'products',
                    'label' => __('Products', 'mailerpress'),
                    'type' => 'token',
                    'required' => false,
                    'data_source' => 'woocommerce_products',
                    'help' => __('Only trigger when one of these specific products is purchased (leave empty to trigger for any product)', 'mailerpress'),
                ],
                [
                    'key' => 'product_categories',
                    'label' => __('Product Categories', 'mailerpress'),
                    'type' => 'token',
                    'required' => false,
                    'data_source' => 'woocommerce_categories',
                    'help' => __('Only trigger when a product from one of these categories is purchased (leave empty to trigger for any category)', 'mailerpress'),
                ],
                [
                    'key' => 'product_tags',
                    'label' => __('Product Tags', 'mailerpress'),
                    'type' => 'token',
                    'required' => false,
                    'data_source' => 'woocommerce_product_tags',
                    'help' => __('Only trigger when a product with one of these tags is purchased (leave empty to trigger for any tag)', 'mailerpress'),
                ],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            self::HOOK_NAME,
            self::contextBuilder(...),
            $definition
        );
    }

    /**
     * Build context from hook parameters
     * 
     * This trigger can be configured with a specific product_id in the trigger settings.
     * If configured, it will only fire for orders containing that product.
     * 
     * @param mixed ...$args Hook arguments passed by WordPress (first arg is order_id)
     * @return array Context data for the workflow
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

            $orderItems = [];
            $purchasedProducts = [];

            foreach ($order->get_items() as $itemId => $item) {
                $product = $item->get_product();
                $productData = [
                    'item_id' => $itemId,
                    'product_id' => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id(),
                    'product_name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'subtotal' => $item->get_subtotal(),
                    'total' => $item->get_total(),
                    'sku' => $product ? $product->get_sku() : '',
                ];

                $orderItems[] = $productData;
                $purchasedProducts[] = [
                    'product_id' => $item->get_product_id(),
                    'product_name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
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
                'purchased_products' => $purchasedProducts,
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'order_status' => $order->get_status(),
                'order_key' => $order->get_order_key(),
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
