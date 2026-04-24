<?php

namespace MailerPress\Actions\ActionScheduler;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class AsCleaner
{
    #[Action('mailerpress_as_clean')]
    public function clean(): void
    {
        $this->cleanEntries('daemon/mailerpress/mailerpress_sync_lists', 15 * DAY_IN_SECONDS);
        $this->cleanEntries('daemon/mailerpress/process_queue_worker', 15 * DAY_IN_SECONDS);
        $this->cleanEntries('daemon/mailerpress/batch', 15 * DAY_IN_SECONDS);
        $this->cleanEntries('mailerpress', 15 * DAY_IN_SECONDS);
        $this->cleanEntries('mailerpress_workflows', 15 * DAY_IN_SECONDS);
    }

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


        // time() returns the current Unix timestamp (GMT)
        $time_to_check = time() - $seconds; // Current time minus number of seconds


        // @link https://actionscheduler.org/api/
        $args = array(
            'group' => $group,
            'per_page' => -1,  // default is 5; -1 for all results
        );

        // Action Scheduler function that returns an array of ids
        $actions_to_delete = as_get_scheduled_actions($args, 'ids');
        // Cast all IDs to int for extra safety
        $actions_to_delete = array_map('intval', $actions_to_delete);
        // Need to implode the array to use with SQL
        $actions_to_delete = implode(', ', $actions_to_delete);

        if (empty($actions_to_delete)) {
            return;
        }

        global $wpdb;

        $sql_actions = "DELETE FROM $wpdb->actionscheduler_actions
					WHERE status
					IN ( 'complete','failed','canceled' )
					AND action_id
					IN ( $actions_to_delete )";

        $wpdb->query($sql_actions);

        $sql_logs = "DELETE FROM $wpdb->actionscheduler_logs
				 WHERE action_id
				 IN ( $actions_to_delete )";

        $wpdb->query($sql_logs);
    }
}