<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined( 'ABSPATH' ) || exit;

use MailerPress\Core\Enums\Tables;

class PendingContactManager {

	public const OPTION_NAME = 'mailerpress_signup_confirmation';
	public const BATCH_SIZE  = 100;

	public static function validateSettings( array $settings ): array|\WP_Error {
		$enabled = $settings['automaticallyDeleteUnconfirmedContacts'] ?? false;
		$days    = $settings['unconfirmedContactRetentionDays'] ?? 30;
		if ( ! is_bool( $enabled ) || ( ! is_int( $days ) && ! is_string( $days ) ) || false === filter_var(
			$days,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 1,
					'max_range' => intdiv( PHP_INT_MAX, DAY_IN_SECONDS ),
				),
			)
		) ) {
			return new \WP_Error( 'invalid_pending_retention', __( 'The retention period must be a positive integer in days.', 'mailerpress' ), array( 'status' => 400 ) );
		}
		$settings['automaticallyDeleteUnconfirmedContacts'] = $enabled;
		$settings['unconfirmedContactRetentionDays']        = (int) $days;
		return $settings;
	}

	/** Read uncached settings so queued workers see changes from other requests. */
	public function getSettings( bool $lock = false ): array {
		global $wpdb;
		$value    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s" . ( $lock ? ' FOR UPDATE' : '' ), self::OPTION_NAME ) );
		$settings = maybe_unserialize( $value );
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true );
		}
		$settings = is_array( $settings ) ? self::validateSettings( $settings ) : array();
		return is_array( $settings ) ? $settings : array();
	}

	/** Only a transition into pending starts a new period; legacy pending rows stay NULL. */
	public static function transitionFields( int $contactId, string $status ): array {
		if ( 'pending' !== $status ) {
			return array();
		}
		global $wpdb;
		$table          = Tables::get( Tables::MAILERPRESS_CONTACT );
		$previousStatus = $wpdb->get_var( $wpdb->prepare( "SELECT subscription_status FROM {$table} WHERE contact_id = %d", $contactId ) );
		return 'pending' === $previousStatus ? array() : array( 'pending_since' => current_time( 'mysql' ) );
	}

	public function findCandidates( int $afterId, int $maxId ): array {
		global $wpdb;
		$settings = $this->getSettings();
		if ( empty( $settings['automaticallyDeleteUnconfirmedContacts'] ) ) {
			return array();
		}
		$cutoff = time() - $settings['unconfirmedContactRetentionDays'] * DAY_IN_SECONDS;
		// MySQL contact dates cannot predate year 1000. Avoid out-of-range cutoffs.
		if ( $cutoff < -30610224000 ) {
			return array();
		}
		$table = Tables::get( Tables::MAILERPRESS_CONTACT );
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT contact_id FROM {$table}
			 WHERE contact_id > %d AND contact_id <= %d AND subscription_status = 'pending'
			 AND COALESCE(pending_since, created_at) <= %s
			 ORDER BY contact_id LIMIT %d",
				$afterId,
				$maxId,
				wp_date( 'Y-m-d H:i:s', $cutoff ),
				self::BATCH_SIZE
			)
		) ?: array();
	}

	private function isExpired( object $contact, int $days ): bool {
		$value = $contact->pending_since ?? $contact->created_at ?? null;
		if ( ! is_string( $value ) || $value < '1000-01-01 00:00:00' ) {
			return false;
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		return false !== $date
			&& ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) )
			&& $date->format( 'Y-m-d H:i:s' ) === $value
			&& $date->getTimestamp() <= time() - $days * DAY_IN_SECONDS;
	}

	public function deleteIfExpired( int $contactId ): bool {
		global $wpdb;
		$table = Tables::get( Tables::MAILERPRESS_CONTACT );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Could not start pending contact cleanup transaction.' );
		}
		try {
			// Lock settings before the contact: disabling cannot race with a deletion.
			$settings = $this->getSettings( true );
			$contact  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d FOR UPDATE", $contactId ) );
			if ( empty( $settings['automaticallyDeleteUnconfirmedContacts'] ) || ! $contact || 'pending' !== $contact->subscription_status || ! $this->isExpired( $contact, $settings['unconfirmedContactRetentionDays'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			foreach ( array( Tables::MAILERPRESS_CONTACT_LIST, Tables::CONTACT_TAGS, Tables::MAILERPRESS_CONTACT_NOTE, Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS ) as $relatedTable ) {
				if ( false === $wpdb->delete( Tables::get( $relatedTable ), array( 'contact_id' => $contactId ), array( '%d' ) ) ) {
					throw new \RuntimeException( 'Could not delete pending contact related data.' );
				}
			}
			if ( 1 !== $wpdb->delete(
				$table,
				array(
					'contact_id'          => $contactId,
					'subscription_status' => 'pending',
				),
				array( '%d', '%s' )
			) ) {
				throw new \RuntimeException( 'Could not delete pending contact.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit pending contact cleanup.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'mailerpress_send_confirmation_reminder', array( $contactId ), 'mailerpress' );
		}
		do_action( 'mailerpress_contact_deleted', $contactId, $contact->email ?? '', $contact->first_name ?? '', $contact->last_name ?? '' );
		return true;
	}
}
