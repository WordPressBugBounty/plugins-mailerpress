<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Actions\DatabaseUpdateNotice;
use MailerPress\Core\Capabilities;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

class DatabaseUpdateRequiredNotification extends AbstractNotificationMessage
{
	protected string $type = 'warning';
	protected ?int $duration = null;
	protected bool $dismissible = false;
	protected bool $persistent = true;

	public function getMessage(): string
	{
		return __( 'Your database needs to be updated for optimal performance with this version of MailerPress. Please run the database repair tool.', 'mailerpress' );
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

		if ( get_option( DatabaseUpdateNotice::OPTION_DONE ) ) {
			return false;
		}

		return (bool) get_option( DatabaseUpdateNotice::OPTION_KEY );
	}

	public function getAction(): ?array
	{
		return [
			'label' => __( 'Run Database Repair', 'mailerpress' ),
			'url'   => admin_url( 'admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings&activeView=database-repair' ),
		];
	}
}
