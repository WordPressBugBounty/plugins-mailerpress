<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\CustomFields;
use MailerPress\Services\ContactImportFields;
use MailerPress\Services\InactiveContactManager;

class ProcessChunkImportContact
{
    private const STANDARD_CONTACT_FIELDS = [
        'email',
        'first_name',
        'last_name',
        'created_at',
        'updated_at',
        'last_engagement_at',
        'last_open_at',
        'last_click_at',
        'last_sending_at',
        'last_subscribed_at',
        'email_count',
        'inactivated_at',
        'inactivation_reason',
    ];

    #[Action('process_import_chunk', priority: 10, acceptedArgs: 2)]
    public function processImportChunk($chunk_id, $forceUpdate = false): void
    {
        global $wpdb;
        $chunk_id = (int) $chunk_id;
        $forceUpdate = true === $forceUpdate || '1' === $forceUpdate || 1 === $forceUpdate;
        $importChunks = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contactBatch = Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES);

        try {
            // Use atomic UPDATE to claim this chunk for processing (prevents race conditions)
            // Also set processing_started_at timestamp for stale detection
            $claimed = $wpdb->query($wpdb->prepare("
                UPDATE {$importChunks}
                SET processed = 2, processing_started_at = NOW(), retry_count = COALESCE(retry_count, 0)
                WHERE id = %d AND processed IN (0, 3)
            ", $chunk_id));

            if ($claimed === 0 || $claimed === false) {
                // Chunk already claimed by another process or doesn't exist
                return;
            }

            // Now fetch the chunk data
            $chunk = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$importChunks}
                WHERE id = %d
            ", $chunk_id));

            if (!$chunk) {
                // Chunk doesn't exist
                return;
            }

            $batch = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$contactBatch} WHERE batch_id = %d", $chunk->batch_id),
                ARRAY_A
            );

            if (!$batch) {
                // Mark chunk as failed with error message
                $wpdb->update($importChunks, [
                    'processed' => 3,
                    'error_message' => 'Batch not found'
                ], ['id' => $chunk_id]);
                $this->scheduleNextChunk($chunk->batch_id);
                return;
            }

            $contactTags = json_decode($batch['tags'], true);
            $tags_json_error = json_last_error_msg();
            $contactLists = json_decode($batch['lists'], true);
            $lists_json_error = json_last_error_msg();
            $contact_status = $batch['subscription_status'];
            $contacts = json_decode($chunk->chunk_data, true);
            $contacts_json_error = json_last_error_msg();

            if (!is_array($contactTags)) {
                $contactTags = [];
            }

            if (!is_array($contactLists)) {
                $contactLists = [];
            }

            // Validate contacts array
            if (!is_array($contacts)) {
                // Mark chunk as failed - invalid data
                $wpdb->update($importChunks, [
                    'processed' => 3,
                    'error_message' => $this->truncateLogMessage('Invalid contacts data: ' . $contacts_json_error)
                ], ['id' => $chunk_id]);
                $this->scheduleNextChunk($chunk->batch_id);
                return;
            }

            $processed_contacts = 0;

            // Memory management: Track initial memory and set threshold
            $initial_memory = memory_get_usage();
            $memory_limit = $this->getMemoryLimit();
            $memory_threshold = $memory_limit * 0.8; // Stop at 80% to prevent exhaustion

            // Cache current_time to avoid repeated calls
            $current_time = current_time('mysql');

