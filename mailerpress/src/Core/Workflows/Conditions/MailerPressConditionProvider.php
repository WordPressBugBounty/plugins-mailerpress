<?php

namespace MailerPress\Core\Workflows\Conditions;

use MailerPress\Models\Contacts as ContactsModel;
use MailerPress\Core\Enums\Tables;
use function add_filter;
use function get_userdata;
use function str_starts_with;
use function is_array;
use function array_filter;
use function array_map;
use function implode;
use function count;
use function array_merge;
use function is_bool;
use function strpos;
use function substr;

class MailerPressConditionProvider
{
	public function __construct()
	{
		\add_filter('mailerpress/condition/get_field_value', [$this, 'getFieldValue'], 10, 4);
		\add_filter('mailerpress/condition/evaluate_rule', [$this, 'evaluateRule'], 10, 4);
	}

	private function getContactByUserId(int $userId)
	{
		$model = new ContactsModel();

		// First, try to get contact directly by contact_id (userId might be a contact_id)
		$contact = $model->get($userId);
		if ($contact) {
			return $contact;
		}

		// Fallback: try to get WordPress user and find contact by email
		$user = \get_userdata($userId);
		if (!$user) {
			return null;
		}

		return $model->getContactByEmail($user->user_email);
	}

	public function getFieldValue($provided, string $field, int $userId, array $context)
	{
		if (!\str_starts_with($field, 'mp_') && !\str_starts_with($field, 'mp.')) {
			return $provided;
		}

		$contact = $this->getContactByUserId($userId);
		if (!$contact) {
			return null;
		}

		switch ($field) {
			case 'mp_subscription_status':
				return $contact->subscription_status ?? null;
		}

		// Handle MailerPress custom fields (format: mp_custom_field:field_key)
		if (\str_starts_with($field, 'mp_custom_field:')) {
			$fieldKey = \substr($field, \strlen('mp_custom_field:'));
			if (empty($fieldKey)) {
				return null;
			}

			global $wpdb;
			$customFieldsTable = $wpdb->prefix . Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS;
			$contactId = (int) $contact->contact_id;

			$value = $wpdb->get_var($wpdb->prepare(
				"SELECT field_value FROM {$customFieldsTable} WHERE contact_id = %d AND field_key = %s",
				$contactId,
				$fieldKey
			));

			if ($value !== null) {
				// Unserialize if needed
				$value = is_serialized($value)
					? unserialize($value, ['allowed_classes' => false])
					: $value;
			}

			return $value;
		}

		return $provided;
	}

