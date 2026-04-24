<?php

declare(strict_types=1);

namespace MailerPress\Actions\Cron;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;

class Cleanup
{
    /**
     * Run cleanup tasks
     */
    #[Action('mailerpress_cleanup', priority: 10, acceptedArgs: 0)]
    public function run(): void
    {
        $this->retryStuckBatches();
        $this->retryFailedChunks();
        $this->cleanupOrphanedTransients();
        $this->cleanupOldChunks();
    }

    /**
     * Retry stuck batches (in_progress > 2h)
     */
    private function retryStuckBatches(): void
    {
        global $wpdb;

        $stuck_threshold = gmdate('Y-m-d H:i:s', time() - (2 * HOUR_IN_SECONDS));
        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        // Trouver batches stuck
        $stuck_batches = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$batchTable} WHERE status = 'in_progress' AND updated_at < %s",
            $stuck_threshold
        ));

        if (empty($stuck_batches)) {
            return;
        }

        // Reschedule leurs chunks non complétés
        foreach ($stuck_batches as $batch) {
            $chunks = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM {$chunksTable}
                WHERE batch_id = %d AND status IN ('pending', 'processing')
                ORDER BY chunk_index ASC",
                $batch->id
            ));

            foreach ($chunks as $chunk) {
                // Reset status to pending - le ChunkWorker les reprendra
                $wpdb->update(
                    $chunksTable,
                    [
                        'status' => 'pending',
                        'started_at' => null,
                        'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 300),
                    ],
                    ['id' => $chunk->id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
            }
        }
    }

    /**
     * Retry failed chunks that haven't reached max retries
     */
    private function retryFailedChunks(): void
    {
        global $wpdb;

        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        // Trouver chunks failed avec retry_count < 3
        $failed_chunks = $wpdb->get_results(
            "SELECT id, batch_id, retry_count FROM {$chunksTable}
            WHERE status = 'failed' AND retry_count < 3
            LIMIT 100"
        );

        if (empty($failed_chunks)) {
            return;
        }

        foreach ($failed_chunks as $chunk) {
            // Calculer le backoff delay
            $backoff_delays = [
                1 => 5 * MINUTE_IN_SECONDS,
                2 => 15 * MINUTE_IN_SECONDS,
                3 => 45 * MINUTE_IN_SECONDS
            ];
            $delay = $backoff_delays[(int)$chunk->retry_count + 1] ?? 60 * MINUTE_IN_SECONDS;

            // Reset status - le ChunkWorker les reprendra
            $wpdb->update(
                $chunksTable,
                [
                    'status' => 'pending',
                    'retry_count' => (int)$chunk->retry_count + 1,
                    'scheduled_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                    'started_at' => null,
                ],
                ['id' => $chunk->id],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            );
        }
    }

    /**
     * Cleanup orphaned transients (chunks completed > 7 days)
     */
    private function cleanupOrphanedTransients(): void
    {
        global $wpdb;

        $cleanup_threshold = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        // Trouver chunks completed > 7 jours
        $completed_chunks = $wpdb->get_results($wpdb->prepare(
            "SELECT batch_id, chunk_index FROM {$chunksTable}
            WHERE status = 'completed' AND completed_at < %s
            LIMIT 500",
            $cleanup_threshold
        ));

        if (empty($completed_chunks)) {
            return;
        }

        $deleted_count = 0;
        foreach ($completed_chunks as $chunk) {
            $transient_key = 'mailerpress_chunk_' . $chunk->batch_id . '_' . $chunk->chunk_index;
            if (delete_transient($transient_key)) {
                $deleted_count++;
            }
        }
    }

    /**
     * Cleanup old completed chunks (> 30 days)
     */
    private function cleanupOldChunks(): void
    {
        global $wpdb;

        $cleanup_threshold = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        // Supprimer chunks completed > 30 jours
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$chunksTable}
            WHERE status = 'completed' AND completed_at < %s
            LIMIT 1000",
            $cleanup_threshold
        ));
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

        $logFile = $logDir . '/cleanup.log';
        $timestamp = current_time('mysql');
        $contextStr = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
        $logEntry = sprintf("[%s] %s%s\n", $timestamp, $message, $contextStr);
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
