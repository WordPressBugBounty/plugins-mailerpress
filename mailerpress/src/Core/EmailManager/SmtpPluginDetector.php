<?php
/**
 * SMTP plugin detection.
 *
 * @package MailerPress
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName -- Follow the existing PSR-4 naming convention.

declare(strict_types=1);

namespace MailerPress\Core\EmailManager;

\defined( 'ABSPATH' ) || exit;

/**
 * Detects an SMTP plugin that takes over wp_mail(). Used for display only.
 */
class SmtpPluginDetector {

	private const KNOWN_PLUGINS = array(
		'wp-mail-smtp'     => 'WP Mail SMTP',
		'wp-mail-smtp-pro' => 'WP Mail SMTP Pro',
		'fluent-smtp'      => 'FluentSMTP',
		'post-smtp'        => 'Post SMTP',
		'gravitysmtp'      => 'Gravity SMTP',
		'easy-wp-smtp'     => 'Easy WP SMTP',
		'smtp2go'          => 'SMTP2GO',
		'mailin'           => 'Brevo',
		'suremails'        => 'SureMail',
		'wp-smtp'          => 'Solid Mail',
		'site-mailer'      => 'Site Mailer',
	);

	/**
	 * Find the active SMTP plugin.
	 *
	 * @return string|null Plugin name, an empty string for an unknown plugin, or null when none is detected.
	 */
	public static function detect(): ?string {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $plugins as $plugin ) {
			$slug = explode( '/', (string) $plugin )[0];
			if ( isset( self::KNOWN_PLUGINS[ $slug ] ) ) {
				return self::KNOWN_PLUGINS[ $slug ];
			}
		}

		return self::overridesWpMail() ? '' : null;
	}

	/**
	 * Check the usual ways an SMTP plugin takes over wp_mail().
	 *
	 * @return bool
	 */
	private static function overridesWpMail(): bool {
		global $phpmailer;

		if ( has_filter( 'pre_wp_mail' ) || has_action( 'phpmailer_init' ) ) {
			return true;
		}

		if ( \is_object( $phpmailer ) && ! \in_array( \get_class( $phpmailer ), array( 'PHPMailer\\PHPMailer\\PHPMailer', 'WP_PHPMailer' ), true ) ) {
			return true;
		}

		if ( ! function_exists( 'wp_mail' ) ) {
			return false;
		}

		$file = ( new \ReflectionFunction( 'wp_mail' ) )->getFileName();

		return false !== $file && wp_normalize_path( $file ) !== wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' );
	}
}
