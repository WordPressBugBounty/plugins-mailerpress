<?php

declare(strict_types=1);

namespace MailerPress\Actions;

\defined('ABSPATH') || exit;

use MailerPress\Services\DatabaseDiagnostic;

class DatabaseUpdateNotice
{
	const OPTION_KEY = 'mailerpress_v2_db_update_needed';
	const OPTION_DONE = 'mailerpress_v2_db_update_done';

	/**
	 * Run diagnostic after migrations and set the flag if issues are found.
	 * Called from TableManager after migrations complete on version change.
	 */
	public static function checkAfterMigrations(): void
	{
		if ( get_option( self::OPTION_DONE ) ) {
			return;
		}

		try {
			$diagnostic = new DatabaseDiagnostic();
			$result = $diagnostic->diagnose();

			if ( ! $result['healthy'] ) {
				$issueCount = $result['summary']['total_issues'] ?? 1;
				update_option( self::OPTION_KEY, $issueCount );
			} else {
				delete_option( self::OPTION_KEY );
			}
		} catch ( \Throwable $e ) {
			// Silently fail — don't block plugin load
		}
	}
}
