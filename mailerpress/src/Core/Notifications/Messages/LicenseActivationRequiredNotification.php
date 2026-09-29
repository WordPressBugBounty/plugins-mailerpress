<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Capabilities;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

class LicenseActivationRequiredNotification extends AbstractNotificationMessage
{
	protected string $type = 'warning';
	protected ?int $duration = null;
	protected bool $dismissible = true;

	public function getMessage(): string
	{
		return __( 'Activate your license to unlock all MailerPress Pro features and receive automatic updates.', 'mailerpress' );
	}

	public function getRequiredCapability(): string
	{
		return Capabilities::MANAGE_SETTINGS;
	}

	public function shouldDisplay(): bool
	{
		if ( ! $this->userHasCapability() || ! defined( 'MAILERPRESS_PRO_VERSION' ) ) {
			return false;
		}

		if ( get_option( 'mailerpress_license_activated', false ) ) {
			return false;
		}

		$user_preferences = get_user_meta( get_current_user_id(), 'mailerpress_preferences', true );
		if ( is_array( $user_preferences ) && ! empty( $user_preferences['dismissLicenseNotice'] ) ) {
			return false;
		}

		if ( defined( 'MAILERPRESS_SHOW_NOTICE_LICENCE_ACTIVATION' ) && true !== constant( 'MAILERPRESS_SHOW_NOTICE_LICENCE_ACTIVATION' ) ) {
			return false;
		}

		$white_label = apply_filters(
			'mailerpress_white_label_options',
			[
				'white_label_active' => false,
			]
		);

		return empty( $white_label['white_label_active'] );
	}

	public function getAction(): ?array
	{
		return [
			'label' => __( 'Activate License', 'mailerpress' ),
			'url'   => admin_url( 'admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings&activeView=licence' ),
		];
	}
}
