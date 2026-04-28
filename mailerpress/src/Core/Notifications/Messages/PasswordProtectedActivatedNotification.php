<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Capabilities;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

class PasswordProtectedActivatedNotification extends AbstractNotificationMessage
{
	protected string $type = 'warning';
	protected ?int $duration = null;
	protected bool $dismissible = false;
	protected bool $persistent = true;

	public function getMessage(): string
	{
		return __('Password Protected plugin detected with REST API access disabled. MailerPress requires REST API access to function properly. Please enable the "Allow REST API" option in Password Protected settings.', 'mailerpress');
	}

	public function getRequiredCapability(): string
	{
		return Capabilities::MANAGE_SETTINGS;
	}

	public function shouldDisplay(): bool
	{
		if ( ! $this->userHasCapability() ) {
			return false;
		}

		if ( ! in_array( 'password-protected/password-protected.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			return false;
		}


		$rest_allowed = get_option( 'password_protected_rest', 0 );

		return empty( $rest_allowed );
	}

	public function getAction(): ?array
	{
		return [
			'label' => __('Configure', 'mailerpress'),
			'url' => admin_url('options-general.php?page=password-protected'),
		];
	}
}
