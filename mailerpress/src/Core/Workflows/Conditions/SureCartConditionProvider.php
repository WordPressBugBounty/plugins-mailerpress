<?php

namespace MailerPress\Core\Workflows\Conditions;

use function add_filter;
use function function_exists;
use function str_starts_with;
use function method_exists;
use function get_userdata;
use function is_array;
use function array_filter;
use function array_map;
use function is_bool;
use function call_user_func;

class SureCartConditionProvider
{
	public function __construct()
	{
		// Register only if SureCart is present
		call_user_func('add_filter', 'mailerpress/condition/get_field_value', [$this, 'getFieldValue'], 10, 4);
		call_user_func('add_filter', 'mailerpress/condition/evaluate_rule', [$this, 'evaluateRule'], 10, 4);
	}

	public function getFieldValue($provided, string $field, int $userId, array $context)
	{
		if (!class_exists('\SureCart\Models\Purchase') && !function_exists('surecart')) {
			return $provided;
		}

		if (!str_starts_with($field, 'sc_') && !str_starts_with($field, 'sc.')) {
			return $provided;
		}

		switch ($field) {
			case 'sc_total_spent':
				return $this->getCustomerTotalSpent($userId, $context);

			case 'sc_order_count':
				return $this->getCustomerOrderCount($userId, $context);

			case 'sc_last_order_status':
				return $this->getLastOrderStatus($userId, $context);

			case 'sc_order_created':
				// Check if an order was created after the cart was updated
				// This is useful for abandoned cart recovery workflows
				$orderId = $context['order_id'] ?? null;
				if (!empty($orderId)) {
					return true;
				}

				// If no order_id in context, check if a recent order exists
				$customerEmail = $context['customer_email'] ?? null;
				$customerId = $context['customer_id'] ?? '';
				
				if (empty($customerEmail) && empty($customerId)) {
					return false;
				}

				// Check for recent purchases (last 24 hours)
				try {
					$purchases = \SureCart\Models\Purchase::where([
						'limit' => 1,
					]);

					if (!empty($customerId)) {
						$purchases = $purchases->where('customer_id', $customerId);
					} elseif (!empty($customerEmail)) {
						// Try to find customer by email first
						$customer = \SureCart\Models\Customer::where(['email' => $customerEmail])->first();
						if ($customer && isset($customer->id)) {
							$purchases = $purchases->where('customer_id', $customer->id);
						} else {
							return false;
						}
					}

					$purchases = $purchases->get();
					
					if (is_wp_error($purchases) || empty($purchases)) {
						return false;
					}

					// Check if purchase was created in last 24 hours
					$purchase = is_array($purchases) ? ($purchases[0] ?? null) : ($purchases->data[0] ?? null);
					if (!$purchase) {
						return false;
					}

					$createdAt = is_object($purchase) ? ($purchase->created_at ?? null) : ($purchase['created_at'] ?? null);
					if (!$createdAt) {
						return false;
					}

					$purchaseTime = strtotime($createdAt);
					$twentyFourHoursAgo = time() - 86400;
					
					return $purchaseTime >= $twentyFourHoursAgo;
				} catch (\Exception $e) {
					return false;
				}
		}

		return $provided;
	}

