<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

/**
 * Système de logging personnalisé pour MailerPress
 * 
 * Les logs ne sont écrits que si MAILERPRESS_DEBUG est défini à true dans wp-config.php
 * Les fichiers de logs sont créés dans le répertoire logs/ du plugin
 */
class Logger
{
    /**
     * Niveaux de log disponibles
     */
    public const LEVEL_DEBUG = 'DEBUG';
    public const LEVEL_INFO = 'INFO';
    public const LEVEL_WARNING = 'WARNING';
    public const LEVEL_ERROR = 'ERROR';
    public const LEVEL_CRITICAL = 'CRITICAL';

    /**
     * Répertoire de logs
     */
    protected static ?string $logDir = null;

    /**
     * Fichier de log actuel (par date)
     */
    protected static ?string $logFile = null;

    /**
     * Vérifie si le logging est activé
     */
    protected static function isEnabled(): bool
    {
        return \defined('MAILERPRESS_DEBUG') && \constant('MAILERPRESS_DEBUG') === true;
    }

    /**
     * Initialise le système de logging
     */
    protected static function init(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (self::$logDir !== null && self::$logFile !== null) {
            return;
        }

        try {
            // Utiliser le répertoire du plugin pour les logs
            $pluginDir = \defined('MAILERPRESS_PLUGIN_DIR_PATH')
                ? \constant('MAILERPRESS_PLUGIN_DIR_PATH')
                : dirname(dirname(__DIR__));

            self::$logDir = $pluginDir . '/logs';

            // Créer le répertoire de logs s'il n'existe pas
            if (!file_exists(self::$logDir)) {
                \wp_mkdir_p(self::$logDir);

                // Ajouter un fichier .htaccess pour protéger les logs
                $htaccessFile = self::$logDir . '/.htaccess';
                if (!file_exists($htaccessFile)) {
                    @file_put_contents($htaccessFile, "deny from all\n");
                }
            }

            // Créer le fichier de log avec la date du jour
            $date = date('Y-m-d');
            self::$logFile = self::$logDir . '/mailerpress-' . $date . '.log';
        } catch (\Throwable $e) {
            // En cas d'erreur d'initialisation, désactiver le logging
            self::$logDir = null;
            self::$logFile = null;
        }
    }

    /**
     * Écrit un message dans le fichier de log
     */
    protected static function writeLog(string $level, string $message, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::init();

        if (self::$logFile === null) {
            return;
        }

        try {
            $timestamp = \current_time('mysql');
            $contextStr = '';

            if (!empty($context)) {
                $contextStr = ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $logEntry = sprintf(
                "[%s] [%s] %s%s\n",
                $timestamp,
                $level,
                $message,
                $contextStr
            );

            @file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Ne pas logger l'erreur de logging pour éviter les boucles infinies
        }
    }

    /**
     * Log un message de niveau DEBUG
     */
    public static function debug(string $message, array $context = []): void
    {
        self::writeLog(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log un message de niveau INFO
     */
    public static function info(string $message, array $context = []): void
    {
        self::writeLog(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log un message de niveau WARNING
     */
    public static function warning(string $message, array $context = []): void
    {
        self::writeLog(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log un message de niveau ERROR
     */
    public static function error(string $message, array $context = []): void
    {
        self::writeLog(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log un message de niveau CRITICAL
     */
    public static function critical(string $message, array $context = []): void
    {
        self::writeLog(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Log une exception avec tous les détails
     */
    public static function exception(\Throwable $exception, array $context = []): void
    {
        $context['exception'] = [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'class' => get_class($exception),
        ];

        self::error('Exception: ' . $exception->getMessage(), $context);
    }

    /**
     * Log un message avec un niveau personnalisé
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        self::writeLog(strtoupper($level), $message, $context);
    }

    /**
     * Nettoie les anciens fichiers de logs
     * 
     * @param int $daysToKeep Nombre de jours à conserver (par défaut 30)
     */
    public static function cleanup(int $daysToKeep = 30): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::init();

        if (self::$logDir === null || !is_dir(self::$logDir)) {
            return;
        }

        try {
            $files = glob(self::$logDir . '/mailerpress-*.log');
            $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);

            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    @unlink($file);
                }
            }
        } catch (\Throwable $e) {
            // Ignorer les erreurs de nettoyage
        }
    }

    /**
     * Récupère le chemin du répertoire de logs
     */
    public static function getLogDir(): ?string
    {
        if (!self::isEnabled()) {
            return null;
        }

        self::init();
        return self::$logDir;
    }

    /**
     * Récupère le chemin du fichier de log actuel
     */
    public static function getLogFile(): ?string
    {
        if (!self::isEnabled()) {
            return null;
        }

        self::init();
        return self::$logFile;
    }

    /**
     * Lit le contenu du fichier de log
     * 
     * @param int $lines Nombre de lignes à récupérer (0 = toutes)
     * @return string Contenu du fichier de log
     */
    public static function getLogContent(int $lines = 0): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        self::init();

        if (self::$logFile === null || !file_exists(self::$logFile)) {
            return '';
        }

        try {
            $content = @file_get_contents(self::$logFile);
            if ($content === false) {
                return '';
            }

            if ($lines > 0) {
                $allLines = explode("\n", $content);
                $recentLines = array_slice($allLines, -$lines);
                return implode("\n", $recentLines);
            }

            return $content;
        } catch (\Throwable $e) {
            return '';
        }
    }
}
