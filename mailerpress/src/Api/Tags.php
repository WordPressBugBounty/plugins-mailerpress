<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Capabilities;
use MailerPress\Core\Enums\Tables;
use MailerPress\Api\Permissions;

class Tags
{
    #[Endpoint(
        'tags',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function all(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $tagTable = Tables::get(Tables::MAILERPRESS_TAGS);
        $contactTags = Tables::get(Tables::CONTACT_TAGS);
        $search = $request->get_param('search');
        $per_page = isset($_GET['perPages']) ? (int)(wp_unslash($_GET['perPages'])) : 20; // Items per page (default is 10)
        $page = isset($_GET['paged']) ? (int)(wp_unslash($_GET['paged'])) : 1; // Current page
        $offset = ($page - 1) * $per_page;

        // Filters
        $where = '1=1'; // Default condition to make concatenation easier
        $params = [];
        if (!empty($search)) {
            $where .= ' AND t.name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Whitelist orderby and order parameters
        $allowed_orderby = ['name', 'tag_id'];
        $allowed_order = ['ASC', 'DESC'];
        $orderby_param = $request->get_param('orderby');
        $order_param = strtoupper($request->get_param('order') ?? 'DESC');
        $orderby = in_array($orderby_param, $allowed_orderby, true) ? $orderby_param : 'name';
        $order = in_array($order_param, $allowed_order, true) ? $order_param : 'ASC';
        $orderBy = "t.$orderby $order";

        $query = $wpdb->prepare("
    SELECT
        t.*,
        t.tag_id AS id,
        COUNT(ct.contact_id) AS contact_count
    FROM {$tagTable} t
    LEFT JOIN
        {$contactTags} ct ON t.tag_id = ct.tag_id
    WHERE {$where}
    GROUP BY t.tag_id
    ORDER BY {$orderBy}
    LIMIT %d OFFSET %d
", [...$params, $per_page, $offset]);

        $total_query = $wpdb->prepare("
		    SELECT COUNT(*)
		    FROM {$tagTable} t
		    WHERE {$where}
		", $params);

        $total_count = (int) $wpdb->get_var($total_query);

        $total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 0;

        $posts = $wpdb->get_results($query);
        if (!is_array($posts)) {
            $posts = [];
        }

        $response = [
            'posts' => $posts,
            'pages' => $total_pages,
            'count' => $total_count,
        ];

        return new \WP_REST_Response(
            $response,
            200
        );
    }

    #[Endpoint(
        'tag/all',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function getAll(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_TAGS); // returns the full table name, e.g., wp_mailerpress_list

        $results = $wpdb->get_results(
            "SELECT tag_id as id, name FROM {$table}",
            ARRAY_A
        );

        return new \WP_REST_Response($results, 200);
    }

    #[Endpoint(
        'tags',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function create(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_TAGS);

        $new_tag_name = sanitize_text_field($request->get_param('name'));

        if (empty($new_tag_name)) {
            return new \WP_Error('invalid_input', 'The tag name cannot be empty.', ['status' => 400]);
        }

        // Check if a tag with the same name already exists
        $existing_tag = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT tag_id, name FROM {$table_name} WHERE name = %s",
                $new_tag_name
            )
        );

        if ($existing_tag) {
            return new \WP_Error('duplicate_tag', 'A tag with this name already exists.', ['status' => 409]);
        }

        // Insert the tag
        $inserted = $wpdb->insert(
            $table_name,
            ['name' => $new_tag_name],
            ['%s']
        );

        if (false === $inserted) {
            return new \WP_Error('db_error', 'Failed to create the tag.', ['status' => 500]);
        }

        $new_tag_id = $wpdb->insert_id;

