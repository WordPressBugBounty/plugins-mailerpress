<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Capabilities;
use MailerPress\Core\Enums\Tables;

class Lists
{
    #[Endpoint(
        'list',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function all(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_LIST);
        $contacts_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        $search = $request->get_param('search');
        $per_page = isset($_GET['perPages']) ? (int)(sanitize_key(wp_unslash($_GET['perPages']))) : 20;
        $page = isset($_GET['paged']) ? (int)(sanitize_key(wp_unslash($_GET['paged']))) : 1;
        $offset = ($page - 1) * $per_page;

        // Filters
        $where = '1=1';
        $params = [];
        if (!empty($search)) {
            $where .= ' AND t.name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $allowed_orderby = ['name', 'list_id', 'created_at', 'contact_count'];
        $allowed_order = ['ASC', 'DESC'];
        $orderby_param = $request->get_param('orderby');
        $order_param = strtoupper($request->get_param('order') ?? 'DESC');
        $orderby = in_array($orderby_param, $allowed_orderby, true) ? $orderby_param : 'name';
        $order = in_array($order_param, $allowed_order, true) ? $order_param : 'ASC';

        // Handle contact_count specially since it's computed in the query
        if ($orderby === 'contact_count') {
            $orderBy = \sprintf('contact_count %s', $order);
        } else {
            $orderBy = \sprintf('t.%s %s', $orderby, $order);
        }

        // Total count for pagination
        $total_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} t WHERE {$where}", $params));

        $total_pages = ceil($total_count / $per_page);

        // Fetch lists with contact counts
        $response = [
            'posts' => $wpdb->get_results($wpdb->prepare(
                "SELECT t.*, t.list_id as id, COUNT(c.contact_id) as contact_count
            FROM {$table} t
            LEFT JOIN {$contacts_table} c ON c.list_id = t.list_id
            WHERE {$where}
            GROUP BY t.list_id
            ORDER BY {$orderBy}
            LIMIT %d OFFSET %d",
                [...$params, $per_page, $offset]
            )),
            'pages' => $total_pages,
            'count' => $total_count,
        ];

