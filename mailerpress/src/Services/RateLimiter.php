<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class RateLimiter
{
    // Form type identifiers
    public const CONTACT_FORM_IDENTIFIER = 'contact_form';
    public const EMBED_FORM_IDENTIFIER = 'embed_form';

    /**
     * Check if the request is within rate limits
     *
     * @param string $identifier Form or API key identifier
     * @param string $ipAddress The client IP address
     * @param int $limit Maximum requests allowed in the window
     * @param int $window Time window in seconds
     * @return bool True if within limits, false if exceeded
     */
    public static function checkLimit(string $identifier, string $ipAddress, int $limit, int $window): bool
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_RATE_LIMIT);
        $now = current_time('mysql');
        $windowStart = date('Y-m-d H:i:s', strtotime("-{$window} seconds", strtotime($now)));

        // Clean up expired entries (older than the window)
        self::cleanupExpiredEntries($windowStart);

        // Count requests in current window for this identifier and IP
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(request_count) FROM {$table}
                 WHERE api_key_hash = %s
                   AND ip_address = %s
                   AND window_start >= %s",
                $identifier,
                $ipAddress,
                $windowStart
            )
        );

        $currentCount = (int)($count ?? 0);

        // Check if limit exceeded
        if ($currentCount >= $limit) {
            return false; // Rate limit exceeded
        }

        // Record this request
        self::recordRequest($identifier, $ipAddress, $window);

        return true; // Within limits
    }

    /**
     * Record a request in the rate limit table
     *
     * @param string $identifier Form or API key identifier
     * @param string $ipAddress The client IP address
     * @param int $window Time window in seconds
     * @return void
     */
    private static function recordRequest(string $identifier, string $ipAddress, int $window): void
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_RATE_LIMIT);
        $now = current_time('mysql');
        $windowEnd = date('Y-m-d H:i:s', strtotime("+{$window} seconds", strtotime($now)));

        // Try to find existing entry in current window
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, request_count FROM {$table}
                 WHERE api_key_hash = %s
                   AND ip_address = %s
                   AND window_end > %s
                 ORDER BY window_start DESC
                 LIMIT 1",
                $identifier,
                $ipAddress,
                $now
            )
        );

        if ($existing) {
            // Update existing entry
            $wpdb->update(
                $table,
                ['request_count' => $existing->request_count + 1],
                ['id' => $existing->id],
                ['%d'],
                ['%d']
            );
        } else {
            // Insert new entry
            $wpdb->insert(
                $table,
                [
                    'api_key_hash' => $identifier,
                    'ip_address' => $ipAddress,
                    'request_count' => 1,
                    'window_start' => $now,
                    'window_end' => $windowEnd,
                ],
                [
                    '%s', // api_key_hash
                    '%s', // ip_address
                    '%d', // request_count
                    '%s', // window_start
                    '%s', // window_end
                ]
            );
        }
    }

    /**
     * Clean up expired rate limit entries
     *
     * @param string $windowStart Entries older than this will be deleted
     * @return void
     */
    private static function cleanupExpiredEntries(string $windowStart): void
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_RATE_LIMIT);

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE window_end < %s",
                $windowStart
            )
        );
    }

    /**
     * Get current request count for an identifier and IP
     *
     * @param string $identifier Form or API key identifier
     * @param string $ipAddress The client IP address
     * @param int $window Time window in seconds
     * @return int Current request count
     */
    public static function getCurrentCount(string $identifier, string $ipAddress, int $window): int
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_RATE_LIMIT);
        $windowStart = date('Y-m-d H:i:s', strtotime("-{$window} seconds"));

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(request_count) FROM {$table}
                 WHERE api_key_hash = %s
                   AND ip_address = %s
                   AND window_start >= %s",
                $identifier,
                $ipAddress,
                $windowStart
            )
        );

        return (int)($count ?? 0);
    }

    /**
     * Reset rate limit for a specific identifier and IP
     *
     * @param string $identifier Form or API key identifier
     * @param string $ipAddress The client IP address
     * @return void
     */
    public static function reset(string $identifier, string $ipAddress): void
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_RATE_LIMIT);

        $wpdb->delete(
            $table,
            [
                'api_key_hash' => $identifier,
                'ip_address' => $ipAddress,
            ],
            ['%s', '%s']
        );
    }
}
