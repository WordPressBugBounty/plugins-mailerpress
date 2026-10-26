<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined( 'ABSPATH' ) || exit;

/** Resolve the selected wall-clock time once, before creating a scheduled batch. */
final class CampaignScheduleTime {
	public static function resolve( mixed $value, ?string $timezone = null, ?int $now = null ): \DateTimeImmutable|\WP_Error {
		$invalid = static fn() => new \WP_Error(
			'mailerpress_scheduled_date_invalid',
			__( 'Select a valid future date and time. This time may not exist because of a daylight saving change.', 'mailerpress' ),
			[ 'status' => 422 ]
		);
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $invalid();
		}

		try {
			$zone = null === $timezone || '' === $timezone ? wp_timezone() : new \DateTimeZone( $timezone );
			$wall_time = str_replace( 'T', ' ', $value );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $wall_time ) ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $wall_time, $zone );
				if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $wall_time ) {
					return $invalid();
				}

				// A repeated autumn hour needs an explicit offset; never silently pick one.
				$transitions = $zone->getTransitions( $date->getTimestamp() - DAY_IN_SECONDS, $date->getTimestamp() + DAY_IN_SECONDS );
				$wall_timestamp = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $wall_time, new \DateTimeZone( 'UTC' ) )->getTimestamp();
				$matches = [];
				foreach ( $transitions ?: [] as $transition ) {
					$candidate = $wall_timestamp - $transition['offset'];
					if ( ( new \DateTimeImmutable( '@' . $candidate ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' ) === $wall_time ) {
						$matches[ $candidate ] = true;
					}
				}
				if ( count( $matches ) > 1 ) {
					return new \WP_Error(
						'mailerpress_scheduled_date_ambiguous',
						__( 'This time occurs twice because of a daylight saving change. Please choose a time outside the repeated hour.', 'mailerpress' ),
						[ 'status' => 422 ]
					);
				}
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
				$date = new \DateTimeImmutable( $value );
				$errors = \DateTimeImmutable::getLastErrors();
				if ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
					return $invalid();
				}
			} else {
				return $invalid();
			}
		} catch ( \Exception $exception ) {
			return $invalid();
		}

		if ( $date->getTimestamp() <= ( $now ?? time() ) ) {
			return new \WP_Error(
				'mailerpress_scheduled_date_past',
				__( 'The scheduled date is in the past. Please select a future date and time.', 'mailerpress' ),
				[ 'status' => 422 ]
			);
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
