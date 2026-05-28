<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;

use WP_REST_Response;

class Search
{
    #[Endpoint(
        'search',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function search(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $postTypes = get_post_types(['exclude_from_search' => false, 'public' => true]);
        unset($postTypes['attachment']);
        
        // Allow filtering by post_type parameter
        $requestPostType = sanitize_text_field($request->get_param('post_type'));
        if ($requestPostType && post_type_exists($requestPostType)) {
            $postTypes = [$requestPostType => $requestPostType];
        }
        
        $search_query = sanitize_text_field($request->get_param('search'));
        
        // Set up the query arguments
        $args = [
            's' => $search_query,
            'post_type' => array_keys($postTypes),
            'posts_per_page' => $request->get_param('per_page') ?? 10,
            'post_status' => 'publish',
            // ✅ Optimisation: Supprimer les filtres pour éviter les hooks lourds de WooCommerce et autres plugins
            'suppress_filters' => true,
        ];

        // Perform the search query
        $query = new \WP_Query($args);
        $data = [];

        foreach ($query->posts as $post) {
            // ensure $post is object WP_Post
            if (is_array($post)) {
                $post = (object)$post;
            }
            $response = rest_ensure_response($post);

            $filtered = apply_filters("mailerpress_rest_prepare_{$post->post_type}", $response, $post, $request);
            $data[] = $filtered instanceof WP_REST_Response ? $filtered->get_data() : $filtered;
        }

        return rest_ensure_response($data);
    }

    #[Endpoint(
        'pages/search',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function searchPages(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $search = sanitize_text_field((string) $request->get_param('search'));
        $include = $request->get_param('include');
        $perPage = min(50, max(1, absint($request->get_param('per_page') ?: 10)));

        $args = [
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ];

        if (!empty($include)) {
            $ids = is_array($include) ? $include : explode(',', (string) $include);
            $ids = array_values(array_filter(array_map('absint', $ids)));

            if (empty($ids)) {
                return rest_ensure_response([]);
            }

            $args['post__in'] = $ids;
            $args['orderby'] = 'post__in';
            $args['posts_per_page'] = count($ids);
        } elseif ($search !== '') {
            $args['s'] = $search;
        }

        $query = new \WP_Query($args);
        $data = array_map([$this, 'formatPageResult'], $query->posts);

        return rest_ensure_response($data);
    }

    private function formatPageResult(\WP_Post $post): array
    {
        $url = get_permalink($post);
        $path = $url ? wp_parse_url($url, PHP_URL_PATH) : '';
        $title = get_the_title($post) ?: sprintf(__('Page #%d', 'mailerpress'), $post->ID);
        $context = $path ?: sprintf(__('ID %d', 'mailerpress'), $post->ID);

        return [
            'id' => $post->ID,
            'title' => $title,
            'label' => sprintf('%s (%s)', $title, $context),
            'slug' => $post->post_name,
            'url' => $url,
        ];
    }
}
