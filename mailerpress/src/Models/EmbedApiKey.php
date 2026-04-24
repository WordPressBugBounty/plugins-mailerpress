<?php

declare(strict_types=1);

namespace MailerPress\Models;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class EmbedApiKey
{
    /**
     * Generate a new API key
     * Format: mp_live_[40 random hexadecimal characters]
     *
     * @return string
     */
    public static function generate(): string
    {
        return 'mp_live_' . bin2hex(random_bytes(20));
    }

    /**
     * Hash an API key for secure storage
     *
     * @param string $key
     * @return string SHA-256 hash
     */
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Create a new API key
     *
     * @param array $data Array containing: name, allowed_domain, rate_limit_requests, rate_limit_window, notes
     * @return array Array with: id, key (plaintext), hash
     */
    public static function create(array $data): array
    {
        global $wpdb;

        $key = self::generate();
        $hash = self::hash($key);
        $table = Tables::get(Tables::MAILERPRESS_EMBED_API_KEYS);

        $wpdb->insert(
            $table,
            [
                'api_key' => $hash,
                'name' => sanitize_text_field($data['name'] ?? ''),
                'allowed_domain' => !empty($data['allowed_domain']) ? sanitize_text_field($data['allowed_domain']) : null,
                'rate_limit_requests' => (int)($data['rate_limit_requests'] ?? 5),
                'rate_limit_window' => (int)($data['rate_limit_window'] ?? 60),
                'notes' => !empty($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
                'status' => 'active',
            ],
            [
                '%s', // api_key
                '%s', // name
                '%s', // allowed_domain
                '%d', // rate_limit_requests
                '%d', // rate_limit_window
                '%s', // notes
                '%s', // status
            ]
        );

        return [
            'id' => $wpdb->insert_id,
            'key' => $key, // Return plaintext ONLY on creation
            'hash' => $hash,
        ];
    }

    /**
     * Validate an API key and return key data if valid
     *
     * @param string $key The API key to validate
     * @return object|null Key data if valid and active, null otherwise
     */
    public static function validate(string $key): ?object
    {
        global $wpdb;

        $hash = self::hash($key);
        $table = Tables::get(Tables::MAILERPRESS_EMBED_API_KEYS);

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE api_key = %s AND status = 'active'",
                $hash
            )
        );

        // Cast numeric fields to proper types
        if ($result) {
            $result->id = (int)$result->id;
            $result->rate_limit_requests = (int)$result->rate_limit_requests;
            $result->rate_limit_window = (int)$result->rate_limit_window;
            $result->request_count = (int)$result->request_count;
        }

        return $result ?: null;
    }

    /**
     * Revoke an API key
     *
     * @param int $id The API key ID
     * @return bool True on success, false on failure
     */
    public static function revoke(int $id): bool
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_API_KEYS);

        $result = $wpdb->update(
            $table,
            ['status' => 'revoked'],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Activate a revoked API key
     *
     * @param int $id The API key ID
     * @return bool True on success, false on failure
     */
    public static function activate(int $id): bool
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_API_KEYS);

        $result = $wpdb->update(
            $table,
            ['status' => 'active'],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Update usage statistics for an API key
     *
     * @param int $id The API key ID
     * @return bool True on success, false on failure
     */
    public static function updateUsage(int $id): bool
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_API_KEYS);

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET request_count = request_count + 1, last_used_at = %s WHERE id = %d",
                current_time('mysql'),
                $id
            )
        );

        return $result !== false;
    }

    /**
     * Get all API keys (for admin display)
     *
     * @return array Array of API key objects
     */
    public static function getAll(): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_API_KEYS);

        $results = $wpdb->get_results(
            "SELECT id, name,
                    CONCAT(LEFT(api_key, 12), '...') as api_key_preview,
                    allowed_domain, status, request_count,
                    created_at, last_used_at, rate_limit_requests, rate_limit_window, notes
             FROM {$table}
             ORDER BY created_at DESC"
        );

        return $results ?: [];
    }

    /**
     * Get a single API key by ID
     *
     * @param int $id The API key ID
     * @return object|null Key data if found, null otherwise
     */
    public static function getById(int $id): ?object
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMBED_API_KEYS);

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $id
            )
        );

        // Cast numeric fields to proper types
        if ($result) {
            $result->id = (int)$result->id;
            $result->rate_limit_requests = (int)$result->rate_limit_requests;
            $result->rate_limit_window = (int)$result->rate_limit_window;
            $result->request_count = (int)$result->request_count;
        }

        return $result ?: null;
    }
}
