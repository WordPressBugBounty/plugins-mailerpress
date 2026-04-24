<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;

class ProcessChunkDeleteContact
{
    #[Action('process_delete_chunk', priority: 10, acceptedArgs: 1)]
    public function processDeleteChunk($chunk_id): void
    {
        global $wpdb;
        $importChunks = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contactBatch = Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES);
        $contactListTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        $contactTagsTable = Tables::get(Tables::CONTACT_TAGS);
        $contactNoteTable = Tables::get(Tables::MAILERPRESS_CONTACT_NOTE);
        $contactCustomFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);

        try {
            // Use atomic UPDATE to claim this chunk for processing (prevents race conditions)
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

            $contact_ids = json_decode($chunk->chunk_data, true);

            // Validate contact_ids array
            if (!is_array($contact_ids) || empty($contact_ids)) {
                // Mark chunk as failed - invalid data
                $wpdb->update($importChunks, [
                    'processed' => 3,
                    'error_message' => 'Invalid contact IDs data - not an array or empty'
                ], ['id' => $chunk_id]);
                $this->scheduleNextChunk($chunk->batch_id);
                return;
            }

            // Count total contacts in this chunk for tracking
            $total_contacts_in_chunk = count($contact_ids);
            $deleted_count = 0;

            // Prepare placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($contact_ids), '%d'));

            // Fetch contact data before deletion (for webhook)
            $contacts_data = $wpdb->get_results($wpdb->prepare(
                "SELECT contact_id, email, first_name, last_name FROM {$contactTable} WHERE contact_id IN ({$placeholders})",
                ...$contact_ids
            ));

            // Delete related data first (foreign key constraints)
            // Delete contact lists
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$contactListTable} WHERE contact_id IN ({$placeholders})",
                ...$contact_ids
            ));

            // Delete contact tags
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$contactTagsTable} WHERE contact_id IN ({$placeholders})",
                ...$contact_ids
            ));

            // Delete contact notes
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$contactNoteTable} WHERE contact_id IN ({$placeholders})",
                ...$contact_ids
            ));

            // Delete contact custom fields
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$contactCustomFieldsTable} WHERE contact_id IN ({$placeholders})",
                ...$contact_ids
            ));

            // Delete contacts
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$contactTable} WHERE contact_id IN ({$placeholders})",
                ...$contact_ids
            ));

            if ($deleted !== false) {
                $deleted_count = $deleted;

                // Fire webhook for each deleted contact
                if (!empty($contacts_data)) {
                    foreach ($contacts_data as $contact) {
                        do_action('mailerpress_contact_deleted', (int) $contact->contact_id, $contact->email ?? '', $contact->first_name ?? '', $contact->last_name ?? '');
                    }
                }
            }

            // Mark chunk as completed
            $wpdb->update($importChunks, [
                'processed' => 1, // 1 = completed
                'processing_completed_at' => current_time('mysql'),
            ], ['id' => $chunk_id]);

            // Update batch processed count
            $wpdb->query($wpdb->prepare("
                UPDATE {$contactBatch}
                SET processed_count = processed_count + %d,
                    updated_at = NOW()
                WHERE batch_id = %d
            ", $deleted_count, $chunk->batch_id));

            // Check if batch is complete
            $remaining_chunks = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$importChunks}
                WHERE batch_id = %d AND processed IN (0, 2, 3)
            ", $chunk->batch_id));

            if ($remaining_chunks == 0) {
                // All chunks processed, mark batch as done
                $wpdb->update($contactBatch, [
                    'status' => 'done',
                    'updated_at' => current_time('mysql'),
                ], ['batch_id' => $chunk->batch_id]);
            }

            // Schedule next chunk
            $this->scheduleNextChunk($chunk->batch_id);

        } catch (\Exception $e) {
            // Mark chunk as failed
            $wpdb->update($importChunks, [
                'processed' => 3,
                'error_message' => $e->getMessage(),
            ], ['id' => $chunk_id]);

            // Still try to schedule next chunk
            if (isset($chunk)) {
                $this->scheduleNextChunk($chunk->batch_id);
            }
        }
    }

    /**
     * Schedule the next pending chunk for this batch
     */
    private function scheduleNextChunk($batch_id): void
    {
        global $wpdb;
        $importChunks = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);

        // Number of chunks to schedule in parallel - filterable for performance tuning
        $parallel_chunks = apply_filters('mailerpress_delete_parallel_chunks', 10);
        $parallel_chunks = max(5, min(50, $parallel_chunks));

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
            $stagger_delay = apply_filters('mailerpress_delete_chunk_stagger_delay', 0.5);

            foreach ($nextChunks as $chunk) {
                // Check if action is already scheduled
                if (function_exists('as_has_scheduled_action')) {
                    $alreadyScheduled = as_has_scheduled_action('process_delete_chunk', [$chunk->id]);

                    if (!$alreadyScheduled && function_exists('as_schedule_single_action')) {
                        // Schedule with staggered delay
                        as_schedule_single_action(
                            time() + (int)($scheduled_count * $stagger_delay),
                            'process_delete_chunk',
                            [$chunk->id],
                            'mailerpress'
                        );
                        $scheduled_count++;
                    }
                }
            }
        }
    }
}
