<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler;

\defined('ABSPATH') || exit;

use MailerPress\Actions\ActionScheduler\Processors\ProcessChunkDeleteContact;
use MailerPress\Actions\ActionScheduler\Processors\ProcessChunkImportContact;
use MailerPress\Api\CountDown;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\Kernel;

final class AsInit
{
    private const SCHEDULE_CHECK_PREFIX = 'mailerpress_as_schedule_check_';
    private const SCHEDULE_CHECK_TTL = 5 * MINUTE_IN_SECONDS;
    private const BOUNCE_CHECK_TTL = MINUTE_IN_SECONDS;

    #[Action('init', priority: 20)]
    public function initCron(): void
    {
        // Register processor callbacks explicitly for Action Scheduler
        // This ensures hooks are available for async requests
        $this->registerImportChunkProcessor();
        $this->registerDeleteChunkProcessor();

        CountDown::cleanupExpired();

        if (
            $this->shouldRunScheduleCheck('cleaner')
            && \function_exists('as_has_scheduled_action')
            && \function_exists('as_get_scheduled_actions')
            && \function_exists('as_schedule_recurring_action')
        ) {
            $existing = as_get_scheduled_actions([
                'hook'   => 'mailerpress_as_clean',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ], 'ARRAY_A');

            $needsReschedule = true;
            foreach ( $existing as $action ) {
                $interval = $action['schedule'] ?? null;
                if ( $interval instanceof \ActionScheduler_IntervalSchedule ) {
                    $recurrence = $interval->get_recurrence();
                    if ( (int) $recurrence === DAY_IN_SECONDS ) {
                        $needsReschedule = false;
                        break;
                    }
                }
            }

            if ( $needsReschedule ) {
                as_unschedule_all_actions( 'mailerpress_as_clean' );
                as_schedule_recurring_action(
                    time(),
                    DAY_IN_SECONDS,
                    'mailerpress_as_clean',
                    [],
                    'mailerpress'
                );
            }
        }

        // Gestion simple de l'action de check bounce (toutes les 12h)
        if (
            $this->shouldRunScheduleCheck('bounce', self::BOUNCE_CHECK_TTL)
            && \function_exists('as_has_scheduled_action')
            && \function_exists('as_schedule_recurring_action')
        ) {
            $bounceConfig = \MailerPress\Services\BounceParser::getValidatedConfig();
            $hasScheduledAction = as_has_scheduled_action('mailerpress_check_bounces');

            // Si la config est valide ET qu'aucune action n'est planifiée : on la crée
            if ($bounceConfig !== null && !$hasScheduledAction) {
                as_schedule_recurring_action(
                    time(),
                    12 * HOUR_IN_SECONDS,
                    'mailerpress_check_bounces',
                    [],
                    'mailerpress'
                );
            }

            // Si la config n'est plus valide ET qu'une action existe : on la supprime
            if ($bounceConfig === null && $hasScheduledAction) {
                as_unschedule_all_actions('mailerpress_check_bounces');
                self::deleteAllBounceActionsFromDatabase();
            }
        }

        // Refresh audience counts for scheduled campaigns (every hour)
        if (
            $this->shouldRunScheduleCheck('refresh_counts')
            && \function_exists('as_has_scheduled_action')
            && \function_exists('as_get_scheduled_actions')
        ) {
            $refreshActions = as_get_scheduled_actions([
                'hook'   => 'mailerpress_refresh_scheduled_counts',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ], 'ARRAY_A');

            $needsReschedule = true;
            foreach ( $refreshActions as $action ) {
                $interval = $action['schedule'] ?? null;
                if ( $interval instanceof \ActionScheduler_IntervalSchedule ) {
                    if ( (int) $interval->get_recurrence() === HOUR_IN_SECONDS ) {
                        $needsReschedule = false;
                        break;
                    }
                }
            }

            if ( $needsReschedule ) {
                as_unschedule_all_actions( 'mailerpress_refresh_scheduled_counts' );
                as_schedule_recurring_action(
                    time(),
                    HOUR_IN_SECONDS,
                    'mailerpress_refresh_scheduled_counts',
                    [],
                    'mailerpress'
                );
            }
        }

        // Workflow cleanup cron (daily)
        if (
            $this->shouldRunScheduleCheck('workflow_cleanup')
            && \function_exists('as_has_scheduled_action')
            && \function_exists('as_schedule_recurring_action')
            && !as_has_scheduled_action('mailerpress_workflow_cleanup')
        ) {
            as_schedule_recurring_action(
                time(),
                DAY_IN_SECONDS,
                'mailerpress_workflow_cleanup',
                [],
                'mailerpress'
            );
        }

        add_action('mailerpress_workflow_cleanup', function () {
            $cleanup = new \MailerPress\Core\Workflows\Services\WorkflowCleanup();
            $cleanup->cleanup();
        });
    }

