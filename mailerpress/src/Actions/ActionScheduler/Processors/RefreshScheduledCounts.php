<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Services\ClassicContactFetcher;
use MailerPress\Services\SegmentContactFetcher;

final class RefreshScheduledCounts
{
	#[Action('mailerpress_refresh_scheduled_counts', priority: 10, acceptedArgs: 0)]
	public function refresh(): void
	{
		global $wpdb;

		$batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
		$campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

		$scheduled = $wpdb->get_results(
			"SELECT b.id AS batch_id, c.config
			 FROM {$batchesTable} b
			 INNER JOIN {$campaignsTable} c ON c.batch_id = b.id
			 WHERE b.status = 'scheduled'",
			ARRAY_A
		);

		if ( empty( $scheduled ) ) {
			return;
		}

		$chunkSize = 1000;

		foreach ( $scheduled as $row ) {
			$config = json_decode( $row['config'] ?? '', true );
			if ( ! is_array( $config ) ) {
				continue;
			}

			$recipientTargeting = $config['recipientTargeting'] ?? 'classic';
			$lists   = $config['lists'] ?? [];
			$tags    = $config['tags'] ?? [];
			$segment = $config['segment'] ?? [];

			try {
				$fetcher = match ( $recipientTargeting ) {
					'segment' => new SegmentContactFetcher( is_array( $segment ) ? $segment[0] : $segment ),
					default   => new ClassicContactFetcher( $lists, $tags ),
				};
			} catch ( \Exception $e ) {
				continue;
			}

			$total  = 0;
			$offset = 0;
			do {
				$contacts = $fetcher->fetch( $chunkSize, $offset );
				$total   += count( $contacts );
				$offset  += $chunkSize;
			} while ( count( $contacts ) === $chunkSize );

			$wpdb->update(
				$batchesTable,
				[ 'total_emails' => $total ],
				[ 'id' => (int) $row['batch_id'] ],
				[ '%d' ],
				[ '%d' ]
			);
		}
	}
}
