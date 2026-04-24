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
        permissionCallback: [Permissions::class, 'canEdit']
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
                        $wpdb->prepare("SELECT * FROM {$table_name} WHERE name = %s LIMIT 1", $pattern_category)
                    );

                    if (!$category) {
                        // Insert new category
                        $wpdb->insert(
                            $table_name,
                            [
                                'name' => $pattern_category,
                                'slug' => sanitize_title($pattern_category),
                                'type' => 'pattern',
                            ],
                            ['%s', '%s', '%s']
                        );
                        $category_id = $wpdb->insert_id;

                        $category_data = [
                            'id' => $category_id,
                            'label' => $pattern_category,
                            'slug' => sanitize_title($pattern_category),
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

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'pattern/(?P<id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function delete(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $id = (int)$request->get_param('id');
        if (!empty($id)) {
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
