<?php

namespace MailerPress\Core\Workflows\Conditions;

use function add_filter;
use function function_exists;
use function str_starts_with;
use function method_exists;
use function call_user_func;
use function is_array;
use function array_filter;
use function array_map;
use function is_bool;

class WooCommerceSubscriptionsConditionProvider
{
	public function __construct()
	{
		// Register only if WooCommerce Subscriptions is present
		if (!class_exists('WC_Subscriptions')) {
			return;
		}

		call_user_func('add_filter', 'mailerpress/condition/available_fields', [$this, 'registerFields'], 10, 1);
		call_user_func('add_filter', 'mailerpress/condition/get_field_value', [$this, 'getFieldValue'], 10, 4);
		call_user_func('add_filter', 'mailerpress/condition/evaluate_rule', [$this, 'evaluateRule'], 10, 4);
	}

	/**
	 * Register condition fields for WooCommerce Subscriptions
	 */
	public function registerFields(array $fields): array
	{
		if (!class_exists('WC_Subscriptions')) {
			return $fields;
		}

		// Get subscription statuses for options
		$statusOptions = [];
		if (function_exists('wcs_get_subscription_statuses')) {
			$statuses = call_user_func('wcs_get_subscription_statuses');
			foreach ($statuses as $statusKey => $statusLabel) {
				$cleanKey = str_replace('wc-', '', $statusKey);
				$statusOptions[] = [
					'value' => $cleanKey,
					'label' => $statusLabel,
				];
			}
		} else {
			// Fallback to default statuses
			$statusOptions = [
				['value' => 'active', 'label' => __('Active', 'mailerpress')],
				['value' => 'on-hold', 'label' => __('On Hold', 'mailerpress')],
				['value' => 'cancelled', 'label' => __('Cancelled', 'mailerpress')],
				['value' => 'expired', 'label' => __('Expired', 'mailerpress')],
				['value' => 'pending-cancel', 'label' => __('Pending Cancellation', 'mailerpress')],
			];
		}

		$fields[] = [
			'key' => 'wcs_subscription_status',
			'label' => __('Subscription Status', 'mailerpress'),
			'type' => 'string',
			'category' => 'woocommerce_subscriptions',
			'description' => __('Check the status of the WooCommerce subscription', 'mailerpress'),
			'operators' => ['==', '!=', 'in', 'not_in'],
			'valueType' => 'select',
			'valueOptions' => $statusOptions,
		];

		$fields[] = [
			'key' => 'wcs_subscription_next_payment_date',
			'label' => __('Next Payment Date', 'mailerpress'),
			'type' => 'date',
			'category' => 'woocommerce_subscriptions',
			'description' => __('Check the next payment date of the subscription', 'mailerpress'),
			'operators' => ['==', '!=', '>', '<', '>=', '<='],
			'valueType' => 'date',
		];

		$fields[] = [
			'key' => 'wcs_subscription_billing_period',
			'label' => __('Billing Period', 'mailerpress'),
			'type' => 'string',
			'category' => 'woocommerce_subscriptions',
			'description' => __('Check the billing period of the subscription (day, week, month, year)', 'mailerpress'),
			'operators' => ['==', '!=', 'in', 'not_in'],
			'valueType' => 'select',
			'valueOptions' => [
				['value' => 'day', 'label' => __('Day', 'mailerpress')],
				['value' => 'week', 'label' => __('Week', 'mailerpress')],
				['value' => 'month', 'label' => __('Month', 'mailerpress')],
				['value' => 'year', 'label' => __('Year', 'mailerpress')],
			],
		];

		$fields[] = [
			'key' => 'wcs_subscription_billing_interval',
			'label' => __('Billing Interval', 'mailerpress'),
			'type' => 'number',
			'category' => 'woocommerce_subscriptions',
			'description' => __('Check the billing interval of the subscription (e.g., 1 for monthly, 2 for bi-monthly)', 'mailerpress'),
			'operators' => ['==', '!=', '>', '<', '>=', '<='],
			'valueType' => 'number',
		];

		$fields[] = [
			'key' => 'wcs_subscription_has_trial',
			'label' => __('Has Trial Period', 'mailerpress'),
			'type' => 'boolean',
			'category' => 'woocommerce_subscriptions',
			'description' => __('Check if the subscription has a trial period', 'mailerpress'),
			'operators' => ['==', '!='],
			'valueType' => 'select',
			'valueOptions' => [
				['value' => 'true', 'label' => __('Yes', 'mailerpress')],
				['value' => 'false', 'label' => __('No', 'mailerpress')],
			],
		];

		$fields[] = [
			'key' => 'wcs_subscription_trial_end_date',
			'label' => __('Trial End Date', 'mailerpress'),
			'type' => 'date',
			'category' => 'woocommerce_subscriptions',
			'description' => __('Check the trial end date of the subscription', 'mailerpress'),
			'operators' => ['==', '!=', '>', '<', '>=', '<='],
			'valueType' => 'date',
		];

		$fields[] = [
			'key' => 'wcs_subscription_total',
			'label' => __('Subscription Total', 'mailerpress'),
			'type' => 'number',
			'category' => 'woocommerce_subscriptions',
			'description' => __('Check the total amount of the subscription', 'mailerpress'),
			'operators' => ['==', '!=', '>', '<', '>=', '<='],
			'valueType' => 'number',
		];

		$fields[] = [
			'key' => 'wcs_subscription_product_id',
			'label' => __('Subscription Product ID', 'mailerpress'),
			'type' => 'number',
			'category' => 'woocommerce_subscriptions',
			'description' => __('Check if the subscription contains a specific product', 'mailerpress'),
			'operators' => ['==', '!=', 'in', 'not_in'],
			'valueType' => 'number',
		];

		return $fields;
	}

