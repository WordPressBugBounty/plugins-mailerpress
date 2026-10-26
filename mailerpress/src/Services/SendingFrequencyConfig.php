<?php
/**
 * ESP sending settings and limits.
 *
 * @package MailerPress
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName, WordPress.WP.CapitalPDangit -- Follow the existing PSR-4 service naming convention; "wordpress" is a provider key.

declare(strict_types=1);

namespace MailerPress\Services;

\defined( 'ABSPATH' ) || exit;

use MailerPress\Core\EmailManager\EmailServiceManager;

/**
 * Resolves sending settings from the shared ESP catalog.
 */
class SendingFrequencyConfig {

	public const OPTION            = 'mailerpress_frequency_sending';
	private const PROVIDER_OPTIONS = 'mailerpress_frequency_sending_by_provider';
	private const LEGACY_PROFILES  = array(
		'php_micro'   => 'wordpress_micro',
		'php_prudent' => 'wordpress_conservative',
	);

	/**
	 * Load the bundled ESP catalog: the WordPress and SMTP profiles, plus the profiles extensions add for the
	 * providers they register (e.g. MailerPress Pro) through the "mailerpress_esp_sending_configs" filter.
	 *
	 * @return array
	 */
	public static function catalog(): array {
		static $catalog = null;
		if ( null === $catalog ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled local JSON, not a remote URL.
			$catalog = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/esp-sending-configs.json' ), true );
			$catalog = is_array( $catalog ) ? $catalog : array();
			$catalog = (array) apply_filters( 'mailerpress_esp_sending_configs', $catalog );
		}
		return $catalog;
	}

	/**
	 * Get the configured primary provider.
	 *
	 * @return string
	 */
	public static function activeProvider(): string {
		// Use the WordPress pace while it replaces an unavailable primary provider.
		if ( function_exists( 'mailerpress_get_provider_class' ) ) {
			$effective = mailerpress_get_provider_class()->getEffectiveServiceKey();
			if ( null !== $effective ) {
				return $effective;
			}
		}

		$services = self::decode( get_option( 'mailerpress_email_services', array() ) );
		return EmailServiceManager::normalizeServiceKey( (string) ( $services['default_service'] ?? 'wordpress' ) );
	}

