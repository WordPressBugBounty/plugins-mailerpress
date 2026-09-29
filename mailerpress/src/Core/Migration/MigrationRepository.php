<?php

declare(strict_types=1);

namespace MailerPress\Core\Migration;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class MigrationRepository
{
    public function createRun(string $sourceKey, array $entityTypes, array $options, array $totals, string $mode = 'import'): int
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_RUNS);
        $settings = [
            'entity_types' => array_values($entityTypes),
            'options' => $options,
        ];

        $inserted = $wpdb->insert(
            $table,
            [
                'source_key' => $sourceKey,
                'status' => 'pending',
                'mode' => $mode,
                'settings' => wp_json_encode($settings),
                'totals' => wp_json_encode($totals),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    public function getRun(int $runId): ?object
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_RUNS);

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $runId)) ?: null;
    }

    public function listRuns(int $limit = 10): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_RUNS);
        $limit = max(1, min(50, $limit));

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit)
        ) ?: [];
    }

    public function markRunStarted(int $runId): void
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_RUNS);
        $wpdb->update(
            $table,
            [
                'status' => 'running',
                'started_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $runId],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public function markRunCancelled(int $runId): bool
    {
        global $wpdb;

        $runsTable = Tables::get(Tables::MAILERPRESS_MIGRATION_RUNS);
        $chunksTable = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);

        $updated = $wpdb->update(
            $runsTable,
            [
                'status' => 'cancelled',
                'completed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $runId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$chunksTable}
                 SET status = 'cancelled', updated_at = %s
                 WHERE run_id = %d AND status IN ('pending', 'queued', 'processing')",
                current_time('mysql'),
                $runId
            )
        );

        return $updated !== false;
    }

    public function insertChunk(int $runId, string $sourceKey, string $entityType, array $payload = []): int
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);
        $inserted = $wpdb->insert(
            $table,
            [
                'run_id' => $runId,
                'source_key' => $sourceKey,
                'entity_type' => $entityType,
                'payload' => wp_json_encode($payload),
                'status' => 'pending',
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    public function getChunk(int $chunkId): ?object
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $chunkId)) ?: null;
    }

    public function getNextPendingChunk(int $runId): ?object
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE run_id = %d AND status = 'pending'
                 ORDER BY id ASC
                 LIMIT 1",
                $runId
            )
        ) ?: null;
    }

    public function getNextOpenChunk(int $runId): ?object
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE run_id = %d AND status IN ('pending', 'queued')
                 ORDER BY id ASC
                 LIMIT 1",
                $runId
            )
        ) ?: null;
    }

    public function markChunkQueued(int $chunkId): bool
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);
        $updated = $wpdb->update(
            $table,
            [
                'status' => 'queued',
                'scheduled_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $chunkId, 'status' => 'pending'],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );

        return $updated !== false && $updated > 0;
    }

    public function markChunkProcessing(int $chunkId): bool
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'processing', started_at = %s, updated_at = %s
                 WHERE id = %d AND status IN ('pending', 'queued')",
                current_time('mysql'),
                current_time('mysql'),
                $chunkId
            )
        );

        return $updated !== false && $updated > 0;
    }

    public function markChunkCompleted(int $chunkId, array $result): void
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);
        $status = !empty($result['skipped_chunk']) ? 'skipped' : 'completed';

        $wpdb->update(
            $table,
            [
                'status' => $status,
                'processed_count' => (int) ($result['processed'] ?? 0),
                'skipped_count' => (int) ($result['skipped'] ?? 0),
                'error_count' => (int) ($result['errors'] ?? 0),
                'error_message' => $result['message'] ?? null,
                'completed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $chunkId],
            ['%s', '%d', '%d', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    public function markChunkFailed(int $chunkId, string $message): void
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'failed',
                     error_count = error_count + 1,
                     retry_count = retry_count + 1,
                     error_message = %s,
                     completed_at = %s,
                     updated_at = %s
                 WHERE id = %d",
                $message,
                current_time('mysql'),
                current_time('mysql'),
                $chunkId
            )
        );
    }

    public function refreshRunCounters(int $runId): ?object
    {
        global $wpdb;

        $runsTable = Tables::get(Tables::MAILERPRESS_MIGRATION_RUNS);
        $chunksTable = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);

        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(processed_count), 0) AS processed_count,
                    COALESCE(SUM(skipped_count), 0) AS skipped_count,
                    COALESCE(SUM(error_count), 0) AS error_count,
                    SUM(CASE WHEN status IN ('pending', 'queued', 'processing') THEN 1 ELSE 0 END) AS open_chunks,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_chunks
                 FROM {$chunksTable}
                 WHERE run_id = %d",
                $runId
            )
        );

        if (!$summary) {
            return null;
        }

        $status = null;
        if ((int) $summary->open_chunks === 0) {
            $status = (int) $summary->failed_chunks > 0 ? 'failed' : 'completed';
        }

        $data = [
            'processed_count' => (int) $summary->processed_count,
            'skipped_count' => (int) $summary->skipped_count,
            'error_count' => (int) $summary->error_count,
            'updated_at' => current_time('mysql'),
        ];
        $formats = ['%d', '%d', '%d', '%s'];

        if ($status !== null) {
            $data['status'] = $status;
            $data['completed_at'] = current_time('mysql');
            $formats[] = '%s';
            $formats[] = '%s';
        }

        $wpdb->update($runsTable, $data, ['id' => $runId], $formats, ['%d']);

        return $summary;
    }

    public function getRunChunkSummary(int $runId): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_CHUNKS);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entity_type,
                        status,
                        COUNT(*) AS chunks,
                        COALESCE(SUM(processed_count), 0) AS processed,
                        COALESCE(SUM(skipped_count), 0) AS skipped,
                        COALESCE(SUM(error_count), 0) AS errors
                 FROM {$table}
                 WHERE run_id = %d
                 GROUP BY entity_type, status
                 ORDER BY entity_type ASC, status ASC",
                $runId
            ),
            ARRAY_A
        ) ?: [];

        return array_map(static function (array $row): array {
            return [
                'entity_type' => (string) $row['entity_type'],
                'status' => (string) $row['status'],
                'chunks' => (int) $row['chunks'],
                'processed' => (int) $row['processed'],
                'skipped' => (int) $row['skipped'],
                'errors' => (int) $row['errors'],
            ];
        }, $rows);
    }

    public function getMapping(string $sourceKey, string $sourceEntity, string|int $sourceId): ?object
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_MAPPINGS);

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE source_key = %s AND source_entity = %s AND source_id = %s
                 LIMIT 1",
                $sourceKey,
                $sourceEntity,
                (string) $sourceId
            )
        ) ?: null;
    }

    public function saveMapping(
        string $sourceKey,
        string $sourceEntity,
        string|int $sourceId,
        string $targetEntity,
        string|int $targetId,
        int $runId,
        array $metadata = []
    ): void {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_MIGRATION_MAPPINGS);
        $existing = $this->getMapping($sourceKey, $sourceEntity, $sourceId);
        $data = [
            'source_key' => $sourceKey,
            'source_entity' => $sourceEntity,
            'source_id' => (string) $sourceId,
            'target_entity' => $targetEntity,
            'target_id' => (string) $targetId,
            'metadata' => wp_json_encode($metadata),
            'run_id' => $runId,
            'updated_at' => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update(
                $table,
                $data,
                ['id' => (int) $existing->id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );
            return;
        }

        unset($data['updated_at']);
        $wpdb->insert(
            $table,
            $data,
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );
    }
}