	/**
	 * Get field value from subscription context
	 */
	public function getFieldValue($provided, string $field, int $userId, array $context)
	{
		if (!class_exists('WC_Subscriptions') || !function_exists('wcs_get_subscription')) {
			return $provided;
		}

		if (!str_starts_with($field, 'wcs_') && !str_starts_with($field, 'wcs.')) {
			return $provided;
		}

		// Get subscription from context or by user_id
		$subscription = null;
		$subscriptionId = $context['subscription_id'] ?? null;

		if ($subscriptionId) {
			$subscription = call_user_func('wcs_get_subscription', (int) $subscriptionId);
		} else {
			// Try to get active subscription for user
			if (function_exists('wcs_get_users_subscriptions')) {
				$subscriptions = call_user_func('wcs_get_users_subscriptions', $userId);
				if (!empty($subscriptions)) {
					// Get the most recent active subscription
					$subscription = reset($subscriptions);
				}
			}
		}

		if (!$subscription || !($subscription instanceof \WC_Subscription)) {
			return null;
		}

		switch ($field) {
			case 'wcs_subscription_status':
				return $subscription->get_status();

			case 'wcs_subscription_next_payment_date':
				$date = $subscription->get_date('next_payment');
				return $date ? date('Y-m-d', strtotime($date)) : null;

			case 'wcs_subscription_billing_period':
				return $subscription->get_billing_period();

			case 'wcs_subscription_billing_interval':
				return (int) $subscription->get_billing_interval();

			case 'wcs_subscription_has_trial':
				$trialEnd = $subscription->get_date('trial_end');
				return !empty($trialEnd);

			case 'wcs_subscription_trial_end_date':
				$date = $subscription->get_date('trial_end');
				return $date ? date('Y-m-d', strtotime($date)) : null;

			case 'wcs_subscription_total':
				return (float) $subscription->get_total();

			case 'wcs_subscription_product_id':
				// Return array of product IDs in the subscription
				$productIds = [];
				foreach ($subscription->get_items() as $item) {
					$productId = $item->get_product_id();
					if ($productId > 0) {
						$productIds[] = $productId;
					}
				}
				return $productIds;
		}

		return $provided;
	}

	/**
	 * Evaluate subscription-specific rules
	 */
	public function evaluateRule($maybe, array $rule, int $userId, array $context)
	{
		if ($maybe !== null) {
			return $maybe;
		}

		if (!class_exists('WC_Subscriptions')) {
			return null;
		}

		$field = $rule['field'] ?? '';
		$operator = $rule['operator'] ?? '==';
		$value = $rule['value'] ?? null;

		if (!str_starts_with($field, 'wcs_') && !str_starts_with($field, 'wcs.')) {
			return null;
		}

		// Get field value using getFieldValue
		$fieldValue = $this->getFieldValue(null, $field, $userId, $context);

		if ($fieldValue === null) {
			return match ($operator) {
				'==', 'equals', 'is' => false,
				'!=', 'not_equals', 'is_not' => true,
				'empty' => true,
				'not_empty' => false,
				default => false,
			};
		}

		// Handle wcs_subscription_product_id (array comparison)
		if ($field === 'wcs_subscription_product_id') {
			$productIds = is_array($fieldValue) ? $fieldValue : [$fieldValue];
			$productIds = array_filter(array_map('intval', $productIds));

			$targetProductIds = is_array($value) ? $value : [$value];
			$targetProductIds = array_filter(array_map('intval', $targetProductIds));

			$hasProduct = !empty(array_intersect($productIds, $targetProductIds));

			return match ($operator) {
				'==', 'equals', 'is', 'in' => $hasProduct,
				'!=', 'not_equals', 'is_not', 'not_in' => !$hasProduct,
				default => $hasProduct,
			};
		}

		// Handle date comparisons
		if (in_array($field, ['wcs_subscription_next_payment_date', 'wcs_subscription_trial_end_date'], true)) {
			$fieldTimestamp = $fieldValue ? strtotime($fieldValue) : 0;
			$valueTimestamp = $value ? strtotime($value) : 0;

			return match ($operator) {
				'==' => $fieldTimestamp === $valueTimestamp,
				'!=' => $fieldTimestamp !== $valueTimestamp,
				'>' => $fieldTimestamp > $valueTimestamp,
				'<' => $fieldTimestamp < $valueTimestamp,
				'>=' => $fieldTimestamp >= $valueTimestamp,
				'<=' => $fieldTimestamp <= $valueTimestamp,
				default => false,
			};
		}

		// Handle boolean comparisons
		if ($field === 'wcs_subscription_has_trial') {
			$fieldBool = (bool) $fieldValue;
			$valueBool = is_bool($value) ? $value : ($value === 'true' || $value === '1' || $value === 1 || $value === true);

			return match ($operator) {
				'==', 'equals', 'is' => $fieldBool === $valueBool,
				'!=', 'not_equals', 'is_not' => $fieldBool !== $valueBool,
				default => $fieldBool === $valueBool,
			};
		}

		// Handle array comparisons (for 'in' and 'not_in' operators)
		if (in_array($operator, ['in', 'not_in'], true)) {
			$valueArray = is_array($value) ? $value : [$value];
			$fieldValueArray = is_array($fieldValue) ? $fieldValue : [$fieldValue];

			$isIn = !empty(array_intersect($fieldValueArray, $valueArray));

			return match ($operator) {
				'in' => $isIn,
				'not_in' => !$isIn,
				default => false,
			};
		}

		// Default comparison (handled by ConditionEvaluator)
		return null;
	}
}

