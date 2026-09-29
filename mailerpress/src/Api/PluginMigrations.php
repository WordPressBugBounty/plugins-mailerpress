<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Migration\MigrationManager;

class PluginMigrations
{
    public function __construct(private MigrationManager $migrationManager)
    {
    }

    #[Endpoint(
        'migration/sources',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function sources(\WP_REST_Request $request): \WP_REST_Response
    {
        $refresh = filter_var($request->get_param('refresh'), FILTER_VALIDATE_BOOLEAN);
        $category = sanitize_key((string) $request->get_param('category'));
        $category = in_array($category, ['internal', 'external'], true) ? $category : null;

        return new \WP_REST_Response($this->migrationManager->listSources($refresh, $category), 200);
    }

    #[Endpoint(
        'migration/sources/(?P<source>[a-z0-9_]+)/preview',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function preview(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $source = sanitize_key((string) $request->get_param('source'));
        $entityTypes = $request->get_param('entity_types');
        $entityTypes = is_array($entityTypes) ? $entityTypes : [];
        $result = $this->migrationManager->preview($source, $entityTypes);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 200);
    }

    #[Endpoint(
        'migration/sources/(?P<source>[a-z0-9_]+)/run',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function start(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $source = sanitize_key((string) $request->get_param('source'));
        $entityTypes = $request->get_param('entity_types');
        $options = $request->get_param('options');

        $result = $this->migrationManager->start(
            $source,
            is_array($entityTypes) ? $entityTypes : [],
            is_array($options) ? $options : []
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 201);
    }

    #[Endpoint(
        'migration/runs/(?P<id>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function run(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->migrationManager->getRun((int) $request->get_param('id'));

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 200);
    }

    #[Endpoint(
        'migration/runs/(?P<id>\d+)/process-next',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function processNext(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->migrationManager->processNextChunk((int) $request->get_param('id'));

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 200);
    }

    #[Endpoint(
        'migration/runs/(?P<id>\d+)/cancel',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function cancel(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->migrationManager->cancel((int) $request->get_param('id'));

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 200);
    }
}