	public function evaluateRule($maybe, array $rule, int $userId, array $context)
	{
		if ($maybe !== null) {
			return $maybe;
		}

		$field = $rule['field'] ?? '';
		$operator = $rule['operator'] ?? '==';
		$value = $rule['value'] ?? null;

		if (!in_array($field, ['mp_has_tag', 'mp_in_list', 'mp_email_opened', 'mp_email_clicked'], true)) {
			return null;
		}

		global $wpdb;
		$contact = $this->getContactByUserId($userId);

		// For mp_email_opened and mp_email_clicked, we allow contact_id = 0 for non-subscribers
		// The tracking uses user_id from the workflow job instead
		// For mp_has_tag and mp_in_list, we need a real contact

		if ($field === 'mp_has_tag') {
			if (!$contact) {
				// Tags require a MailerPress contact
				return false;
			}
			$contactId = (int) $contact->contact_id;
			$tagIds = \is_array($value) ? $value : [$value];
			$tagIds = \array_filter(\array_map('intval', $tagIds));
			if (empty($tagIds)) {
				return false;
			}
			$table = $wpdb->prefix . Tables::CONTACT_TAGS;
			$placeholders = \implode(',', \array_fill(0, \count($tagIds), '%d'));

			// Use direct query without cache to ensure we get the latest data
			// This is important when checking tags after a remove_tag action in the same workflow
			$wpdb->flush();
			$sql = "SELECT COUNT(*) FROM {$table} WHERE contact_id = %d AND tag_id IN ($placeholders)";
			$count = (int) $wpdb->get_var($wpdb->prepare($sql, \array_merge([$contactId], $tagIds)));
			$hasAny = $count > 0;

			return match ($operator) {
				'==', 'equals', 'is' => $hasAny, // Contact has at least one of the specified tags
				'!=', 'not_equals', 'is_not' => !$hasAny, // Contact has none of the specified tags
				'in' => $hasAny, // Contact has at least one of the specified tags
				'not_in' => !$hasAny, // Contact has none of the specified tags
				default => $hasAny,
			};
		}

		if ($field === 'mp_in_list') {
			if (!$contact) {
				// Lists require a MailerPress contact
				return false;
			}
			$contactId = (int) $contact->contact_id;
			$listIds = \is_array($value) ? $value : [$value];
			$listIds = \array_filter(\array_map('intval', $listIds));
			if (empty($listIds)) {
				return false;
			}
			$table = $wpdb->prefix . Tables::MAILERPRESS_CONTACT_LIST;
			$placeholders = \implode(',', \array_fill(0, \count($listIds), '%d'));

			// Use direct query without cache to ensure we get the latest data
			// This is important when checking lists after a remove_from_list action in the same workflow
			$wpdb->flush();
			$sql = "SELECT COUNT(*) FROM {$table} WHERE contact_id = %d AND list_id IN ($placeholders)";
			$count = (int) $wpdb->get_var($wpdb->prepare($sql, \array_merge([$contactId], $listIds)));
			$inAny = $count > 0;
			return match ($operator) {
				'==', 'equals', 'is' => $inAny, // Contact is in at least one of the specified lists
				'!=', 'not_equals', 'is_not' => !$inAny, // Contact is not in any of the specified lists
				'in' => $inAny, // Contact is in at least one of the specified lists
				'not_in' => !$inAny, // Contact is not in any of the specified lists
				default => $inAny,
			};
		}

		if ($field === 'mp_email_opened') {
			$campaignId = \is_array($value) ? (int) ($value[0] ?? 0) : (int) $value;
			if ($campaignId <= 0) {
				return false;
			}

			// For workflow emails, if user is not a subscriber, we use user_id as contact_id
			// This allows tracking even for non-subscribers
			// We prioritize contact_id from context (from email sent log) as it's the exact value used during tracking
			if (isset($context['contact_id'])) {
				// Use contact_id from email sent log - this is the exact value used when tracking the email open
				$contactId = (int) $context['contact_id'];
			} else {
				// Fallback: calculate from contact or user_id
				$contextUserId = $context['user_id'] ?? $userId;
				$contactId = $contact ? (int) $contact->contact_id : $contextUserId;
			}

			// Check if this is being evaluated in a workflow context
			// If we have job_id and step_id in context, we need to verify that the email
			// was opened AFTER it was sent in THIS specific workflow instance
			$jobId = $context['job_id'] ?? null;
			$stepId = $context['step_id'] ?? null;
			$emailSentAt = $context['email_sent_at'] ?? null;

			// If we're in a workflow context with email_sent_at, verify the email was opened AFTER sending
			if ($jobId && $stepId && $emailSentAt) {
				// Get the timestamp when the email was last opened/updated for this campaign
				// For non-subscribers, contact_id = 0 in contact_stats
				$statsTable = $wpdb->prefix . Tables::MAILERPRESS_CONTACT_STATS;
				$stats = $wpdb->get_row($wpdb->prepare(
					"SELECT opened, updated_at FROM {$statsTable} WHERE contact_id = %d AND campaign_id = %d",
					$contactId,
					$campaignId
				));

				if (!$stats || (int)$stats->opened === 0) {
					return match ($operator) {
						'==', 'equals', 'is' => false,
						'!=', 'not_equals', 'is_not' => true,
						default => false,
					};
				}

				// Check if updated_at is after email_sent_at (email was opened after sending)
				$emailSentTimestamp = strtotime($emailSentAt);
				$updatedTimestamp = $stats->updated_at ? strtotime($stats->updated_at) : 0;

				// Email was opened after it was sent in this workflow
				// Use >= instead of > to handle edge cases where they might be the same second
				$openedAfterSending = $updatedTimestamp >= $emailSentTimestamp;

				if (!$openedAfterSending) {
					return match ($operator) {
						'==', 'equals', 'is' => false,
						'!=', 'not_equals', 'is_not' => true,
						default => false,
					};
				}

				return match ($operator) {
					'==', 'equals', 'is' => true,
					'!=', 'not_equals', 'is_not' => false,
					default => true,
				};
			}

			// Fallback: Original behavior - just check if email was opened (for backward compatibility)
			// For workflow emails, if user is not a subscriber, we use user_id as contact_id
			// We prioritize contact_id from context (from email sent log) as it's the exact value used during tracking
			if (isset($context['contact_id'])) {
				// Use contact_id from email sent log
				$contactId = (int) $context['contact_id'];
			} else {
				// Fallback: calculate from contact or user_id
				$contextUserId = $context['user_id'] ?? $userId;
				$contactId = $contact ? (int) $contact->contact_id : $contextUserId;
			}
			$statsTable = $wpdb->prefix . Tables::MAILERPRESS_CONTACT_STATS;
			$opened = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT opened FROM {$statsTable} WHERE contact_id = %d AND campaign_id = %d",
				$contactId,
				$campaignId
			));
			$hasOpened = $opened > 0;

			$result = match ($operator) {
				'==', 'equals', 'is' => (bool) $hasOpened === (bool) (\is_bool($value) ? $value : true),
				'!=', 'not_equals', 'is_not' => (bool) $hasOpened !== (bool) (\is_bool($value) ? $value : true),
				default => $hasOpened,
			};

			return $result;
		}

		if ($field === 'mp_email_clicked') {
			$campaignId = \is_array($value) ? (int) ($value[0] ?? 0) : (int) $value;
			if ($campaignId <= 0) {
				return false;
			}
			// For workflow emails, if user is not a subscriber, we use user_id as contact_id
			// We prioritize contact_id from context (from email sent log) as it's the exact value used during tracking
			if (isset($context['contact_id'])) {
				// Use contact_id from email sent log
				$contactId = (int) $context['contact_id'];
			} else {
				// Fallback: calculate from contact or user_id
				$contextUserId = $context['user_id'] ?? $userId;
				$contactId = $contact ? (int) $contact->contact_id : $contextUserId;
			}
			// Vérifier si le contact a cliqué sur un lien dans cet email via la table de stats
			$table = $wpdb->prefix . Tables::MAILERPRESS_CONTACT_STATS;
			$clicked = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT clicked FROM {$table} WHERE contact_id = %d AND campaign_id = %d",
				$contactId,
				$campaignId
			));
			$hasClicked = $clicked > 0;
			return match ($operator) {
				'==', 'equals', 'is' => (bool) $hasClicked === (bool) (\is_bool($value) ? $value : true),
				'!=', 'not_equals', 'is_not' => (bool) $hasClicked !== (bool) (\is_bool($value) ? $value : true),
				default => $hasClicked,
			};
		}

		return null;
	}
}
