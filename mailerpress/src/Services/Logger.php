<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

/**
 * Kept for backward compatibility only: MailerPress no longer writes debug logs.
 *
 * Every method is a no-op so that code still calling the logger (for example older
 * MailerPress Pro releases) keeps working without writing any file.
 */
class Logger
{
    public const LEVEL_DEBUG = 'DEBUG';
    public const LEVEL_INFO = 'INFO';
    public const LEVEL_WARNING = 'WARNING';
    public const LEVEL_ERROR = 'ERROR';
    public const LEVEL_CRITICAL = 'CRITICAL';

    public static function debug(string $message, array $context = []): void
    {
    }

    public static function info(string $message, array $context = []): void
    {
    }

    public static function warning(string $message, array $context = []): void
    {
    }

    public static function error(string $message, array $context = []): void
    {
    }

    public static function critical(string $message, array $context = []): void
    {
    }

    public static function exception(\Throwable $exception, array $context = []): void
    {
    }

    public static function log(string $level, string $message, array $context = []): void
    {
    }

    public static function cleanup(int $daysToKeep = 30): void
    {
    }

    public static function getLogDir(): ?string
    {
        return null;
    }

    public static function getLogFile(): ?string
    {
        return null;
    }

    public static function getLogContent(int $lines = 0): string
    {
        return '';
    }
}
