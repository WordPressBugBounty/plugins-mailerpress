<?php
/**
 * Merge the former "php" provider key into the WordPress provider key.
 *
 * Idempotent: does nothing once the options are migrated.
 *
 * @package MailerPress
 */

// phpcs:disable WordPress.Files.FileName, WordPress.WP.CapitalPDangit, Generic.CodeAnalysis.UnusedFunctionParameter -- Migration naming and signature; "wordpress" is the provider key.

use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Services\SendingFrequencyConfig;

return function ( SchemaBuilder $schema ) {
	$services = get_option( 'mailerpress_email_services', null );
	$decoded  = is_string( $services ) ? json_decode( $services, true ) : $services;

	if ( is_array( $decoded ) ) {
		$normalized = EmailServiceManager::normalizeConfigurations( $decoded );
		if ( $normalized !== $decoded ) {
			update_option( 'mailerpress_email_services', $normalized );
		}
	}

	SendingFrequencyConfig::migrateLegacyProvider();
};
