<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\FluentCart;

\defined('ABSPATH') || exit;

/**
 * Fluent Cart Order Paid Trigger
 *
 * Fires when an order payment is completed in Fluent Cart.
 * Hook: fluent_cart/order_paid
 * Parameter: array{ order: Order, customer: ?Customer, transaction: ?OrderTransaction }
 *
 * @since 1.3.0
 */
class OrderPaid
{
    public const TRIGGER_KEY = 'fluentcart_order_paid';

    public static function register($manager): void
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $definition = [
            'label' => __('Order Paid', 'mailerpress'),
            'description' => __('Triggered when an order payment is completed in Fluent Cart. Perfect for sending order confirmations, thank you emails, or starting post-purchase sequences.', 'mailerpress'),
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
                ['key' => 'order_subtotal', 'label' => __('Order Subtotal', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'order_currency', 'label' => __('Order Currency', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_status', 'label' => __('Order Status', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'payment_status', 'label' => __('Payment Status', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'payment_method', 'label' => __('Payment Method', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_date', 'label' => __('Order Date', 'mailerpress'), 'type' => 'date', 'group' => 'order'],
                ['key' => 'billing_address.name', 'label' => __('Billing Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.address_1', 'label' => __('Billing Address', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.city', 'label' => __('Billing City', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.state', 'label' => __('Billing State', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.postcode', 'label' => __('Billing Postcode', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.country', 'label' => __('Billing Country', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'fluent_cart/order_paid',
            [self::class, 'contextBuilder'],
            $definition
        );
    }

    /**
     * Build context from Fluent Cart event data.
     *
     * @param array $eventData { order: Order, customer: ?Customer, transaction: ?OrderTransaction }
     * @return array
     */
    public static function contextBuilder($eventData): array
    {
        if (empty($eventData) || !is_array($eventData)) {
            return [];
        }

        return self::buildOrderContext($eventData);
    }

    /**
     * Shared order context builder used by other FluentCart triggers.
     *
     * @param array $eventData
     * @return array
     */
    public static function buildOrderContext(array $eventData): array
    {
        $order = $eventData['order'] ?? null;
        $customer = $eventData['customer'] ?? null;

        if (empty($order)) {
            return [];
        }

        try {
            // Extract order properties (Eloquent model)
            $orderId = $order->id ?? 0;
            $orderStatus = $order->status ?? '';
            $paymentStatus = $order->payment_status ?? '';
            $currency = $order->currency ?? 'USD';
            $totalAmount = $order->total_amount ?? 0;
            $subtotal = $order->subtotal ?? 0;
            $paymentMethod = $order->payment_method ?? '';
            $paymentMethodTitle = $order->payment_method_title ?? '';
            $receiptNumber = $order->receipt_number ?? '';
            $invoiceNo = $order->invoice_no ?? '';
            $createdAt = $order->created_at ?? '';

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

            // Fallback: try to get customer from order relation
            if (empty($customerEmail) && isset($order->customer)) {
                $orderCustomer = $order->customer;
                if ($orderCustomer) {
                    $customerEmail = $orderCustomer->email ?? '';
                    $firstName = $orderCustomer->first_name ?? '';
                    $lastName = $orderCustomer->last_name ?? '';
                    $fullName = trim(($firstName . ' ' . $lastName));
                    $customerId = $orderCustomer->id ?? 0;
                    $userId = $orderCustomer->user_id ?? 0;
                }
            }

            if (empty($customerEmail)) {
                return [];
            }

            // Extract billing address
            $billingAddress = [];
            $billingModel = $order->billing_address ?? null;
            if ($billingModel && is_object($billingModel)) {
                $billingAddress = [
                    'name' => $billingModel->name ?? $fullName,
                    'address_1' => $billingModel->address_1 ?? '',
                    'address_2' => $billingModel->address_2 ?? '',
                    'city' => $billingModel->city ?? '',
                    'state' => $billingModel->state ?? '',
                    'postcode' => $billingModel->postcode ?? '',
                    'country' => $billingModel->country ?? '',
                ];
            }

            // Extract shipping address
            $shippingAddress = [];
            $shippingModel = $order->shipping_address ?? null;
            if ($shippingModel && is_object($shippingModel)) {
                $shippingAddress = [
                    'name' => $shippingModel->name ?? '',
                    'address_1' => $shippingModel->address_1 ?? '',
                    'address_2' => $shippingModel->address_2 ?? '',
                    'city' => $shippingModel->city ?? '',
                    'state' => $shippingModel->state ?? '',
                    'postcode' => $shippingModel->postcode ?? '',
                    'country' => $shippingModel->country ?? '',
                ];
            }

            // Extract order items
            $orderItems = [];
            $items = $order->order_items ?? [];
            if ($items) {
                foreach ($items as $item) {
                    $orderItems[] = [
                        'item_id' => $item->id ?? 0,
                        'product_id' => $item->product_id ?? 0,
                        'variation_id' => $item->variation_id ?? 0,
                        'product_name' => $item->item_name ?? $item->title ?? '',
                        'quantity' => $item->quantity ?? 1,
                        'total' => $item->item_total ?? 0,
                        'subtotal' => $item->item_subtotal ?? $item->item_total ?? 0,
                    ];
                }
            }

            return [
                'user_id' => $userId ?: 0,
                'order_id' => $orderId,
                'order_number' => $receiptNumber ?: (string) $orderId,
                'invoice_no' => $invoiceNo,
                'customer_email' => $customerEmail,
                'customer_first_name' => $firstName,
                'customer_last_name' => $lastName,
                'customer_name' => $fullName,
                'customer_id' => $customerId,
                'order_total' => $totalAmount,
                'order_subtotal' => $subtotal,
                'order_currency' => strtoupper($currency),
                'order_status' => $orderStatus,
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'payment_method_title' => $paymentMethodTitle,
                'order_date' => $createdAt ? (string) $createdAt : '',
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'order_items' => $orderItems,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
