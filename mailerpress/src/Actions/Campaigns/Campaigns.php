<?php

declare(strict_types=1);

namespace MailerPress\Actions\Campaigns;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;

class Campaigns
{
    #[Action('mailerpress_batch_event', priority: 10, acceptedArgs: 3)]
    public function updateCampaignStatus(string $status, string $campaign_id, string $batch_id): void
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

        // Ensure status is not empty
        if (empty($status)) {
            $status = 'pending';
        }

        // Get campaign type before updating status
        $campaign = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT campaign_type, status FROM {$table_name} WHERE campaign_id = %d",
                (int) $campaign_id
            ),
            \ARRAY_A
        );

        // Get batch info to check for errors
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT sent_emails, error_emails, total_emails, error_message FROM {$batchTable} WHERE id = %d",
                (int) $batch_id
            ),
            \ARRAY_A
        );

        // Determine final campaign status based on batch errors
        $finalStatus = $status;

        if ($status === 'sent' && $batch) {
            $error_emails = (int) ($batch['error_emails'] ?? 0);
            $sent_emails = (int) ($batch['sent_emails'] ?? 0);
            $total_emails = (int) ($batch['total_emails'] ?? 0);

            // If there are errors, mark campaign as 'error' if all emails failed, or keep as 'sent' if partially sent
            if ($error_emails > 0 && $total_emails > 0) {
                // If all emails failed, mark as 'error'
                if ($error_emails === $total_emails && $sent_emails === 0) {
                    $finalStatus = 'error';

                    // Store error summary in batch if not already set
                    if (empty($batch['error_message'])) {
                        $wpdb->update(
                            $batchTable,
                            [
                                'error_message' => sprintf(
                                    __('All %d emails failed to send. Check the batch log files in wp-content/mailerpress-logs/ for details.', 'mailerpress'),
                                    $total_emails
                                ),
                            ],
                            ['id' => (int) $batch_id],
                            ['%s'],
                            ['%d']
                        );
                    }
                }
                // If some emails succeeded and some failed, keep as 'sent' but log the errors
                // The errors are already logged in mailerpress_email_logs table
            }
        }
        
        // Ensure finalStatus is never empty
        if (empty($finalStatus)) {
            $finalStatus = 'pending';
        }

        // For automated campaigns, keep status as 'active' even when batch is 'sent'
        // Automated campaigns should continue running according to their schedule
        if ($campaign && $campaign['campaign_type'] === 'automated') {
            // Only update batch_id and updated_at, keep status as 'active'
            $wpdb->update(
                $table_name,
                [
                    'batch_id' => $batch_id,
                    'updated_at' => current_time('mysql'),
                ],
                ['campaign_id' => $campaign_id],
                ['%d', '%s'],
                ['%d']
            );
            
            // Still trigger the webhook if batch is sent (for statistics)
            if ($status === 'sent') {
                $this->triggerCampaignSentWebhook($campaign_id, $batch_id);
            }
            
            return;
        }

        // For non-automated campaigns, update status with final status (may be 'error' if all emails failed)
        $updated = $wpdb->update(
            $table_name,
            [
                'status' => $finalStatus,
                'batch_id' => $batch_id,
                'updated_at' => current_time('mysql'), // Set to the current timestamp
            ],
            ['campaign_id' => $campaign_id], // Where condition
            ['%s', '%s'], // Data format: string for status and timestamp
            ['%d']        // Where condition format: integer for campaign_id
        );


        // Déclencher le hook mailerpress_campaign_sent seulement quand le statut final est 'sent'
        if ($finalStatus === 'sent') {
            $this->triggerCampaignSentWebhook($campaign_id, $batch_id);
        }
    }

    /**
     * Log message to file for debugging
     */
    private function log(string $message, array $context = []): void
    {
        $logDir = WP_CONTENT_DIR . '/mailerpress-logs';
        if (!is_dir($logDir)) {
            wp_mkdir_p($logDir);
        }

        $logFile = $logDir . '/queue-debug.log';
        $timestamp = current_time('mysql');
        $contextStr = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
        $logEntry = sprintf("[%s] %s%s\n", $timestamp, $message, $contextStr);
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Trigger webhook when campaign batch is sent
     */
    private function triggerCampaignSentWebhook(string $campaign_id, string $batch_id): void
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        
        // Récupérer les données de la campagne et du batch pour le webhook
        $campaign = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE campaign_id = %d",
                (int) $campaign_id
            ),
            \ARRAY_A
        );

        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$batchTable} WHERE id = %d",
                (int) $batch_id
            ),
            \ARRAY_A
        );

        // Préparer les données pour le webhook
        $webhookData = [
            'campaign_id' => (int) $campaign_id,
            'campaign_name' => $campaign['name'] ?? '',
            'campaign_subject' => $campaign['subject'] ?? '',
            'total_emails' => isset($batch['total_emails']) ? (int) $batch['total_emails'] : 0,
            'sent_emails' => isset($batch['sent_emails']) ? (int) $batch['sent_emails'] : 0,
            'error_emails' => isset($batch['error_emails']) ? (int) $batch['error_emails'] : 0,
            'sent_at' => $batch['updated_at'] ?? current_time('mysql'),
        ];

        // Déclencher le hook
        do_action('mailerpress_campaign_sent', (int) $campaign_id, $webhookData);
    }
}
