<?php
/**
 * REST endpoints for the contact extension API.
 *
 * @package MailerPress
 */

declare(strict_types=1);

namespace MailerPress\Api;

\defined( 'ABSPATH' ) || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Extensions\ContactTabs as Registry;

class ContactTabs {
	#[Endpoint(
		'contact/(?P<contact_id>\d+)/tabs',
		methods: 'GET',
		permissionCallback: [ Permissions::class, 'canManageAudience' ]
	)]
	public static function listing( \WP_REST_Request $request ) {
		return self::respond( $request );
	}

	#[Endpoint(
		'contact/(?P<contact_id>\d+)/tabs/(?P<tab_id>[a-z0-9-]+)',
		methods: 'GET',
		permissionCallback: [ Permissions::class, 'canManageAudience' ]
	)]
	public static function content( \WP_REST_Request $request ) {
		return self::respond( $request, true );
	}

	private static function respond( \WP_REST_Request $request, bool $with_content = false ) {
		global $wpdb;
		$table = Tables::get( Tables::MAILERPRESS_CONTACT );
		$contact = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT contact_id, email, first_name, last_name, subscription_status FROM {$table} WHERE contact_id = %d",
				(int) $request['contact_id']
			),
			ARRAY_A
		);
		if ( ! $contact ) {
			return new \WP_Error( 'mailerpress_contact_not_found', __( 'Contact not found.', 'mailerpress' ), [ 'status' => 404 ] );
		}
		$contact['contact_id'] = (int) $contact['contact_id'];
		$registry = new Registry();
		try {
			/** Register tabs on every request; data is only loaded on tab activation. */
			do_action( 'mailerpress_register_contact_tabs', $registry );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'mailerpress_tabs_failed', __( 'Unable to load contact extensions.', 'mailerpress' ), [ 'status' => 502 ] );
		}
		$data = $with_content
			? $registry->content( (string) $request['tab_id'], $contact )
			: [ 'api_version' => 1, 'tabs' => $registry->listing( $contact ) ];
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
