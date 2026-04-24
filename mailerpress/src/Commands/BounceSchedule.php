<?php

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Services\BounceParser;

\defined('ABSPATH') || exit;

class BounceSchedule
{
    #[Command('mailerpress bounce:schedule-status')]
    public function status(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('🔍 Statut de la planification des bounces...');
        \WP_CLI::log('');

        // 1. Vérifier la configuration
        $config = BounceParser::getValidatedConfig();
        
        if ($config === null) {
            \WP_CLI::warning('⚠️  Aucune configuration de bounce valide');
            \WP_CLI::log('💡 Configurez le Bounce Manager dans les paramètres');
            return;
        }
        
        \WP_CLI::success('✅ Configuration bounce valide');
        \WP_CLI::log('');

        // 2. Vérifier si l'action est planifiée
        if (!\function_exists('as_has_scheduled_action')) {
            \WP_CLI::error('❌ Action Scheduler non disponible');
            return;
        }

        $hasAction = as_has_scheduled_action('mailerpress_check_bounces');
        
        if (!$hasAction) {
            \WP_CLI::warning('⚠️  Aucune action planifiée trouvée');
        } else {
            \WP_CLI::success('✅ Action planifiée trouvée');
        }
        \WP_CLI::log('');

        // 3. Prochaine exécution
        if (\function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action('mailerpress_check_bounces');
            if ($next) {
                $datetime = date('Y-m-d H:i:s', $next);
                $diff = human_time_diff($next, time());
                \WP_CLI::log("📅 Prochaine exécution: {$datetime} (dans {$diff})");
            } else {
                \WP_CLI::warning('⚠️  Aucune prochaine exécution planifiée');
            }
        }
        \WP_CLI::log('');

        // 4. Intervalle stocké
        $interval = get_option('mailerpress_check_bounces_interval', null);
        if ($interval) {
            $hours = $interval / HOUR_IN_SECONDS;
            \WP_CLI::log("⏱️  Intervalle: {$hours} heures ({$interval} secondes)");
        } else {
            \WP_CLI::warning('⚠️  Aucun intervalle stocké');
        }
        \WP_CLI::log('');

        // 5. Vérifier les logs d'Action Scheduler
        $this->checkActionSchedulerLogs();
    }

    #[Command('mailerpress bounce:schedule-reset')]
    public function reset(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('🔄 Réinitialisation de la planification des bounces...');
        \WP_CLI::log('');

        // 1. Vérifier la configuration
        $config = BounceParser::getValidatedConfig();
        
        if ($config === null) {
            \WP_CLI::error('❌ Aucune configuration de bounce valide - impossible de planifier');
            return;
        }

        if (!\function_exists('as_unschedule_all_actions') || !\function_exists('as_schedule_recurring_action')) {
            \WP_CLI::error('❌ Action Scheduler non disponible');
            return;
        }

        // 2. Supprimer toutes les actions existantes
        \WP_CLI::log('🗑️  Suppression des actions existantes...');
        as_unschedule_all_actions('mailerpress_check_bounces');
        delete_option('mailerpress_check_bounces_interval');
        
        $this->deleteAllBounceActionsFromDatabase();
        
        \WP_CLI::success('✅ Actions supprimées');
        \WP_CLI::log('');

        // 3. Créer une nouvelle action
        \WP_CLI::log('➕ Création d\'une nouvelle action...');
        
        $interval = 1 * DAY_IN_SECONDS;
        
        as_schedule_recurring_action(
            time(),
            $interval,
            'mailerpress_check_bounces',
            [],
            'mailerpress'
        );
        
        update_option('mailerpress_check_bounces_interval', $interval);
        
        \WP_CLI::success('✅ Nouvelle action créée');
        \WP_CLI::log('');

        // 4. Vérifier que ça fonctionne
        $next = as_next_scheduled_action('mailerpress_check_bounces');
        if ($next) {
            $datetime = date('Y-m-d H:i:s', $next);
            \WP_CLI::success("📅 Prochaine exécution: {$datetime}");
        } else {
            \WP_CLI::error('❌ Erreur: l\'action n\'a pas été créée correctement');
        }
    }

    #[Command('mailerpress bounce:schedule-run-now')]
    public function runNow(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('▶️  Exécution immédiate du check bounce...');
        \WP_CLI::log('');

        try {
            BounceParser::parse();
            \WP_CLI::success('✅ Check bounce exécuté avec succès');
        } catch (\Exception $e) {
            \WP_CLI::error('❌ Erreur: ' . $e->getMessage());
            \WP_CLI::log('');
            \WP_CLI::log('Stack trace:');
            \WP_CLI::log($e->getTraceAsString());
        }
    }

    private function checkActionSchedulerLogs(): void
    {
        global $wpdb;

        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';

        // Vérifier que les tables existent
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $actions_table
        )) === $actions_table;

        if (!$table_exists) {
            \WP_CLI::warning('⚠️  Table Action Scheduler non trouvée');
            return;
        }

        // Récupérer les actions
        $actions = $wpdb->get_results($wpdb->prepare(
            "SELECT action_id, status, scheduled_date_gmt, last_attempt_gmt 
             FROM {$actions_table} 
             WHERE hook = %s 
             ORDER BY action_id DESC 
             LIMIT 10",
            'mailerpress_check_bounces'
        ));

        if (empty($actions)) {
            \WP_CLI::warning('⚠️  Aucune action trouvée dans la base de données');
            return;
        }

        \WP_CLI::log('📊 Dernières actions (max 10):');
        \WP_CLI::log(str_repeat('-', 100));

        foreach ($actions as $action) {
            $status_emoji = $this->getStatusEmoji($action->status);
            
            \WP_CLI::log(sprintf(
                '   %s ID: %d | Status: %s | Planifié: %s | Dernière tentative: %s',
                $status_emoji,
                $action->action_id,
                $action->status,
                $action->scheduled_date_gmt ?? 'N/A',
                $action->last_attempt_gmt ?? 'N/A'
            ));

            // Récupérer les logs pour cette action si elle a échoué
            if ($action->status === 'failed') {
                $logs = $wpdb->get_results($wpdb->prepare(
                    "SELECT message FROM {$logs_table} 
                     WHERE action_id = %d 
                     ORDER BY log_date_gmt DESC 
                     LIMIT 1",
                    $action->action_id
                ));

                if (!empty($logs)) {
                    \WP_CLI::log('      ⚠️  Erreur: ' . $logs[0]->message);
                }
            }
        }

        \WP_CLI::log(str_repeat('-', 100));
    }

    private function getStatusEmoji(string $status): string
    {
        return match($status) {
            'pending' => '⏳',
            'in-progress' => '▶️',
            'complete' => '✅',
            'failed' => '❌',
            'canceled' => '🚫',
            default => '❓',
        };
    }

    /**
     * Supprime toutes les actions mailerpress_check_bounces de la base de données
     */
    private function deleteAllBounceActionsFromDatabase(): void
    {
        global $wpdb;

        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';

        // Vérifier que les tables existent
        $actions_table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $actions_table
        )) === $actions_table;

        if (!$actions_table_exists) {
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

        // Supprimer les actions
        $wpdb->query(
            "DELETE FROM {$actions_table} WHERE action_id IN ({$action_ids_string})"
        );

        // Supprimer les logs
        $logs_table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $logs_table
        )) === $logs_table;

        if ($logs_table_exists) {
            $wpdb->query(
                "DELETE FROM {$logs_table} WHERE action_id IN ({$action_ids_string})"
            );
        }
    }
}

