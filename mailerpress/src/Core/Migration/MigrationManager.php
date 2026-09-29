<?php

declare(strict_types=1);

namespace MailerPress\Core\Migration;

\defined('ABSPATH') || exit;

class MigrationManager
{
    public const PROCESS_CHUNK_HOOK = 'mailerpress_process_migration_chunk';

    public function __construct(
        private MigrationSourceRegistry $registry,
        private MigrationRepository $repository
    ) {
    }

    public function listSources(bool $refresh = false, ?string $categoryFilter = null): array
    {
        $sources = [];
        $categoryFilter = in_array($categoryFilter, ['internal', 'external'], true) ? $categoryFilter : null;

        foreach ($this->registry->all() as $source) {
            $category = sanitize_key((string) apply_filters(
                'mailerpress_migration_source_category',
                'internal',
                $source->getKey(),
                $source
            ));
            if (!in_array($category, ['internal', 'external'], true)) {
                $category = 'internal';
            }

            if ($categoryFilter !== null && $category !== $categoryFilter) {
                continue;
            }

            if ($refresh) {
                $this->clearSourceCache($source);
            }

            $estimate = $source->isDetected() ? $source->estimate() : [
                'total' => 0,
                'entities' => [],
                'warnings' => [],
            ];

            $sources[] = [
                'key' => $source->getKey(),
                'category' => $category,
                'label' => $source->getLabel(),
                'description' => $source->getDescription(),
                'icon' => $source->getIcon(),
                'icon_svg' => self::sanitizeIconSvg($source->getIconSvg()),
                'detected' => $source->isDetected(),
                'details' => $source->getDetectionDetails(),
                'entity_types' => $source->getEntityTypes(),
                'estimate' => $estimate,
            ];
        }

        return [
            'sources' => $sources,
            'runs' => array_map(fn (object $run): array => $this->formatRun($run), $this->repository->listRuns()),
        ];
    }

    public function preview(string $sourceKey, array $entityTypes = []): array|\WP_Error
    {
        $source = $this->registry->get($sourceKey);

        if (!$source) {
            return new \WP_Error('migration_source_not_found', __('Migration source not found.', 'mailerpress'), ['status' => 404]);
        }

        if (!$source->isDetected()) {
            return new \WP_Error('migration_source_not_detected', __('Migration source is not detected on this site.', 'mailerpress'), ['status' => 404]);
        }

        $entityTypes = $this->normalizeEntityTypes($source, $entityTypes);

        return [
            'source' => [
                'key' => $source->getKey(),
                'label' => $source->getLabel(),
            ],
            'entity_types' => $entityTypes,
            'estimate' => $source->estimate($entityTypes),
        ];
    }

    public function start(string $sourceKey, array $entityTypes = [], array $options = []): array|\WP_Error
    {
        $source = $this->registry->get($sourceKey);

        if (!$source) {
            return new \WP_Error('migration_source_not_found', __('Migration source not found.', 'mailerpress'), ['status' => 404]);
        }

        if (!$source->isDetected()) {
            return new \WP_Error('migration_source_not_detected', __('Migration source is not detected on this site.', 'mailerpress'), ['status' => 404]);
        }

        $entityTypes = $this->normalizeEntityTypes($source, $entityTypes);
        if (empty($entityTypes)) {
            return new \WP_Error('migration_empty_selection', __('Select at least one supported entity type to migrate.', 'mailerpress'), ['status' => 400]);
        }

        $mode = (($options['mode'] ?? 'import') === 'dry_run') ? 'dry_run' : 'import';
        $this->clearSourceCache($source);
        $estimate = $source->estimate($entityTypes);
        $runId = $this->repository->createRun($source->getKey(), $entityTypes, $options, $estimate, $mode);

        if ($runId <= 0) {
            return new \WP_Error('migration_run_create_failed', __('Unable to create the migration run.', 'mailerpress'), ['status' => 500]);
        }

        try {
            $source->createChunks($runId, $entityTypes, $options, $this->repository);
        } catch (\Throwable $e) {
            $this->repository->markRunCancelled($runId);

            return new \WP_Error(
                'migration_chunks_create_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        $this->repository->markRunStarted($runId);

        if (!$this->isForegroundRun($options)) {
            $this->scheduleNextPendingChunk($runId);
        }

        $run = $this->repository->getRun($runId);

        return [
            'success' => true,
            'run' => $run ? $this->formatRun($run) : null,
        ];
    }

    public function cancel(int $runId): array|\WP_Error
    {
        $run = $this->repository->getRun($runId);

        if (!$run) {
            return new \WP_Error('migration_run_not_found', __('Migration run not found.', 'mailerpress'), ['status' => 404]);
        }

        $this->repository->markRunCancelled($runId);

        return [
            'success' => true,
            'run' => $this->formatRun($this->repository->getRun($runId)),
        ];
    }

    public function processChunk(int $chunkId, bool $scheduleNext = true): void
    {
        $chunk = $this->repository->getChunk($chunkId);

        if (!$chunk || !in_array($chunk->status, ['pending', 'queued'], true)) {
            return;
        }

        $run = $this->repository->getRun((int) $chunk->run_id);
        if (!$run || !in_array($run->status, ['pending', 'running'], true)) {
            return;
        }

        if (!$this->repository->markChunkProcessing($chunkId)) {
            return;
        }

        $source = $this->registry->get((string) $chunk->source_key);
        if (!$source) {
            $this->repository->markChunkFailed($chunkId, __('Migration source no longer exists.', 'mailerpress'));
            $this->repository->refreshRunCounters((int) $chunk->run_id);
            if ($scheduleNext) {
                $this->scheduleNextPendingChunk((int) $chunk->run_id);
            }
            return;
        }

        $chunk = $this->repository->getChunk($chunkId);

        try {
            $result = $source->processChunk($run, $chunk, $this->repository);
            $this->repository->markChunkCompleted($chunkId, $result);
        } catch (\Throwable $e) {
            $this->repository->markChunkFailed($chunkId, $e->getMessage());
        }

        $this->repository->refreshRunCounters((int) $chunk->run_id);
        if ($scheduleNext) {
            $this->scheduleNextPendingChunk((int) $chunk->run_id);
        }
    }

    public function processNextChunk(int $runId): array|\WP_Error
    {
        $run = $this->repository->getRun($runId);

        if (!$run) {
            return new \WP_Error('migration_run_not_found', __('Migration run not found.', 'mailerpress'), ['status' => 404]);
        }

        if (!in_array($run->status, ['pending', 'running'], true)) {
            return $this->formatRun($run);
        }

        $chunk = $this->repository->getNextOpenChunk($runId);
        if (!$chunk) {
            $this->repository->refreshRunCounters($runId);

            return $this->formatRun($this->repository->getRun($runId));
        }

        $this->processChunk((int) $chunk->id, false);

        return $this->formatRun($this->repository->getRun($runId));
    }

    public function getRun(int $runId): array|\WP_Error
    {
        $run = $this->repository->getRun($runId);

        if (!$run) {
            return new \WP_Error('migration_run_not_found', __('Migration run not found.', 'mailerpress'), ['status' => 404]);
        }

        return $this->formatRun($run);
    }

    public function scheduleNextPendingChunk(int $runId): void
    {
        $run = $this->repository->getRun($runId);
        if (!$run || !in_array($run->status, ['pending', 'running'], true)) {
            return;
        }

        $chunk = $this->repository->getNextPendingChunk($runId);
        if (!$chunk) {
            $this->repository->refreshRunCounters($runId);
            return;
        }

        if (!$this->repository->markChunkQueued((int) $chunk->id)) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::PROCESS_CHUNK_HOOK, [(int) $chunk->id], 'mailerpress');
            return;
        }

        if (!wp_next_scheduled(self::PROCESS_CHUNK_HOOK, [(int) $chunk->id])) {
            wp_schedule_single_event(time() + 5, self::PROCESS_CHUNK_HOOK, [(int) $chunk->id]);
        }
    }

