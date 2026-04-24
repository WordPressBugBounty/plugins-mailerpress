<?php

namespace MailerPress\Core\Workflows\Conditions;

use function add_filter;
use function get_userdata;
use function get_user_meta;
use function str_starts_with;

class UserConditionProvider
{
	public function __construct()
	{
		\add_filter('mailerpress/condition/get_field_value', [$this, 'getFieldValue'], 10, 4);
	}

	public function getFieldValue($provided, string $field, int $userId, array $context)
	{
		// Extend with convenient aliases like user.role, user.meta:xyz
		if (!\str_starts_with($field, 'user.') && !\str_starts_with($field, 'user_')) {
			return $provided;
		}

		$user = \get_userdata($userId);
		if (!$user) {
			return null;
		}

		if ($field === 'user.role' || $field === 'user_role') {
			return $user->roles[0] ?? '';
		}

		if ($field === 'user.email' || $field === 'user_email') {
			return $user->user_email;
		}

		if ($field === 'user.login' || $field === 'user_login') {
			return $user->user_login;
		}

		if (\str_starts_with($field, 'user.meta:')) {
			$metaKey = substr($field, strlen('user.meta:'));
			return \get_user_meta($userId, $metaKey, true);
		}

		return $provided;
	}
}


