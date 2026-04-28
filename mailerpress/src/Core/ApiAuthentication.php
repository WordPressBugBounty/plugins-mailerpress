<?php

declare(strict_types=1);

namespace MailerPress\Core;

use MailerPress\Core\Enums\Tables;

\defined('ABSPATH') || exit;

/**
 * API Authentication Handler
 *
 * Supports multiple authentication methods:
 * 1. WordPress Application Passwords (HTTP Basic Auth)
 * 2. Custom API Keys (X-MailerPress-API-Key + X-MailerPress-API-Secret headers)
 * 3. WordPress Cookie/Nonce (default for admin UI)
 */
class ApiAuthentication
{
    /**
     * Authenticate API request using custom API keys
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error True if authenticated, WP_Error if failed, false to try other methods
     */
    public static function authenticate($request)
    {
        // Check for custom API key headers
        $api_key = $request->get_header('X-MailerPress-API-Key');
        $api_secret = $request->get_header('X-MailerPress-API-Secret');

        // If no API key provided, return false to allow other auth methods
        if (empty($api_key) || empty($api_secret)) {
            return false;
        }

        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        // Hash the provided credentials
        $api_key_hash = hash('sha256', $api_key);
        $api_secret_hash = hash('sha256', $api_secret);

        // Fetch the API key record
        $key_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE api_key_hash = %s AND api_secret_hash = %s",
            $api_key_hash,
            $api_secret_hash
        ));

        // Invalid credentials
        if (!$key_record) {
            return new \WP_Error(
                'invalid_api_key',
                __('Invalid API credentials', 'mailerpress'),
                ['status' => 401]
            );
        }

        // Check if key is active
        if ($key_record->status !== 'active') {
            return new \WP_Error(
                'revoked_api_key',
                __('API key has been revoked or expired', 'mailerpress'),
                ['status' => 401]
            );
        }

        // Check expiration
        if ($key_record->expires_at && strtotime($key_record->expires_at) < time()) {
            // Auto-mark as expired
            $wpdb->update(
                $table,
                ['status' => 'expired'],
                ['key_id' => $key_record->key_id],
                ['%s'],
                ['%d']
            );

            return new \WP_Error(
                'expired_api_key',
                __('API key has expired', 'mailerpress'),
                ['status' => 401]
            );
        }

        // Check IP whitelist if configured
        if (!empty($key_record->allowed_ips)) {
            $allowed_ips = array_map('trim', explode(',', $key_record->allowed_ips));
            $client_ip = self::getClientIp();

            if (!self::isIpAllowed($client_ip, $allowed_ips)) {
                return new \WP_Error(
                    'forbidden_ip',
                    __('Your IP address is not authorized to use this API key', 'mailerpress'),
                    ['status' => 403]
                );
            }
        }

        // Check rate limit
        $rate_limit_check = self::checkRateLimit($key_record);
        if (is_wp_error($rate_limit_check)) {
            return $rate_limit_check;
        }

        // Update last used timestamp and increment request count
        $wpdb->update(
            $table,
            [
                'last_used_at' => current_time('mysql'),
                'request_count' => $key_record->request_count + 1,
            ],
            ['key_id' => $key_record->key_id],
            ['%s', '%d'],
            ['%d']
        );

        // Set WordPress user context for permission checks
        wp_set_current_user($key_record->user_id);

        // Store key info in request for later use (e.g., logging)
        $request->set_param('_api_key_id', $key_record->key_id);
        $request->set_param('_api_key_name', $key_record->name);

        return true;
    }

    /**
     * Check rate limit for API key
     *
     * @param object $key_record
     * @return bool|\WP_Error
     */
    private static function checkRateLimit($key_record)
    {
        global $wpdb;

        $limit = (int)$key_record->rate_limit_requests;
        $window = (int)$key_record->rate_limit_window; // seconds

        if ($limit <= 0) {
            return true; // No rate limit
        }

        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        // Simple rate limiting: check requests in the last window period
        // For production, consider using Redis or a dedicated rate limit table
        $window_start = date('Y-m-d H:i:s', time() - $window);

        // Count recent requests (simplified - in production use a proper rate limit table)
        $recent_requests = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE key_id = %d
             AND last_used_at >= %s",
            $key_record->key_id,
            $window_start
        ));

        if ($recent_requests >= $limit) {
            $retry_after = $window; // seconds until rate limit resets

            return new \WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    __('Rate limit exceeded. Maximum %d requests per %d seconds. Try again in %d seconds.', 'mailerpress'),
                    $limit,
                    $window,
                    $retry_after
                ),
                [
                    'status' => 429,
                    'headers' => [
                        'Retry-After' => $retry_after,
                        'X-RateLimit-Limit' => $limit,
                        'X-RateLimit-Window' => $window,
                    ],
                ]
            );
        }

        return true;
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private static function getClientIp(): string
    {
        $remote_addr = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');

        /**
         * Filter trusted proxy IPs/CIDRs. Only when the request comes from a trusted proxy
         * will forwarded headers (X-Forwarded-For, CF-Connecting-IP) be respected.
         *
         * @param array $trusted_proxies List of trusted proxy IPs or CIDR ranges
         */
        $trusted_proxies = apply_filters('mailerpress_trusted_proxies', []);

        $is_trusted = false;
        foreach ($trusted_proxies as $proxy) {
            if ($remote_addr === $proxy || (str_contains($proxy, '/') && self::ipInCidr($remote_addr, $proxy))) {
                $is_trusted = true;
                break;
            }
        }

        if ($is_trusted) {
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                return sanitize_text_field(trim($_SERVER['HTTP_CF_CONNECTING_IP']));
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                return sanitize_text_field(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]));
            }
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                return sanitize_text_field(trim($_SERVER['HTTP_X_REAL_IP']));
            }
        }

        return $remote_addr;
    }

    /**
     * Check if IP is in allowed list (supports CIDR notation)
     *
     * @param string $ip Client IP
     * @param array $allowed_ips List of allowed IPs or CIDR ranges
     * @return bool
     */
    private static function isIpAllowed(string $ip, array $allowed_ips): bool
    {
        foreach ($allowed_ips as $allowed) {
            // Exact match
            if ($ip === $allowed) {
                return true;
            }

            // CIDR range match (e.g., 192.168.1.0/24)
            if (strpos($allowed, '/') !== false) {
                if (self::ipInCidr($ip, $allowed)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = ~((1 << (32 - $mask)) - 1);

        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }

    /**
     * Generate a new API key pair
     *
     * @return array ['api_key' => string, 'api_secret' => string]
     */
    public static function generateApiKeyPair(): array
    {
        // Generate cryptographically secure random keys
        $api_key = 'mpk_' . bin2hex(random_bytes(24)); // 48 chars + prefix
        $api_secret = 'mps_' . bin2hex(random_bytes(32)); // 64 chars + prefix

        return [
            'api_key' => $api_key,
            'api_secret' => $api_secret,
            'api_key_hash' => hash('sha256', $api_key),
            'api_secret_hash' => hash('sha256', $api_secret),
        ];
    }

    /**
     * Verify permission scope for API key
     *
     * @param \WP_REST_Request $request
     * @param string $required_scope e.g., 'contacts:write'
     * @return bool
     */
    public static function hasPermission($request, string $required_scope): bool
    {
        $api_key_id = $request->get_param('_api_key_id');

        // If not using API key auth, skip scope check
        if (!$api_key_id) {
            return true;
        }

        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        $permissions = $wpdb->get_var($wpdb->prepare(
            "SELECT permissions FROM {$table} WHERE key_id = %d",
            $api_key_id
        ));

        if (empty($permissions)) {
            return false; // No permissions set = no access (least privilege)
        }

        $permissions_array = json_decode($permissions, true);
        if (!is_array($permissions_array)) {
            return true;
        }

        // Check if required scope is in permissions
        // Also check for wildcard permissions (e.g., 'contacts:*' covers 'contacts:read' and 'contacts:write')
        list($resource, $action) = explode(':', $required_scope);

        return in_array($required_scope, $permissions_array, true)
            || in_array("{$resource}:*", $permissions_array, true)
            || in_array('*:*', $permissions_array, true);
    }
}
