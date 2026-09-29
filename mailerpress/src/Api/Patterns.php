<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;

class Patterns
{
    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'pattern',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canUseEditorContent']
    )]
    public function response(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $pattern_post_type = Kernel::getContainer()->get('cpt-pattern-slug');

        if (post_type_exists($pattern_post_type)) {
            $pattern_name = sanitize_text_field($request->get_param('patternName'));
            $pattern_json = wp_kses_post($request->get_param('patternJSON'));
            $pattern_category = sanitize_text_field($request->get_param('patternCategory')); // single category string

            $post_data = [
                'post_title'   => $pattern_name,
                'post_content' => $pattern_json,
                'post_status'  => 'publish',
                'post_type'    => $pattern_post_type,
                'post_author'  => get_current_user_id(),
            ];

            $post_id = wp_insert_post($post_data);

            if (!is_wp_error($post_id)) {
                $category_data = null;

                if (!empty($pattern_category)) {
                    $table_name = Tables::get(Tables::MAILERPRESS_CATEGORIES);

                    // Check if category exists by name
                    $category = $wpdb->get_row(
                        $wpdb->prepare("SELECT * FROM {$table_name} WHERE name = %s AND type = %s LIMIT 1", $pattern_category, 'pattern')
                    );

                    if (!$category) {
                        // A renamed category may still use the slug derived from this name.
                        $base_slug = substr(sanitize_title($pattern_category), 0, 190) ?: 'pattern';
                        $category_slug = $base_slug;
                        $suffix = 2;
                        while ($wpdb->get_var($wpdb->prepare("SELECT category_id FROM {$table_name} WHERE slug = %s LIMIT 1", $category_slug))) {
                            $category_slug = $base_slug . '-' . $suffix++;
                        }

                        // Insert new category
                        $wpdb->insert(
                            $table_name,
                            [
                                'name' => $pattern_category,
                                'slug' => $category_slug,
                                'type' => 'pattern',
                            ],
                            ['%s', '%s', '%s']
                        );
                        $category_id = $wpdb->insert_id;

                        $category_data = [
                            'id' => $category_id,
                            'label' => $pattern_category,
                            'slug' => $category_slug,
                        ];
                    } else {
                        $category_id = $category->category_id; // adjust column name if needed

                        $category_data = [
                            'id' => $category_id,
                            'label' => $category->name,
                            'slug' => $category->slug,
                        ];
                    }

                    update_post_meta($post_id, 'mailerpress_category_id', $category_id);
                }

                $post = get_post($post_id);

                return new \WP_REST_Response([
                    'post' => $post,
                    'category' => $category_data,
                ], 200);
            }

            return new \WP_REST_Response('error', 400);
        }

        return new \WP_REST_Response('error', 400);
    }

    #[Endpoint(
        'pattern/category/(?P<id>\d+)',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canUseEditorContent'],
        args: ['name' => ['type' => 'string', 'required' => true]]
    )]
    public function renameCategory(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        global $wpdb;

        $id = (int)$request->get_param('id');
        $name = trim(sanitize_text_field($request->get_param('name')));

        if ($name === '' || mb_strlen($name) > 255) {
            return new \WP_Error(
                'invalid_name',
                __('Enter a category name between 1 and 255 characters.', 'mailerpress'),
                ['status' => 400]
            );
        }

        $table = Tables::get(Tables::MAILERPRESS_CATEGORIES);
        $category = $wpdb->get_row(
            $wpdb->prepare("SELECT category_id, slug FROM {$table} WHERE category_id = %d AND type = %s", $id, 'pattern'),
            ARRAY_A
        );

        if (!$category) {
            return new \WP_Error('not_found', __('Category not found.', 'mailerpress'), ['status' => 404]);
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT category_id FROM {$table} WHERE name = %s AND type = %s AND category_id <> %d LIMIT 1",
                $name,
                'pattern',
                $id
            )
        );

        if ($existing) {
            return new \WP_Error(
                'already_exists',
                __('A pattern category with this name already exists.', 'mailerpress'),
                ['status' => 409]
            );
        }

        // Keep the slug stable: patterns and the open editor use it as their category key.
        $updated = $wpdb->update($table, ['name' => $name], ['category_id' => $id, 'type' => 'pattern'], ['%s'], ['%d', '%s']);

        if ($updated === false) {
            return new \WP_Error('db_error', __('Could not update category.', 'mailerpress'), ['status' => 500]);
        }

        return new \WP_REST_Response([
            'id' => $id,
            'label' => $name,
            'slug' => $category['slug'],
        ], 200);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'pattern/(?P<id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canUseEditorContent']
    )]
    public function delete(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $id = (int)$request->get_param('id');
        $pattern_post_type = Kernel::getContainer()->get('cpt-pattern-slug');

        if (!empty($id)) {
            // Security: verify the post belongs to the pattern post type
            $post = get_post($id);
            if (!$post || $post->post_type !== $pattern_post_type) {
                return new \WP_REST_Response(
                    esc_html__('Pattern not found.', 'mailerpress'),
                    404
                );
            }

            $result = wp_delete_post($id);
            if (!is_wp_error($result)) {
                return new \WP_REST_Response(
                    $result,
                    200
                );
            }

            return new \WP_REST_Response(
                esc_html__('An error occurred while removing the pattern.', 'mailerpress'),
                400
            );
        }
    }
}
