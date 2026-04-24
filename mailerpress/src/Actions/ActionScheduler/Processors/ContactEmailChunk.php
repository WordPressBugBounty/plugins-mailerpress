<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Interfaces\JobInterface;
use MailerPress\Core\Kernel;
use MailerPress\Core\QueueManager;
use MailerPress\Jobs\SendEmailJob;
use MailerPress\Models\Contacts;
use MailerPress\Services\Logger;

class ContactEmailChunk
{
    /**
     * Generate a secure tracking token from access_token and batch_id
     */
    private static function generateTrackingToken(string $accessToken, int $batchId): string
    {
        // Use HMAC to create a secure token that includes batch_id
        $secret = defined('AUTH_SALT') ? AUTH_SALT : 'mailerpress-tracking-secret';
        $data = $accessToken . '|' . $batchId;
        $token = hash_hmac('sha256', $data, $secret);

        // Encode the token and batch_id together (base64url safe)
        $payload = base64_encode($token . '|' . $batchId);
        return rtrim(strtr($payload, '+/', '-_'), '=');
    }

    /**
     * @throws NotFoundException
     * @throws DependencyException
     * @throws \Exception
     */
    #[Action('mailerpress_process_contact_chunk', priority: 10, acceptedArgs: 2)]
    public function mailerpress_process_contact_chunk($batch_id, $chunk_id): void
    {
        global $wpdb;

        $start_time = microtime(true);

        // 1. Récupérer chunk de la DB
        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);
        $chunk = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$chunksTable} WHERE id = %d AND batch_id = %d",
            $chunk_id, $batch_id
        ));

        if (!$chunk) {
            Logger::info('Chunk not found in database', [
                'chunk_id' => $chunk_id,
                'batch_id' => $batch_id
            ]);
            return;
        }

        // Calculer le délai d'exécution
        $scheduled_time = strtotime($chunk->scheduled_at);
        $actual_time = time();
        $delay_seconds = $actual_time - $scheduled_time;

        Logger::info('Chunk processing started', [
            'chunk_id' => $chunk_id,
            'batch_id' => $batch_id,
            'scheduled_at' => $chunk->scheduled_at,
            'actual_execution' => current_time('mysql'),
            'delay_seconds' => $delay_seconds,
            'status' => $chunk->status,
            'retry_count' => (int)$chunk->retry_count,
        ]);

        // 2. Vérifier si déjà traité
        if ($chunk->status === 'completed') {
            Logger::info('Chunk already completed, skipping', ['chunk_id' => $chunk_id]);
            return;
        }

        // 3. Marquer comme "processing"
        $wpdb->update(
            $chunksTable,
            ['status' => 'processing', 'started_at' => current_time('mysql')],
            ['id' => $chunk_id],
            ['%s', '%s'],
            ['%d']
        );

        // 4. Récupérer données (transient PUIS database)
        $transient_key = 'mailerpress_chunk_' . $batch_id . '_' . $chunk->chunk_index;
        $transient = get_transient($transient_key);

        if (false !== $transient) {
            // Fast path : transient existe
            $chunkData = $transient;
            $contact_chunk = $transient['contacts'];
            Logger::info('Using transient cache (fast path)', [
                'chunk_id' => $chunk_id,
                'contacts_count' => count($contact_chunk),
            ]);
        } else {
            // Fallback : transient expiré, lire depuis DB
            Logger::info('Transient expired, using database fallback', [
                'chunk_id' => $chunk_id,
                'transient_key' => $transient_key,
            ]);

            $contact_chunk = json_decode($chunk->contact_ids, true);
            $chunkData = json_decode($chunk->chunk_data, true);

            if (!is_array($contact_chunk) || !is_array($chunkData)) {
                $this->markChunkAsFailed($chunk_id, $batch_id, 'Invalid chunk data');
                return;
            }

            $chunkData['contacts'] = $contact_chunk;
        }

        $html = $chunkData['html'];

        // 5. Traiter les emails (logique existante)
        try {
            $this->processStandardEmailChunk($chunk_id, $batch_id, $contact_chunk, $html, $chunkData);

            // 6. Marquer comme completed
            $wpdb->update(
                $chunksTable,
                ['status' => 'completed', 'completed_at' => current_time('mysql')],
                ['id' => $chunk_id],
                ['%s', '%s'],
                ['%d']
            );

            $processing_duration = round(microtime(true) - $start_time, 2);

            Logger::info('Chunk processing completed successfully', [
                'chunk_id' => $chunk_id,
                'batch_id' => $batch_id,
                'processing_duration_seconds' => $processing_duration,
                'contacts_processed' => count($contact_chunk),
            ]);
        } catch (\Throwable $e) {
            // 7. Gérer l'erreur avec retry
            $this->handleChunkError($chunk_id, $batch_id, $e->getMessage());
        }
    }

    /**
     * Process standard email chunk (extracted from mailerpress_process_contact_chunk)
     */
    private function processStandardEmailChunk(int $chunk_id, int $batch_id, array $contact_chunk, string $html, array $chunkData): void
    {
        global $wpdb;

        $sendingService = Kernel::getContainer()->get(EmailServiceManager::class)->getConfigurations();

        if ('mailerpress' === $sendingService['default_service']) {
            $service = Kernel::getContainer()->get(EmailServiceManager::class)->getServiceByKey($sendingService['default_service']);
            if (method_exists($service, 'createBatchSending')) {
                $service->createBatchSending(
                    $chunkData,
                    $batch_id
                );
            }
        } else {
            // Optimize database queries by fetching all contacts and custom fields in batch
            $contact_ids = array_map('intval', $contact_chunk);
            $contact_ids = array_filter($contact_ids, fn($id) => $id > 0);

            if (empty($contact_ids)) {
                throw new \Exception('No valid contact IDs in chunk');
            }

            // Fetch all contacts in a single query
            $contact_placeholders = implode(',', array_fill(0, count($contact_ids), '%d'));
            $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
            $contacts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$contactTable} WHERE contact_id IN ({$contact_placeholders})",
                    ...$contact_ids
                )
            );

            // Index contacts by contact_id for quick lookup
            $contacts_by_id = [];
            foreach ($contacts as $contact) {
                $contacts_by_id[(int)$contact->contact_id] = $contact;
            }

            // Fetch all custom fields in a single query
            $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
            $all_custom_fields = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT contact_id, field_key, field_value FROM {$customFieldsTable} WHERE contact_id IN ({$contact_placeholders})",
                    ...$contact_ids
                )
            );

            // Group custom fields by contact_id
            $custom_fields_by_contact = [];
            foreach ($all_custom_fields as $customField) {
                $contact_id = (int)$customField->contact_id;
                if (!isset($custom_fields_by_contact[$contact_id])) {
                    $custom_fields_by_contact[$contact_id] = [];
                }
                $custom_fields_by_contact[$contact_id][$customField->field_key] = $customField->field_value;
            }

            // Build the 'to' array using the pre-fetched data
            $to_array = [];
            foreach ($contact_ids as $contact_id) {
                if (!isset($contacts_by_id[$contact_id])) {
                    continue; // Skip if contact not found
                }

                $contactEntity = $contacts_by_id[$contact_id];
                // Get tracking settings from chunkData (default to 'yes' for backward compatibility)
                $openTracking = $chunkData['openTracking'] ?? 'yes';

                $contact_variables = [
                    'TRACK_CLICK' => home_url('/'),
                    'CONTACT_ID'  => (int) $contactEntity->contact_id,
                    'CAMPAIGN_ID' => (int) $chunkData['campaignId'],
                    'UNSUB_LINK' => wp_unslash(
                        \sprintf(
                            '%s&data=%s&cid=%s&batchId=%s',
                            mailerpress_get_page('unsub_page'),
                            esc_attr($contactEntity->unsubscribe_token),
                            esc_attr($contactEntity->access_token),
                            $batch_id
                        )
                    ),
                    'MANAGE_SUB_LINK' => wp_unslash(
                        \sprintf(
                            '%s&cid=%s',
                            mailerpress_get_page('manage_page'),
                            esc_attr($contactEntity->access_token)
                        )
                    ),
                    'CONTACT_NAME' => esc_html($contactEntity->first_name) . ' ' . esc_html($contactEntity->last_name),
                    'contact_name' => \sprintf(
                        '%s %s',
                        esc_html($contactEntity->first_name),
                        esc_html($contactEntity->last_name)
                    ),
                    'contact_email' => \sprintf('%s', esc_html($contactEntity->email)),
                    'contact_first_name' => \sprintf('%s', esc_html($contactEntity->first_name)),
                    'contact_last_name' => \sprintf('%s', esc_html($contactEntity->last_name)),
                ];

                $clickTracking = $chunkData['clickTracking'] ?? 'yes';

                // Generate anonymous key if either open or click tracking is anonymous
                $anonymousKey = null;
                if ('anonymously' === $openTracking || 'anonymously' === $clickTracking) {
                    $anonymousKey = bin2hex(random_bytes(16)); // 32 character hex string
                    $contact_variables['ANONYMOUS_KEY'] = $anonymousKey;
                }

                // Only add TRACK_OPEN if open tracking is enabled
                if ('no' !== $openTracking) {
                    // For anonymous tracking, we use contact_id = 0
                    $trackContactId = ('anonymously' === $openTracking) ? 0 : (int) $contactEntity->contact_id;

                    $contact_variables['TRACK_OPEN'] = get_rest_url(
                        null,
                        \sprintf(
                            'mailerpress/v1/campaign/track-open?token=%s',
                            \MailerPress\Core\HtmlParser::generateTrackOpenToken(
                                $trackContactId,
                                (int) $chunkData['campaignId'],
                                (int) $batch_id,
                                null, // jobId
                                null, // stepId
                                $anonymousKey
                            )
                        )
                    );
                }

                // Add custom fields to variables
                if (isset($custom_fields_by_contact[$contact_id])) {
                    foreach ($custom_fields_by_contact[$contact_id] as $field_key => $field_value) {
                        $contact_variables[$field_key] = esc_html($field_value ?? '');
                    }
                }

                $to_array[] = [
                    'email' => $contactEntity->email,
                    'id' => (int) $contactEntity->contact_id,
                    'campaign_id' => (int) $chunkData['campaignId'],
                    'variables' => $contact_variables,
                ];
            }

            /** @var JobInterface $process */
            $process = new SendEmailJob([
                'chunk_id' => $chunk_id,
                'subject' => $chunkData['subject'] ?? '',
                'sender_name' => $chunkData['sender_name'],
                'sender_to' => $chunkData['sender_to'],
                'api_key' => $chunkData['api_key'],
                'body' => $html,
                'to' => $to_array,
                'webhook_url' => $chunkData['webhook_url'],
                'scheduled_at' => $chunkData['scheduled_at'],
                'batch_id' => $batch_id,
                'sendType' => $chunkData['sendType'],
                'timestamp' => time(),
                'clickTracking' => $chunkData['clickTracking'] ?? 'yes',
                'rate_limit' => $chunkData['rate_limit'] ?? 10, // Rate limit (emails per second)
            ]);

            $queueManager = QueueManager::getInstance();
            $job_id = $queueManager->registerJob($process);

            // Get the job we just created by ID (not the oldest job in queue)
            // Use a small delay to ensure the job is available in the database
            usleep(100000); // 100ms delay

            $jobRow = $queueManager->getJobById($job_id);
            if ($jobRow) {
                $queueManager->processJob($jobRow);
            } else {
                // Fallback: try to get next job (but this might get an older job)
                $nextJob = $queueManager->getNextJob();
                if (null !== $nextJob) {
                    $queueManager->processJob($nextJob);
                }
            }

            do_action('mailerpress_process_queue_worker');
        }
    }

    /**
     * Extract batch_id from job data for logging
     */
    private function extractBatchIdFromJob(object $jobRow): ?int
    {
        try {
            $jobInstance = unserialize(json_decode($jobRow->job), ['allowed_classes' => [
                \MailerPress\Jobs\SendEmailJob::class,
                \MailerPress\Core\Abstract\BaseJob::class,
            ]]);
            if ($jobInstance && method_exists($jobInstance, 'getData')) {
                $data = $jobInstance->getData();
                return $data['batch_id'] ?? null;
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }
        return null;
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
     * Handle chunk error with retry logic
     */
    private function handleChunkError(int $chunk_id, int $batch_id, string $error): void
    {
        global $wpdb;

        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        $chunk = $wpdb->get_row($wpdb->prepare(
            "SELECT retry_count FROM {$chunksTable} WHERE id = %d",
            $chunk_id
        ));

        if (!$chunk) {
            return;
        }

        $retry_count = (int) $chunk->retry_count;

        if ($retry_count < 3) {
            // Retry avec exponential backoff : 5min, 15min, 45min
            $backoff_delays = [
                1 => 5 * MINUTE_IN_SECONDS,
                2 => 15 * MINUTE_IN_SECONDS,
                3 => 45 * MINUTE_IN_SECONDS
            ];
            $delay = $backoff_delays[$retry_count + 1] ?? 60 * MINUTE_IN_SECONDS;

            // Calculer le prochain scheduled_at (maintenant + délai)
            $next_scheduled_at = gmdate('Y-m-d H:i:s', time() + $delay);

            // Mettre à jour chunk en DB - le ChunkWorker le reprendra automatiquement
            $wpdb->update(
                $chunksTable,
                [
                    'status' => 'pending', // Status = pending pour que le worker le reprenne
                    'retry_count' => $retry_count + 1,
                    'error_message' => $error,
                    'scheduled_at' => $next_scheduled_at,
                    'started_at' => null,
                ],
                ['id' => $chunk_id],
                ['%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );

            Logger::info('Chunk scheduled for retry (will be picked up by ChunkWorker)', [
                'chunk_id' => $chunk_id,
                'batch_id' => $batch_id,
                'retry' => $retry_count + 1,
                'delay_seconds' => $delay,
                'next_scheduled_at' => $next_scheduled_at,
            ]);
        } else {
            // Max retries : marquer comme failed définitif
            $this->markChunkAsFailed($chunk_id, $batch_id, $error);
        }
    }

    /**
     * Mark chunk as failed
     */
    private function markChunkAsFailed(int $chunk_id, int $batch_id, string $error): void
    {
        global $wpdb;

        $chunksTable = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        $wpdb->update(
            $chunksTable,
            [
                'status' => 'failed',
                'error_message' => $error
            ],
            ['id' => $chunk_id],
            ['%s', '%s'],
            ['%d']
        );


        // Optionally mark batch as failed if too many chunks failed
        // (This can be implemented later in Phase 3)
    }

    /**
     * Mark batch and campaign as failed
     */
    private function markBatchAsFailed(int $batch_id, string $error_message): void
    {
        global $wpdb;

        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $campaignTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Get batch info to find campaign_id
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT campaign_id FROM {$batchTable} WHERE id = %d",
                $batch_id
            )
        );

        if ($batch) {
            // Update batch status to failed with error message
            $wpdb->update(
                $batchTable,
                [
                    'status' => 'failed',
                    'error_message' => $error_message,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $batch_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            // Update campaign status to error
            $wpdb->update(
                $campaignTable,
                [
                    'status' => 'error',
                    'updated_at' => current_time('mysql'),
                ],
                ['campaign_id' => (int) $batch->campaign_id],
                ['%s', '%s'],
                ['%d']
            );
        }
    }
}
