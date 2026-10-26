<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Api\Permissions;

class CustomFieldDefinitions
{
    #[Endpoint(
        'custom-field/all',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canReadCustomFields'],
    )]
    public function getAll(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CUSTOM_FIELD_DEFINITIONS);

        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
        if (!$tableExists) {
            return new \WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results(
            "SELECT id, field_key, label, type, options, required, is_editable
             FROM {$table}
             ORDER BY label ASC",
            ARRAY_A
        );

        if (!is_array($results)) {
            return new \WP_REST_Response([], 200);
        }

        foreach ($results as &$field) {
            $field['id'] = (int) $field['id'];
            $field['required'] = (bool) $field['required'];
            $field['is_editable'] = (bool) $field['is_editable'];
            if (!empty($field['options'])) {
                $field['options'] = maybe_unserialize($field['options']);
            }
        }

        return new \WP_REST_Response($results, 200);
    }
}
