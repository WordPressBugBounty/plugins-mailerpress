<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Services\Logger;

/**
 * Worker récurrent qui traite les chunks en attente
 *
 * Remplace le système ActionScheduler individuel par chunk.
 * S'exécute toutes les 1 minute et traite 1 SEUL chunk à la fois
 * pour respecter le rate limiting configuré.
 */
class ChunkWorker
{
    /**
     * Nombre maximum de chunks à traiter par run
     * = 1 pour respecter le rate limiting
     */
    private const MAX_CHUNKS_PER_RUN = 1;

    /**
     * Action récurrente qui traite les chunks en attente
     *
     * S'exécute toutes les 2 minutes via ActionScheduler
     */
    #[Action('mailerpress_process_pending_chunks', priority: 10, acceptedArgs: 0)]
    public function processPendingChunks(): void
    {
        global $wpdb;

        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);
        $now = gmdate('Y-m-d H:i:s'); // UTC pour cohérence avec la création des chunks

        // Récupérer les chunks prêts à être traités
        // IMPORTANT : scheduled_at est en UTC (gmdate), donc on compare avec NOW() en UTC aussi
        $pending_chunks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, batch_id, chunk_index, scheduled_at, status
            FROM {$chunksTable}
            WHERE status = 'pending'
            AND scheduled_at <= %s
            ORDER BY scheduled_at ASC, batch_id ASC, chunk_index ASC
            LIMIT %d",
            $now,
            self::MAX_CHUNKS_PER_RUN
        ));

        if (empty($pending_chunks)) {
            if (!self::hasPendingChunks()) {
                self::unregisterRecurringWorker();
            }
            return;
        }

        Logger::info('ChunkWorker: Processing pending chunks', [
            'count' => count($pending_chunks),
            'max_per_run' => self::MAX_CHUNKS_PER_RUN,
        ]);

        // Traiter chaque chunk
        $processed = 0;
        $errors = 0;

        foreach ($pending_chunks as $chunk) {
            try {
                $this->processChunk((int) $chunk->batch_id, (int) $chunk->id);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                Logger::error('ChunkWorker: Error processing chunk', [
                    'chunk_id' => $chunk->id,
                    'batch_id' => $chunk->batch_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!self::hasPendingChunks()) {
            self::unregisterRecurringWorker();
        }
    }

    /**
     * Traite un chunk spécifique
     *
     * Appelle directement ContactEmailChunk pour traiter le chunk
     */
    private function processChunk(int $batch_id, int $chunk_id): void
    {
        try {
            // Récupérer l'instance de ContactEmailChunk via le conteneur DI
            $contactEmailChunk = Kernel::getContainer()->get(ContactEmailChunk::class);

            // Appeler directement la méthode de traitement
            $contactEmailChunk->mailerpress_process_contact_chunk($batch_id, $chunk_id);

        } catch (\Throwable $e) {
            Logger::error('ChunkWorker: Error processing chunk', [
                'batch_id' => $batch_id,
                'chunk_id' => $chunk_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw pour que le catch du parent le capture
        }
    }

    /**
     * Enregistrer le worker récurrent
     *
     * À appeler au chargement du plugin
     */
    #[Action('init', priority: 10, acceptedArgs: 0)]
    public static function registerRecurringWorker(): void
    {
        if (!\function_exists('as_next_scheduled_action')) {
            return;
        }

        $isScheduled = (bool) as_next_scheduled_action('mailerpress_process_pending_chunks', [], 'mailerpress');
        $hasPending = self::hasPendingChunks();

        if ($isScheduled && !$hasPending) {
            self::unregisterRecurringWorker();
        } elseif (!$isScheduled && $hasPending) {
            self::ensureWorkerRunning();
        }
    }

    /**
     * Désactiver le worker récurrent
     *
     * À appeler lors de la désactivation du plugin
     */
    public static function unregisterRecurringWorker(): void
    {
        if (\function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('mailerpress_process_pending_chunks', [], 'mailerpress');
            Logger::info('ChunkWorker: Worker stopped — no pending chunks');
        }
    }

    public static function hasPendingChunks(): bool
    {
        global $wpdb;
        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$chunksTable} WHERE status IN ('pending', 'processing')"
        ) > 0;
    }

    public static function ensureWorkerRunning(): void
    {
        if (!\function_exists('as_next_scheduled_action')) {
            return;
        }

        if (!as_next_scheduled_action('mailerpress_process_pending_chunks', [], 'mailerpress')) {
            as_schedule_recurring_action(
                time() + 60,
                1 * MINUTE_IN_SECONDS,
                'mailerpress_process_pending_chunks',
                [],
                'mailerpress'
            );
            Logger::info('ChunkWorker: Worker activated — pending chunks detected');
        }
    }
}
