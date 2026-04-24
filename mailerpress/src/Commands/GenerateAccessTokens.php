<?php

declare(strict_types=1);

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Core\Enums\Tables;

\defined('ABSPATH') || exit;

class GenerateAccessTokens
{
    #[Command('mailerpress tokens:generate')]
    public function generate(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_CONTACT);

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
        if (!$table_exists) {
            \WP_CLI::error('Table mailerpress_contact does not exist.');
            return;
        }

        // Check if access_token column exists
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                'access_token'
            )
        );

        if (empty($column_exists)) {
            \WP_CLI::error('Column access_token does not exist. Run migrations first.');
            return;
        }

        \WP_CLI::log('🔑 Generating missing access tokens...');

        $totalProcessed = 0;
        $batchSize = 200;

        do {
            // Fetch contacts missing access_token
            $contacts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT contact_id FROM $table WHERE access_token IS NULL OR access_token = '' LIMIT %d",
                    $batchSize
                )
            );

            if (empty($contacts)) {
                break;
            }

            foreach ($contacts as $contact) {
                $token = bin2hex(random_bytes(32));

                // Ensure uniqueness
                while ($wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $table WHERE access_token = %s",
                        $token
                    )) > 0) {
                    $token = bin2hex(random_bytes(32));
                }

                $wpdb->update(
                    $table,
                    ['access_token' => $token],
                    ['contact_id' => $contact->contact_id],
                    ['%s'],
                    ['%d']
                );

                $totalProcessed++;
            }

            \WP_CLI::log(sprintf('Processed %d contacts...', $totalProcessed));

            // Continue processing until no more contacts are found
        } while (count($contacts) === $batchSize);

        \WP_CLI::success(sprintf('✅ Generated %d access tokens.', $totalProcessed));
    }
}
