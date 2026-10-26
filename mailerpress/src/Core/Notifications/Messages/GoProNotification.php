<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\ExternalLinks;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Invites free users to upgrade to MailerPress Pro.
 * Once dismissed, it won't show again.
 */
class GoProNotification extends AbstractNotificationMessage
{
	protected string $type = 'neutral';
	protected ?int $duration = null;
	protected bool $dismissible = true;

	public function getMessage(): string
	{
		return __( 'Go Pro: unlock premium tools to grow your audience and automate your email marketing.', 'mailerpress' );
	}

	public function shouldDisplay(): bool
	{
		if ( ! $this->userHasCapability() || file_exists( WP_PLUGIN_DIR . '/mailerpress-pro/mailerpress-pro.php' ) ) {
			return false;
		}

		// Respect the dismissal of the former sidebar notice.
		$user_preferences = get_user_meta( get_current_user_id(), 'mailerpress_preferences', true );

		return ! ( is_array( $user_preferences ) && ! empty( $user_preferences['dismissProNotice'] ) );
	}

	public function getAction(): ?array
	{
		return [
			'label'  => __( 'Upgrade', 'mailerpress' ),
			'url'    => ExternalLinks::get( 'pricing' ),
			'target' => '_blank',
		];
	}
}
