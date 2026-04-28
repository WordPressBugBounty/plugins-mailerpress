<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Synchronisation\SynchronisationManager;

class Synchronisation
{
    private SynchronisationManager $manager;

    public function __construct(SynchronisationManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * GET /mailerpress/v1/sync/connectors
     * Returns all registered connectors with their DB status for the UI listing.
     */
    #[Endpoint(
        'sync/connectors',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function listConnectors(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->manager->getAllWithStatus(), 200);
    }

    /**
     * GET|POST /mailerpress/v1/sync/connectors/{key}
     * GET  → returns connector details + settings
     * POST → saves settings and status (body: { settings: {...}, status: 'active'|'inactive' })
     */
    #[Endpoint(
        'sync/connectors/(?P<key>[a-z_]+)',
        methods: 'GET, POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function handleConnector(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key       = sanitize_key($request->get_param('key'));
        $connector = $this->manager->get($key);

        if (!$connector) {
            return new \WP_Error('not_found', __('Connector not found.', 'mailerpress'), ['status' => 404]);
        }

        if ($request->get_method() === 'POST') {
            $settings = $request->get_param('settings') ?? [];
            $status   = $request->get_param('status') ?? 'inactive';

            if (!in_array($status, ['active', 'inactive'], true)) {
                return new \WP_Error('invalid_status', __('Invalid status value.', 'mailerpress'), ['status' => 400]);
            }

            if ('inactive' === $status) {
                $connector->unregisterHooks();
            }

            $saved = $this->manager->saveConnector($key, (array) $settings, $status);

            if (!$saved) {
                return new \WP_Error('save_failed', __('Failed to save connector settings.', 'mailerpress'), ['status' => 500]);
            }

            if ('active' === $status) {
                $connector->registerHooks((array) $settings);
            }

            return new \WP_REST_Response(['success' => true], 200);
        }

        // GET
        $record = $this->manager->getDbRecord($key);

        return new \WP_REST_Response([
            'key'             => $key,
            'label'           => $connector->getLabel(),
            'description'     => $connector->getDescription(),
            'icon'            => $connector->getIcon(),
            'status'          => $record?->status ?? 'inactive',
            'settings'        => $record?->settings
                ? (json_decode($record->settings, true) ?? $connector->getDefaultSettings())
                : $connector->getDefaultSettings(),
            'last_sync_at'    => $record?->last_sync_at ?? null,
            'last_sync_count' => (int) ($record?->last_sync_count ?? 0),
            'last_error'      => $record?->last_error ?? null,
        ], 200);
    }

    /**
     * GET /mailerpress/v1/sync/connectors/{key}/source-fields
     * Return the available source fields for a connector (used to build field mapping UI).
     * Passes current saved settings so API-based connectors can fetch live fields.
     */
    #[Endpoint(
        'sync/connectors/(?P<key>[a-z_]+)/source-fields',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function getSourceFields(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key       = sanitize_key($request->get_param('key'));
        $connector = $this->manager->get($key);

        if (!$connector) {
            return new \WP_Error('not_found', __('Connector not found.', 'mailerpress'), ['status' => 404]);
        }

        $record   = $this->manager->getDbRecord($key);
        $settings = $record?->settings
            ? (json_decode($record->settings, true) ?? $connector->getDefaultSettings())
            : $connector->getDefaultSettings();

        return new \WP_REST_Response($connector->getAvailableSourceFields($settings), 200);
    }

    /**
     * POST /mailerpress/v1/sync/connectors/{key}/sync
     * Trigger a manual full synchronisation for the given connector.
     */
    #[Endpoint(
        'sync/connectors/(?P<key>[a-z_]+)/sync',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function runSync(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = sanitize_key($request->get_param('key'));
        $connector = $this->manager->get($key);

        if (!$connector) {
            return new \WP_Error('not_found', __('Connector not found.', 'mailerpress'), ['status' => 404]);
        }

        $record   = $this->manager->getDbRecord($key);
        $settings = $record?->settings
            ? (json_decode($record->settings, true) ?? $connector->getDefaultSettings())
            : $connector->getDefaultSettings();

        try {
            $result = $connector->sync($settings);
            $this->manager->updateSyncResult($key, $result['synced'], null);

            return new \WP_REST_Response([
                'success' => true,
                'synced'  => $result['synced'],
                'skipped' => $result['skipped'],
                'errors'  => $result['errors'],
            ], 200);
        } catch (\Throwable $e) {
            $this->manager->updateSyncResult($key, 0, $e->getMessage());

            return new \WP_Error('sync_failed', $e->getMessage(), ['status' => 500]);
        }
    }
}
