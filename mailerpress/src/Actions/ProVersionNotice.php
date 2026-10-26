<?php

declare(strict_types=1);

namespace MailerPress\Actions;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;

/**
 * Warns administrators when the installed MailerPress Pro is older than the version this plugin requires.
 *
 * MailerPress updates from WordPress.org, while MailerPress Pro updates separately. An older Pro
 * can miss hooks and data that this version relies on, so it is asked to be updated.
 */
class ProVersionNotice
{
	const MIN_PRO_VERSION = '2.2';

	#[Action('admin_notices')]
	public function render(): void
	{
		$promotion = $this->getPromotion();

		if ( null === $promotion ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html( $promotion['title'] ); ?></strong>
			</p>
			<p>
				<?php echo esc_html( $promotion['message'] ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $promotion['action']['url'] ); ?>">
					<?php echo esc_html( $promotion['action']['label'] ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	#[Filter('mailerpress_promotions')]
	public function addPromotion( array $promotions ): array
	{
		$promotion = $this->getPromotion();

		if ( null !== $promotion ) {
			$promotions[] = $promotion;
		}

		return $promotions;
	}

	private function getPromotion(): ?array
	{
		if ( ! current_user_can( 'update_plugins' ) || ! defined( 'MAILERPRESS_PRO_VERSION' ) ) {
			return null;
		}

		$proVersion = (string) MAILERPRESS_PRO_VERSION;

		// Keep the development marker split so release packaging cannot replace it.
		if ( '{{' . 'PLUGIN_VERSION' . '}}' === $proVersion || ! version_compare( $proVersion, self::MIN_PRO_VERSION, '<' ) ) {
			return null;
		}

		return [
			// A new minimum version must not inherit a previous warning's dismissal.
			'id'          => 'core-pro-update-' . str_replace( '.', '-', self::MIN_PRO_VERSION ),
			'placements'  => [ 'app.top' ],
			'type'        => 'warning',
			'priority'    => PHP_INT_MAX,
			'dismissible' => true,
			'title'       => __( 'Update MailerPress Pro', 'mailerpress' ),
			'message'     => sprintf(
				/* translators: 1: installed MailerPress Pro version, 2: minimum required MailerPress Pro version */
				__( 'MailerPress Pro %1$s is not compatible with this version of MailerPress. Some features, such as premium templates and WordPress emails, will not work until you update MailerPress Pro to version %2$s or later.', 'mailerpress' ),
				$proVersion,
				self::MIN_PRO_VERSION
			),
			'action'      => [
				'label' => __( 'Go to Plugins', 'mailerpress' ),
				'url'   => self_admin_url( 'plugins.php' ),
			],
		];
	}
}
