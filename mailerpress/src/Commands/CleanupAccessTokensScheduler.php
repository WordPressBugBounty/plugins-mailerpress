<?php

declare(strict_types=1);

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;

\defined('ABSPATH') || exit;

class CleanupAccessTokensScheduler
{
    #[Command('mailerpress tokens:cleanup-scheduler')]
    public function cleanup(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('🧹 Cleaning up old access tokens scheduler...');

        $cleaned = 0;

        // 1. Delete the WordPress option
        if (delete_option('mailerpress_access_tokens_scheduled')) {
            \WP_CLI::log('✓ Deleted option: mailerpress_access_tokens_scheduled');
            $cleaned++;
        } else {
            \WP_CLI::log('- Option mailerpress_access_tokens_scheduled not found');
        }

        // 2. Unschedule any remaining Action Scheduler actions
        if (function_exists('as_unschedule_all_actions')) {
            $unscheduled = as_unschedule_all_actions('mailerpress_generate_access_tokens');
            if ($unscheduled) {
                \WP_CLI::log(sprintf('✓ Unscheduled %d action(s): mailerpress_generate_access_tokens', $unscheduled));
                $cleaned++;
            } else {
                \WP_CLI::log('- No scheduled actions found for mailerpress_generate_access_tokens');
            }
        }

        // 3. Clean up from Action Scheduler database directly
        if (class_exists('\\ActionScheduler_Store')) {
            global $wpdb;
            $actions_table = $wpdb->prefix . 'actionscheduler_actions';
            $logs_table = $wpdb->prefix . 'actionscheduler_logs';

            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $actions_table
            )) === $actions_table;

            if ($table_exists) {
                // Get action IDs
                $action_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT action_id FROM {$actions_table} WHERE hook = %s",
                    'mailerpress_generate_access_tokens'
                ));

                if (!empty($action_ids)) {
                    $action_ids = array_map('intval', $action_ids);
                    $action_ids_string = implode(', ', $action_ids);

                    // Delete actions
                    $deleted_actions = $wpdb->query("DELETE FROM {$actions_table} WHERE action_id IN ({$action_ids_string})");
                    \WP_CLI::log(sprintf('✓ Deleted %d action(s) from database', $deleted_actions));

                    // Delete logs
                    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) === $logs_table) {
                        $deleted_logs = $wpdb->query("DELETE FROM {$logs_table} WHERE action_id IN ({$action_ids_string})");
                        \WP_CLI::log(sprintf('✓ Deleted %d log(s) from database', $deleted_logs));
                    }

                    $cleaned++;
                } else {
                    \WP_CLI::log('- No actions found in database');
                }
            }
        }

        if ($cleaned > 0) {
            \WP_CLI::success('✅ Cleanup completed.');
        } else {
            \WP_CLI::log('ℹ Nothing to clean up.');
        }
    }
}