	/**
	 * Get total amount spent by customer in SureCart
	 */
	private function getCustomerTotalSpent(int $userId, array $context): float
	{
		try {
			$user = call_user_func('get_userdata', $userId);
			if (!$user) {
				return 0.0;
			}

			$customerEmail = $user->user_email;
			$customerId = $context['customer_id'] ?? '';

			// Try to find customer by ID or email
			$customer = null;
			if (!empty($customerId)) {
				$customer = \SureCart\Models\Customer::find($customerId);
			}

			if (!$customer && !empty($customerEmail)) {
				$customers = \SureCart\Models\Customer::where(['email' => $customerEmail])->get();
				if (!is_wp_error($customers) && !empty($customers)) {
					$customer = is_array($customers) ? ($customers[0] ?? null) : ($customers->data[0] ?? null);
				}
			}

			if (!$customer) {
				return 0.0;
			}

			$customerId = is_object($customer) ? ($customer->id ?? '') : ($customer['id'] ?? '');
			if (empty($customerId)) {
				return 0.0;
			}

			// Get all purchases for this customer
			$purchases = \SureCart\Models\Purchase::where(['customer_id' => $customerId])->get();
			
			if (is_wp_error($purchases) || empty($purchases)) {
				return 0.0;
			}

			$purchaseList = is_array($purchases) ? $purchases : ($purchases->data ?? []);
			$total = 0;

			foreach ($purchaseList as $purchase) {
				// Get initial_order from purchase
				$initialOrder = is_object($purchase) 
					? ($purchase->initial_order ?? $purchase->attributes['initial_order'] ?? null)
					: ($purchase['initial_order'] ?? null);

				if ($initialOrder) {
					$amount = is_object($initialOrder)
						? ($initialOrder->total_amount ?? $initialOrder->amount_due ?? 0)
						: ($initialOrder['total_amount'] ?? $initialOrder['amount_due'] ?? 0);
					
					// SureCart stores amounts in cents
					$total += is_numeric($amount) ? ($amount / 100) : 0;
				}
			}

			return (float) $total;
		} catch (\Exception $e) {
			return 0.0;
		}
	}

	/**
	 * Get order count for customer in SureCart
	 */
	private function getCustomerOrderCount(int $userId, array $context): int
	{
		try {
			$user = call_user_func('get_userdata', $userId);
			if (!$user) {
				return 0;
			}

			$customerEmail = $user->user_email;
			$customerId = $context['customer_id'] ?? '';

			// Try to find customer by ID or email
			$customer = null;
			if (!empty($customerId)) {
				$customer = \SureCart\Models\Customer::find($customerId);
			}

			if (!$customer && !empty($customerEmail)) {
				$customers = \SureCart\Models\Customer::where(['email' => $customerEmail])->get();
				if (!is_wp_error($customers) && !empty($customers)) {
					$customer = is_array($customers) ? ($customers[0] ?? null) : ($customers->data[0] ?? null);
				}
			}

			if (!$customer) {
				return 0;
			}

			$customerId = is_object($customer) ? ($customer->id ?? '') : ($customer['id'] ?? '');
			if (empty($customerId)) {
				return 0;
			}

			// Get all purchases for this customer
			$purchases = \SureCart\Models\Purchase::where(['customer_id' => $customerId])->get();
			
			if (is_wp_error($purchases)) {
				return 0;
			}

			$purchaseList = is_array($purchases) ? $purchases : ($purchases->data ?? []);
			return count($purchaseList);
		} catch (\Exception $e) {
			return 0;
		}
	}

	/**
	 * Get last order status for customer
	 */
	private function getLastOrderStatus(int $userId, array $context): ?string
	{
		try {
			$user = call_user_func('get_userdata', $userId);
			if (!$user) {
				return null;
			}

			$customerEmail = $user->user_email;
			$customerId = $context['customer_id'] ?? '';

			// Try to find customer by ID or email
			$customer = null;
			if (!empty($customerId)) {
				$customer = \SureCart\Models\Customer::find($customerId);
			}

			if (!$customer && !empty($customerEmail)) {
				$customers = \SureCart\Models\Customer::where(['email' => $customerEmail])->get();
				if (!is_wp_error($customers) && !empty($customers)) {
					$customer = is_array($customers) ? ($customers[0] ?? null) : ($customers->data[0] ?? null);
				}
			}

			if (!$customer) {
				return null;
			}

			$customerId = is_object($customer) ? ($customer->id ?? '') : ($customer['id'] ?? '');
			if (empty($customerId)) {
				return null;
			}

			// Get last purchase
			$purchases = \SureCart\Models\Purchase::where([
				'customer_id' => $customerId,
				'limit' => 1,
			])->get();

			if (is_wp_error($purchases) || empty($purchases)) {
				return null;
			}

			$purchaseList = is_array($purchases) ? $purchases : ($purchases->data ?? []);
			$purchase = $purchaseList[0] ?? null;

			if (!$purchase) {
				return null;
			}

			$status = is_object($purchase) 
				? ($purchase->status ?? null)
				: ($purchase['status'] ?? null);

			return $status ? (string) $status : null;
		} catch (\Exception $e) {
			return null;
		}
	}

