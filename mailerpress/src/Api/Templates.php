<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Blocks\TemplatesCategories;
use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Services\TemplateVisibility;
use WP_Error;
use WP_REST_Response;

class Templates
{
    #[Endpoint(
        'templates/all',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canReadTemplates']
    )]
    public function all(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_TEMPLATES);

        // Get parameters from the request
        $page = max(1, (int)$request->get_param('paged')); // Ensure the page is at least 1
        $limit = (int)($request->get_param('perPages') ?: $request->get_param('limit') ?: 20);
        $category = $request->get_param('category');
        $categories = $request->get_param('categories');
        $offset = ($page - 1) * $limit;
        $search = $request->get_param('search');
        $internal = $request->get_param('internal');
        $usage_type = $request->get_param('usage_type'); // 'newsletter' or 'automation'

        // Base query
        $where = '1=1'; // Default condition to make concatenation easier
        $params = [];

        // Filter by usage_type — only if column exists (migration may not have run yet)
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);
        $hasUsageType = in_array('usage_type', $columns, true);

        if ($hasUsageType && !empty($usage_type)) {
            if (!in_array($usage_type, ['newsletter', 'automation'], true)) {
                $usage_type = null;
            }
        }

        if ($hasUsageType && !empty($usage_type)) {
            $where .= ' AND (usage_type = %s OR usage_type IS NULL)';
            $params[] = $usage_type;
        }

        if (!empty($search)) {
            $where .= ' AND name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Only list the templates this site may use: premium ones need the extension that ships them.
        $allowedInternal = TemplateVisibility::internalValues();
        $requestedInternal = isset($internal) ? array_map('intval', explode(',', (string) $internal)) : $allowedInternal;
        $array = array_values(array_intersect($requestedInternal, $allowedInternal)) ?: [-1];
        $placeholders = implode(',', array_fill(0, \count($array), '%d'));
        $where .= " AND internal IN ({$placeholders})";
        $params = array_merge($params, $array);


        // Add filtering by category if provided
        if (!empty($category) && empty($categories)) {
            $where .= ' AND cat_id = %d';
            $params[] = (int)$category;
        } else {
            if (!empty($categories) && is_array($categories)) {
                $catIds = array_map('intval', $categories);
                if (!empty($catIds)) {
                    $placeholders = implode(',', array_fill(0, count($catIds), '%d'));
                    $where .= " AND cat_id IN ($placeholders)";
                    $params = array_merge($params, $catIds);
                }
            }
        }


        $allowed_orderby = ['name', 'created_at', 'updated_at', 'category'];
        $allowed_order = ['ASC', 'DESC'];
        $orderby_param = $request->get_param('orderby');
        $order_param_raw = $request->get_param('order');
        $order_param = !empty($order_param_raw) ? strtoupper((string)$order_param_raw) : 'ASC';
        $orderby = in_array($orderby_param, $allowed_orderby, true) ? $orderby_param : 'created_at';
        $order = in_array($order_param, $allowed_order, true) ? $order_param : 'ASC';
        $orderBy = "$orderby $order";
        $favorite_ids = array_values(array_filter(array_unique(array_map('absint', (array) get_user_meta(get_current_user_id(), 'mailerpress_favorite_templates', true)))));
        if ($favorite_ids) {
            $orderBy = 'CASE WHEN id IN (' . implode(',', $favorite_ids) . ") THEN 0 ELSE 1 END, {$orderBy}";
        }

        $query = $wpdb->prepare("
		    SELECT
		        *
		    FROM {$table_name}
		    WHERE {$where}
		    ORDER BY {$orderBy}
		    LIMIT %d OFFSET %d
		", [...$params, $limit, $offset]);

        // Fetch templates from the database
        $templates = $wpdb->get_results($query, ARRAY_A);
        foreach ($templates as &$template) {
            $template['is_favorite'] = in_array((int) $template['id'], $favorite_ids, true);
        }
        unset($template);

        $total_query = $wpdb->prepare("
		    SELECT COUNT(*)
		    FROM {$table_name}
		    WHERE {$where}
		", $params);

        $total_count = $wpdb->get_var($total_query);

        $total_pages = $limit > 0 ? ceil($total_count / $limit) : 1;

        // Construct response
        $response = [
            'posts' => $templates,
            'pages' => $total_pages,
            'count' => (int)$total_count,
        ];

        return new \WP_REST_Response($response, 200);
    }

    #[Endpoint(
        'templates/favorite',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canReadTemplates']
    )]
    public function favorite(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        global $wpdb;

        $user_id = get_current_user_id();
        $template_id = absint($request->get_param('id'));
        $favorite = rest_sanitize_boolean($request->get_param('favorite'));

        if (!$user_id || !$template_id || null === $request->get_param('favorite')) {
            return new \WP_Error('invalid_template_favorite', __('Invalid template favorite request.', 'mailerpress'), ['status' => 400]);
        }

        $table_name = Tables::get(Tables::MAILERPRESS_TEMPLATES);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE id = %d", $template_id));
        if (!$exists) {
            return new \WP_Error('template_not_found', __('Template not found.', 'mailerpress'), ['status' => 404]);
        }

        $favorite_ids = array_values(array_filter(array_unique(array_map('absint', (array) get_user_meta($user_id, 'mailerpress_favorite_templates', true)))));
        $favorite_ids = array_values(array_diff($favorite_ids, [$template_id]));
        if ($favorite) {
            $favorite_ids[] = $template_id;
        }
        update_user_meta($user_id, 'mailerpress_favorite_templates', $favorite_ids);

        return new \WP_REST_Response(['id' => $template_id, 'is_favorite' => $favorite], 200);
    }

    #[Endpoint(
        'template',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageTemplates']
    )]
    public function create(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $templates_table = Tables::get(Tables::MAILERPRESS_TEMPLATES);
        $categories_table = Tables::get(Tables::MAILERPRESS_CATEGORIES);

        // Get & sanitize data
        $name = sanitize_text_field($request->get_param('templateName'));
        $categories = (array)$request->get_param('templateCategory'); // Expect array of category names
        $content = $request->get_param('templateJSON');
        $usage_type = $request->get_param('usage_type'); // 'newsletter' or 'automation', defaults to 'newsletter'

        if (empty($name) || empty($categories) || empty($content)) {
            return new \WP_Error(
                'missing_fields',
                __('Name, content, and at least one category are required.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Validate and set default usage_type
        if (empty($usage_type) || !in_array($usage_type, ['newsletter', 'automation'], true)) {
            $usage_type = 'newsletter'; // Default to newsletter for backward compatibility
        }

        $categoryIds = [];
        foreach ($categories as $catNameRaw) {
            $catName = sanitize_text_field(trim($catNameRaw));
            if (empty($catName)) {
                continue;
            }

            // Check if category exists by name & type
            $existingCategoryId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT category_id FROM {$categories_table} WHERE name = %s AND type = %s LIMIT 1",
                    $catName,
                    'template'
                )
            );

            if (!$existingCategoryId) {
                // Insert new category
                $inserted = $wpdb->insert(
                    $categories_table,
                    [
                        'name' => $catName,
                        'slug' => $this->getUniqueCategorySlug($categories_table, sanitize_title($catName), 0),
                        'type' => 'template',
                        'created_at' => current_time('mysql'),
                    ],
                    ['%s', '%s', '%s', '%s']
                );

                if ($inserted) {
                    $existingCategoryId = $wpdb->insert_id;
                } else {
                    // Log error or skip failed insert
                    continue;
                }
            }

            $categoryIds[$existingCategoryId] = $catName;
        }

        if (empty($categoryIds)) {
            return new \WP_Error(
                'invalid_category',
                __('No valid category provided.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Take only the first category ID for the template FK (no pivot table)
        $firstCategoryId = array_key_first($categoryIds);

        $result = $wpdb->insert(
            $templates_table,
            [
                'name' => $name,
                'content' => $content,
                'cat_id' => (int)$firstCategoryId, // FK reference
                'usage_type' => $usage_type,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'internal' => 0,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%d']
        );

        if (false === $result) {
            return new \WP_Error('db_insert_error', __('Could not insert template.', 'mailerpress'), ['status' => 500]);
        }

        // Return the inserted template ID + categories data (to update frontend cache)
        return new \WP_REST_Response([
            'status' => 'success',
            'message' => __('Template created successfully.', 'mailerpress'),
            'template_id' => $wpdb->insert_id,
            'categories' => $categoryIds,
        ], 200);
    }

    #[Endpoint(
        'template/(?P<id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageTemplates']
    )]
    public function update(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        global $wpdb;

        $templates_table = Tables::get(Tables::MAILERPRESS_TEMPLATES);
        $categories_table = Tables::get(Tables::MAILERPRESS_CATEGORIES);

        $template_id = (int)$request->get_param('id');
        $name = sanitize_text_field($request->get_param('templateName'));
        $categories = (array)$request->get_param('templateCategory'); // array of category names
        $usage_type = $request->get_param('usage_type'); // 'newsletter' or 'automation'

        if (empty($name) || empty($categories)) {
            return new \WP_Error(
                'missing_fields',
                __('Template name and at least one category are required.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Validate usage_type if provided
        if (!empty($usage_type) && !in_array($usage_type, ['newsletter', 'automation'], true)) {
            return new \WP_Error(
                'invalid_usage_type',
                __('Usage type must be either "newsletter" or "automation".', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Handle categories: find existing or create new ones
        $categoryIds = [];
        foreach ($categories as $catNameRaw) {
            $catName = sanitize_text_field(trim($catNameRaw));
            if (empty($catName)) {
                continue;
            }

            $existingCategoryId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT category_id FROM {$categories_table} WHERE name = %s AND type = %s LIMIT 1",
                    $catName,
                    'template'
                )
            );

            if (!$existingCategoryId) {
                $inserted = $wpdb->insert(
                    $categories_table,
                    [
                        'name' => $catName,
                        'slug' => $this->getUniqueCategorySlug($categories_table, sanitize_title($catName), 0),
                        'type' => 'template',
                        'created_at' => current_time('mysql'),
                    ],
                    ['%s', '%s', '%s', '%s']
                );

                if ($inserted) {
                    $existingCategoryId = $wpdb->insert_id;
                } else {
                    continue; // skip if failed
                }
            }

            $categoryIds[$existingCategoryId] = $catName;
        }

        if (empty($categoryIds)) {
            return new \WP_Error(
                'invalid_category',
                __('No valid category provided.', 'mailerpress'),
                ['status' => 400]
            );
        }

        $firstCategoryId = array_key_first($categoryIds);

        // Prepare data for update
        $updateData = [
            'name' => $name,
            'cat_id' => (int)$firstCategoryId,
            'updated_at' => current_time('mysql'),
        ];
        $updateFormat = ['%s', '%d', '%s'];

        // Update usage_type if provided - always update it (even if empty, set to 'newsletter' for backward compatibility)
        if (!empty($usage_type) && in_array($usage_type, ['newsletter', 'automation'], true)) {
            $updateData['usage_type'] = $usage_type;
            $updateFormat[] = '%s';
        } else {
            // If usage_type is not provided or invalid, default to 'newsletter' for backward compatibility
            $updateData['usage_type'] = 'newsletter';
            $updateFormat[] = '%s';
        }


        $updated = $wpdb->update(
            $templates_table,
            $updateData,
            ['id' => $template_id],
            $updateFormat,
            ['%d']
        );

        if ($updated === false) {
            return new \WP_Error('db_update_error', __('Could not update template.', 'mailerpress'), ['status' => 500]);
        }

        return new \WP_REST_Response([
            'status' => 'success',
            'message' => __('Template updated successfully.', 'mailerpress'),
            'template_id' => $template_id,
            'categories' => $categoryIds,
        ], 200);
    }


    #[Endpoint(
        'template/(?P<id>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageTemplates']
    )]
    public function getById(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_TEMPLATES);
        $id = (int) $request->get_param('id');

        $template = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$template || !TemplateVisibility::isVisible($template)) {
            return new \WP_Error('not_found', __('Template not found.', 'mailerpress'), ['status' => 404]);
        }

        return new \WP_REST_Response($template, 200);
    }

    #[Endpoint(
        'template/(?P<id>\d+)/content',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageTemplates']
    )]
    public function updateContent(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_TEMPLATES);
        $id = (int) $request->get_param('id');
        $content = $request->get_param('content');

        if (empty($content)) {
            return new \WP_Error('missing_content', __('Content is required.', 'mailerpress'), ['status' => 400]);
        }

        // Ensure template exists
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table_name} WHERE id = %d", $id)
        );

        if (!$exists) {
            return new \WP_Error('not_found', __('Template not found.', 'mailerpress'), ['status' => 404]);
        }

        $updated = $wpdb->update(
            $table_name,
            [
                'content' => is_string($content) ? $content : wp_json_encode($content),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return new \WP_Error('db_error', __('Could not update template content.', 'mailerpress'), ['status' => 500]);
        }

        return new \WP_REST_Response([
            'status' => 'success',
            'message' => __('Template content updated successfully.', 'mailerpress'),
            'template_id' => $id,
        ], 200);
    }

    #[Endpoint(
        'templates/(?P<id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageTemplates']
    )]
    public function delete(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_TEMPLATES); // adjust if your table is named differently
        $id = (int)$request->get_param('id');

        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);

        if ($deleted === false) {
            return new WP_Error('db_error', __('Could not delete the template.', 'mailerpress'), ['status' => 500]);
        } elseif ($deleted === 0) {
            return new WP_Error('not_found', __('Template not found.', 'mailerpress'), ['status' => 404]);
        }

        return new WP_REST_Response(['message' => __('Template deleted successfully.', 'mailerpress')], 200);
    }

    #[Endpoint(
        'category/rename',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageTemplates']
    )]
    public function renameCategoryByName(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        global $wpdb;

        $categories_table = Tables::get(Tables::MAILERPRESS_CATEGORIES);

        // Get & sanitize parameters
        $current_name = sanitize_text_field($request->get_param('current_name'));
        $new_name = sanitize_text_field($request->get_param('new_name'));

        if (empty($current_name) || empty($new_name)) {
            return new \WP_Error(
                'missing_fields',
                __('Current name and new name are required.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Find the category by current name and type 'template'
        $category = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$categories_table} WHERE name = %s AND type = %s LIMIT 1",
                $current_name,
                'template'
            ),
            ARRAY_A
        );

        if (!$category) {
            return new \WP_Error(
                'not_found',
                __('Category not found.', 'mailerpress'),
                ['status' => 404]
            );
        }

        $category_id = (int)$category['category_id'];
        $new_slug = sanitize_title($new_name);

        $existing_same_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT category_id FROM {$categories_table} WHERE name = %s AND type = %s AND category_id <> %d LIMIT 1",
                $new_name,
                'template',
                $category_id
            )
        );

        if ($existing_same_name) {
            return new \WP_Error(
                'already_exists',
                __('A template category with this name already exists.', 'mailerpress'),
                ['status' => 409]
            );
        }

        $new_slug = $this->getUniqueCategorySlug($categories_table, $new_slug, $category_id);

        // Update the category
        $updated = $wpdb->update(
            $categories_table,
            [
                'name' => $new_name,
                'slug' => $new_slug,
            ],
            ['category_id' => $category_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return new \WP_Error(
                'db_error',
                __('Could not update category.', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response([
            'status' => 'success',
            'message' => __('Category renamed successfully.', 'mailerpress'),
        ], 200);
    }

    private function getUniqueCategorySlug(string $categories_table, string $base_slug, int $current_category_id): string
    {
        global $wpdb;

        $base_slug = $base_slug !== '' ? $base_slug : 'category';
        $base_slug = substr($base_slug, 0, 190);
        $slug = $base_slug;
        $suffix = 2;

        while ($wpdb->get_var(
            $wpdb->prepare(
                "SELECT category_id FROM {$categories_table} WHERE slug = %s AND category_id <> %d LIMIT 1",
                $slug,
                $current_category_id
            )
        )) {
            $slug = substr($base_slug, 0, 185) . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    #[Endpoint(
        'category/delete',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageTemplates']
    )]
    public function deleteCategoryByName(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        global $wpdb;

        $categories_table = Tables::get(Tables::MAILERPRESS_CATEGORIES);

        // Get & sanitize parameters
        $name = sanitize_text_field($request->get_param('name'));

        if (!isset($name)) {
            return new \WP_Error(
                'missing_fields',
                __('Category name is required.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Find the category by name and type 'template'
        $category = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$categories_table} WHERE name = %s AND type = %s LIMIT 1",
                $name,
                'template'
            ),
            ARRAY_A
        );

        if (!$category) {
            return new \WP_Error(
                'not_found',
                __('Category not found.', 'mailerpress'),
                ['status' => 404]
            );
        }

        $category_id = (int)$category['category_id'];

        // Delete the category
        $deleted = $wpdb->delete(
            $categories_table,
            ['category_id' => $category_id],
            ['%d']
        );

        if ($deleted === false) {
            return new \WP_Error(
                'db_error',
                __('Could not delete category.', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response([
            'status' => 'success',
            'message' => __('Category deleted successfully.', 'mailerpress'),
        ], 200);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'categories/all',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageTemplates']
    )]
    public function getCategoriesEndpoint(\WP_REST_Request $request): \WP_REST_Response
    {
        $usage_type = $request->get_param('usage_type'); // 'newsletter' or 'automation'

        $categories = Kernel::getContainer()->get(TemplatesCategories::class)->getCategories($usage_type);
        // Convert to a simple array for frontend FormTokenField [{label, value}, ...]
        $result = [];
        foreach ($categories as $id => $cat) {
            $result[] = [
                'label' => $cat['label'],
                'value' => $cat['label'], // value can be name if you want creation on the fly
            ];
        }

        return new \WP_REST_Response(
            $result,
            200
        );
    }
}
