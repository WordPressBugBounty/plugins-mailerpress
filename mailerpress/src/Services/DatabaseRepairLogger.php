<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

/**
 * Service de logging pour la réparation de la base de données
 * Fonctionne même sans WP_DEBUG activé
 */
class DatabaseRepairLogger
{
    protected static array $logs = [];
    protected static string $logFile = '';

    /**
     * Initialise le système de logging
     */
    public static function init(): void
    {
        try {
            $uploadDir = wp_upload_dir();

            if (isset($uploadDir['error']) && $uploadDir['error']) {
                // Fallback si wp_upload_dir échoue
                $uploadDir['basedir'] = WP_CONTENT_DIR . '/uploads';
            }

            $logDir = $uploadDir['basedir'] . '/mailerpress-logs';

            // Créer le dossier de logs s'il n'existe pas
            if (!file_exists($logDir)) {
                $created = wp_mkdir_p($logDir);
                if (!$created) {
                    // Fallback: utiliser le dossier de plugins
                    $logDir = WP_PLUGIN_DIR . '/mailerpress/logs';
                    wp_mkdir_p($logDir);
                }
            }

            self::$logFile = $logDir . '/database-repair-' . date('Y-m-d') . '.log';

            // Tester l'écriture avec un message initial
            $testMessage = "[" . date('Y-m-d H:i:s') . "] [INFO] Logger initialisé\n";
            $written = @file_put_contents(self::$logFile, $testMessage, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Ajoute un log
     */
    public static function log(string $message, string $level = 'INFO', array $context = []): void
    {
        if (empty(self::$logFile)) {
            self::init();
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";

        // Ajouter à la mémoire
        self::$logs[] = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        // Écrire dans le fichier
        @file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);}

    /**
     * Log une erreur
     */
    public static function error(string $message, array $context = []): void
    {
        self::log($message, 'ERROR', $context);
    }

    /**
     * Log un avertissement
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log($message, 'WARNING', $context);
    }

    /**
     * Log une information
     */
    public static function info(string $message, array $context = []): void
    {
        self::log($message, 'INFO', $context);
    }

    /**
     * Log une exception
     */
    public static function exception(\Throwable $e, array $context = []): void
    {
        $context['exception'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
        self::error('Exception: ' . $e->getMessage(), $context);
    }

    /**
     * Récupère tous les logs en mémoire
     */
    public static function getLogs(): array
    {
        return self::$logs;
    }

    /**
     * Récupère les logs du fichier
     */
    public static function getLogFileContent(int $lines = 1000): string
    {
        if (empty(self::$logFile) || !file_exists(self::$logFile)) {
            return '';
        }

        $content = file_get_contents(self::$logFile);
        if ($content === false) {
            return '';
        }

        // Si on veut limiter le nombre de lignes
        if ($lines > 0) {
            $allLines = explode("\n", $content);
            $recentLines = array_slice($allLines, -$lines);
            return implode("\n", $recentLines);
        }

        return $content;
    }

    /**
     * Récupère le chemin du fichier de log
     */
    public static function getLogFilePath(): string
    {
        if (empty(self::$logFile)) {
            self::init();
        }
        return self::$logFile;
    }

    /**
     * Exporte les logs au format JSON
     */
    public static function exportJson(): string
    {
        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'wordpress_version' => get_bloginfo('version'),
            'plugin_version' => defined('MAILERPRESS_VERSION') ? MAILERPRESS_VERSION : 'unknown',
            'php_version' => PHP_VERSION,
            'mysql_version' => self::getMysqlVersion(),
            'logs' => self::getLogs(),
            'log_file_content' => self::getLogFileContent(500),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Récupère la version MySQL
     */
    protected static function getMysqlVersion(): string
    {
        global $wpdb;
        $version = $wpdb->get_var('SELECT VERSION()');
        return $version ?: 'unknown';
    }

    /**
     * Nettoie les anciens fichiers de logs (garde les 7 derniers jours)
     */
    public static function cleanup(): void
    {
        if (empty(self::$logFile)) {
            self::init();
        }

        $uploadDir = wp_upload_dir();
        $logDir = $uploadDir['basedir'] . '/mailerpress-logs';

        if (!is_dir($logDir)) {
            return;
        }

        $files = glob($logDir . '/database-repair-*.log');
        $cutoffTime = time() - (7 * 24 * 60 * 60); // 7 jours

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                @unlink($file);
            }
        }
    }
}
