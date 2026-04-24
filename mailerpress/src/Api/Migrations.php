<?php

namespace MailerPress\Api;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Kernel;
use MailerPress\Core\Migrations\Manager;
use MailerPress\Services\DatabaseDiagnostic;
use MailerPress\Services\DatabaseRepairLogger;
use WP_REST_Request;
use WP_REST_Response;

\defined('ABSPATH') || exit;

class Migrations
{
    /**
     * Get migration status
     */
    #[Endpoint('migrations/status', 'GET', permissionCallback: [Permissions::class, 'canManageSettings'])]
    public function getStatus(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['error' => __('Unauthorized', 'mailerpress')], 403);
        }

        $manager = new Manager(
            Kernel::$config['root'] . '/src/Core/Migrations/migrations',
            []
        );

        $status = $manager->getStatus();

        return new WP_REST_Response($status, 200);
    }

    /**
     * Reset failed migrations and unlock
     */
    #[Endpoint('migrations/reset-failed', 'POST', permissionCallback: [Permissions::class, 'canManageSettings'])]
    public function resetFailed(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['error' => __('Unauthorized', 'mailerpress')], 403);
        }

        $manager = new Manager(
            Kernel::$config['root'] . '/src/Core/Migrations/migrations',
            []
        );

        try {
            $count = $manager->resetFailed();

            return new WP_REST_Response([
                'success' => true,
                'message' => sprintf(__('Reset %d failed migration(s) and released lock.', 'mailerpress'), $count),
                'reset_count' => $count,
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Force run migrations (reset failed and run)
     */
    #[Endpoint('migrations/force-run', 'POST', permissionCallback: [Permissions::class, 'canManageSettings'])]
    public function forceRun(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['error' => __('Unauthorized', 'mailerpress')], 403);
        }

        $manager = new Manager(
            Kernel::$config['root'] . '/src/Core/Migrations/migrations',
            []
        );

        try {
            $manager->runForce();

            return new WP_REST_Response([
                'success' => true,
                'message' => __('Migrations completed successfully.', 'mailerpress'),
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unlock migrations (release lock only)
     */
    #[Endpoint('migrations/unlock', 'POST', permissionCallback: [Permissions::class, 'canManageSettings'])]
    public function unlock(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['error' => __('Unauthorized', 'mailerpress')], 403);
        }

        $manager = new Manager(
            Kernel::$config['root'] . '/src/Core/Migrations/migrations',
            []
        );

        try {
            $manager->forceReleaseLock();

            return new WP_REST_Response([
                'success' => true,
                'message' => __('Migration lock released.', 'mailerpress'),
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get database diagnostic
     */
    #[Endpoint('database/diagnostic', 'GET', permissionCallback: [Permissions::class, 'canManageSettings'])]
    public function getDiagnostic(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['error' => __('Unauthorized', 'mailerpress')], 403);
        }


        try {
            $diagnostic = new DatabaseDiagnostic();
            $result = $diagnostic->diagnose();

            return new WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => defined('WP_DEBUG') && WP_DEBUG ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Repair database
     */
    #[Endpoint('database/repair', 'POST', permissionCallback: [Permissions::class, 'canManageSettings'])]
    public function repairDatabase(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['error' => __('Unauthorized', 'mailerpress')], 403);
        }


        try {
            // Initialiser le logger avant tout
            DatabaseRepairLogger::init();
            DatabaseRepairLogger::info('API: Début de la réparation de la base de données');

            $diagnostic = new DatabaseDiagnostic();
            $result = $diagnostic->repair();

            // Ajouter le chemin du fichier de log dans la réponse
            $logFile = DatabaseRepairLogger::getLogFilePath();
            $result['log_file'] = $logFile;
            $result['log_file_exists'] = file_exists($logFile);
            $result['log_file_readable'] = $logFile && is_readable($logFile);

            // Ajouter un échantillon des logs en mémoire
            $logs = DatabaseRepairLogger::getLogs();
            $result['logs_count'] = count($logs);
            $result['recent_logs'] = array_slice($logs, -10); // 10 derniers logs

            DatabaseRepairLogger::info('API: Réparation terminée', [
                'success' => $result['success'],
                'log_file' => $logFile,
            ]);

            return new WP_REST_Response($result, $result['success'] ? 200 : 500);
        } catch (\Throwable $e) {
            DatabaseRepairLogger::init(); // S'assurer que le logger est initialisé
            DatabaseRepairLogger::exception($e, ['source' => 'API repairDatabase']);

            $logFile = DatabaseRepairLogger::getLogFilePath();
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => defined('WP_DEBUG') && WP_DEBUG ? $e->getTraceAsString() : null,
                'log_file' => $logFile,
                'log_file_exists' => file_exists($logFile),
            ], 500);
        }
    }

    /**
     * Export repair logs with full diagnostic information
     */
    #[Endpoint('database/export-logs', 'GET', permissionCallback: [Permissions::class, 'canManageSettings'])]
    public function exportLogs(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['error' => __('Unauthorized', 'mailerpress')], 403);
        }


        try {
            $format = $request->get_param('format') ?: 'json';

            // Get current diagnostic
            $diagnostic = new DatabaseDiagnostic();
            $diagnosticData = $diagnostic->diagnose();

            // Get only error logs (ERROR, CRITICAL, EXCEPTION levels)
            DatabaseRepairLogger::init();
            $allLogs = DatabaseRepairLogger::getLogs();

            // Filter only ERROR and CRITICAL level logs
            $errorLogs = array_values(array_filter($allLogs, function ($log) {
                $level = strtoupper($log['level'] ?? '');
                return $level === 'ERROR' || $level === 'CRITICAL' || $level === 'EXCEPTION';
            }));

            // Prepare comprehensive export data
            $exportData = [
                'export_timestamp' => date('Y-m-d H:i:s'),
                'system_info' => [
                    'wordpress_version' => get_bloginfo('version'),
                    'plugin_version' => defined('MAILERPRESS_VERSION') ? MAILERPRESS_VERSION : 'unknown',
                    'php_version' => PHP_VERSION,
                    'mysql_version' => $this->getMysqlVersion(),
                    'site_url' => get_site_url(),
                    'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
                ],
                'diagnostic' => [
                    'healthy' => $diagnosticData['healthy'] ?? false,
                    'summary' => $diagnosticData['summary'] ?? [],
                    'migration_status' => $diagnosticData['migration_status'] ?? [],
                    'issues' => $diagnosticData['issues'] ?? [],
                    'critical_issues' => array_values(array_filter($diagnosticData['issues'] ?? [], function ($issue) {
                        return ($issue['type'] ?? '') === 'critical';
                    })),
                    'errors' => array_values(array_filter($diagnosticData['issues'] ?? [], function ($issue) {
                        return ($issue['type'] ?? '') === 'error';
                    })),
                    'warnings' => array_values(array_filter($diagnosticData['issues'] ?? [], function ($issue) {
                        return ($issue['type'] ?? '') === 'warning';
                    })),
                ],
                'error_logs' => $errorLogs,
                'log_file_path' => DatabaseRepairLogger::getLogFilePath(),
                'log_file_exists' => file_exists(DatabaseRepairLogger::getLogFilePath()),
                'total_error_logs' => count($errorLogs),
                'total_log_entries' => count($allLogs),
            ];

            if ($format === 'json') {
                $jsonExport = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return new WP_REST_Response([
                    'success' => true,
                    'logs' => $jsonExport,
                    'log_file' => DatabaseRepairLogger::getLogFilePath(),
                ], 200);
            } else {
                // Format texte simple
                $textExport = $this->formatTextExport($exportData);
                return new WP_REST_Response([
                    'success' => true,
                    'logs' => $textExport,
                    'log_file' => DatabaseRepairLogger::getLogFilePath(),
                ], 200);
            }
        } catch (\Throwable $e) {
            DatabaseRepairLogger::exception($e);
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => defined('WP_DEBUG') && WP_DEBUG ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Get MySQL version
     */
    protected function getMysqlVersion(): string
    {
        global $wpdb;
        $version = $wpdb->get_var('SELECT VERSION()');
        return $version ?: 'unknown';
    }

    /**
     * Format export data as text
     */
    protected function formatTextExport(array $data): string
    {
        $output = "=== MailerPress Database Diagnostic Export ===\n";
        $output .= "Export Date: {$data['export_timestamp']}\n\n";

        $output .= "=== System Information ===\n";
        foreach ($data['system_info'] as $key => $value) {
            $output .= ucfirst(str_replace('_', ' ', $key)) . ": " . (is_bool($value) ? ($value ? 'Yes' : 'No') : $value) . "\n";
        }
        $output .= "\n";

        $output .= "=== Diagnostic Summary ===\n";
        $output .= "Status: " . ($data['diagnostic']['healthy'] ? 'Healthy' : 'Issues Detected') . "\n";
        if (!empty($data['diagnostic']['summary'])) {
            foreach ($data['diagnostic']['summary'] as $key => $value) {
                $output .= ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
            }
        }
        $output .= "\n";

        if (!empty($data['diagnostic']['migration_status'])) {
            $output .= "=== Migration Status ===\n";
            foreach ($data['diagnostic']['migration_status'] as $key => $value) {
                $output .= ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
            }
            $output .= "\n";
        }

        if (!empty($data['diagnostic']['critical_issues'])) {
            $output .= "=== Critical Issues (" . count($data['diagnostic']['critical_issues']) . ") ===\n";
            foreach ($data['diagnostic']['critical_issues'] as $index => $issue) {
                $output .= ($index + 1) . ". [{$issue['table']}] {$issue['message']}\n";
                if (!empty($issue['error_message'])) {
                    $output .= "   Error: {$issue['error_message']}\n";
                }
            }
            $output .= "\n";
        }

        if (!empty($data['diagnostic']['errors'])) {
            $output .= "=== Errors (" . count($data['diagnostic']['errors']) . ") ===\n";
            foreach ($data['diagnostic']['errors'] as $index => $issue) {
                $output .= ($index + 1) . ". [{$issue['table']}] {$issue['message']}\n";
                if (!empty($issue['error_message'])) {
                    $output .= "   Error: {$issue['error_message']}\n";
                }
            }
            $output .= "\n";
        }

        if (!empty($data['diagnostic']['warnings'])) {
            $output .= "=== Warnings (" . count($data['diagnostic']['warnings']) . ") ===\n";
            foreach ($data['diagnostic']['warnings'] as $index => $issue) {
                $output .= ($index + 1) . ". [{$issue['table']}] {$issue['message']}\n";
            }
            $output .= "\n";
        }

        if (!empty($data['error_logs'])) {
            $output .= "=== Error Logs (" . count($data['error_logs']) . ") ===\n";
            foreach ($data['error_logs'] as $index => $log) {
                $output .= ($index + 1) . ". [{$log['timestamp']}] [{$log['level']}] {$log['message']}\n";
                if (!empty($log['context'])) {
                    $output .= "   Context: " . json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
            $output .= "\n";
        }

        return $output;
    }
}
