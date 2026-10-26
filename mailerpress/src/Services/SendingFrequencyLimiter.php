<?php
/**
 * ESP sending settings and limits.
 *
 * @package MailerPress
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName -- Follow the existing PSR-4 service naming convention.

declare(strict_types=1);

namespace MailerPress\Services;

use MailerPress\Core\EmailManager\EmailServiceInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Shares sending rates and quota usage across MailerPress sends on this site.
 */
class SendingFrequencyLimiter {

	/**
	 * Reserve capacity and send through the configured ESP.
	 *
	 * @param EmailServiceInterface $service Connected email service.
	 * @param array                 $email Email payload.
	 * @return bool|\WP_Error
	 */
	public static function send( EmailServiceInterface $service, array $email ): bool|\WP_Error {
		$provider = (string) ( $service->config()['key'] ?? SendingFrequencyConfig::activeProvider() );
		$settings = SendingFrequencyConfig::get( $provider );
		$config   = $settings['effectiveConfig'];
		$reserved = self::reserve( $provider, $config );
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}

		// Capture provider throttling before services simplify a response to false.
		$deferred = null;
		$observe  = static function ( $response, $context ) use ( &$deferred ): void {
			if ( 'response' !== $context || is_wp_error( $response ) || 429 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return;
			}
			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$delay = max( 1, (int) wp_remote_retrieve_header( $response, 'retry-after' ), (int) ( $body['retry_after'] ?? 0 ) );
			$type  = $body['name'] ?? $body['error'] ?? '';
			if ( 'daily_quota_exceeded' === $type ) {
				$delay = max( $delay, strtotime( 'tomorrow UTC' ) - time() );
			} elseif ( 'monthly_quota_exceeded' === $type ) {
				$delay = max( $delay, strtotime( 'first day of next month 00:00:00 UTC' ) - time() );
			}
			$deferred = self::deferred( $delay );
		};
		add_action( 'http_api_debug', $observe, 10, 2 );
		try {
			$result = $service->sendEmail( $email );
		} finally {
			remove_action( 'http_api_debug', $observe, 10 );
		}
		if ( null !== $deferred ) {
			self::pause( $provider, $deferred->get_error_data()['retry_after'] );
			return $deferred;
		}
		return $result;
	}

	/**
	 * Reserve quota atomically across independent workers.
	 *
	 * @param string $provider ESP key.
	 * @param array  $config Effective rate and quotas.
	 * @return bool|\WP_Error
	 */
	private static function reserve( string $provider, array $config ): bool|\WP_Error {
		global $wpdb;
		$ledger = self::database();
		$option = self::option( $provider );
		$lock   = 'mailerpress_send_' . md5( $wpdb->prefix . $provider );
		if ( 1 !== (int) $ledger->get_var( $ledger->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return self::deferred( 1 );
		}
		try {
			$state = self::read( $ledger, $option );
			$state = is_array( $state ) ? $state : array();
			$now   = time();
			if ( ( $state['paused_until'] ?? 0 ) > $now ) {
				return self::deferred( $state['paused_until'] - $now );
			}
			// Minute buckets conservatively cover the last 24 hours, including the boundary minute.
			$daily = array_filter( $state['daily'] ?? array(), static fn( $bucket ) => (int) $bucket + 60 > $now - DAY_IN_SECONDS, ARRAY_FILTER_USE_KEY );
			$month = gmdate( 'Y-m' );
			$total = ( $state['month'] ?? '' ) === $month ? (int) ( $state['monthly_count'] ?? 0 ) : 0;
			if ( ! empty( $config['daily_limit'] ) && array_sum( $daily ) >= $config['daily_limit'] ) {
				return self::deferred( max( 1, (int) min( array_keys( $daily ) ) + DAY_IN_SECONDS + 60 - $now ) );
			}
			if ( ! empty( $config['monthly_limit'] ) && $total >= $config['monthly_limit'] ) {
				return self::deferred( strtotime( 'first day of next month 00:00:00 UTC' ) - $now );
			}
			$rate = (int) $config['rate_limit'];
			if ( $rate > 0 ) {
				$wait = (float) ( $state['next_send'] ?? 0 ) - microtime( true );
				if ( $wait > 1 ) {
					return self::deferred( (int) ceil( $wait ) );
				}
				if ( $wait > 0 ) {
					usleep( (int) ceil( $wait * 1000000 ) );
				}
			}
			$bucket           = (int) ( floor( time() / 60 ) * 60 );
			$daily[ $bucket ] = ( $daily[ $bucket ] ?? 0 ) + 1;
			$state            = array(
				'daily'         => $daily,
				'month'         => $month,
				'monthly_count' => $total + 1,
				'next_send'     => $rate > 0 ? microtime( true ) + 1 / $rate : 0,
				'paused_until'  => 0,
			);
			if ( ! self::write( $ledger, $option, $state ) ) {
				return self::deferred( 60 );
			}
			return true;
		} finally {
			$ledger->get_var( $ledger->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Pause a provider after a temporary API refusal.
	 *
	 * @param string $provider ESP key.
	 * @param int    $delay Pause in seconds.
	 * @return void
	 */
	private static function pause( string $provider, int $delay ): void {
		global $wpdb;
		$ledger = self::database();
		$lock   = 'mailerpress_send_' . md5( $wpdb->prefix . $provider );
		if ( 1 !== (int) $ledger->get_var( $ledger->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return;
		}
		try {
			$option                = self::option( $provider );
			$state                 = self::read( $ledger, $option );
			$state['paused_until'] = max( $state['paused_until'] ?? 0, time() + $delay );
			self::write( $ledger, $option, $state );
		} finally {
			$ledger->get_var( $ledger->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Use an independent connection to commit reservations outside workflow transactions.
	 *
	 * @return \wpdb
	 */
	private static function database(): \wpdb {
		static $ledger = null;
		if ( null === $ledger ) {
			$ledger = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		}
		return $ledger;
	}

	/**
	 * Read usage without a stale options cache.
	 *
	 * @param \wpdb  $ledger Independent database connection.
	 * @param string $option Usage option name.
	 * @return array
	 */
	private static function read( \wpdb $ledger, string $option ): array {
		global $wpdb;
		$raw   = $ledger->get_var( $ledger->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$state = maybe_unserialize( $raw );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist reserved capacity without autoloading the ledger.
	 *
	 * @param \wpdb  $ledger Independent database connection.
	 * @param string $option Usage option name.
	 * @param array  $state Updated usage state.
	 * @return bool
	 */
	private static function write( \wpdb $ledger, string $option, array $state ): bool {
		global $wpdb;
		$written = $ledger->query( $ledger->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'", $option, maybe_serialize( $state ) ) );
		wp_cache_delete( $option, 'options' );
		return false !== $written;
	}

	/**
	 * Get the provider usage option name.
	 *
	 * @param string $provider ESP key.
	 * @return string
	 */
	private static function option( string $provider ): string {
		return 'mailerpress_sending_usage_' . sanitize_key( $provider );
	}

	/**
	 * Describe a temporary sending pause.
	 *
	 * @param int $delay Retry delay in seconds.
	 * @return \WP_Error
	 */
	public static function deferred( int $delay ): \WP_Error {
		return new \WP_Error(
			'mailerpress_sending_deferred',
			__( 'The sending limit has been reached. Sending will resume when capacity is available.', 'mailerpress' ),
			array(
				'status'      => 429,
				'retry_after' => max( 1, $delay ),
			)
		);
	}
}
