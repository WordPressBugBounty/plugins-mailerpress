<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\Contacts;

class OneClickUnsubscribe
{
	#[Endpoint(
		'one-click-unsubscribe',
		methods: [ 'GET', 'POST' ],
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

		$batchId = $this->resolveBatchId(
			absint( $request->get_param( 'batchId' ) ?? 0 ),
			(int) $contact->contact_id
		);

		if ( 'GET' === $request->get_method() ) {
			$args = [
				'data' => $token,
				'cid'  => $contact->access_token ?? '',
			];

			if ( null !== $batchId ) {
				$args['batchId'] = $batchId;
			}

			$url = add_query_arg( $args, mailerpress_get_page( 'unsub_page' ) );

			return new \WP_REST_Response( null, 302, [ 'Location' => esc_url_raw( $url ) ] );
		}

		if ( 'unsubscribed' === $contact->subscription_status ) {
			return new \WP_REST_Response( null, 200 );
		}

		$contacts->unsubscribe(
			(string) $contact->contact_id,
			null !== $batchId ? (string) $batchId : null
		);

		do_action( 'mailerpress_contact_unsubscribed', (int) $contact->contact_id );

		return new \WP_REST_Response( null, 200 );
	}

	/**
	 * Keep the batch only when its campaign was actually delivered to the contact.
	 */
	private function resolveBatchId( int $batchId, int $contactId ): ?int
	{
		if ( 0 >= $batchId || 0 >= $contactId ) {
			return null;
		}

		global $wpdb;
		$batchesTable      = Tables::get( Tables::MAILERPRESS_EMAIL_BATCHES );
		$contactStatsTable = Tables::get( Tables::MAILERPRESS_CONTACT_STATS );

		$sent = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$batchesTable} b
				INNER JOIN {$contactStatsTable} s ON s.campaign_id = b.campaign_id
				WHERE b.id = %d AND s.contact_id = %d
				LIMIT 1",
				$batchId,
				$contactId
			)
		);

		return null === $sent ? null : $batchId;
	}
}
