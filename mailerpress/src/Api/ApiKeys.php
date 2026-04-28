<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\ApiAuthentication;
use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class ApiKeys
{
    /**
     * List all API keys for the current user
     */
    #[Endpoint(
        'api-keys',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function listKeys(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        $page = max(1, (int)($request->get_param('paged') ?? $request->get_param('page') ?? 1));
        $per_page = max(1, min(100, (int)($request->get_param('perPages') ?? $request->get_param('per_page') ?? 20)));
        $offset = ($page - 1) * $per_page;

        $search = $request->get_param('search');
        $status = $request->get_param('status');

        // Sorting
        $allowed_orderby = ['key_id', 'name', 'status', 'request_count', 'last_used_at', 'created_at'];
        $orderby = in_array($request->get_param('orderby'), $allowed_orderby, true)
            ? $request->get_param('orderby')
            : 'created_at';
        $order = strtoupper($request->get_param('order') ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Build WHERE clause
        $where = $wpdb->prepare('WHERE user_id = %d', $user_id);
        $params = [];

        if (!empty($search)) {
            $where .= ' AND (name LIKE %s OR description LIKE %s)';
            $like_search = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like_search;
            $params[] = $like_search;
        }

        if (!empty($status) && in_array($status, ['active', 'revoked', 'expired'], true)) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        // Count total
        $count_query = "SELECT COUNT(*) FROM {$table} {$where}";
        $total = (int)$wpdb->get_var(
            empty($params) ? $count_query : $wpdb->prepare($count_query, ...$params)
        );

        // Fetch keys (without sensitive hashes)
        $query = "
            SELECT
                key_id,
                name,
                description,
                permissions,
                status,
                rate_limit_requests,
                rate_limit_window,
                allowed_ips,
                request_count,
                last_used_at,
                expires_at,
                created_at,
                updated_at
            FROM {$table}
            {$where}
            ORDER BY {$orderby} {$order}
            LIMIT %d OFFSET %d
        ";

        $params[] = $per_page;
        $params[] = $offset;

        $keys = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);

        // Parse JSON fields
        foreach ($keys as &$key) {
            $key['permissions'] = !empty($key['permissions']) ? json_decode($key['permissions'], true) : [];
            $key['allowed_ips'] = !empty($key['allowed_ips'])
                ? array_map('trim', explode(',', $key['allowed_ips']))
                : [];
        }

        return new WP_REST_Response([
            'posts' => $keys,
            'count' => $total,
            'page' => $page,
            'pages' => ceil($total / $per_page),
        ], 200);
    }

    /**
     * Create a new API key
     */
    #[Endpoint(
        'api-keys',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function createKey(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        // Validate input
        $name = sanitize_text_field($request->get_param('name'));
        if (empty($name)) {
            return new WP_Error(
                'missing_name',
                __('API key name is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        $description = sanitize_textarea_field($request->get_param('description') ?? '');
        $permissions = $request->get_param('permissions') ?? [];
        $rate_limit_requests = max(1, (int)($request->get_param('rate_limit_requests') ?? 1000));
        $rate_limit_window = max(60, (int)($request->get_param('rate_limit_window') ?? 3600));
        $allowed_ips = $request->get_param('allowed_ips') ?? [];
        $expires_in_days = (int)($request->get_param('expires_in_days') ?? 0);

        // Validate permissions
        if (!empty($permissions) && !is_array($permissions)) {
            return new WP_Error(
                'invalid_permissions',
                __('Permissions must be an array', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Validate IPs
        if (!empty($allowed_ips)) {
            if (!is_array($allowed_ips)) {
                return new WP_Error(
                    'invalid_ips',
                    __('Allowed IPs must be an array', 'mailerpress'),
                    ['status' => 400]
                );
            }

            foreach ($allowed_ips as $ip) {
                // Basic validation - improve for production
                if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[\d\.\/]+$/', $ip)) {
                    return new WP_Error(
                        'invalid_ip_format',
                        sprintf(__('Invalid IP format: %s', 'mailerpress'), $ip),
                        ['status' => 400]
                    );
                }
            }
        }

        // Generate API key pair
        $key_pair = ApiAuthentication::generateApiKeyPair();

        // Calculate expiration
        $expires_at = null;
        if ($expires_in_days > 0) {
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_in_days} days"));
        }

        // Insert into database
        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'api_key_hash' => $key_pair['api_key_hash'],
                'api_secret_hash' => $key_pair['api_secret_hash'],
                'name' => $name,
                'description' => $description,
                'permissions' => !empty($permissions) ? json_encode($permissions) : null,
                'status' => 'active',
                'rate_limit_requests' => $rate_limit_requests,
                'rate_limit_window' => $rate_limit_window,
                'allowed_ips' => !empty($allowed_ips) ? implode(',', $allowed_ips) : null,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql'),
            ],
            [
                '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s'
            ]
        );

        if (!$inserted) {
            return new WP_Error(
                'creation_failed',
                __('Failed to create API key', 'mailerpress'),
                ['status' => 500]
            );
        }

        $key_id = $wpdb->insert_id;

        // Return the key pair (ONLY TIME these values are returned!)
        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key created successfully. Please save these credentials - they will not be shown again.', 'mailerpress'),
            'key_id' => $key_id,
            'api_key' => $key_pair['api_key'],
            'api_secret' => $key_pair['api_secret'],
            'name' => $name,
            'expires_at' => $expires_at,
        ], 201);
    }

    /**
     * Update an API key (metadata only, not credentials)
     */
    #[Endpoint(
        'api-keys/(?P<key_id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function updateKey(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $key_id = (int)$request->get_param('key_id');
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        // Verify ownership
        $key = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE key_id = %d AND user_id = %d",
            $key_id,
            $user_id
        ));

        if (!$key) {
            return new WP_Error(
                'key_not_found',
                __('API key not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Build update data
        $update_data = [];
        $update_format = [];

        if ($request->has_param('name')) {
            $name = sanitize_text_field($request->get_param('name'));
            if (!empty($name)) {
                $update_data['name'] = $name;
                $update_format[] = '%s';
            }
        }

        if ($request->has_param('description')) {
            $update_data['description'] = sanitize_textarea_field($request->get_param('description'));
            $update_format[] = '%s';
        }

        if ($request->has_param('permissions')) {
            $permissions = $request->get_param('permissions');
            $update_data['permissions'] = !empty($permissions) ? json_encode($permissions) : null;
            $update_format[] = '%s';
        }

        if ($request->has_param('rate_limit_requests')) {
            $update_data['rate_limit_requests'] = max(1, (int)$request->get_param('rate_limit_requests'));
            $update_format[] = '%d';
        }

        if ($request->has_param('rate_limit_window')) {
            $update_data['rate_limit_window'] = max(60, (int)$request->get_param('rate_limit_window'));
            $update_format[] = '%d';
        }

        if ($request->has_param('allowed_ips')) {
            $allowed_ips = $request->get_param('allowed_ips');
            $update_data['allowed_ips'] = !empty($allowed_ips) ? implode(',', $allowed_ips) : null;
            $update_format[] = '%s';
        }

        if (empty($update_data)) {
            return new WP_Error(
                'no_updates',
                __('No updates provided', 'mailerpress'),
                ['status' => 400]
            );
        }

        $update_data['updated_at'] = current_time('mysql');
        $update_format[] = '%s';

        $updated = $wpdb->update(
            $table,
            $update_data,
            ['key_id' => $key_id],
            $update_format,
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error(
                'update_failed',
                __('Failed to update API key', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key updated successfully', 'mailerpress'),
        ], 200);
    }

    /**
     * Revoke an API key
     */
    #[Endpoint(
        'api-keys/(?P<key_id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function revokeKey(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $key_id = (int)$request->get_param('key_id');
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        // Verify ownership
        $key = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE key_id = %d AND user_id = %d",
            $key_id,
            $user_id
        ));

        if (!$key) {
            return new WP_Error(
                'key_not_found',
                __('API key not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Soft delete - mark as revoked instead of hard delete for audit trail
        $updated = $wpdb->update(
            $table,
            [
                'status' => 'revoked',
                'updated_at' => current_time('mysql'),
            ],
            ['key_id' => $key_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error(
                'revoke_failed',
                __('Failed to revoke API key', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key revoked successfully', 'mailerpress'),
        ], 200);
    }

    /**
     * Reactivate a revoked API key
     */
    #[Endpoint(
        'api-keys/(?P<key_id>\d+)/reactivate',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function reactivateKey(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $key_id = (int)$request->get_param('key_id');
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        $key = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE key_id = %d AND user_id = %d",
            $key_id,
            $user_id
        ));

        if (!$key) {
            return new WP_Error(
                'key_not_found',
                __('API key not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        if ($key->status === 'active') {
            return new WP_Error(
                'already_active',
                __('API key is already active', 'mailerpress'),
                ['status' => 400]
            );
        }

        $updated = $wpdb->update(
            $table,
            [
                'status' => 'active',
                'updated_at' => current_time('mysql'),
            ],
            ['key_id' => $key_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error(
                'reactivate_failed',
                __('Failed to reactivate API key', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key reactivated successfully', 'mailerpress'),
        ], 200);
    }

    /**
     * Permanently delete an API key
     */
    #[Endpoint(
        'api-keys/(?P<key_id>\d+)/delete',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function deleteKey(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $key_id = (int)$request->get_param('key_id');
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        // Verify ownership
        $key = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE key_id = %d AND user_id = %d",
            $key_id,
            $user_id
        ));

        if ( ! $key ) {
            return new WP_Error(
                'key_not_found',
                __('API key not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        $deleted = $wpdb->delete(
            $table,
            ['key_id' => $key_id, 'user_id' => $user_id],
            ['%d', '%d']
        );

        if ( false === $deleted ) {
            return new WP_Error(
                'delete_failed',
                __('Failed to delete API key', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key permanently deleted', 'mailerpress'),
        ], 200);
    }

    /**
     * Get API key statistics
     */
    #[Endpoint(
        'api-keys/(?P<key_id>\d+)/stats',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function getKeyStats(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $key_id = (int)$request->get_param('key_id');
        $table = Tables::get(Tables::MAILERPRESS_API_KEYS);

        // Verify ownership
        $key = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE key_id = %d AND user_id = %d",
            $key_id,
            $user_id
        ));

        if (!$key) {
            return new WP_Error(
                'key_not_found',
                __('API key not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response([
            'key_id' => $key->key_id,
            'name' => $key->name,
            'status' => $key->status,
            'request_count' => (int)$key->request_count,
            'last_used_at' => $key->last_used_at,
            'created_at' => $key->created_at,
            'rate_limit' => [
                'requests' => (int)$key->rate_limit_requests,
                'window' => (int)$key->rate_limit_window,
            ],
        ], 200);
    }

    /**
     * Get available permission scopes
     */
    #[Endpoint(
        'api-keys/permissions',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function getAvailablePermissions(WP_REST_Request $request): WP_REST_Response
    {
        $permissions = [
            [
                'id' => 'contacts:read',
                'name' => __('Contacts: Read', 'mailerpress'),
                'description' => __('View contacts and their data', 'mailerpress'),
            ],
            [
                'id' => 'contacts:write',
                'name' => __('Contacts: Write', 'mailerpress'),
                'description' => __('Create, update, and delete contacts', 'mailerpress'),
            ],
            [
                'id' => 'campaigns:read',
                'name' => __('Campaigns: Read', 'mailerpress'),
                'description' => __('View campaigns and their statistics', 'mailerpress'),
            ],
            [
                'id' => 'campaigns:write',
                'name' => __('Campaigns: Write', 'mailerpress'),
                'description' => __('Create, update, and send campaigns', 'mailerpress'),
            ],
            [
                'id' => 'lists:read',
                'name' => __('Lists: Read', 'mailerpress'),
                'description' => __('View lists', 'mailerpress'),
            ],
            [
                'id' => 'lists:write',
                'name' => __('Lists: Write', 'mailerpress'),
                'description' => __('Create, update, and delete lists', 'mailerpress'),
            ],
            [
                'id' => 'tags:read',
                'name' => __('Tags: Read', 'mailerpress'),
                'description' => __('View tags', 'mailerpress'),
            ],
            [
                'id' => 'tags:write',
                'name' => __('Tags: Write', 'mailerpress'),
                'description' => __('Create, update, and delete tags', 'mailerpress'),
            ],
            [
                'id' => '*:*',
                'name' => __('Full Access', 'mailerpress'),
                'description' => __('Complete access to all endpoints', 'mailerpress'),
            ],
        ];

        return new WP_REST_Response([
            'permissions' => $permissions,
        ], 200);
    }
}
