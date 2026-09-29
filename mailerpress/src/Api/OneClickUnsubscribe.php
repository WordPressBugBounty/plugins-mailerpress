<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Models\Contacts;

class OneClickUnsubscribe
{
	#[Endpoint(
		'one-click-unsubscribe',
		methods: 'POST',
		permissionCallback: '__return_true',
	)]
	public function handle(\WP_REST_Request $request): \WP_REST_Response
	{
		$token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );

		if ( empty( $token ) ) {
			return new \WP_REST_Response( null, 400 );
		}

		$contacts = new Contacts();
		$contact  = $contacts->getContactByToken( $token );

		if ( ! $contact ) {
			return new \WP_REST_Response( null, 200 );
		}

		if ( 'unsubscribed' === $contact->subscription_status ) {
			return new \WP_REST_Response( null, 200 );
		}

		$contacts->unsubscribe( (string) $contact->contact_id );

		do_action( 'mailerpress_contact_unsubscribed', (int) $contact->contact_id );

		return new \WP_REST_Response( null, 200 );
	}
}
