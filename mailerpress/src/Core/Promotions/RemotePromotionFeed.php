<?php

namespace MailerPress\Core\Promotions;

defined('ABSPATH') || exit;

use MailerPress\Core\ExternalLinks;

class RemotePromotionFeed
{
    private const CACHE_TTL = 30 * MINUTE_IN_SECONDS;
    private const STALE_CACHE_TTL = DAY_IN_SECONDS;
    private const FAILURE_TTL = 10 * MINUTE_IN_SECONDS;
    private const REQUEST_TIMEOUT = 0.5;
    private const LOCK_TTL = 15;

    public static function getPromotions(array $context = []): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $endpoint = self::getEndpoint();
        if (!$endpoint) {
            return [];
        }

        $cacheKey = self::getCacheKey($endpoint);
        $staleCacheKey = self::getStaleCacheKey($endpoint);
        $cached = get_transient($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $failureKey = self::getFailureKey($endpoint);

        if (get_transient($failureKey)) {
            if (self::getFailureTtl() > 0) {
                return self::getStalePromotions($staleCacheKey);
            }

            delete_transient($failureKey);
        }

        $lockKey = self::getLockKey($endpoint);
        if (get_transient($lockKey)) {
            return self::getStalePromotions($staleCacheKey);
        }

        set_transient($lockKey, 1, self::LOCK_TTL);

        try {
            $promotions = self::fetchPromotions($endpoint);
        } finally {
            delete_transient($lockKey);
        }

        if (!is_array($promotions)) {
            $failureTtl = self::getFailureTtl();
            if ($failureTtl > 0) {
                set_transient($failureKey, 1, $failureTtl);
            }

            return self::getStalePromotions($staleCacheKey);
        }

        $cacheTtl = self::getCacheTtl();
        if ($cacheTtl > 0) {
            set_transient($cacheKey, $promotions, $cacheTtl);
        }
        set_transient($staleCacheKey, $promotions, self::STALE_CACHE_TTL);

        return $promotions;
    }

    public static function clearCache(): void
    {
        global $wpdb;

        $patterns = [
            '_transient_mailerpress_remote_promotions_',
            '_transient_timeout_mailerpress_remote_promotions_',
            '_transient_mailerpress_remote_promotions_failure_',
            '_transient_timeout_mailerpress_remote_promotions_failure_',
            '_transient_mailerpress_remote_promotions_lock_',
            '_transient_timeout_mailerpress_remote_promotions_lock_',
            '_transient_mailerpress_remote_promotions_stale_',
            '_transient_timeout_mailerpress_remote_promotions_stale_',
        ];

        foreach ($patterns as $pattern) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like($pattern) . '%'
                )
            );
        }
    }

    private static function isEnabled(): bool
    {
        return (bool) apply_filters('mailerpress_remote_promotions_enabled', true);
    }

    private static function getEndpoint(): string
    {
        $endpoint = (string) apply_filters(
            'mailerpress_remote_promotions_endpoint',
            'https://mailerpress.com/wp-json/mailerpress-site/v1/promotions'
        );

        return esc_url_raw($endpoint);
    }

    private static function fetchPromotions(string $endpoint): ?array
    {
        $response = wp_remote_get(add_query_arg(self::getRequestContext(), $endpoint), [
            'timeout' => self::getRequestTimeout(),
            'redirection' => 1,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return null;
        }

        $promotions = $body['data'] ?? $body['promotions'] ?? [];

        return is_array($promotions) ? $promotions : null;
    }

    private static function getStalePromotions(string $staleCacheKey): array
    {
        $stale = get_transient($staleCacheKey);

        return is_array($stale) ? $stale : [];
    }

    private static function getRequestContext(): array
    {
        return [
            'version' => self::getPluginVersion(),
            'is_pro' => self::isProActive() ? '1' : '0',
            'pro_installed' => self::isProInstalled() ? '1' : '0',
            'license_valid' => self::isLicenseValid() ? '1' : '0',
            'install_days' => (string) self::getInstallAgeInDays(),
            'locale' => ExternalLinks::getLocale(),
            'site_domain' => self::getSiteDomain(),
            'site_url' => esc_url_raw(home_url('/')),
            'access_token' => self::getAccessToken(),
        ];
    }

    private static function getCacheKey(string $endpoint): string
    {
        return 'mailerpress_remote_promotions_' . md5($endpoint . wp_json_encode(self::getRequestContext()));
    }

    private static function getStaleCacheKey(string $endpoint): string
    {
        return 'mailerpress_remote_promotions_stale_' . md5($endpoint . wp_json_encode(self::getRequestContext()));
    }

    private static function getFailureKey(string $endpoint): string
    {
        return 'mailerpress_remote_promotions_failure_' . md5($endpoint . wp_json_encode(self::getRequestContext()));
    }

    private static function getLockKey(string $endpoint): string
    {
        return 'mailerpress_remote_promotions_lock_' . md5($endpoint . wp_json_encode(self::getRequestContext()));
    }

    private static function getCacheTtl(): int
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return max(0, (int) apply_filters('mailerpress_remote_promotions_cache_ttl', MINUTE_IN_SECONDS));
        }

        return max(0, (int) apply_filters('mailerpress_remote_promotions_cache_ttl', self::CACHE_TTL));
    }

    private static function getFailureTtl(): int
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return max(0, (int) apply_filters('mailerpress_remote_promotions_failure_ttl', 30));
        }

        return max(0, (int) apply_filters('mailerpress_remote_promotions_failure_ttl', self::FAILURE_TTL));
    }

    private static function getRequestTimeout(): float
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return max(0.1, (float) apply_filters('mailerpress_remote_promotions_request_timeout', self::REQUEST_TIMEOUT));
        }

        return max(0.1, (float) apply_filters('mailerpress_remote_promotions_request_timeout', self::REQUEST_TIMEOUT));
    }

    private static function getPluginVersion(): string
    {
        if (!defined('MAILERPRESS_VERSION') || str_contains((string) MAILERPRESS_VERSION, '{{')) {
            return 'dev';
        }

        return sanitize_text_field((string) MAILERPRESS_VERSION);
    }

    private static function isProActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    private static function isProInstalled(): bool
    {
        return file_exists(WP_PLUGIN_DIR . '/mailerpress-pro/mailerpress-pro.php');
    }

    private static function isLicenseValid(): bool
    {
        return (bool) get_option('mailerpress_license_activated', false);
    }

    private static function getInstallAgeInDays(): int
    {
        $installedAt = (int) get_option('mailerpress_installed_at', 0);

        if ($installedAt <= 0) {
            $installedAt = time();
            add_option('mailerpress_installed_at', $installedAt, '', false);
        }

        return max(0, (int) floor((time() - $installedAt) / DAY_IN_SECONDS));
    }

    private static function getSiteDomain(): string
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (!is_string($host)) {
            return '';
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);
        $host = preg_replace('/[^a-z0-9.-]/', '', (string) $host);

        return trim((string) $host, '.');
    }

    private static function getAccessToken(): string
    {
        $token = (string) apply_filters('mailerpress_remote_promotions_access_token', '');

        if (defined('MAILERPRESS_REMOTE_PROMOTIONS_ACCESS_TOKEN')) {
            $token = (string) MAILERPRESS_REMOTE_PROMOTIONS_ACCESS_TOKEN;
        }

        return sanitize_text_field($token);
    }
}
