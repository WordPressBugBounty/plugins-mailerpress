<?php

namespace MailerPress\Actions\ActionScheduler;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class AsCleaner
{
    private const FINISHED_STATUSES = [
        \ActionScheduler_Store::STATUS_COMPLETE,
        \ActionScheduler_Store::STATUS_FAILED,
        \ActionScheduler_Store::STATUS_CANCELED,
    ];

    private const BATCH_SIZE = 500;
    private const MAX_BATCHES = 20;

    #[Action('mailerpress_as_clean')]
    public function clean(): void
    {
        $this->cleanEntries('daemon/mailerpress/mailerpress_sync_lists', 3 * DAY_IN_SECONDS);
        $this->cleanEntries('daemon/mailerpress/process_queue_worker', 3 * DAY_IN_SECONDS);
        $this->cleanEntries('daemon/mailerpress/batch', 3 * DAY_IN_SECONDS);
        $this->cleanEntries('mailerpress', 3 * DAY_IN_SECONDS);
        $this->cleanEntries('mailerpress_workflows', 3 * DAY_IN_SECONDS);
    }

    /**
     * Delete finished actions (and their logs) of a group last attempted more than $seconds ago.
     *
     * Rows must be old enough: deleting a freshly completed recurring action races with the
     * queue runner, which reads its status in schedule_next_instance() outside any try/catch
     * and dies with "Invalid action ID. No status found." if the row is gone.
     */
    private function cleanEntries(string $group, int $seconds = 86400): void
    {
        // If there's no group specified, we're done.
        if (empty($group)) {
            return;
        }

        // If for some reason Action Scheduler is not in use, do not proceed.
        if (!function_exists('as_get_scheduled_actions')) {
            return;
        }

        global $wpdb;

        $cutoff = time() - $seconds;

        foreach (self::FINISHED_STATUSES as $status) {
            for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
                // 'modified' filters on last_attempt_gmt, like Action Scheduler's own QueueCleaner.
                $ids = as_get_scheduled_actions([
                    'group'            => $group,
                    'status'           => $status,
                    'modified'         => $cutoff,
                    'modified_compare' => '<=',
                    'per_page'         => self::BATCH_SIZE,
                    'orderby'          => 'none',
                ], 'ids');

                $ids = array_filter(array_map('intval', $ids));
                if (empty($ids)) {
                    break;
                }

                $idList = implode(', ', $ids);

                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->actionscheduler_actions} WHERE status = %s AND action_id IN ( {$idList} )",
                    $status
                ));

                $wpdb->query(
                    "DELETE FROM {$wpdb->actionscheduler_logs} WHERE action_id IN ( {$idList} )"
                );

                if (count($ids) < self::BATCH_SIZE) {
                    break;
                }
            }
        }
    }
}
