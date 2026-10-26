<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

/**
 * Collects database repair messages in memory for the current request, so the
 * Database repair screen can return and export them. Nothing is written to disk.
 */
class DatabaseRepairLogger
{
    protected static array $logs = [];

    /**
     * Ajoute un log
     */
    public static function log(string $message, string $level = 'INFO', array $context = []): void
    {
        self::$logs[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

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
}
