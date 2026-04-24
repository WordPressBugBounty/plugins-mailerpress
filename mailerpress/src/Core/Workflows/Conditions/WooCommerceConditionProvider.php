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

class WooCommerceConditionProvider
{
	public function __construct()
	{
		// Register only if WooCommerce is present
		call_user_func('add_filter', 'mailerpress/condition/get_field_value', [$this, 'getFieldValue'], 10, 4);
		call_user_func('add_filter', 'mailerpress/condition/evaluate_rule', [$this, 'evaluateRule'], 10, 4);
	}

	public function getFieldValue($provided, string $field, int $userId, array $context)
	{
		if (!function_exists('wc_get_customer_total_spent')) {
			return $provided;
		}

		if (!str_starts_with($field, 'wc_') && !str_starts_with($field, 'wc.')) {
			return $provided;
		}

		switch ($field) {
			case 'wc_total_spent':
				return (float) \call_user_func('wc_get_customer_total_spent', $userId);

			case 'wc_order_count':
				return (int) \call_user_func('wc_get_customer_order_count', $userId);

			case 'wc_last_order_status':
				if (!function_exists('wc_get_orders')) {
					return null;
				}
				$orders = \call_user_func('wc_get_orders', [
					'customer_id' => $userId,
					'limit' => 1,
					'orderby' => 'date',
					'order' => 'DESC',
				]);
				if (!empty($orders)) {
					$order = $orders[0];
					return method_exists($order, 'get_status') ? $order->get_status() : null;
				}
				return null;

			case 'wc_order_created':
				// Check if an order was created after the cart was updated
				// This is useful for abandoned cart recovery workflows
				// We check if there's an order_id in context OR if a recent order exists
				$orderId = $context['order_id'] ?? null;
				if (!empty($orderId) && $orderId > 0) {
					return true; // Return boolean true
				}

				// If no order_id in context, check if a recent order exists
				// This handles the case where the order was created after the cart trigger fired
				if (!function_exists('wc_get_orders')) {
					return false;
				}

				// Get cart updated timestamp from context
				$cartUpdatedAt = $context['cart_updated_at'] ?? null;
				$customerEmail = $context['customer_email'] ?? null;
				$customerId = $context['customer_id'] ?? 0;

				// Build query to find orders created after cart update
				$query = [
					'limit' => 1,
					'orderby' => 'date',
					'order' => 'DESC',
				];

				if ($customerId > 0) {
					$query['customer_id'] = $customerId;
				} elseif (!empty($customerEmail)) {
					$query['billing_email'] = $customerEmail;
				} else {
					return false;
				}

				// If we have cart_updated_at, only check orders created after that
				if ($cartUpdatedAt) {
					try {
						$cartTimestamp = strtotime($cartUpdatedAt);
						if ($cartTimestamp) {
							$query['date_created'] = '>=' . $cartTimestamp;
						}
					} catch (\Exception $e) {
						// If date parsing fails, just check for recent orders (last 24 hours)
						$query['date_created'] = '>=' . (time() - 86400);
					}
				} else {
					// If no cart timestamp, check for orders in last 24 hours
					$query['date_created'] = '>=' . (time() - 86400);
				}

				$orders = \call_user_func('wc_get_orders', $query);
				return !empty($orders);
		}

		return $provided;
	}

