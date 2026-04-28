<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\SureCart;

\defined('ABSPATH') || exit;

/**
 * SureCart Order Created Trigger
 * 
 * Fires when a new order/purchase is created in SureCart.
 * This trigger captures order creation events and extracts relevant
 * order and customer data to be used in workflow automations.
 * 
 * The trigger listens to the 'surecart/purchase_created' hook
 * which fires when a purchase is completed.
 * 
 * Data available in the workflow context:
 * - order_id: The unique identifier of the order
 * - customer_email: The customer's email address
 * - customer_first_name: The customer's first name
 * - customer_last_name: The customer's last name
 * - customer_id: The SureCart customer ID
 * - user_id: The WordPress user ID (if linked)
 * - order_total: The total amount of the order
 * - order_currency: The currency used for the order
 * - order_status: The order status
 * - payment_method: The payment method used
 * - line_items: Array of order line items
 * 
 * @since 1.2.0
 */
class OrderCreated
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'surecart_order_created';

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
            'label' => __('Order Created', 'mailerpress'),
            'description' => __('Triggered when a new order is created in SureCart. Perfect for sending order confirmations, thank you emails, or starting post-purchase sequences.', 'mailerpress'),
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
                // Order fields
                ['key' => 'order_id', 'label' => __('Order ID', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_number', 'label' => __('Order Number', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_total', 'label' => __('Order Total', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'order_subtotal', 'label' => __('Order Subtotal', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'order_currency', 'label' => __('Order Currency', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_status', 'label' => __('Order Status', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'payment_method', 'label' => __('Payment Method', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'order_date', 'label' => __('Order Date', 'mailerpress'), 'type' => 'date', 'group' => 'order'],
                // Billing address
                ['key' => 'billing_address.name', 'label' => __('Billing Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.line_1', 'label' => __('Billing Address Line 1', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.city', 'label' => __('Billing City', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.state', 'label' => __('Billing State', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.postal_code', 'label' => __('Billing Postal Code', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.country', 'label' => __('Billing Country', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
            ],
        ];

        // Register for the main purchase created hook (specific SureCart hook)
        // Note: We only use purchase_created to avoid double-triggering.
        // checkout_confirmed fires after purchase_created and would cause duplicates.
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'surecart/purchase_created',
            [self::class, 'contextBuilder'],
            $definition
        );
    }

    /**
     * Build context from purchase object
     * 
     * @param mixed $purchase The SureCart purchase object
     * @return array Context data for the workflow
     */
    public static function contextBuilder($purchase): array
    {
        if (empty($purchase)) {
            return [];
        }

        try {
            // SureCart passes a Purchase model object
            // We need to access its properties carefully

            // Get purchase ID
            $purchaseId = '';
            if (is_object($purchase) && isset($purchase->id)) {
                $purchaseId = $purchase->id;
            } elseif (is_array($purchase) && isset($purchase['id'])) {
                $purchaseId = $purchase['id'];
            }

            // Extract customer data - SureCart stores customer as object or ID
            $customerEmail = '';
            $customerName = '';
            $customerId = '';
            $firstName = '';
            $lastName = '';

            // Try to get customer from purchase object
            $customer = null;
            if (is_object($purchase)) {
                // Try direct property access
                if (isset($purchase->customer)) {
                    $customer = $purchase->customer;
                }
                // Try attributes array (SureCart Model pattern)
                if (empty($customer) && isset($purchase->attributes['customer'])) {
                    $customer = $purchase->attributes['customer'];
                }
            } elseif (is_array($purchase)) {
                $customer = $purchase['customer'] ?? null;
            }

            // Extract customer details
            if (!empty($customer)) {
                if (is_object($customer)) {
                    $customerEmail = $customer->email ?? '';
                    $customerName = $customer->name ?? '';
                    $customerId = $customer->id ?? '';
                    $firstName = $customer->first_name ?? '';
                    $lastName = $customer->last_name ?? '';
                } elseif (is_array($customer)) {
                    $customerEmail = $customer['email'] ?? '';
                    $customerName = $customer['name'] ?? '';
                    $customerId = $customer['id'] ?? '';
                    $firstName = $customer['first_name'] ?? '';
                    $lastName = $customer['last_name'] ?? '';
                } elseif (is_string($customer)) {
                    // Customer is just an ID, try to fetch full customer
                    $customerId = $customer;
                    if (class_exists('\SureCart\Models\Customer')) {
                        try {
                            $customerObj = \SureCart\Models\Customer::find($customer);
                            if ($customerObj && !is_wp_error($customerObj)) {
                                $customerEmail = $customerObj->email ?? '';
                                $customerName = $customerObj->name ?? '';
                                $firstName = $customerObj->first_name ?? '';
                                $lastName = $customerObj->last_name ?? '';
                            }
                        } catch (\Exception $e) {
                            // Ignore errors fetching customer
                        }
                    }
                }
            }

            // If no first/last name but we have full name, split it
            if (empty($firstName) && !empty($customerName)) {
                $nameParts = explode(' ', $customerName, 2);
                $firstName = $nameParts[0] ?? '';
                $lastName = $nameParts[1] ?? '';
            }

            // Get WordPress user ID
            $userId = 0;
            if (is_object($purchase) && method_exists($purchase, 'getUser')) {
                $user = $purchase->getUser();
                if ($user && isset($user->ID)) {
                    $userId = (int) $user->ID;
                }
            }
            if (!$userId && !empty($customerEmail)) {
                $user = \get_user_by('email', $customerEmail);
                if ($user) {
                    $userId = $user->ID;
                }
            }

            // Get order/checkout details
            $initialOrder = null;
            if (is_object($purchase) && isset($purchase->initial_order)) {
                $initialOrder = $purchase->initial_order;
            } elseif (is_object($purchase) && isset($purchase->attributes['initial_order'])) {
                $initialOrder = $purchase->attributes['initial_order'];
            } elseif (is_array($purchase)) {
                $initialOrder = $purchase['initial_order'] ?? $purchase['checkout'] ?? null;
            }

            $total = 0;
            $subtotal = 0;
            $currency = 'USD';
            $billingAddress = [];

            if (!empty($initialOrder)) {
                if (is_object($initialOrder)) {
                    $total = $initialOrder->total_amount ?? $initialOrder->amount_due ?? 0;
                    $subtotal = $initialOrder->subtotal_amount ?? $total;
                    $currency = $initialOrder->currency ?? 'USD';
                    $billingAddress = $initialOrder->billing_address ?? $initialOrder->shipping_address ?? null;
                } elseif (is_array($initialOrder)) {
                    $total = $initialOrder['total_amount'] ?? $initialOrder['amount_due'] ?? 0;
                    $subtotal = $initialOrder['subtotal_amount'] ?? $total;
                    $currency = $initialOrder['currency'] ?? 'USD';
                    $billingAddress = $initialOrder['billing_address'] ?? $initialOrder['shipping_address'] ?? null;
                }
            }

            // Normalize billing address
            $billingAddressArray = [];
            if (!empty($billingAddress)) {
                if (is_object($billingAddress)) {
                    $billingAddressArray = [
                        'name' => $billingAddress->name ?? $customerName,
                        'line_1' => $billingAddress->line_1 ?? $billingAddress->line1 ?? '',
                        'line_2' => $billingAddress->line_2 ?? $billingAddress->line2 ?? '',
                        'city' => $billingAddress->city ?? '',
                        'state' => $billingAddress->state ?? '',
                        'postal_code' => $billingAddress->postal_code ?? '',
                        'country' => $billingAddress->country ?? '',
                    ];
                } elseif (is_array($billingAddress)) {
                    $billingAddressArray = [
                        'name' => $billingAddress['name'] ?? $customerName,
                        'line_1' => $billingAddress['line_1'] ?? $billingAddress['line1'] ?? '',
                        'line_2' => $billingAddress['line_2'] ?? $billingAddress['line2'] ?? '',
                        'city' => $billingAddress['city'] ?? '',
                        'state' => $billingAddress['state'] ?? '',
                        'postal_code' => $billingAddress['postal_code'] ?? '',
                        'country' => $billingAddress['country'] ?? '',
                    ];
                }
            }

            // Get status
            $status = 'completed';
            if (is_object($purchase) && isset($purchase->status)) {
                $status = $purchase->status;
            } elseif (is_array($purchase) && isset($purchase['status'])) {
                $status = $purchase['status'];
            }

            // Get created_at
            $createdAt = \current_time('mysql');
            if (is_object($purchase) && isset($purchase->created_at)) {
                $createdAt = $purchase->created_at;
            } elseif (is_array($purchase) && isset($purchase['created_at'])) {
                $createdAt = $purchase['created_at'];
            }

            return [
                'user_id' => $userId,
                'order_id' => $purchaseId,
                'order_number' => $purchaseId,
                'customer_email' => $customerEmail,
                'customer_first_name' => $firstName,
                'customer_last_name' => $lastName,
                'customer_name' => $customerName,
                'customer_id' => $customerId,
                'order_total' => is_numeric($total) ? $total / 100 : 0, // SureCart stores amounts in cents
                'order_subtotal' => is_numeric($subtotal) ? $subtotal / 100 : 0,
                'order_currency' => strtoupper($currency),
                'order_status' => $status,
                'order_date' => $createdAt,
                'payment_method' => 'surecart',
                'billing_address' => $billingAddressArray,
                'line_items' => [],
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
