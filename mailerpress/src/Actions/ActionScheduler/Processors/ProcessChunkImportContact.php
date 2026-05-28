<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\CustomFields;
use MailerPress\Services\Logger;

class ProcessChunkImportContact
{
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
                $this->logImportEvent('debug', 'Import chunk was not claimed for processing.', [
                    'chunk_id' => $chunk_id,
                    'claimed_result' => $claimed,
                ]);
                return;
            }

            // Now fetch the chunk data
            $chunk = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$importChunks}
                WHERE id = %d
            ", $chunk_id));

            if (!$chunk) {
                // Chunk doesn't exist
                $this->logImportEvent('warning', 'Import chunk was claimed but could not be loaded.', [
                    'chunk_id' => $chunk_id,
                ]);
                return;
            }

            $this->logImportEvent('info', 'Import chunk processing started.', [
                'batch_id' => (int) $chunk->batch_id,
                'chunk_id' => $chunk_id,
                'force_update' => $forceUpdate,
                'memory_usage' => memory_get_usage(),
            ]);

            $batch = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$contactBatch} WHERE batch_id = %d", $chunk->batch_id),
                ARRAY_A
            );

            if (!$batch) {
                // Mark chunk as failed with error message
                $this->logImportEvent('error', 'Import chunk failed because the parent batch was not found.', [
                    'batch_id' => (int) $chunk->batch_id,
                    'chunk_id' => $chunk_id,
                ]);
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
                $this->logImportEvent('warning', 'Import batch tags payload is invalid; continuing without tags.', [
                    'batch_id' => (int) $chunk->batch_id,
                    'chunk_id' => $chunk_id,
                    'json_error' => $tags_json_error,
                ]);
                $contactTags = [];
            }

            if (!is_array($contactLists)) {
                $this->logImportEvent('warning', 'Import batch lists payload is invalid; continuing without lists.', [
                    'batch_id' => (int) $chunk->batch_id,
                    'chunk_id' => $chunk_id,
                    'json_error' => $lists_json_error,
                ]);
                $contactLists = [];
            }

            // Validate contacts array
            if (!is_array($contacts)) {
                // Mark chunk as failed - invalid data
                $this->logImportEvent('error', 'Import chunk contains invalid contacts data.', [
                    'batch_id' => (int) $chunk->batch_id,
                    'chunk_id' => $chunk_id,
                    'json_error' => $contacts_json_error,
                    'payload_size' => is_string($chunk->chunk_data) ? strlen($chunk->chunk_data) : 0,
                ]);
                $wpdb->update($importChunks, [
                    'processed' => 3,
                    'error_message' => $this->truncateLogMessage('Invalid contacts data: ' . $contacts_json_error)
                ], ['id' => $chunk_id]);
                $this->scheduleNextChunk($chunk->batch_id);
                return;
            }

            // Count total contacts in this chunk for tracking
            $total_contacts_in_chunk = count($contacts);
            $processed_contacts = 0;
            $created_contacts = 0;
            $updated_contacts = 0;
            $existing_contacts = 0;
            $invalid_contacts = 0;
            $failed_contacts = 0;
            $chunk_contact_offset = $this->getChunkContactOffset($importChunks, (int) $chunk->batch_id, $chunk_id);

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
                        $this->logImportEvent('warning', 'Import chunk is being rescheduled because memory usage is close to the PHP limit.', [
                            'batch_id' => (int) $chunk->batch_id,
                            'chunk_id' => $chunk_id,
                            'processed_contacts' => $processed_contacts,
                            'chunk_row' => $index + 1,
                            'estimated_csv_line' => $chunk_contact_offset + $index + 2,
                            'current_memory' => $current_memory,
                            'memory_limit' => $memory_limit,
                        ]);

                        // Save progress and reschedule chunk with remaining contacts
                        $this->reschedulePartialChunk($chunk_id, $chunk->batch_id, $contacts, $index, $forceUpdate);

                        // Update processed count for contacts we did complete
                        if ($processed_contacts > 0) {
                            $count_updated = $wpdb->query(
                                $wpdb->prepare(
                                    "UPDATE {$contactBatch} SET processed_count = processed_count + %d WHERE batch_id = %d",
                                    $processed_contacts,
                                    $chunk->batch_id
                                )
                            );

                            if (false === $count_updated) {
                                $this->logImportEvent('error', 'Failed to update import batch processed count before rescheduling.', [
                                    'batch_id' => (int) $chunk->batch_id,
                                    'chunk_id' => $chunk_id,
                                    'processed_contacts' => $processed_contacts,
                                    'db_error' => $wpdb->last_error,
                                ]);
                            }
                        }

                        return;
                    }

                    // Clear WordPress object cache every 50 contacts to prevent buildup
                    if ($index % 50 === 0) {
                        wp_cache_flush();
                    }
                }

                if (!is_array($contact)) {
                    $invalid_contacts++;
                    $processed_contacts++;
                    $this->logImportEvent('warning', 'Skipping malformed import row because it is not an object.', [
                        'batch_id' => (int) $chunk->batch_id,
                        'chunk_id' => $chunk_id,
                        'chunk_row' => $index + 1,
                        'estimated_csv_line' => $chunk_contact_offset + $index + 2,
                        'value_type' => gettype($contact),
                    ]);
                    continue;
                }

                $email = isset($contact['email']) && is_scalar($contact['email'])
                    ? sanitize_email(trim((string) $contact['email'], " \t\n\r\0\x0B\"'"))
                    : '';

                // Validate email before processing
                if (empty($email) || !is_email($email)) {
                    // Skip invalid emails but still count them as processed
                    $invalid_contacts++;
                    $processed_contacts++;
                    $this->logImportEvent('warning', 'Skipping import row because the email is missing or invalid.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                        'email_value' => isset($contact['email']) && is_scalar($contact['email'])
                            ? $this->redactValue((string) $contact['email'])
                            : null,
                    ]));
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
                        $created_contacts++;

                        // Insert tags
                        foreach ($contactTags as $tag) {
                            if (!is_array($tag) || empty($tag['id'])) {
                                $this->logImportEvent('warning', 'Skipping invalid tag relation during contact import.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                                    'contact_id' => (int) $contactId,
                                    'tag' => $tag,
                                ]));
                                continue;
                            }

                            $tag_result = $wpdb->insert(Tables::get(Tables::CONTACT_TAGS), [
                                'contact_id' => $contactId,
                                'tag_id' => $tag['id'],
                            ]);

                            if (false === $tag_result) {
                                $this->logImportEvent('error', 'Failed to attach tag during contact import.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                                    'contact_id' => (int) $contactId,
                                    'tag_id' => (int) $tag['id'],
                                    'db_error' => $wpdb->last_error,
                                ]));
                            }
                        }

                        // Insert lists
                        foreach ($contactLists as $list) {
                            if (!is_array($list) || empty($list['id'])) {
                                $this->logImportEvent('warning', 'Skipping invalid list relation during contact import.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                                    'contact_id' => (int) $contactId,
                                    'list' => $list,
                                ]));
                                continue;
                            }

                            $list_result = $wpdb->insert(Tables::get(Tables::MAILERPRESS_CONTACT_LIST), [
                                'contact_id' => $contactId,
                                'list_id' => $list['id'],
                            ]);

                            if (false === $list_result) {
                                $this->logImportEvent('error', 'Failed to attach list during contact import.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                                    'contact_id' => (int) $contactId,
                                    'list_id' => (int) $list['id'],
                                    'db_error' => $wpdb->last_error,
                                ]));
                            }
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

                                $custom_field_result = $wpdb->insert(Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS), [
                                    'contact_id' => $contactId,
                                    'field_key' => sanitize_text_field($field_key),
                                    'field_value' => $db_value,
                                ]);

                                if (false === $custom_field_result) {
                                    $this->logImportEvent('error', 'Failed to insert custom field during contact import.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                                        'contact_id' => (int) $contactId,
                                        'field_key' => sanitize_text_field($field_key),
                                        'db_error' => $wpdb->last_error,
                                    ]));
                                    continue;
                                }

                                do_action('mailerpress_contact_custom_field_added', $contactId, sanitize_text_field($field_key), $sanitized_value);
                            }
                        }

                        $processed_contacts++;
                    } else {
                        // Insert failed, but still count as processed (attempted)
                        $failed_contacts++;
                        $processed_contacts++;
                        $this->logImportEvent('error', 'Failed to insert contact during import.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                            'db_error' => $wpdb->last_error,
                        ]));
                    }
                } else {
                    // Update existing contact
                    // Always count existing contacts as processed, even if not updated
                    $existing_contacts++;
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
                            $updated_contacts++;
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
                                        $custom_field_result = $wpdb->update(
                                            Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS),
                                            ['field_value' => $db_value],
                                            ['field_id' => $existing]
                                        );
                                    } else {
                                        $custom_field_result = $wpdb->insert(
                                            Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS),
                                            [
                                                'contact_id' => $contact_id,
                                                'field_key' => sanitize_text_field($field_key),
                                                'field_value' => $db_value,
                                            ]
                                        );
                                    }

                                    if (false === $custom_field_result) {
                                        $this->logImportEvent('error', 'Failed to save custom field for existing contact during import.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                                            'contact_id' => (int) $contact_id,
                                            'field_key' => sanitize_text_field($field_key),
                                            'db_error' => $wpdb->last_error,
                                        ]));
                                    }
                                }
                            }
                        } else {
                            $failed_contacts++;
                            $this->logImportEvent('error', 'Failed to update existing contact during import.', $this->getRowLogContext($chunk, $index, $contact, $chunk_contact_offset, [
                                'contact_id' => (int) $contact_id,
                                'db_error' => $wpdb->last_error,
                            ]));
                        }
                    }
                }
            }

            // Update processed_count once for all contacts in this chunk
            // This ensures accurate counting even if some contacts fail to process
            if ($processed_contacts > 0) {
                $count_updated = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$contactBatch} SET processed_count = processed_count + %d WHERE batch_id = %d",
                        $processed_contacts,
                        $chunk->batch_id
                    )
                );

                if (false === $count_updated) {
                    $this->logImportEvent('error', 'Failed to update import batch processed count.', [
                        'batch_id' => (int) $chunk->batch_id,
                        'chunk_id' => $chunk_id,
                        'processed_contacts' => $processed_contacts,
                        'db_error' => $wpdb->last_error,
                    ]);
                }
            }

            // Clear any remaining cached data before marking complete
            wp_cache_flush();

            // Mark the chunk as processed successfully
            $chunk_updated = $wpdb->update($importChunks, [
                'processed' => 1,
                'processing_completed_at' => current_time('mysql')
            ], ['id' => $chunk_id]);

            if (false === $chunk_updated) {
                $this->logImportEvent('error', 'Failed to mark import chunk as completed.', [
                    'batch_id' => (int) $chunk->batch_id,
                    'chunk_id' => $chunk_id,
                    'db_error' => $wpdb->last_error,
                ]);
            }

            $this->logImportEvent(
                ($invalid_contacts > 0 || $failed_contacts > 0) ? 'warning' : 'info',
                'Import chunk processing completed.',
                [
                    'batch_id' => (int) $chunk->batch_id,
                    'chunk_id' => $chunk_id,
                    'total_contacts_in_chunk' => $total_contacts_in_chunk,
                    'processed_contacts' => $processed_contacts,
                    'created_contacts' => $created_contacts,
                    'existing_contacts' => $existing_contacts,
                    'updated_contacts' => $updated_contacts,
                    'invalid_contacts' => $invalid_contacts,
                    'failed_contacts' => $failed_contacts,
                    'memory_usage' => memory_get_usage(),
                    'peak_memory_usage' => memory_get_peak_usage(),
                ]
            );

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
                $this->logImportEvent('info', 'Import batch completed.', [
                    'batch_id' => (int) $chunk->batch_id,
                    'total_contacts' => isset($batch_info['count']) ? (int) $batch_info['count'] : null,
                    'processed_contacts' => isset($batch_info['processed_count']) ? (int) $batch_info['processed_count'] : null,
                ]);
            }
        } catch (\Throwable $e) {
            // Get current retry count
            $chunk_info = $wpdb->get_row($wpdb->prepare(
                "SELECT retry_count, batch_id FROM {$importChunks} WHERE id = %d",
                $chunk_id
            ));

            $retry_count = isset($chunk_info->retry_count) ? (int)$chunk_info->retry_count : 0;
            $this->logImportEvent('error', 'Import chunk processing threw an exception.', [
                'batch_id' => $chunk_info && isset($chunk_info->batch_id) ? (int) $chunk_info->batch_id : null,
                'chunk_id' => $chunk_id,
                'retry_count' => $retry_count,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
            ]);

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
                $this->logImportEvent('warning', 'Import chunk scheduled for retry.', [
                    'batch_id' => $chunk_info && isset($chunk_info->batch_id) ? (int) $chunk_info->batch_id : null,
                    'chunk_id' => $chunk_id,
                    'next_retry_count' => $retry_count + 1,
                    'delay_seconds' => $delay,
                ]);
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
                $this->logImportEvent('error', 'Import chunk permanently failed after reaching the retry limit.', [
                    'batch_id' => $chunk_info && isset($chunk_info->batch_id) ? (int) $chunk_info->batch_id : null,
                    'chunk_id' => $chunk_id,
                    'retry_count' => $retry_count,
                    'max_retries' => $max_retries,
                ]);
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

    private function getChunkContactOffset(string $importChunks, int $batch_id, int $chunk_id): int
    {
        global $wpdb;

        $previous_chunks = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT chunk_data FROM {$importChunks} WHERE batch_id = %d AND id < %d ORDER BY id ASC",
                $batch_id,
                $chunk_id
            )
        );

        if (empty($previous_chunks)) {
            return 0;
        }

        $offset = 0;
        foreach ($previous_chunks as $chunk_data) {
            $decoded = json_decode((string) $chunk_data, true);
            if (is_array($decoded)) {
                $offset += count($decoded);
            }
        }

        return $offset;
    }

    private function getRowLogContext(object $chunk, int $index, array $contact, int $chunk_contact_offset, array $extra = []): array
    {
        $context = [
            'batch_id' => (int) $chunk->batch_id,
            'chunk_id' => (int) $chunk->id,
            'chunk_row' => $index + 1,
            'estimated_csv_line' => $chunk_contact_offset + $index + 2,
            'email' => isset($contact['email']) && is_scalar($contact['email'])
                ? $this->maskEmail((string) $contact['email'])
                : null,
            'fields' => array_values(array_filter(
                array_keys($contact),
                static fn($field) => is_string($field) && !str_starts_with($field, '_')
            )),
        ];

        if (isset($contact['_mailerpress_csv_row']) && is_numeric($contact['_mailerpress_csv_row'])) {
            $context['csv_line'] = (int) $contact['_mailerpress_csv_row'];
        }

        return array_merge($context, $extra);
    }

    private function logImportEvent(string $level, string $message, array $context = []): void
    {
        if (!$this->isImportDebugEnabled()) {
            return;
        }

        $level = strtoupper($level);
        $context = $this->sanitizeLogContext($context);

        try {
            Logger::log($level, '[ContactImport] ' . $message, $context);
        } catch (\Throwable $e) {
            // Logging must never interrupt imports.
        }

        $this->writeImportLogFile($level, $message, $context);

        $write_to_php_error_log = apply_filters('mailerpress_import_log_to_php_error_log', true);
        if (!$write_to_php_error_log) {
            return;
        }

        $encoded_context = function_exists('wp_json_encode')
            ? wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        error_log(sprintf(
            '[MailerPress Contact Import] [%s] %s%s',
            $level,
            $message,
            $encoded_context ? ' | ' . $encoded_context : ''
        ));
    }

    private function isImportDebugEnabled(): bool
    {
        return defined('WP_DEBUG')
            && true === WP_DEBUG
            && defined('WP_DEBUG_LOG')
            && true === WP_DEBUG_LOG;
    }

    private function writeImportLogFile(string $level, string $message, array $context): void
    {
        try {
            $upload_dir = wp_upload_dir();
            $base_dir = empty($upload_dir['basedir']) ? WP_CONTENT_DIR . '/uploads' : $upload_dir['basedir'];
            $log_dir = trailingslashit($base_dir) . 'mailerpress-logs';

            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
            }

            if (!is_dir($log_dir) || !is_writable($log_dir)) {
                return;
            }

            $htaccess_file = trailingslashit($log_dir) . '.htaccess';
            if (!file_exists($htaccess_file)) {
                @file_put_contents($htaccess_file, "deny from all\n");
            }

            $index_file = trailingslashit($log_dir) . 'index.html';
            if (!file_exists($index_file)) {
                @file_put_contents($index_file, '');
            }

            $encoded_context = function_exists('wp_json_encode')
                ? wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $log_entry = sprintf(
                "[%s] [%s] %s%s\n",
                current_time('mysql'),
                $level,
                $message,
                $encoded_context ? ' | ' . $encoded_context : ''
            );

            @file_put_contents(
                trailingslashit($log_dir) . 'contact-import-' . current_time('Y-m-d') . '.log',
                $log_entry,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // Logging must never interrupt imports.
        }
    }

    private function sanitizeLogContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeLogContext($value);
                continue;
            }

            if (is_object($value)) {
                $sanitized[$key] = get_class($value);
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = $this->truncateLogMessage(sanitize_text_field($value), 500);
                continue;
            }

            if (is_scalar($value) || null === $value) {
                $sanitized[$key] = $value;
                continue;
            }

            $sanitized[$key] = gettype($value);
        }

        return $sanitized;
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if (!str_contains($email, '@')) {
            return $this->redactValue($email);
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible_local = substr($local, 0, min(2, strlen($local)));

        return $visible_local . '***@' . $domain;
    }

    private function redactValue(string $value): string
    {
        $value = trim(sanitize_text_field($value));

        if ('' === $value) {
            return '';
        }

        if (str_contains($value, '@')) {
            return $this->maskEmail($value);
        }

        return substr($value, 0, 2) . '***' . (strlen($value) > 8 ? substr($value, -2) : '');
    }

    private function truncateLogMessage(string $message, int $length = 255): string
    {
        if (strlen($message) <= $length) {
            return $message;
        }

        return substr($message, 0, max(0, $length - 3)) . '...';
    }
}