	public function evaluateRule($maybe, array $rule, int $userId, array $context)
	{
		if ($maybe !== null) {
			return $maybe;
		}

		if (!class_exists('\SureCart\Models\Purchase') && !function_exists('surecart')) {
			return null;
		}

		$field = $rule['field'] ?? '';
		$operator = $rule['operator'] ?? '==';
		$value = $rule['value'] ?? null;

		// Only handle specific fields that need custom evaluation logic
		// Other sc_* fields (like sc_total_spent) are handled via getFieldValue + standard comparison
		$handledFields = ['sc_has_purchased_product'];
		if (!in_array($field, $handledFields, true)) {
			return null;
		}

		$user = call_user_func('get_userdata', $userId);
		if (!$user) {
			return false;
		}

		// Handle sc_has_purchased_product
		if ($field === 'sc_has_purchased_product') {
			$productIds = is_array($value) ? $value : [$value];
			$productIds = array_filter(array_map('strval', $productIds));
			if (empty($productIds)) {
				return false;
			}

			$customerEmail = $user->user_email;
			$customerId = $context['customer_id'] ?? '';

			// Try to find customer
			$customer = null;
			if (!empty($customerId)) {
				$customer = \SureCart\Models\Customer::find($customerId);
			}

			if (!$customer && !empty($customerEmail)) {
				$customers = \SureCart\Models\Customer::where(['email' => $customerEmail])->get();
				if (!is_wp_error($customers) && !empty($customers)) {
					$customer = is_array($customers) ? ($customers[0] ?? null) : ($customers->data[0] ?? null);
				}
			}

			if (!$customer) {
				return match ($operator) {
					'==', 'equals', 'is' => false,
					'!=', 'not_equals', 'is_not' => true,
					default => false,
				};
			}

			$customerId = is_object($customer) ? ($customer->id ?? '') : ($customer['id'] ?? '');
			if (empty($customerId)) {
				return false;
			}

			// Get all purchases for this customer
			$purchases = \SureCart\Models\Purchase::where(['customer_id' => $customerId])->get();
			
			if (is_wp_error($purchases) || empty($purchases)) {
				return match ($operator) {
					'==', 'equals', 'is' => false,
					'!=', 'not_equals', 'is_not' => true,
					default => false,
				};
			}

			$purchaseList = is_array($purchases) ? $purchases : ($purchases->data ?? []);
			$hasPurchasedAny = false;

			foreach ($purchaseList as $purchase) {
				// Get line items or purchased products
				$lineItems = is_object($purchase)
					? ($purchase->line_items ?? $purchase->attributes['line_items'] ?? [])
					: ($purchase['line_items'] ?? []);

				// Also check initial_order line_items
				$initialOrder = is_object($purchase)
					? ($purchase->initial_order ?? $purchase->attributes['initial_order'] ?? null)
					: ($purchase['initial_order'] ?? null);

				if ($initialOrder) {
					$orderLineItems = is_object($initialOrder)
						? ($initialOrder->line_items ?? [])
						: ($initialOrder['line_items'] ?? []);
					
					if (!empty($orderLineItems)) {
						$lineItems = array_merge($lineItems, $orderLineItems);
					}
				}

				foreach ($lineItems as $item) {
					$itemData = is_object($item) ? (array) $item : $item;
					$productId = $itemData['product_id'] ?? $itemData['product'] ?? '';
					
					if (is_object($productId)) {
						$productId = $productId->id ?? '';
					} elseif (is_array($productId)) {
						$productId = $productId['id'] ?? '';
					}

					$productId = (string) $productId;
					if (!empty($productId) && in_array($productId, $productIds, true)) {
						$hasPurchasedAny = true;
						break 2; // Break both loops
					}
				}
			}

			// Interpret with boolean semantics
			return match ($operator) {
				'==', 'equals', 'is' => (bool) $hasPurchasedAny === (bool) (is_bool($value) ? $value : true),
				'!=', 'not_equals', 'is_not' => (bool) $hasPurchasedAny !== (bool) (is_bool($value) ? $value : true),
				default => (bool) $hasPurchasedAny,
			};
		}

		return null;
	}
}

