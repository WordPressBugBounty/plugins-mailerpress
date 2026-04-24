<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use WP_REST_Request;
use WP_REST_Response;

class Recovery
{
    /**
     * Test endpoint to debug ChunkWorker
     */
    #[Endpoint(
        'recovery/test-chunk-worker',
        methods: ['GET', 'POST'],
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function testChunkWorker(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);
        $now = gmdate('Y-m-d H:i:s'); // UTC pour cohérence avec ChunkWorker

        // 1. Compter les chunks
        $counts = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$chunksTable}
        ");

        // 2. Chunks prêts à être traités
        $ready_chunks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, batch_id, chunk_index, status, scheduled_at
            FROM {$chunksTable}
            WHERE status = 'pending'
            AND scheduled_at <= %s
            ORDER BY scheduled_at ASC
            LIMIT 10",
            $now
        ));

        // 3. Prochains chunks à venir
        $upcoming_chunks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, batch_id, chunk_index, status, scheduled_at
            FROM {$chunksTable}
            WHERE status = 'pending'
            AND scheduled_at > %s
            ORDER BY scheduled_at ASC
            LIMIT 5",
            $now
        ));

        // 4. Vérifier ActionScheduler
        $as_action = $wpdb->get_row("
            SELECT hook, status, scheduled_date_gmt, last_attempt_gmt
            FROM {$wpdb->prefix}actionscheduler_actions
            WHERE hook = 'mailerpress_process_pending_chunks'
            ORDER BY scheduled_date_gmt DESC
            LIMIT 1
        ");

        // 5. Trigger manuel du worker (POST only to prevent CSRF)
        $trigger_result = null;
        if ($request->get_method() === 'POST' && $request->get_param('trigger') === 'true') {
            do_action('mailerpress_process_pending_chunks');
            $trigger_result = 'Action triggered manually';
        }

        return new \WP_REST_Response([
            'success' => true,
            'current_time' => $now,
            'chunks_count' => [
                'total' => (int) $counts->total,
                'pending' => (int) $counts->pending,
                'processing' => (int) $counts->processing,
                'completed' => (int) $counts->completed,
                'failed' => (int) $counts->failed,
            ],
            'ready_to_process' => [
                'count' => count($ready_chunks),
                'chunks' => array_map(function($chunk) use ($now) {
                    return [
                        'id' => (int) $chunk->id,
                        'batch_id' => (int) $chunk->batch_id,
                        'chunk_index' => (int) $chunk->chunk_index,
                        'scheduled_at' => $chunk->scheduled_at,
                        'late_by_seconds' => strtotime($now) - strtotime($chunk->scheduled_at),
                    ];
                }, $ready_chunks),
            ],
            'upcoming' => [
                'count' => count($upcoming_chunks),
                'chunks' => array_map(function($chunk) use ($now) {
                    return [
                        'id' => (int) $chunk->id,
                        'batch_id' => (int) $chunk->batch_id,
                        'scheduled_at' => $chunk->scheduled_at,
                        'in_seconds' => strtotime($chunk->scheduled_at) - strtotime($now),
                    ];
                }, $upcoming_chunks),
            ],
            'action_scheduler' => $as_action ? [
                'hook' => $as_action->hook,
                'status' => $as_action->status,
                'scheduled_date_gmt' => $as_action->scheduled_date_gmt,
                'last_attempt_gmt' => $as_action->last_attempt_gmt,
            ] : null,
            'trigger_result' => $trigger_result,
            'tip' => 'POST with trigger=true to manually trigger the worker',
        ], 200);
    }

    /**
     * Retry all failed/retry chunks for a specific batch
     */
    #[Endpoint(
        'recovery/batch/{batch_id}/retry',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function retryBatch(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $batch_id = (int) $request->get_param('batch_id');

        if (!$batch_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid batch_id',
            ], 400);
        }

        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        // Récupérer chunks failed/retry
        $failed_chunks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$chunksTable} WHERE batch_id = %d AND status IN ('failed', 'retry')",
            $batch_id
        ));

        if (empty($failed_chunks)) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'No failed chunks to retry',
                'chunks_retried' => 0,
            ], 200);
        }

        // Reset et reschedule chaque chunk
        $retried_count = 0;
        foreach ($failed_chunks as $chunk) {
            $wpdb->update(
                $chunksTable,
                [
                    'status' => 'pending',
                    'retry_count' => 0,
                    'error_message' => null,
                    'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 60),
                    'started_at' => null,
                ],
                ['id' => $chunk->id],
                ['%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );

            $retried_count++;
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => sprintf('%d chunk(s) scheduled for retry', $retried_count),
            'chunks_retried' => $retried_count,
        ], 200);
    }

    /**
     * Retry a specific chunk
     */
    #[Endpoint(
        'recovery/chunk/{chunk_id}/retry',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function retryChunk(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $chunk_id = (int) $request->get_param('chunk_id');

        if (!$chunk_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid chunk_id',
            ], 400);
        }

        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        $chunk = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$chunksTable} WHERE id = %d",
            $chunk_id
        ));

        if (!$chunk) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Chunk not found',
            ], 404);
        }

        // Reset chunk
        $wpdb->update(
            $chunksTable,
            [
                'status' => 'pending',
                'retry_count' => 0,
                'error_message' => null,
                'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 60),
                'started_at' => null,
            ],
            ['id' => $chunk_id],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Chunk scheduled for retry',
            'chunk_id' => $chunk_id,
            'batch_id' => (int) $chunk->batch_id,
        ], 200);
    }

    /**
     * Get list of stuck batches (in_progress > 24h)
     */
    #[Endpoint(
        'recovery/stuck-batches',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getStuckBatches(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $hours = (int) ($request->get_param('hours') ?? 24);
        $threshold = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));

        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        $stuck_batches = $wpdb->get_results($wpdb->prepare(
            "SELECT
                b.id,
                b.campaign_id,
                b.status,
                b.total_emails,
                b.sent_emails,
                b.error_emails,
                b.created_at,
                b.updated_at,
                (SELECT COUNT(*) FROM {$chunksTable} WHERE batch_id = b.id AND status = 'pending') as pending_chunks,
                (SELECT COUNT(*) FROM {$chunksTable} WHERE batch_id = b.id AND status = 'processing') as processing_chunks,
                (SELECT COUNT(*) FROM {$chunksTable} WHERE batch_id = b.id AND status = 'failed') as failed_chunks,
                (SELECT COUNT(*) FROM {$chunksTable} WHERE batch_id = b.id AND status = 'retry') as retry_chunks,
                (SELECT COUNT(*) FROM {$chunksTable} WHERE batch_id = b.id AND status = 'completed') as completed_chunks
            FROM {$batchTable} b
            WHERE b.status = 'in_progress' AND b.updated_at < %s
            ORDER BY b.updated_at ASC",
            $threshold
        ));

        return new WP_REST_Response([
            'success' => true,
            'stuck_batches' => $stuck_batches,
            'threshold_hours' => $hours,
            'count' => count($stuck_batches),
        ], 200);
    }

    /**
     * Reset a batch completely (reset all chunks to pending)
     */
    #[Endpoint(
        'recovery/batch/{batch_id}/reset',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function resetBatch(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $batch_id = (int) $request->get_param('batch_id');

        if (!$batch_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid batch_id',
            ], 400);
        }

        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        // Vérifier que le batch existe
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$batchTable} WHERE id = %d",
            $batch_id
        ));

        if (!$batch) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Batch not found',
            ], 404);
        }

        // Reset batch
        $wpdb->update(
            $batchTable,
            [
                'status' => 'in_progress',
                'sent_emails' => 0,
                'error_emails' => 0,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $batch_id],
            ['%s', '%d', '%d', '%s'],
            ['%d']
        );

        // Reset all chunks
        $wpdb->query($wpdb->prepare(
            "UPDATE {$chunksTable}
            SET
                status = 'pending',
                retry_count = 0,
                error_message = NULL,
                started_at = NULL,
                completed_at = NULL,
                scheduled_at = %s
            WHERE batch_id = %d",
            gmdate('Y-m-d H:i:s', time() + 60),
            $batch_id
        ));

        // Count chunks
        $chunks_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$chunksTable} WHERE batch_id = %d",
            $batch_id
        ));

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Batch reset successfully',
            'batch_id' => $batch_id,
            'chunks_rescheduled' => (int) $chunks_count,
        ], 200);
    }
}