    private function shouldRunScheduleCheck(string $key, int $ttl = self::SCHEDULE_CHECK_TTL): bool
    {
        $transientKey = self::SCHEDULE_CHECK_PREFIX . $key;

        if (get_transient($transientKey)) {
            return false;
        }

        set_transient($transientKey, 1, $ttl);

        return true;
    }

    #[Filter('action_scheduler_queue_runner_concurrent_batches')]
    public function mailerpress_increase_concurrent_batches($concurrent_batches)
    {
        return 3;
    }

    #[Filter('action_scheduler_queue_runner_batch_size')]
    public function mailerpress_increase_queue_batch_size($batch_size)
    {
        return 20;
    }

    #[Filter(['action_scheduler_timeout_period', 'action_scheduler_failure_period'])]
    public function mailerpress_increase_timeout($timeout)
    {
        return $timeout * 3;
    }

    #[Filter('action_scheduler_default_cleaner_statuses')]
    public function mailerpress_default_cleaner_statuses($statuses)
    {
        $statuses[] = 'failed';
        return $statuses;
    }

    #[Filter('action_scheduler_cleanup_batch_size')]
    public function mailerpress_cleanup_batch_size($batch_size)
    {
        return 100;
    }

    /**
     * Register the import chunk processor callback explicitly
     * This ensures it's available for Action Scheduler async requests
     */
    private function registerImportChunkProcessor(): void
    {
        // Only register if not already registered
        if (!has_action('process_import_chunk')) {
            try {
                $container = Kernel::getContainer();
                if ($container && $container->has(ProcessChunkImportContact::class)) {
                    $processor = $container->get(ProcessChunkImportContact::class);
                    add_action('process_import_chunk', [$processor, 'processImportChunk'], 10, 2);
                }
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * Register the delete chunk processor callback explicitly
     * This ensures it's available for Action Scheduler async requests
     */
    private function registerDeleteChunkProcessor(): void
    {
        if (!has_action('process_delete_chunk')) {
            try {
                $container = Kernel::getContainer();
                if ($container && $container->has(ProcessChunkDeleteContact::class)) {
                    $processor = $container->get(ProcessChunkDeleteContact::class);
                    add_action('process_delete_chunk', [$processor, 'processDeleteChunk'], 10, 1);
                }
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * Nettoie complètement toutes les traces de l'action bounce dans la base de données
     */
    private static function deleteAllBounceActionsFromDatabase(): void
    {
        global $wpdb;

        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';

        // Vérifier que la table existe
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $actions_table
        )) === $actions_table;

        if (!$table_exists) {
            return;
        }

        // Récupérer tous les IDs
        $action_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT action_id FROM {$actions_table} WHERE hook = %s",
            'mailerpress_check_bounces'
        ));

        if (empty($action_ids)) {
            return;
        }

        $action_ids = array_map('intval', $action_ids);
        $action_ids_string = implode(', ', $action_ids);

        // Supprimer les actions et logs
        $wpdb->query("DELETE FROM {$actions_table} WHERE action_id IN ({$action_ids_string})");

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) === $logs_table) {
            $wpdb->query("DELETE FROM {$logs_table} WHERE action_id IN ({$action_ids_string})");
        }
    }
}