	public function evaluateRule($maybe, array $rule, int $userId, array $context)
	{
		if ($maybe !== null) {
			return $maybe;
		}

		if (!function_exists('wc_customer_bought_product')) {
			return null;
		}

		$field = $rule['field'] ?? '';
		$operator = $rule['operator'] ?? '==';
		$value = $rule['value'] ?? null;

		$user = call_user_func('get_userdata', $userId);
		if (!$user) {
			return false;
		}

		// Handle wc_has_purchased_product
		if ($field === 'wc_has_purchased_product') {
			$productIds = is_array($value) ? $value : [$value];
			$productIds = array_filter(array_map('intval', $productIds));
			if (empty($productIds)) {
				return false;
			}

			$hasPurchasedAny = false;
			foreach ($productIds as $pid) {
				if (\call_user_func('wc_customer_bought_product', $user->user_email, $userId, $pid)) {
					$hasPurchasedAny = true;
					break;
				}
			}

			// Interpret with boolean semantics
			return match ($operator) {
				'==', 'equals', 'is' => (bool) $hasPurchasedAny === (bool) (is_bool($value) ? $value : true),
				'!=', 'not_equals', 'is_not' => (bool) $hasPurchasedAny !== (bool) (is_bool($value) ? $value : true),
				default => (bool) $hasPurchasedAny,
			};
		}

		// Handle wc_purchased_in_category
		if ($field === 'wc_purchased_in_category') {
			$categoryId = is_array($value) ? (int) ($value[0] ?? 0) : (int) $value;
			if ($categoryId <= 0) {
				return false;
			}

			// Get all orders for this customer
			if (!function_exists('wc_get_orders')) {
				return match ($operator) {
					'==', 'equals', 'is' => false,
					'!=', 'not_equals', 'is_not' => true,
					default => false,
				};
			}

			$orders = \call_user_func('wc_get_orders', [
				'customer_id' => $userId,
				'status' => ['wc-completed', 'wc-processing'],
				'limit' => -1,
				'return' => 'ids',
			]);

			if (empty($orders)) {
				return match ($operator) {
					'==', 'equals', 'is' => false,
					'!=', 'not_equals', 'is_not' => true,
					default => false,
				};
			}

			// Check if any product in any order belongs to this category
			$hasPurchasedInCategory = false;
			foreach ($orders as $orderId) {
				$order = \call_user_func('wc_get_order', $orderId);
				if (!$order) {
					continue;
				}

				foreach ($order->get_items() as $item) {
					$productId = $item->get_product_id();
					$productCategories = \call_user_func('wp_get_post_terms', $productId, 'product_cat', ['fields' => 'ids']);

					if (!is_wp_error($productCategories) && in_array($categoryId, $productCategories, true)) {
						$hasPurchasedInCategory = true;
						break 2; // Break both loops
					}
				}
			}

			return match ($operator) {
				'==', 'equals', 'is' => (bool) $hasPurchasedInCategory === (bool) (is_bool($value) ? $value : true),
				'!=', 'not_equals', 'is_not' => (bool) $hasPurchasedInCategory !== (bool) (is_bool($value) ? $value : true),
				default => (bool) $hasPurchasedInCategory,
			};
		}

		// Handle wc_has_reviewed_order
		// Check if customer has left a review for products in the current order
		if ($field === 'wc_has_reviewed_order') {
			global $wpdb;

			// Get order_id from context
			$orderId = $context['order_id'] ?? null;

			if (empty($orderId) || $orderId <= 0) {
				return match ($operator) {
					'==', 'equals', 'is' => false,
					'!=', 'not_equals', 'is_not' => true,
					default => false,
				};
			}

			// Get order and products
			if (!function_exists('wc_get_order')) {
				return match ($operator) {
					'==', 'equals', 'is' => false,
					'!=', 'not_equals', 'is_not' => true,
					default => false,
				};
			}

			$order = \call_user_func('wc_get_order', $orderId);
			if (!$order) {
				return match ($operator) {
					'==', 'equals', 'is' => false,
					'!=', 'not_equals', 'is_not' => true,
					default => false,
				};
			}

			// Get product IDs from order
			$productIds = [];
			foreach ($order->get_items() as $item) {
				$productId = $item->get_product_id();
				if ($productId > 0) {
					$productIds[] = $productId;
				}
			}

			if (empty($productIds)) {
				return match ($operator) {
					'==', 'equals', 'is' => false,
					'!=', 'not_equals', 'is_not' => true,
					default => false,
				};
			}

			// Get customer email and user ID
			$customerEmail = $order->get_billing_email();
			$customerUserId = $order->get_customer_id();

			// Check if customer has left a review for any product in this order
			// Include both approved and pending reviews (comment_approved = 1 OR comment_approved = 0)
			// This ensures we catch reviews even if they're pending moderation
			$commentsTable = $wpdb->prefix . 'comments';
			$postsTable = $wpdb->prefix . 'posts';

			// Prepare product IDs for IN clause (sanitized)
			$productIdsInt = array_map('intval', $productIds);
			$productIdsPlaceholder = implode(',', array_fill(0, count($productIdsInt), '%d'));

			// Build query with proper placeholders
			$query = $wpdb->prepare(
				"
				SELECT COUNT(*) 
				FROM {$commentsTable} c
				INNER JOIN {$postsTable} p ON c.comment_post_ID = p.ID
				WHERE p.post_type = 'product'
				AND p.ID IN ({$productIdsPlaceholder})
				AND c.comment_type = 'review'
				AND c.comment_parent = 0
				AND (c.comment_approved = '1' OR c.comment_approved = '0')
				AND (c.user_id = %d OR c.comment_author_email = %s)
				",
				array_merge($productIdsInt, [$customerUserId ?: 0, $customerEmail])
			);

			$reviewCount = (int) $wpdb->get_var($query);
			$hasReviewed = $reviewCount > 0;

			// Handle the value comparison
			$expectedValue = is_bool($value) ? $value : ($value === 'true' || $value === '1' || $value === 1 || $value === true);

			$result = match ($operator) {
				'==', 'equals', 'is' => (bool) $hasReviewed === (bool) $expectedValue,
				'!=', 'not_equals', 'is_not' => (bool) $hasReviewed !== (bool) $expectedValue,
				default => (bool) $hasReviewed,
			};

			return $result;
		}

		return null;
	}
}
