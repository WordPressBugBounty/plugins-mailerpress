<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

class RateLimitConfig
{
    private const OPTION_KEY = 'mailerpress_contact_rate_limit';

    private const DEFAULTS = [
        'enabled' => true,
        'requests' => 5,
        'window' => 60,  // seconds
        'honeypot_enabled' => true,
    ];

    /**
     * Get rate limit configuration
     *
     * @return array
     */
    public static function get(): array
    {
        $config = get_option(self::OPTION_KEY, []);

        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        return array_merge(self::DEFAULTS, $config ?: []);
    }

    /**
     * Update rate limit configuration
     *
     * @param array $config
     * @return bool
     */
    public static function update(array $config): bool
    {
        $merged = array_merge(self::DEFAULTS, $config);
        $encoded = wp_json_encode($merged);

        // update_option returns false if value hasn't changed, so we check if option exists
        $result = update_option(self::OPTION_KEY, $encoded);

        // If update_option returns false, check if it's because the value is the same
        if (!$result) {
            $current = get_option(self::OPTION_KEY);
            // If current value matches what we're trying to save, consider it a success
            if ($current === $encoded) {
                return true;
            }
        }

        return $result;
    }

    /**
     * Check if rate limiting is enabled
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        $config = self::get();
        return (bool)($config['enabled'] ?? true);
    }

    /**
     * Get request limit
     *
     * @return int
     */
    public static function getLimit(): int
    {
        $config = self::get();
        return max(1, (int)($config['requests'] ?? 5));
    }

    /**
     * Get time window in seconds
     *
     * @return int
     */
    public static function getWindow(): int
    {
        $config = self::get();
        return max(10, (int)($config['window'] ?? 60));
    }

    /**
     * Check if honeypot protection is enabled
     *
     * @return bool
     */
    public static function isHoneypotEnabled(): bool
    {
        $config = self::get();
        return (bool)($config['honeypot_enabled'] ?? true);
    }
}
