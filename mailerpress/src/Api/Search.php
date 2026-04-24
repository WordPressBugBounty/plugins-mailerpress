<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;

use WP_REST_Response;
use function MailerPress\Helpers\formatPostForApi;

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
}