        $new_tag = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT tag_id, name FROM {$table_name} WHERE tag_id = %d",
                $new_tag_id
            )
        );

        do_action('mailerpress_tag_created', $new_tag);

        return new \WP_REST_Response(
            ['id' => $new_tag_id, 'label' => $new_tag->name],
            200
        );
    }

    #[Endpoint(
        'tag',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canDeleteTags'],
    )]
    public function deleteTag(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        if (!current_user_can(Capabilities::DELETE_TAGS)) {
            return new \WP_Error(
                'forbidden',
                __('You do not have permission to do that.', 'mailerpress'),
                ['status' => 403]
            );
        }

        global $wpdb;

        $tag_ids = $request->get_param('ids');

        // Sanitize input
        if (!\is_array($tag_ids) || empty($tag_ids)) {
            return new \WP_Error(
                'invalid_input',
                __('Tag IDs must be an array and cannot be empty', 'mailerpress'),
                ['status' => 400]
            );
        }

        $tag_ids = array_map('intval', $tag_ids);

        $table_name = Tables::get(Tables::MAILERPRESS_TAGS);
        $placeholders = implode(',', array_fill(0, \count($tag_ids), '%d'));

        // Verify if the lists exist
        $existing_lists = $wpdb->get_col($wpdb->prepare(
            "SELECT tag_id FROM {$table_name} WHERE tag_id IN ({$placeholders})",
            ...$tag_ids
        ));

        if (\count($existing_lists) !== \count($tag_ids)) {
            return new \WP_Error(
                'tags_not_found',
                __('One or more tags were not found.', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Start a transaction for safer deletion
        $wpdb->query('START TRANSACTION');

        // Delete the lists
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE tag_id IN ({$placeholders})",
            ...$tag_ids
        ));

        if ($deleted) {
            $wpdb->query('COMMIT');
            return new \WP_REST_Response([
                'success' => true,
                'message' => __('Tags deleted successfully', 'mailerpress'),
                'deleted_count' => $deleted,
            ], 200);
        }

        $wpdb->query('ROLLBACK');

        return new \WP_Error(
            'delete_failed',
            __('Failed to delete the tags.', 'mailerpress'),
            [
                'status' => 500,
                'error' => $wpdb->last_error
            ]
        );
    }


    #[Endpoint(
        'tag/all',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canDeleteLists'],
    )]
    public function deleteAll(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        if (!current_user_can(Capabilities::DELETE_TAGS)) {
            return new \WP_Error(
                'forbidden',
                __('You do not have permission to do that.', 'mailerpress'),
                ['status' => 403]
            );
        }

        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_TAGS);

        // Delete all rows in the table
        $result = $wpdb->query("DELETE FROM {$table_name}");

        if (false === $result) {
            return new \WP_REST_Response(['message' => __('Failed to delete tags.', 'mailerpress')], 500);
        }

        return new \WP_REST_Response(
            ['message' => __('All Tags have been deleted successfully.', 'mailerpress'), 'deleted' => $result],
            200
        );
    }

    #[Endpoint(
        'tag/(?P<id>\d+)/rename',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function rename(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $tag_id = (int)$request->get_param('id');
        $title = sanitize_text_field($request->get_param('title'));

        if (empty($title)) {
            return new \WP_Error('invalid_title', __('Title cannot be empty.', 'mailerpress'), ['status' => 400]);
        }

        $table_name = Tables::get(Tables::MAILERPRESS_TAGS);
        $tag = $wpdb->get_row($wpdb->prepare("SELECT tag_id FROM {$table_name} WHERE tag_id = %d", $tag_id));

        if (!$tag) {
            return new \WP_Error('not_found', __('Campaign not found.', 'mailerpress'), ['status' => 404]);
        }

        $updated = $wpdb->update(
            $table_name,
            [
                'name' => $title,
            ],
            [
                'tag_id' => $tag_id,
            ]
        );

        if (false === $updated) {
            return new \WP_Error('db_update_error', __('Failed to rename list.', 'mailerpress'), ['status' => 500]);
        }

        return new \WP_REST_Response(
            [
                'success' => true,
                'message' => __('Tag renamed successfully.', 'mailerpress'),
                'list_id' => $tag_id,
            ],
            200
        );
    }

}
