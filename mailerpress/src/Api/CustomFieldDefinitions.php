<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Api\Permissions;
use MailerPress\Models\CustomFields;

class CustomFieldDefinitions
{
    #[Endpoint(
        'custom-field/all',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
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

    #[Endpoint(
        'custom-field',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function create(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CUSTOM_FIELD_DEFINITIONS);

        $label = sanitize_text_field($request->get_param('label') ?? '');
        $type = sanitize_text_field($request->get_param('type') ?? 'text');

        if (empty($label)) {
            return new \WP_Error('invalid_input', 'The field label cannot be empty.', ['status' => 400]);
        }

        $allowed_types = ['text', 'number', 'date', 'checkbox', 'select'];
        if (!in_array($type, $allowed_types, true)) {
            $type = 'text';
        }

        // Generate field_key from label: lowercase, replace non-alphanumeric with underscore
        $field_key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label));
        $field_key = trim($field_key, '_');

        if (empty($field_key)) {
            return new \WP_Error('invalid_input', 'Could not generate a valid field key from the label.', ['status' => 400]);
        }

        // Check for duplicate field_key
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, field_key, label FROM {$table} WHERE field_key = %s",
                $field_key
            )
        );

        if ($existing) {
            // Return existing field instead of error — allows "get or create" semantics
            return new \WP_REST_Response([
                'id'        => (int) $existing->id,
                'field_key' => $existing->field_key,
                'label'     => $existing->label,
                'type'      => $type,
                'required'  => false,
                'is_editable' => true,
                'existing'  => true,
            ], 200);
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'field_key'   => $field_key,
                'label'       => $label,
                'type'        => $type,
                'required'    => 0,
                'is_editable' => 1,
            ],
            ['%s', '%s', '%s', '%d', '%d']
        );

        if (false === $inserted) {
            return new \WP_Error('db_error', 'Failed to create the custom field.', ['status' => 500]);
        }

        CustomFields::clearCache();

        $new_id = $wpdb->insert_id;

        return new \WP_REST_Response([
            'id'        => $new_id,
            'field_key' => $field_key,
            'label'     => $label,
            'type'      => $type,
            'required'  => false,
            'is_editable' => true,
        ], 200);
    }
}
