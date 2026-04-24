<?php

declare(strict_types=1);

namespace MailerPress\Actions\Unsubscribe;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;

class Unsubscribe
{
    #[Action('unsubscribe_from_batch', priority: 10, acceptedArgs: 2)]
    public function unsubscribeFromBatch($batchId, $contactId): void
    {
        global $wpdb;

        // Table name (replace 'your_table_name' with the actual table name)
        $table_name = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);

        // Current timestamp
        $unsubscribed_at = current_time('mysql');

        // Check if the row exists with the matching batch_id and contact_id
        $row_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE batch_id = %d AND contact_id = %d",
                esc_attr($batchId),
                esc_attr($contactId)
            )
        );

        if (!empty($row_exists)) {
            // Update the unsubscribed_at field if the row exists
            $wpdb->update(
                $table_name,
                ['unsubscribed_at' => $unsubscribed_at], // Set current timestamp
                [
                    'batch_id' => $batchId,
                    'contact_id' => $contactId,
                ],
                ['%s'], // Format for 'unsubscribed_at'
                ['%d', '%d'] // Formats for batch_id and contact_id
            );
        } else {
            // Insert a new record if it doesn't exist
            $wpdb->replace(
                $table_name,
                [
                    'batch_id' => $batchId,
                    'contact_id' => $contactId,
                    'unsubscribed_at' => $unsubscribed_at,
                ],
                [
                    '%d',
                    '%d',
                    '%s', // Format for batch_id, contact_id, and unsubscribed_at
                ]
            );
        }
    }
}
