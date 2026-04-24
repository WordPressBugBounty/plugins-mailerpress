<?php

declare(strict_types=1);

namespace MailerPress\Api;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;

\defined('ABSPATH') || exit;

class ActionSchedulerDiagnostic
{
    /**
     * Get diagnostic information about Action Scheduler and pending campaigns
     */
    #[Endpoint(
        'diagnostic/action-scheduler',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManage'],
    )]
    public function getDiagnostics(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $diagnostics = [
            'action_scheduler' => $this->getActionSchedulerStatus(),
            'pending_campaigns' => $this->getPendingCampaigns(),
            'pending_batches' => $this->getPendingBatches(),
            'scheduled_actions' => $this->getMailerPressScheduledActions(),
            'wp_cron' => $this->getWpCronStatus(),
            'recommendations' => [],
        ];

        // Add recommendations based on findings
        if (!$diagnostics['action_scheduler']['is_available']) {
            $diagnostics['recommendations'][] = 'Action Scheduler is not available. Make sure WooCommerce or Action Scheduler plugin is installed.';
        }

        if ($diagnostics['wp_cron']['disabled']) {
            $diagnostics['recommendations'][] = 'WP-Cron is disabled. Action Scheduler relies on WP-Cron. Consider enabling it or setting up a system cron.';
        }

        if (count($diagnostics['scheduled_actions']['pending']) > 0 && $diagnostics['scheduled_actions']['stuck_count'] > 0) {
            $diagnostics['recommendations'][] = sprintf(
                '%d actions appear to be stuck (scheduled more than 5 minutes ago). Try running: wp action-scheduler run',
                $diagnostics['scheduled_actions']['stuck_count']
            );
        }

        return new \WP_REST_Response($diagnostics, 200);
    }

    /**
     * Force run pending Action Scheduler actions for MailerPress
     */
    #[Endpoint(
        'diagnostic/action-scheduler/run',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManage'],
    )]
    public function forceRunActions(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return new \WP_Error(
                'action_scheduler_not_available',
                __('Action Scheduler is not available', 'mailerpress'),
                ['status' => 500]
            );
        }

        $processed = 0;
        $errors = [];

        // Get pending MailerPress actions
        $actions = as_get_scheduled_actions([
            'group' => 'mailerpress',
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 50,
            'date' => as_get_datetime_object(), // Actions scheduled before now
            'date_compare' => '<=',
        ]);

        foreach ($actions as $action_id => $action) {
            try {
                // Get the store and runner
                $store = \ActionScheduler::store();
                $runner = new \ActionScheduler_QueueRunner($store);

                // Process this action
                $store->mark_complete($action_id);

                // Execute the action
                $hook = $action->get_hook();
                $args = $action->get_args();

                do_action_ref_array($hook, $args);

                $processed++;
            } catch (\Exception $e) {
                $errors[] = [
                    'action_id' => $action_id,
                    'hook' => $action->get_hook(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return new \WP_REST_Response([
            'processed' => $processed,
            'errors' => $errors,
            'message' => sprintf(__('%d actions processed', 'mailerpress'), $processed),
        ], 200);
    }

    /**
     * Cancel a specific batch for automated campaigns
     */
    #[Endpoint(
        'diagnostic/batch/cancel',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function cancelBatch(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $batch_id = (int) $request->get_param('batch_id');

        if (!$batch_id) {
            return new \WP_Error('missing_batch_id', __('Batch ID is required', 'mailerpress'), ['status' => 400]);
        }

        $tableBatches = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $tableChunks = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        try {
            // Get batch info
            $batch = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tableBatches} WHERE id = %d",
                $batch_id
            ));

            if (!$batch) {
                return new \WP_Error('batch_not_found', __('Batch not found', 'mailerpress'), ['status' => 404]);
            }

            // Only allow cancellation of batches that are in_progress or pending
            if (!in_array($batch->status, ['in_progress', 'pending'])) {
                return new \WP_Error(
                    'batch_not_cancellable',
                    __('This batch cannot be cancelled (status: ' . $batch->status . ')', 'mailerpress'),
                    ['status' => 400]
                );
            }

            $chunks_deleted = 0;

            // Delete chunks for this batch to stop further processing
            $chunks_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tableChunks} WHERE batch_id = %d",
                $batch_id
            ));

            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$tableChunks} WHERE batch_id = %d",
                $batch_id
            ));

            $chunks_deleted = (int) $chunks_count;

            // Update batch status to 'sent' to stop further processing
            // Keep statistics for emails that were already sent
            $wpdb->update(
                $tableBatches,
                [
                    'status' => 'sent',
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $batch_id],
                ['%s', '%s'],
                ['%d']
            );

            $message = $batch->sent_emails > 0
                ? sprintf(
                    __('Batch sending has been stopped. %d emails were sent and will remain in statistics.', 'mailerpress'),
                    $batch->sent_emails
                )
                : __('Batch has been cancelled. No emails were sent.', 'mailerpress');

            return new \WP_REST_Response([
                'success' => true,
                'message' => $message,
                'batch_id' => $batch_id,
                'chunks_deleted' => $chunks_deleted,
                'new_status' => 'sent',
                'sent_emails' => $batch->sent_emails,
            ], 200);
        } catch (\Exception $e) {
            return new \WP_Error(
                'cancel_batch_error',
                sprintf(__('An error occurred while canceling the batch: %s', 'mailerpress'), $e->getMessage()),
                ['status' => 500]
            );
        }
    }

    /**
     * Reset a stuck campaign back to draft and clean up its batches
     */
    #[Endpoint(
        'diagnostic/campaign/reset',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function resetCampaign(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaign_id = (int) $request->get_param('campaign_id');

        if (!$campaign_id) {
            return new \WP_Error('missing_campaign_id', __('Campaign ID is required', 'mailerpress'), ['status' => 400]);
        }

        $tableCampaigns = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $tableBatches = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $tableQueue = Tables::get(Tables::MAILERPRESS_EMAIL_QUEUE);
        $tableChunks = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

        try {
            // Initialize counters
            $chunks_deleted = 0;
            $actions_cancelled = 0;

            // Get campaign info
            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tableCampaigns} WHERE campaign_id = %d",
                $campaign_id
            ));

            if (!$campaign) {
                return new \WP_Error('campaign_not_found', __('Campaign not found', 'mailerpress'), ['status' => 404]);
            }

            // Get batch IDs for this campaign
            $batch_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$tableBatches} WHERE campaign_id = %d",
                $campaign_id
            ));

            // NEW: Delete chunks from mailerpress_email_chunks for this campaign's batches
            if (!empty($batch_ids)) {
                $placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));

                // Count chunks before deletion for reporting
                $chunks_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tableChunks} WHERE batch_id IN ($placeholders)",
                    ...$batch_ids
                ));

                // Delete all chunks (pending, processing, etc.) for this campaign
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$tableChunks} WHERE batch_id IN ($placeholders)",
                    ...$batch_ids
                ));

                $chunks_deleted = (int) $chunks_count;

                // Note: Transients for deleted chunks will be cleaned up by Cleanup cron
            }

            // Cancel and delete scheduled Action Scheduler actions (legacy compatibility)
            // Note: With the new ChunkWorker system, there are no individual chunk actions anymore,
            // but we keep this for backwards compatibility with campaigns created before the migration
            if (function_exists('as_get_scheduled_actions')) {
                $store = \ActionScheduler_Store::instance();

                foreach ($batch_ids as $batch_id) {
                    // Check for old individual chunk actions (legacy system)
                    $all_actions = as_get_scheduled_actions([
                        'hook' => 'mailerpress_process_contact_chunk',
                        'status' => \ActionScheduler_Store::STATUS_PENDING,
                        'per_page' => 1000,
                    ]);

                    foreach ($all_actions as $action_id => $action) {
                        $args = $action->get_args();
                        if (!empty($args[0]) && (int)$args[0] === (int)$batch_id) {
                            try {
                                $store->cancel_action($action_id);
                                $store->delete_action($action_id);
                                $actions_cancelled++;
                            } catch (\Exception $e) {
                                // Continue even if cancel/delete fails
                            }
                        }
                    }

                    // Also cancel process_email_batch actions (legacy)
                    $actions = as_get_scheduled_actions([
                        'hook' => 'process_email_batch',
                        'args' => [$batch_id],
                        'status' => \ActionScheduler_Store::STATUS_PENDING,
                    ]);

                    foreach ($actions as $action_id => $action) {
                        try {
                            $store->cancel_action($action_id);
                            $store->delete_action($action_id);
                            $actions_cancelled++;
                        } catch (\Exception $e) {
                            // Continue even if cancel/delete fails
                        }
                    }
                }

                // IMPORTANT: Cancel mailerpress_batch_email action for this campaign
                // This is the action that creates the batch and chunks for scheduled campaigns
                // Search in PENDING (future scheduled actions are PENDING until execution time)
                $batch_email_actions = as_get_scheduled_actions([
                    'hook' => 'mailerpress_batch_email',
                    'status' => \ActionScheduler_Store::STATUS_PENDING,
                    'per_page' => 500, // Increased to catch more actions
                    'group' => 'mailerpress',
                ]);

                foreach ($batch_email_actions as $action_id => $action) {
                    $args = $action->get_args();
                    // args[1] contains the campaign_id ($post parameter from createBatchV2)
                    if (!empty($args[1]) && (int)$args[1] === $campaign_id) {
                        try {
                            // Cancel first to prevent execution
                            $store->cancel_action($action_id);
                            // Then delete to clean database
                            $store->delete_action($action_id);
                            $actions_cancelled++;
                        } catch (\Exception $e) {
                            error_log(sprintf(
                                '[Cancel Sending] Failed to cancel action #%d: %s',
                                $action_id,
                                $e->getMessage()
                            ));
                        }
                    }
                }
            }

            // Determine final status based on current campaign status
            // If campaign is in_progress, set it to 'sent' (emails were already sent)
            // Otherwise, reset to 'draft'
            $finalStatus = 'draft';
            $shouldDeleteBatches = false;

            if ($campaign && $campaign->status === 'in_progress') {
                $finalStatus = 'sent';
                // Don't delete batches if campaign is in_progress - keep stats
                $shouldDeleteBatches = false;
            } else {
                // Only delete batches if campaign is pending (not started yet)
                $shouldDeleteBatches = ($campaign && $campaign->status === 'pending');
            }

            // Delete email queue entries for this campaign's batches only if we're deleting batches
            if ($shouldDeleteBatches && !empty($batch_ids)) {
                $placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$tableQueue} WHERE batch_id IN ($placeholders)",
                    ...$batch_ids
                ));
            }

            // Delete batches only if campaign is pending (not started)
            // Keep batches for in_progress campaigns to preserve statistics
            if ($shouldDeleteBatches) {
                $wpdb->delete($tableBatches, ['campaign_id' => $campaign_id], ['%d']);
            }

            // Get the batch_id to preserve it if campaign was in_progress
            $preserveBatchId = null;
            if ($campaign && $campaign->status === 'in_progress' && !empty($campaign->batch_id)) {
                // Keep the batch_id to preserve statistics
                $preserveBatchId = $campaign->batch_id;
            }

            // Update campaign status
            // Preserve batch_id if campaign was in_progress to keep statistics accessible
            $updateData = [
                'status' => $finalStatus,
                'updated_at' => current_time('mysql'),
            ];

            // Only set batch_id to null if we're deleting batches (pending status)
            if ($shouldDeleteBatches) {
                $updateData['batch_id'] = null;
            } else if ($preserveBatchId) {
                // Keep the existing batch_id for statistics
                $updateData['batch_id'] = $preserveBatchId;
            }

            $wpdb->update(
                $tableCampaigns,
                $updateData,
                ['campaign_id' => $campaign_id],
                $shouldDeleteBatches ? ['%s', null, '%s'] : ['%s', '%d', '%s'],
                ['%d']
            );

            $message = __('Campaign sending has been canceled.', 'mailerpress');

            return new \WP_REST_Response([
                'success' => true,
                'message' => $message,
                'campaign_id' => $campaign_id,
                'batches_deleted' => $shouldDeleteBatches ? count($batch_ids) : 0,
                'chunks_deleted' => $chunks_deleted,
                'actions_cancelled' => $actions_cancelled ?? 0,
                'final_status' => $finalStatus,
                'batches_preserved' => !$shouldDeleteBatches,
            ], 200);
        } catch (\Exception $e) {
            return new \WP_Error(
                'reset_campaign_error',
                sprintf(__('An error occurred while canceling the sending: %s', 'mailerpress'), $e->getMessage()),
                ['status' => 500]
            );
        }
    }

    private function getActionSchedulerStatus(): array
    {
        $status = [
            'is_available' => function_exists('as_get_scheduled_actions'),
            'version' => null,
            'data_store' => null,
        ];

        if (class_exists('ActionScheduler_Versions')) {
            $status['version'] = \ActionScheduler_Versions::instance()->latest_version();
        }

        if (class_exists('ActionScheduler')) {
            $store = \ActionScheduler::store();
            $status['data_store'] = get_class($store);
        }

        return $status;
    }

    private function getPendingCampaigns(): array
    {
        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        return $wpdb->get_results(
            "SELECT campaign_id, name, status, batch_id, created_at, updated_at 
             FROM {$table} 
             WHERE status IN ('pending', 'in_progress', 'scheduled') 
             ORDER BY updated_at DESC 
             LIMIT 20"
        );
    }

    private function getPendingBatches(): array
    {
        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

        return $wpdb->get_results(
            "SELECT id, campaign_id, status, total_emails, sent_emails, error_emails, scheduled_at, created_at 
             FROM {$table} 
             WHERE status IN ('pending', 'in_progress', 'scheduled') 
             ORDER BY created_at DESC 
             LIMIT 20"
        );
    }

    private function getMailerPressScheduledActions(): array
    {
        global $wpdb;

        $result = [
            'pending' => [],
            'failed' => [],
            'stuck_count' => 0,
        ];

        if (!function_exists('as_get_scheduled_actions')) {
            return $result;
        }

        // Get pending actions
        $pending_actions = as_get_scheduled_actions([
            'group' => 'mailerpress',
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 50,
        ]);

        $five_minutes_ago = time() - (5 * MINUTE_IN_SECONDS);

        foreach ($pending_actions as $action_id => $action) {
            $scheduled = $action->get_schedule()->get_date();
            $scheduled_timestamp = $scheduled ? $scheduled->getTimestamp() : 0;

            $is_stuck = $scheduled_timestamp < $five_minutes_ago;
            if ($is_stuck) {
                $result['stuck_count']++;
            }

            $result['pending'][] = [
                'id' => $action_id,
                'hook' => $action->get_hook(),
                'scheduled_at' => $scheduled ? $scheduled->format('Y-m-d H:i:s') : null,
                'args' => $action->get_args(),
                'is_stuck' => $is_stuck,
            ];
        }

        // Get failed actions
        $failed_actions = as_get_scheduled_actions([
            'group' => 'mailerpress',
            'status' => \ActionScheduler_Store::STATUS_FAILED,
            'per_page' => 20,
        ]);

        foreach ($failed_actions as $action_id => $action) {
            $result['failed'][] = [
                'id' => $action_id,
                'hook' => $action->get_hook(),
                'args' => $action->get_args(),
            ];
        }

        return $result;
    }

    private function getWpCronStatus(): array
    {
        return [
            'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'alternate_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'doing_cron' => defined('DOING_CRON') && DOING_CRON,
        ];
    }
}
