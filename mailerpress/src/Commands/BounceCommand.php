<?php

declare(strict_types=1);

namespace MailerPress\Commands;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Command;
use MailerPress\Services\BounceParser;

/**
 * Commandes WP-CLI pour la gestion des bounces
 */
class BounceCommand
{
    /**
     * Force l'exécution du check de bounces
     * 
     * ## EXAMPLES
     * 
     *     wp mailerpress bounce check
     * 
     * @when after_wp_load
     */
    #[Command('mailerpress bounce check')]
    public function check($args, $assoc_args): void
    {
        \WP_CLI::log('Starting bounce check...');

        $config = BounceParser::getValidatedConfig();
        if ($config === null) {
            \WP_CLI::error('Bounce configuration is not valid or missing.');
            return;
        }

        \WP_CLI::log('Configuration is valid for: ' . $config['email']);

        BounceParser::clearLogs();
        BounceParser::parse();

        $logs = BounceParser::getLogs();

        \WP_CLI::log('Bounce check completed. Found ' . count($logs) . ' log entries:');
        \WP_CLI::log('');

        foreach ($logs as $log) {
            \WP_CLI::log('[' . $log['timestamp'] . '] ' . $log['message']);
        }

        \WP_CLI::success('Bounce check completed successfully.');
    }

    /**
     * Affiche les logs de bounce
     * 
     * ## OPTIONS
     * 
     * [--limit=<number>]
     * : Nombre de logs à afficher (défaut: 100)
     * 
     * ## EXAMPLES
     * 
     *     wp mailerpress bounce logs
     *     wp mailerpress bounce logs --limit=50
     * 
     * @when after_wp_load
     */
    #[Command('mailerpress bounce logs')]
    public function logs($args, $assoc_args): void
    {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 100;
        $logs = BounceParser::getLogs($limit);

        if (empty($logs)) {
            \WP_CLI::log('No logs found.');
            return;
        }

        \WP_CLI::log('Found ' . count($logs) . ' log entries:');
        \WP_CLI::log('');

        foreach ($logs as $log) {
            \WP_CLI::log('[' . $log['timestamp'] . '] ' . $log['message']);
        }
    }

    /**
     * Efface les logs de bounce
     * 
     * ## EXAMPLES
     * 
     *     wp mailerpress bounce clear-logs
     * 
     * @when after_wp_load
     */
    #[Command('mailerpress bounce clear-logs')]
    public function clearLogs($args, $assoc_args): void
    {
        BounceParser::clearLogs();
        \WP_CLI::success('Logs cleared successfully.');
    }

    /**
     * Affiche le statut de la configuration bounce
     * 
     * ## EXAMPLES
     * 
     *     wp mailerpress bounce status
     * 
     * @when after_wp_load
     */
    #[Command('mailerpress bounce status')]
    public function status($args, $assoc_args): void
    {
        $config = BounceParser::getValidatedConfig();

        if ($config === null) {
            \WP_CLI::error('Bounce configuration is not valid or missing.');
            return;
        }

        \WP_CLI::log('Bounce configuration:');
        \WP_CLI::log('  Email: ' . $config['email']);
        \WP_CLI::log('  Host: ' . $config['host']);
        \WP_CLI::log('  Port: ' . $config['port']);
        \WP_CLI::log('  Username: ' . $config['username']);
        \WP_CLI::log('');

        $hasScheduledAction = \function_exists('as_has_scheduled_action')
            && as_has_scheduled_action('mailerpress_check_bounces');

        \WP_CLI::log('Action Scheduler status:');
        \WP_CLI::log('  Scheduled: ' . ($hasScheduledAction ? 'Yes' : 'No'));

        if ($hasScheduledAction && \function_exists('as_next_scheduled_action')) {
            $nextScheduled = as_next_scheduled_action('mailerpress_check_bounces');
            if ($nextScheduled) {
                \WP_CLI::log('  Next run: ' . date('Y-m-d H:i:s', $nextScheduled));
            }
        }

        $interval = \get_option('mailerpress_check_bounces_interval', null);
        if ($interval) {
            \WP_CLI::log('  Interval: ' . human_time_diff(0, $interval));
        }

        \WP_CLI::success('Bounce system is configured and running.');
    }
}