    private function normalizeEntityTypes(MigrationSourceInterface $source, array $entityTypes): array
    {
        $available = [];
        $default = [];

        foreach ($source->getEntityTypes() as $type) {
            if (empty($type['supported'])) {
                continue;
            }

            $key = sanitize_key((string) ($type['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $available[] = $key;

            if (!empty($type['default'])) {
                $default[] = $key;
            }
        }

        $selected = empty($entityTypes) ? $default : array_map(static fn ($type): string => sanitize_key((string) $type), $entityTypes);

        return array_values(array_intersect(array_unique($selected), $available));
    }

    private function isForegroundRun(array $options): bool
    {
        return in_array($options['execution'] ?? '', ['wizard', 'foreground'], true);
    }

    private function clearSourceCache(MigrationSourceInterface $source): void
    {
        if (method_exists($source, 'clearCache')) {
            $source->clearCache();
        }
    }

    private static function sanitizeIconSvg(string $svg): string
    {
        $svg = trim($svg);

        if ($svg === '' || !str_contains(strtolower($svg), '<svg')) {
            return '';
        }

        return wp_kses($svg, [
            'svg' => [
                'xmlns' => true,
                'width' => true,
                'height' => true,
                'viewbox' => true,
                'viewBox' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
                'role' => true,
                'aria-hidden' => true,
                'focusable' => true,
            ],
            'title' => [],
            'path' => [
                'd' => true,
                'fill' => true,
                'fill-rule' => true,
                'clip-rule' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
            ],
            'g' => [
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
            ],
            'circle' => [
                'cx' => true,
                'cy' => true,
                'r' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
            ],
            'rect' => [
                'x' => true,
                'y' => true,
                'width' => true,
                'height' => true,
                'rx' => true,
                'ry' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
            ],
            'line' => [
                'x1' => true,
                'y1' => true,
                'x2' => true,
                'y2' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
            ],
            'polyline' => [
                'points' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
            ],
            'polygon' => [
                'points' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linejoin' => true,
            ],
            'ellipse' => [
                'cx' => true,
                'cy' => true,
                'rx' => true,
                'ry' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
            ],
        ]);
    }

    private function formatRun(?object $run): array
    {
        if (!$run) {
            return [];
        }

        $settings = json_decode((string) ($run->settings ?? '{}'), true);
        $totals = json_decode((string) ($run->totals ?? '{}'), true);

        return [
            'id' => (int) $run->id,
            'source_key' => (string) $run->source_key,
            'status' => (string) $run->status,
            'mode' => (string) $run->mode,
            'settings' => is_array($settings) ? $settings : [],
            'totals' => is_array($totals) ? $totals : [],
            'processed_count' => (int) $run->processed_count,
            'skipped_count' => (int) $run->skipped_count,
            'error_count' => (int) $run->error_count,
            'last_error' => $run->last_error,
            'started_at' => $run->started_at,
            'completed_at' => $run->completed_at,
            'created_at' => $run->created_at,
            'updated_at' => $run->updated_at,
            'chunks' => $this->repository->getRunChunkSummary((int) $run->id),
        ];
    }
}