        return new \WP_REST_Response(
            $response,
            200
        );
    }

    #[Endpoint(
        'list/all',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function getAll(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_LIST); // returns the full table name, e.g., wp_mailerpress_list

        $results = $wpdb->get_results(
            "SELECT list_id as id, name FROM {$table}",
            ARRAY_A
        );

        return new \WP_REST_Response($results, 200);
    }


    #[Endpoint(
        'list',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function create(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_LIST);
        $name = sanitize_text_field($request->get_param('title'));
        $description = sanitize_textarea_field($request->get_param('description'));

        if (empty($name)) {
            return new \WP_Error(
                'invalid_input',
                __('The list name cannot be empty.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Check for duplicates
        $existing_list = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT list_id FROM {$table_name} WHERE name = %s",
                $name
            )
        );

        if ($existing_list) {
            return new \WP_Error(
                'duplicate_list',
                __('A list with this name already exists.', 'mailerpress'),
                ['status' => 409]
            );
        }

        // Check if this is the first list (if so, make it default)
        $total_lists = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $is_default = $total_lists == 0 ? 1 : 0;

        // Insert new list
        $inserted = $wpdb->insert(
            $table_name,
            [
                'name' => $name,
                'description' => $description,
                'is_default' => $is_default,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s']
        );

        if (false === $inserted) {
            return new \WP_Error('db_error', __('Failed to create the list.', 'mailerpress'), ['status' => 500]);
        }

        $new_list_id = $wpdb->insert_id;

        do_action('mailerpress_list_created', $new_list_id);

        return new \WP_REST_Response(
            [
                'id' => $new_list_id,
                'label' => $name,
                'description' => $description,
                'is_default' => $is_default,
            ],
            200
        );
    }

    #[Endpoint(
        '/list',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canDeleteLists'],
    )]
    public function deleteList(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        if (!current_user_can(Capabilities::DELETE_LISTS)) {
            return new \WP_Error(
                'forbidden',
                __('You do not have permission to do that.', 'mailerpress'),
                ['status' => 403]
            );
        }

        global $wpdb;

        $list_ids = $request->get_param('ids');

        // Sanitize input
        if (!\is_array($list_ids) || empty($list_ids)) {
            return new \WP_Error(
                'invalid_input',
                __('List IDs must be an array and cannot be empty', 'mailerpress'),
                ['status' => 400]
            );
        }

        $list_ids = array_map('intval', $list_ids);

        $table_name = Tables::get(Tables::MAILERPRESS_LIST);
        $placeholders = implode(',', array_fill(0, \count($list_ids), '%d'));

        // Verify if the lists exist
        $existing_lists = $wpdb->get_col($wpdb->prepare(
            "SELECT list_id FROM {$table_name} WHERE list_id IN ({$placeholders})",
            ...$list_ids
        ));

        if (\count($existing_lists) !== \count($list_ids)) {
            return new \WP_Error(
                'list_not_found',
                __('One or more lists were not found.', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Filter out the default list from the IDs to delete (silently skip it)
        $default_lists = $wpdb->get_col($wpdb->prepare(
            "SELECT list_id FROM {$table_name} WHERE is_default = 1 AND list_id IN ({$placeholders})",
            ...$list_ids
        ));

        if (!empty($default_lists)) {
            $list_ids = array_values(array_diff($list_ids, array_map('intval', $default_lists)));

            // If nothing left to delete after filtering, return success
            if (empty($list_ids)) {
                return rest_ensure_response([
                    'success' => true,
                    'message' => __('Default list was skipped. No other lists to delete.', 'mailerpress'),
                ]);
            }

            // Recalculate placeholders for the filtered list
            $placeholders = implode(',', array_fill(0, \count($list_ids), '%d'));
        }

        // Start a transaction for safer deletion
        $wpdb->query('START TRANSACTION');

        // Get the default list ID
        $default_list_id = $wpdb->get_var("SELECT list_id FROM {$table_name} WHERE is_default = 1");

        if (!$default_list_id) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error(
                'no_default_list',
                __('No default list found. Cannot reassign contacts.', 'mailerpress'),
                ['status' => 500]
            );
        }

        // Reassign all contacts from deleted lists to the default list
        $contact_lists_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        // Update contact_lists table: replace deleted list IDs with default list ID
        foreach ($list_ids as $list_id) {
            // First, delete duplicates if they exist (contact already in default list)
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$contact_lists_table}
                WHERE contact_id IN (
                    SELECT contact_id FROM {$contact_lists_table}
                    WHERE list_id = %d
                )
                AND list_id = %d",
                $list_id,
                $default_list_id
            ));

            // Now update the list_id to default list
            $wpdb->query($wpdb->prepare(
                "UPDATE {$contact_lists_table} SET list_id = %d WHERE list_id = %d",
                $default_list_id,
                $list_id
            ));
        }

        // Delete the lists
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE list_id IN ({$placeholders})",
            ...$list_ids
        ));

        if ($deleted) {
            $wpdb->query('COMMIT');
            return new \WP_REST_Response([
                'success' => true,
                'message' => __('Lists deleted successfully', 'mailerpress'),
                'deleted_count' => $deleted,
            ], 200);
        }

        $wpdb->query('ROLLBACK');

        return new \WP_Error(
            'delete_failed',
            __('Failed to delete the lists.', 'mailerpress'),
            [
                'status' => 500,
                'error' => $wpdb->last_error
            ]
        );
    }

    #[Endpoint(
        'list/all',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canDeleteLists'],
    )]
    public function deleteAll(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_LIST);
        $tableBatch = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

        // Delete all rows EXCEPT the default list
        $result = $wpdb->query("DELETE FROM {$table_name} WHERE is_default != 1");

        if (false === $result) {
            return new \WP_REST_Response(['message' => __('Failed to delete lists.', 'mailerpress')], 500);
        }

        return new \WP_REST_Response(
            ['message' => __('All lists have been deleted successfully.', 'mailerpress'), 'deleted' => $result],
            200
        );
    }

    #[Endpoint(
        'list/(?P<id>\d+)/rename',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function rename(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $list_id = (int)$request->get_param('id');
        $title = sanitize_text_field($request->get_param('title'));
        $description = sanitize_textarea_field($request->get_param('description'));

        if (empty($title)) {
            return new \WP_Error('invalid_title', __('Title cannot be empty.', 'mailerpress'), ['status' => 400]);
        }

        $table_name = Tables::get(Tables::MAILERPRESS_LIST);
        $list = $wpdb->get_row($wpdb->prepare("SELECT list_id, is_default FROM {$table_name} WHERE list_id = %d", $list_id));

        if (!$list) {
            return new \WP_Error('not_found', __('List not found.', 'mailerpress'), ['status' => 404]);
        }

        $update_data = [
            'name' => $title,
            'updated_at' => current_time('mysql'),
        ];

        if ($description !== null) {
            $update_data['description'] = $description;
        }

        $updated = $wpdb->update(
            $table_name,
            $update_data,
            ['list_id' => $list_id]
        );

        if (false === $updated) {
            return new \WP_Error('db_update_error', __('Failed to rename list.', 'mailerpress'), ['status' => 500]);
        }

        return new \WP_REST_Response(
            [
                'success' => true,
                'message' => __('List updated successfully.', 'mailerpress'),
                'list_id' => $list_id,
            ],
            200
        );
    }

    #[Endpoint(
        'list/(?P<id>\d+)/set-default',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function setDefault(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $list_id = (int)$request->get_param('id');
        $table_name = Tables::get(Tables::MAILERPRESS_LIST);

        // Verify the list exists
        $list = $wpdb->get_row($wpdb->prepare("SELECT list_id FROM {$table_name} WHERE list_id = %d", $list_id));

        if (!$list) {
            return new \WP_Error('not_found', __('List not found.', 'mailerpress'), ['status' => 404]);
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        // Remove default from all lists
        $wpdb->update(
            $table_name,
            ['is_default' => 0],
            ['is_default' => 1],
            ['%d'],
            ['%d']
        );

        // Set this list as default
        $updated = $wpdb->update(
            $table_name,
            ['is_default' => 1],
            ['list_id' => $list_id],
            ['%d'],
            ['%d']
        );

        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('db_update_error', __('Failed to set default list.', 'mailerpress'), ['status' => 500]);
        }

        $wpdb->query('COMMIT');

        return new \WP_REST_Response(
            [
                'success' => true,
                'message' => __('Default list updated successfully.', 'mailerpress'),
                'list_id' => $list_id,
            ],
            200
        );
    }
}