	/**
	 * Decode an option stored as an array or JSON.
	 *
	 * @param mixed $value Stored option value.
	 * @return array
	 */
	private static function decode( mixed $value ): array {
		$value = is_string( $value ) ? json_decode( $value, true ) : $value;
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Find the conservative default profile.
	 *
	 * @param string $provider ESP key.
	 * @return array
	 */
	public static function defaultProfile( string $provider ): array {
		$catalog  = self::catalog();
		$profiles = $catalog[ $provider ]['profiles'] ?? $catalog['smtp']['profiles'] ?? array();
		foreach ( $profiles as $profile ) {
			if ( ! empty( $profile['isDefault'] ) ) {
				return $profile;
			}
		}
		return $profiles[0] ?? array();
	}

	/**
	 * Resolve current settings without overwriting legacy settings.
	 *
	 * @param string|null $provider ESP key, or the primary provider.
	 * @return array
	 */
	public static function get( ?string $provider = null ): array {
		$provider = $provider ?? self::activeProvider();
		$current  = self::decode( get_option( self::OPTION, array() ) );
		if ( ! empty( $current['espKey'] ) && $provider !== $current['espKey'] ) {
			$stored  = self::decode( get_option( self::PROVIDER_OPTIONS, array() ) );
			$current = $stored[ $provider ] ?? array();
		}
		$normalized = self::normalize( $current, $provider );
		return is_wp_error( $normalized ) ? self::normalize( array(), $provider ) : $normalized;
	}

	/**
	 * Validate and save settings for their provider.
	 *
	 * @param mixed $value Incoming settings.
	 * @return array|\WP_Error
	 */
	public static function save( mixed $value ): array|\WP_Error {
		$incoming = self::decode( $value );
		if ( empty( $incoming ) || ! isset( $incoming['mode'], $incoming['espKey'] ) ) {
			return self::error();
		}
		$provider = EmailServiceManager::normalizeServiceKey( (string) ( $incoming['espKey'] ?? self::activeProvider() ) );
		$result   = self::normalize( $incoming, $provider, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$stored   = self::decode( get_option( self::PROVIDER_OPTIONS, array() ) );
		$previous = self::decode( get_option( self::OPTION, array() ) );
		if ( ! empty( $previous['espKey'] ) ) {
			$stored[ $previous['espKey'] ] = $previous;
		}
		$stored[ $provider ] = $result;
		update_option( self::PROVIDER_OPTIONS, $stored, false );
		update_option( self::OPTION, wp_json_encode( $result ), false );
		return $result;
	}

	/**
	 * Preserve the outgoing settings and restore the incoming provider.
	 *
	 * @param string $outgoing Previous ESP key.
	 * @param string $incoming New ESP key.
	 * @return void
	 */
	public static function switchProvider( string $outgoing, string $incoming ): void {
		$stored              = self::decode( get_option( self::PROVIDER_OPTIONS, array() ) );
		$stored[ $outgoing ] = self::get( $outgoing );
		update_option( self::PROVIDER_OPTIONS, $stored, false );
		$settings = self::normalize( $stored[ $incoming ] ?? array(), $incoming );
		if ( is_wp_error( $settings ) ) {
			$settings = self::normalize( array(), $incoming );
		}
		update_option( self::OPTION, wp_json_encode( $settings ), false );
	}

	/**
	 * Move settings saved for the former "php" provider to "wordpress".
	 *
	 * @return void
	 */
	public static function migrateLegacyProvider(): void {
		$current = self::decode( get_option( self::OPTION, array() ) );
		if ( 'php' === ( $current['espKey'] ?? '' ) ) {
			update_option( self::OPTION, wp_json_encode( self::fromLegacyProvider( $current ) ), false );
		}

		$stored = self::decode( get_option( self::PROVIDER_OPTIONS, array() ) );
		if ( ! isset( $stored['php'] ) ) {
			return;
		}
		if ( ! isset( $stored['wordpress'] ) && is_array( $stored['php'] ) ) {
			$stored['wordpress'] = self::fromLegacyProvider( $stored['php'] );
		}
		unset( $stored['php'] );
		update_option( self::PROVIDER_OPTIONS, $stored, false );
	}

	/**
	 * Convert "php" settings without changing the sending pace.
	 *
	 * @param array $settings Settings saved for the "php" provider.
	 * @return array
	 */
	private static function fromLegacyProvider( array $settings ): array {
		$profile = (string) ( $settings['profileId'] ?? '' );
		if ( 'profile' === ( $settings['mode'] ?? '' ) && ! isset( self::LEGACY_PROFILES[ $profile ] ) && isset( $settings['effectiveConfig'] ) ) {
			// "php_standard" has no "wordpress" equivalent: keep its pace as manual settings.
			$settings['mode']         = 'manual';
			$settings['manualConfig'] = $settings['effectiveConfig'];
		}
		$settings['profileId'] = self::LEGACY_PROFILES[ $profile ] ?? $profile;
		$result                = self::normalize( $settings, 'wordpress' );
		return is_wp_error( $result ) ? self::normalize( array(), 'wordpress' ) : $result;
	}

	/**
	 * Resolve catalog profiles or preserve a manual configuration.
	 *
	 * @param array  $value Saved or incoming settings.
	 * @param string $provider ESP key.
	 * @param bool   $strict Reject unknown profile identifiers on writes.
	 * @return array|\WP_Error
	 */
	public static function normalize( array $value, string $provider, bool $strict = false ): array|\WP_Error {
		$default = self::defaultProfile( $provider );
		if ( empty( $default ) || '' === $provider || sanitize_key( $provider ) !== $provider ) {
			return self::error();
		}
		$mode = $value['mode'] ?? ( isset( $value['settings'] ) ? 'manual' : 'auto' );
		if ( ! in_array( $mode, array( 'auto', 'profile', 'manual' ), true ) ) {
			return self::error();
		}
		$profile = $default;
		if ( 'profile' === $mode ) {
			$catalog  = self::catalog();
			$profiles = $catalog[ $provider ]['profiles'] ?? $catalog['smtp']['profiles'];
			$matches  = array_filter( $profiles, static fn( $item ) => ( $value['profileId'] ?? '' ) === $item['id'] );
			if ( empty( $matches ) && $strict ) {
				return self::error();
			}
			$profile = empty( $matches ) ? $default : reset( $matches );
		}
		$manual = $value['manualConfig'] ?? $value['effectiveConfig'] ?? $value['settings'] ?? $default['config'];
		if ( ! is_array( $manual ) ) {
			return self::error();
		}
		if ( isset( $manual['config'] ) && ! isset( $manual['frequency'] ) ) {
			$manual['frequency'] = $manual['config'];
		}
		$manual['rate_limit'] = $manual['rate_limit'] ?? $default['config']['rate_limit'];
		$manual               = self::validateConfig( $manual );
		if ( is_wp_error( $manual ) ) {
			if ( $strict || 'manual' === $mode ) {
				return $manual;
			}
			$manual = $default['config'];
		}
		$effective = 'manual' === $mode ? $manual : $profile['config'];
		if ( 'auto' === $mode ) {
			$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
			$time   = (int) ini_get( 'max_execution_time' );
			if ( ( $memory > 0 && $memory < 128 * MB_IN_BYTES ) || ( $time > 0 && $time < 60 ) ) {
				$effective['numberEmail'] = min( $effective['numberEmail'], 25 );
			}
		}
		return array(
			'mode'            => $mode,
			'profileId'       => $profile['id'],
			'manualConfig'    => $manual,
			'effectiveConfig' => $effective,
			'espKey'          => $provider,
			'catalogVersion'  => self::catalog()['metadata']['version'],
		);
	}

	/**
	 * Validate batch size, interval, rate and quota values.
	 *
	 * @param array $config Effective sending configuration.
	 * @return array|\WP_Error
	 */
	private static function validateConfig( array $config ): array|\WP_Error {
		if ( ! isset( $config['frequency'] ) || ! is_array( $config['frequency'] ) ) {
			return self::error();
		}
		$frequency = $config['frequency'];
		if ( ! in_array( $frequency['unit'] ?? '', array( 'seconds', 'minutes', 'hours' ), true ) ) {
			return self::error();
		}
		foreach ( array(
			'numberEmail'   => array( 1, 500 ),
			'rate_limit'    => array( 0, 1000 ),
			'daily_limit'   => array( 0, PHP_INT_MAX ),
			'monthly_limit' => array( 0, PHP_INT_MAX ),
		) as $key => $bounds ) {
			$value = $config[ $key ] ?? ( in_array( $key, array( 'daily_limit', 'monthly_limit' ), true ) ? 0 : null );
			$value = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false === $value || $value < $bounds[0] || $value > $bounds[1] ) {
				return self::error();
			}
			$config[ $key ] = $value;
		}
		$period = filter_var( $frequency['value'] ?? null, FILTER_VALIDATE_INT );
		if ( false === $period || $period < 1 || $period > 86400 ) {
			return self::error();
		}
		return array(
			'numberEmail'   => $config['numberEmail'],
			'frequency'     => array(
				'value' => $period,
				'unit'  => $frequency['unit'],
			),
			'rate_limit'    => $config['rate_limit'],
			'daily_limit'   => $config['daily_limit'],
			'monthly_limit' => $config['monthly_limit'],
		);
	}

	/**
	 * Create the settings validation error.
	 *
	 * @return \WP_Error
	 */
	private static function error(): \WP_Error {
		return new \WP_Error( 'invalid_sending_frequency', __( 'Please enter a valid sending profile, batch size, interval and sending limits.', 'mailerpress' ), array( 'status' => 400 ) );
	}
}