            // Process contacts with better error handling and memory management
            foreach ($contacts as $index => $contact) {
                // Check memory usage every 10 contacts
                if ($index > 0 && $index % 10 === 0) {
                    $current_memory = memory_get_usage();

                    // If approaching memory limit, stop processing and reschedule remainder
                    if ($current_memory > $memory_threshold) {

                        // Save progress and reschedule chunk with remaining contacts
                        $this->reschedulePartialChunk($chunk_id, $chunk->batch_id, $contacts, $index, $forceUpdate);

                        // Update processed count for contacts we did complete
                        if ($processed_contacts > 0) {
                            $wpdb->query(
                                $wpdb->prepare(
                                    "UPDATE {$contactBatch} SET processed_count = processed_count + %d WHERE batch_id = %d",
                                    $processed_contacts,
                                    $chunk->batch_id
                                )
                            );
                        }

                        return;
                    }

                    // Clear WordPress object cache every 50 contacts to prevent buildup
                    if ($index % 50 === 0) {
                        wp_cache_flush();
                    }
                }

                if (!is_array($contact)) {
                    $processed_contacts++;
                    continue;
                }

                $email = isset($contact['email']) && is_scalar($contact['email'])
                    ? sanitize_email(trim((string) $contact['email'], " \t\n\r\0\x0B\"'"))
                    : '';

                // Validate email before processing
                if (empty($email) || !is_email($email)) {
                    // Skip invalid emails but still count them as processed
                    $processed_contacts++;
                    continue;
                }

                $contact['email'] = $email;

                $contact_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT contact_id FROM {$contactTable} WHERE email = %s LIMIT 1",
                        $contact['email']
                    )
                );

                if (null === $contact_id) {
                    // Insert new contact
                    $names = ContactImportFields::names($contact);
                    $dates = ContactImportFields::dates($contact);

                    $contact_data = [
                        'email' => $contact['email'],
                        'first_name' => $names['first_name'] ?? '',
                        'last_name' => $names['last_name'] ?? '',
                        'subscription_status' => $contact_status ?? 'pending',
                        'unsubscribe_token' => wp_generate_uuid4(),
                        'created_at' => $dates['created_at'] ?? $current_time,
                        'updated_at' => $dates['updated_at'] ?? $dates['created_at'] ?? $current_time,
                        'opt_in_source' => 'batch_import_file',
                        'access_token' => bin2hex(random_bytes(32))
                    ];
                    $contact_data = array_merge($contact_data, $this->lifecycleFieldsForStatus((string) ($contact_status ?? 'pending'), $current_time));
                    if ('pending' === ($contact_status ?? 'pending')) {
                        $contact_data['pending_since'] = $current_time;
                    }

                    $result = $wpdb->insert($contactTable, $contact_data);

                    if (false !== $result) {
                        $contactId = $wpdb->insert_id;
                        $this->attachContactRelations(
                            (int) $contactId,
                            $contactTags,
                            Tables::get(Tables::CONTACT_TAGS),
                            'tag_id'
                        );

                        $this->attachContactRelations(
                            (int) $contactId,
                            $contactLists,
                            Tables::get(Tables::MAILERPRESS_CONTACT_LIST),
                            'list_id'
                        );

                        // Insert custom fields - skip standard fields that shouldn't be in custom_fields
                        if (!empty($contact['custom_fields']) && is_array($contact['custom_fields'])) {
                            foreach ($contact['custom_fields'] as $field_key => $field_value) {
                                // Skip if this is a standard field (shouldn't be in custom_fields)
                                if (in_array($field_key, self::STANDARD_CONTACT_FIELDS, true)) {
                                    continue;
                                }

                                // Sanitize value according to field type
                                $sanitized_value = CustomFields::sanitizeValue($field_key, $field_value);

                                // Skip null values (empty or invalid)
                                if ($sanitized_value === null) {
                                    continue;
                                }

                                // Convert to string for database storage (handles int, float, string, etc.)
                                $db_value = is_numeric($sanitized_value)
                                    ? (string) $sanitized_value
                                    : sanitize_text_field((string) $sanitized_value);

                                $custom_field_result = $wpdb->insert(Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS), [
                                    'contact_id' => $contactId,
                                    'field_key' => sanitize_text_field($field_key),
                                    'field_value' => $db_value,
                                ]);

                                if (false === $custom_field_result) {
                                    continue;
                                }

                                do_action('mailerpress_contact_custom_field_added', $contactId, sanitize_text_field($field_key), $sanitized_value);
                            }
                        }

                        $processed_contacts++;
                    } else {
                        // Insert failed, but still count as processed (attempted)
                        $processed_contacts++;
                    }
                } else {
                    // Update existing contact
                    // Always count existing contacts as processed, even if not updated
                    $processed_contacts++;

                    if (true === $forceUpdate || '1' === $forceUpdate) {
                        // The import status only applies to new contacts: existing contacts keep their subscription status.
                        $update_data = array_merge(
                            ['updated_at' => $current_time],
                            ContactImportFields::names($contact),
                            ContactImportFields::dates($contact)
                        );

                        $result = $wpdb->update(
                            $contactTable,
                            $update_data,
                            ['contact_id' => $contact_id]
                        );

                        if (false !== $result) {
                            $this->attachContactRelations(
                                (int) $contact_id,
                                $contactTags,
                                Tables::get(Tables::CONTACT_TAGS),
                                'tag_id'
                            );

                            $this->attachContactRelations(
                                (int) $contact_id,
                                $contactLists,
                                Tables::get(Tables::MAILERPRESS_CONTACT_LIST),
                                'list_id'
                            );

                            // Insert custom fields for existing contact (update if exists)
                            if (!empty($contact['custom_fields']) && is_array($contact['custom_fields'])) {
                                foreach ($contact['custom_fields'] as $field_key => $field_value) {
                                    // Skip if this is a standard field (shouldn't be in custom_fields)
                                    if (in_array($field_key, self::STANDARD_CONTACT_FIELDS, true)) {
                                        continue;
                                    }

                                    $existing = $wpdb->get_var(
                                        $wpdb->prepare(
                                            "SELECT field_id FROM " . Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS) . " WHERE contact_id = %d AND field_key = %s LIMIT 1",
                                            $contact_id,
                                            $field_key
                                        )
                                    );

                                    // Sanitize value according to field type
                                    $sanitized_value = CustomFields::sanitizeValue($field_key, $field_value);

                                    // Skip null values (empty or invalid)
                                    if ($sanitized_value === null) {
                                        // Delete existing field if value is empty
                                        if ($existing) {
                                            $wpdb->delete(
                                                Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS),
                                                ['field_id' => $existing]
                                            );
                                        }
                                        continue;
                                    }

                                    // Convert to string for database storage (handles int, float, string, etc.)
                                    $db_value = is_numeric($sanitized_value)
                                        ? (string) $sanitized_value
                                        : sanitize_text_field((string) $sanitized_value);

                                    if ($existing) {
                                        $wpdb->update(
                                            Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS),
                                            ['field_value' => $db_value],
                                            ['field_id' => $existing]
                                        );
                                    } else {
                                        $wpdb->insert(
                                            Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS),
                                            [
                                                'contact_id' => $contact_id,
                                                'field_key' => sanitize_text_field($field_key),
                                                'field_value' => $db_value,
                                            ]
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Update processed_count once for all contacts in this chunk
            // This ensures accurate counting even if some contacts fail to process
            if ($processed_contacts > 0) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$contactBatch} SET processed_count = processed_count + %d WHERE batch_id = %d",
                        $processed_contacts,
                        $chunk->batch_id
                    )
                );
            }

            // Clear any remaining cached data before marking complete
            wp_cache_flush();

            // Mark the chunk as processed successfully
            $wpdb->update($importChunks, [
                'processed' => 1,
                'processing_completed_at' => current_time('mysql')
            ], ['id' => $chunk_id]);

            // Schedule next chunks for processing (parallel approach)
            $this->scheduleNextChunk($chunk->batch_id);

            // Detect and reschedule stale chunks before checking completion
            $this->rescheduleStaleChunks($chunk->batch_id);

            // Check if all chunks for this batch are completed
            // Only count pending (0) and actually processing chunks (not stale ones)
            $remaining_chunks = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$importChunks}
                WHERE batch_id = %d
                AND processed IN (0, 2)
                AND (processing_started_at IS NULL OR processing_started_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE))
            ", $chunk->batch_id));

            if (0 === (int) $remaining_chunks) {
                // All chunks processed - verify that processed_count matches count
                $batch_info = $wpdb->get_row($wpdb->prepare(
                    "SELECT count, processed_count, status FROM {$contactBatch} WHERE batch_id = %d",
                    $chunk->batch_id
                ), ARRAY_A);

                if ($batch_info) {
                    // Ensure processed_count doesn't exceed count
                    if ((int)$batch_info['processed_count'] > (int)$batch_info['count']) {
                        $wpdb->update(
                            $contactBatch,
                            ['processed_count' => (int)$batch_info['count']],
                            ['batch_id' => $chunk->batch_id]
                        );
                    }

                    // Set processed_count to count to ensure 100% is shown
                    $wpdb->update(
                        $contactBatch,
                        ['processed_count' => (int)$batch_info['count']],
                        ['batch_id' => $chunk->batch_id]
                    );
                }

                // Mark batch as completed (status = 'done')
                // This removes it from the pending imports list
                $wpdb->update($contactBatch, ['status' => 'done'], ['batch_id' => $chunk->batch_id]);
            }
        } catch (\Throwable $e) {
            // Get current retry count
            $chunk_info = $wpdb->get_row($wpdb->prepare(
                "SELECT retry_count, batch_id FROM {$importChunks} WHERE id = %d",
                $chunk_id
            ));

            $retry_count = isset($chunk_info->retry_count) ? (int)$chunk_info->retry_count : 0;

            // Maximum retries - filterable for reliability tuning
            $max_retries = apply_filters('mailerpress_import_max_retries', 3);
            $max_retries = max(1, min(10, $max_retries));

            if ($retry_count < $max_retries) {
                // Mark for retry (status 0 = pending) with incremented retry count
                $wpdb->update($importChunks, [
                    'processed' => 0,
                    'retry_count' => $retry_count + 1,
                    'error_message' => $this->truncateLogMessage(get_class($e) . ': ' . $e->getMessage())
                ], ['id' => $chunk_id]);

                // Schedule retry with exponential backoff (1min, 2min, 4min)
                $delay = pow(2, $retry_count) * 60;
                if (function_exists('as_schedule_single_action')) {
                    as_schedule_single_action(
                        time() + $delay,
                        'process_import_chunk',
                        [$chunk_id, $forceUpdate]
                    );
                }

            } else {
                // Max retries reached, mark as permanently failed
                $wpdb->update($importChunks, [
                    'processed' => 3,
                    'error_message' => $this->truncateLogMessage('Max retries reached: ' . get_class($e) . ': ' . $e->getMessage())
                ], ['id' => $chunk_id]);
            }

            // Schedule next chunks anyway to prevent entire batch from stalling
            if ($chunk_info && isset($chunk_info->batch_id)) {
                $this->scheduleNextChunk($chunk_info->batch_id);
            }
        }
    }

    private function attachContactRelations(
        int $contactId,
        array $relations,
        string $table,
        string $relationColumn
    ): void {
        global $wpdb;

        foreach ($relations as $relation) {
            if (!is_array($relation) || empty($relation['id'])) {
                continue;
            }

            $relationId = (int) $relation['id'];
            if ($relationId <= 0) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE contact_id = %d AND {$relationColumn} = %d LIMIT 1",
                $contactId,
                $relationId
            ));

            if ($exists) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'contact_id' => $contactId,
                    $relationColumn => $relationId,
                ],
                ['%d', '%d']
            );
        }
    }

    /**
     * Schedule multiple pending chunks for processing
     * Default: Schedules up to 20 chunks at once for better throughput (filterable)
     */
    private function scheduleNextChunk($batch_id): void
    {
        global $wpdb;
        $importChunks = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);
        $contactBatch = Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES);

        // Read force_update from the batch so the flag is preserved for all background chunks.
        $force_update = (bool) $wpdb->get_var(
            $wpdb->prepare("SELECT force_update FROM {$contactBatch} WHERE batch_id = %d", $batch_id)
        );

        // Number of chunks to schedule in parallel - filterable for performance tuning
        $parallel_chunks = apply_filters('mailerpress_import_parallel_chunks', 20);
        $parallel_chunks = max(5, min(100, $parallel_chunks));

        // Find next pending chunks (parallel processing for better speed)
        $nextChunks = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$importChunks}
            WHERE batch_id = %d AND processed = 0
            ORDER BY id ASC
            LIMIT %d
        ", $batch_id, $parallel_chunks));

        if (!empty($nextChunks)) {
            $scheduled_count = 0;

            // Stagger delay between scheduled chunks - filterable
            $stagger_delay = apply_filters('mailerpress_import_chunk_stagger_delay', 0.5);

            foreach ($nextChunks as $chunk) {
                // Check if action is already scheduled
                if (function_exists('as_has_scheduled_action')) {
                    $alreadyScheduled = as_has_scheduled_action('process_import_chunk', [$chunk->id, $force_update]);

                    if (!$alreadyScheduled && function_exists('as_schedule_single_action')) {
                        // Schedule with staggered delay
                        as_schedule_single_action(
                            time() + (int)($scheduled_count * $stagger_delay),
                            'process_import_chunk',
                            [$chunk->id, $force_update]
                        );
                        $scheduled_count++;
                    }
                }
            }
        }
    }

    /**
     * Detect and reschedule stale chunks that have been stuck in processing state
     * Default: A chunk is considered stale if processing for more than 5 minutes (filterable)
     */
    private function rescheduleStaleChunks($batch_id): void
    {
        global $wpdb;
        $importChunks = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);
        $contactBatch = Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES);

        // Read force_update from the batch so the flag is preserved on retry.
        $force_update = (bool) $wpdb->get_var(
            $wpdb->prepare("SELECT force_update FROM {$contactBatch} WHERE batch_id = %d", $batch_id)
        );

        // Stale timeout in minutes - filterable for performance tuning
        $stale_timeout_minutes = apply_filters('mailerpress_import_stale_timeout_minutes', 5);
        $stale_timeout_minutes = max(2, min(30, $stale_timeout_minutes));

        // Find chunks that have been processing for longer than the timeout
        $staleChunks = $wpdb->get_results($wpdb->prepare("
            SELECT id, retry_count FROM {$importChunks}
            WHERE batch_id = %d
            AND processed = 2
            AND processing_started_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
        ", $batch_id, $stale_timeout_minutes));

        if (!empty($staleChunks)) {
            foreach ($staleChunks as $chunk) {
                $retry_count = (int)($chunk->retry_count ?? 0);

                // Maximum retries - filterable for reliability tuning
                $max_retries = apply_filters('mailerpress_import_max_retries', 3);
                $max_retries = max(1, min(10, $max_retries));

                if ($retry_count < $max_retries) {
                    // Reset to pending and increment retry count
                    $wpdb->update($importChunks, [
                        'processed' => 0,
                        'retry_count' => $retry_count + 1,
                        'error_message' => 'Stale chunk - processing timeout',
                        'processing_started_at' => null,
                    ], ['id' => $chunk->id]);

                    // Schedule for immediate processing
                    if (function_exists('as_schedule_single_action')) {
                        $alreadyScheduled = function_exists('as_has_scheduled_action')
                            ? as_has_scheduled_action('process_import_chunk', [$chunk->id, $force_update])
                            : false;

                        if (!$alreadyScheduled) {
                            as_schedule_single_action(
                                time(),
                                'process_import_chunk',
                                [$chunk->id, $force_update]
                            );
                        }
                    }
                } else {
                    // Max retries reached, mark as permanently failed
                    $wpdb->update($importChunks, [
                        'processed' => 3,
                        'error_message' => 'Max retries reached - chunk stale timeout'
                    ], ['id' => $chunk->id]);
                }
            }
        }
    }

    /**
     * Get PHP memory limit in bytes
     */
    private function getMemoryLimit(): int
    {
        $memory_limit = ini_get('memory_limit');

        if ($memory_limit === '-1') {
            // Unlimited memory - use a reasonable default (512MB)
            return 512 * 1024 * 1024;
        }

        // Convert string like "256M" to bytes
        $value = (int) $memory_limit;
        $unit = strtoupper(substr($memory_limit, -1));

        switch ($unit) {
            case 'G':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'M':
                $value *= 1024 * 1024;
                break;
            case 'K':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Reschedule chunk with remaining contacts when memory limit is approached
     */
    private function reschedulePartialChunk($chunk_id, $batch_id, $contacts, $start_index, $forceUpdate): void
    {
        global $wpdb;
        $importChunks = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);

        // Get remaining contacts
        $remaining_contacts = array_slice($contacts, $start_index);

        if (empty($remaining_contacts)) {
            // No remaining contacts, mark chunk as completed
            $wpdb->update($importChunks, [
                'processed' => 1,
                'processing_completed_at' => current_time('mysql')
            ], ['id' => $chunk_id]);
            return;
        }

        // Update current chunk with remaining contacts
        $wpdb->update($importChunks, [
            'chunk_data' => json_encode($remaining_contacts),
            'processed' => 0, // Mark as pending for rescheduling
            'processing_started_at' => null
        ], ['id' => $chunk_id]);

        // Schedule this chunk for immediate processing
        if (function_exists('as_schedule_single_action')) {
            $alreadyScheduled = function_exists('as_has_scheduled_action')
                ? as_has_scheduled_action('process_import_chunk', [$chunk_id, $forceUpdate])
                : false;

            if (!$alreadyScheduled) {
                // Schedule with a slight delay to allow memory to be freed
                as_schedule_single_action(
                    time() + 30, // 30 second delay
                    'process_import_chunk',
                    [$chunk_id, $forceUpdate]
                );
            }
        }
    }

    private function lifecycleFieldsForStatus(string $status, string $now): array
    {
        if (!$this->contactHasLifecycleColumns()) {
            return [];
        }

        if ($status === 'subscribed') {
            return [
                'last_subscribed_at' => $now,
                'inactivated_at' => null,
                'inactivation_reason' => null,
            ];
        }

        if ($status === InactiveContactManager::STATUS_INACTIVE) {
            return [
                'inactivated_at' => $now,
                'inactivation_reason' => 'manual',
            ];
        }

        if ($status !== '') {
            return [
                'inactivated_at' => null,
                'inactivation_reason' => null,
            ];
        }

        return [];
    }

    private function contactHasLifecycleColumns(): bool
    {
        static $hasColumns = null;
        if ($hasColumns !== null) {
            return $hasColumns;
        }

        global $wpdb;
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . Tables::get(Tables::MAILERPRESS_CONTACT), 0) ?: [];
        $required = ['last_subscribed_at', 'inactivated_at', 'inactivation_reason'];

        $hasColumns = count(array_intersect($required, $columns)) === count($required);

        return $hasColumns;
    }

    private function truncateLogMessage(string $message, int $length = 255): string
    {
        if (strlen($message) <= $length) {
            return $message;
        }

        return substr($message, 0, max(0, $length - 3)) . '...';
    }
}
