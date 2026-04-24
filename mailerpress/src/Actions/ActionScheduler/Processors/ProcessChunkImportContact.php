<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\CustomFields;

class ProcessChunkImportContact
{
    #[Action('process_import_chunk', priority: 10, acceptedArgs: 2)]
    public function processImportChunk($chunk_id, $forceUpdate): void
    {
        global $wpdb;
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
            $contactLists = json_decode($batch['lists'], true);
            $contact_status = $batch['subscription_status'];
            $contacts = json_decode($chunk->chunk_data, true);

            // Validate contacts array
            if (!is_array($contacts)) {
                // Mark chunk as failed - invalid data
                $wpdb->update($importChunks, [
                    'processed' => 3,
                    'error_message' => 'Invalid contacts data - not an array'
                ], ['id' => $chunk_id]);
                $this->scheduleNextChunk($chunk->batch_id);
                return;
            }

            // Count total contacts in this chunk for tracking
            $total_contacts_in_chunk = count($contacts);
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
                // Validate email before processing
                if (empty($contact['email']) || !is_email($contact['email'])) {
                    // Skip invalid emails but still count them as processed
                    $processed_contacts++;
                    continue;
                }

                $contact_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT contact_id FROM {$contactTable} WHERE email = %s LIMIT 1",
                        $contact['email']
                    )
                );

                if (null === $contact_id) {
                    // Insert new contact
                    // Extract and clean first_name and last_name
                    // Handle both array_key_exists and isset to catch empty strings
                    $first_name = '';
                    $last_name = '';

                    if (array_key_exists('first_name', $contact)) {
                        $first_name = is_string($contact['first_name']) ? trim($contact['first_name'], ' "\'') : '';
                    }

                    if (array_key_exists('last_name', $contact)) {
                        $last_name = is_string($contact['last_name']) ? trim($contact['last_name'], ' "\'') : '';
                    }

                    $contact_data = [
                        'email' => $contact['email'],
                        'first_name' => sanitize_text_field($first_name),
                        'last_name' => sanitize_text_field($last_name),
                        'subscription_status' => $contact_status ?? 'pending',
                        'unsubscribe_token' => wp_generate_uuid4(),
                        'created_at' => $current_time,
                        'updated_at' => $current_time,
                        'opt_in_source' => 'batch_import_file',
                        'access_token' => bin2hex(random_bytes(32))
                    ];

                    $result = $wpdb->insert($contactTable, $contact_data);

                    if (false !== $result) {
                        $contactId = $wpdb->insert_id;

                        // Insert tags
                        foreach ($contactTags as $tag) {
                            $wpdb->insert(Tables::get(Tables::CONTACT_TAGS), [
                                'contact_id' => $contactId,
                                'tag_id' => $tag['id'],
                            ]);
                        }

                        // Insert lists
                        foreach ($contactLists as $list) {
                            $wpdb->insert(Tables::get(Tables::MAILERPRESS_CONTACT_LIST), [
                                'contact_id' => $contactId,
                                'list_id' => $list['id'],
                            ]);
                        }

                        // Insert custom fields - skip standard fields that shouldn't be in custom_fields
                        if (!empty($contact['custom_fields']) && is_array($contact['custom_fields'])) {
                            $standardFields = ['email', 'first_name', 'last_name', 'created_at', 'updated_at'];
                            foreach ($contact['custom_fields'] as $field_key => $field_value) {
                                // Skip if this is a standard field (shouldn't be in custom_fields)
                                if (in_array($field_key, $standardFields, true)) {
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

                                $wpdb->insert(Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS), [
                                    'contact_id' => $contactId,
                                    'field_key' => sanitize_text_field($field_key),
                                    'field_value' => $db_value,
                                ]);

                                // Déclencher l'action pour notifier que le champ a été ajouté
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
                        // Extract and clean first_name and last_name
                        // Handle both array_key_exists and isset to catch empty strings
                        $first_name = '';
                        $last_name = '';

                        if (array_key_exists('first_name', $contact)) {
                            $first_name = is_string($contact['first_name']) ? trim($contact['first_name'], ' "\'') : '';
                        }

                        if (array_key_exists('last_name', $contact)) {
                            $last_name = is_string($contact['last_name']) ? trim($contact['last_name'], ' "\'') : '';
                        }

                        $result = $wpdb->update(
                            $contactTable,
                            [
                                'subscription_status' => $contact_status,
                                'updated_at' => $current_time,
                                'first_name' => sanitize_text_field($first_name),
                                'last_name' => sanitize_text_field($last_name),
                            ],
                            ['contact_id' => $contact_id]
                        );

                        if (false !== $result) {
                            // Insert custom fields for existing contact (update if exists)
                            if (!empty($contact['custom_fields']) && is_array($contact['custom_fields'])) {
                                $standardFields = ['email', 'first_name', 'last_name', 'created_at', 'updated_at'];
                                foreach ($contact['custom_fields'] as $field_key => $field_value) {
                                    // Skip if this is a standard field (shouldn't be in custom_fields)
                                    if (in_array($field_key, $standardFields, true)) {
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
        } catch (\Exception $e) {
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
                    'error_message' => substr($e->getMessage(), 0, 255)
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
                    'error_message' => 'Max retries reached: ' . substr($e->getMessage(), 0, 200)
                ], ['id' => $chunk_id]);
            }

            // Schedule next chunks anyway to prevent entire batch from stalling
            if ($chunk_info && isset($chunk_info->batch_id)) {
                $this->scheduleNextChunk($chunk_info->batch_id);
            }
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
                    $alreadyScheduled = as_has_scheduled_action('process_import_chunk', [$chunk->id, false]);

                    if (!$alreadyScheduled && function_exists('as_schedule_single_action')) {
                        // Schedule with staggered delay
                        as_schedule_single_action(
                            time() + (int)($scheduled_count * $stagger_delay),
                            'process_import_chunk',
                            [$chunk->id, false]
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
                            ? as_has_scheduled_action('process_import_chunk', [$chunk->id, false])
                            : false;

                        if (!$alreadyScheduled) {
                            as_schedule_single_action(
                                time(),
                                'process_import_chunk',
                                [$chunk->id, false]
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
}
