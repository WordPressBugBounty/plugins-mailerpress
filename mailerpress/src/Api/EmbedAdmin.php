<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Models\EmbedApiKey;
use WP_REST_Request;
use WP_REST_Response;

class EmbedAdmin
{
    /**
     * Check if Pro version is active
     *
     * @return bool
     */
    private function isProActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active')
            && is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    /**
     * List all API keys (admin only)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/keys',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function listKeys(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        global $wpdb;
        $keys = EmbedApiKey::getAll();

        // Debug info
        $table = \MailerPress\Core\Enums\Tables::get(\MailerPress\Core\Enums\Tables::MAILERPRESS_EMBED_API_KEYS);
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;

        return new WP_REST_Response([
            'success' => true,
            'keys' => $keys,
            'debug' => [
                'table_name' => $table,
                'table_exists' => $tableExists,
                'key_count' => count($keys),
            ]
        ]);
    }

    /**
     * Create a new API key (admin only)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/keys',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function createKey(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        $name = $request->get_param('name');

        if (empty($name)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Key name is required', 'mailerpress'),
            ], 400);
        }

        $data = [
            'name' => $name,
            'allowed_domain' => $request->get_param('allowed_domain') ?? '',
            'rate_limit_requests' => (int)($request->get_param('rate_limit_requests') ?? 5),
            'rate_limit_window' => (int)($request->get_param('rate_limit_window') ?? 60),
            'notes' => $request->get_param('notes') ?? '',
        ];

        $result = EmbedApiKey::create($data);

        return new WP_REST_Response([
            'success' => true,
            'id' => $result['id'],
            'key' => $result['key'], // Only returned on creation!
            'message' => __('API key created successfully', 'mailerpress'),
        ]);
    }

    /**
     * Revoke an API key (admin only)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/keys/(?P<id>\d+)/revoke',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function revokeKey(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        $id = (int)$request['id'];

        $success = EmbedApiKey::revoke($id);

        if (!$success) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Failed to revoke API key', 'mailerpress'),
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key revoked successfully', 'mailerpress'),
        ]);
    }

    /**
     * Activate a revoked API key (admin only)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/keys/(?P<id>\d+)/activate',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function activateKey(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        $id = (int)$request['id'];

        $success = EmbedApiKey::activate($id);

        if (!$success) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Failed to activate API key', 'mailerpress'),
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key activated successfully', 'mailerpress'),
        ]);
    }

    /**
     * Update an API key (admin only)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/keys/(?P<id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function updateKey(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        global $wpdb;

        $id = (int)$request['id'];
        $keyData = EmbedApiKey::getById($id);

        if (!$keyData) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('API key not found', 'mailerpress'),
            ], 404);
        }

        $updateData = [];
        $format = [];

        if ($request->has_param('name')) {
            $updateData['name'] = sanitize_text_field($request->get_param('name'));
            $format[] = '%s';
        }

        if ($request->has_param('allowed_domain')) {
            $updateData['allowed_domain'] = sanitize_text_field($request->get_param('allowed_domain'));
            $format[] = '%s';
        }

        if ($request->has_param('rate_limit_requests')) {
            $updateData['rate_limit_requests'] = (int)$request->get_param('rate_limit_requests');
            $format[] = '%d';
        }

        if ($request->has_param('rate_limit_window')) {
            $updateData['rate_limit_window'] = (int)$request->get_param('rate_limit_window');
            $format[] = '%d';
        }

        if ($request->has_param('notes')) {
            $updateData['notes'] = sanitize_textarea_field($request->get_param('notes'));
            $format[] = '%s';
        }

        if (empty($updateData)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('No data to update', 'mailerpress'),
            ], 400);
        }

        $table = \MailerPress\Core\Enums\Tables::get(\MailerPress\Core\Enums\Tables::MAILERPRESS_EMBED_API_KEYS);

        $result = $wpdb->update(
            $table,
            $updateData,
            ['id' => $id],
            $format,
            ['%d']
        );

        if ($result === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Failed to update API key', 'mailerpress'),
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key updated successfully', 'mailerpress'),
        ]);
    }

    /**
     * Delete an API key permanently (admin only)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/keys/(?P<id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function deleteKey(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        global $wpdb;

        $id = (int)$request['id'];
        $table = \MailerPress\Core\Enums\Tables::get(\MailerPress\Core\Enums\Tables::MAILERPRESS_EMBED_API_KEYS);

        // Check if key exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d",
            $id
        ));

        if (!$exists) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('API key not found', 'mailerpress'),
            ], 404);
        }

        $result = $wpdb->delete(
            $table,
            ['id' => $id],
            ['%d']
        );

        // $result is the number of rows deleted, or false on error
        // Since we already checked the key exists, if $result is 0, it means
        // the key was deleted between the check and the delete (race condition)
        // We still consider this a success since the end result is the same
        if ($result === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Database error while deleting API key', 'mailerpress'),
                'error' => $wpdb->last_error,
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key deleted successfully', 'mailerpress'),
        ]);
    }
}
