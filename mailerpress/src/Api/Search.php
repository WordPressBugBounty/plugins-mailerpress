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
        permissionCallback: [Permissions::class, 'canReadEditorMetadata']
    )]
    public function search(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $postTypes = get_post_types(['exclude_from_search' => false, 'public' => true]);
        unset($postTypes['attachment']);

        $requestPostType = sanitize_key((string) $request->get_param('post_type'));
        if ($requestPostType && post_type_exists($requestPostType)) {
            $postTypes = [$requestPostType => $requestPostType];
        }

        $searchQuery = sanitize_text_field((string) $request->get_param('search'));
        $perPage = min(50, max(1, absint($request->get_param('per_page') ?: 10)));

        $args = [
            's' => $searchQuery,
            'post_type' => array_keys($postTypes),
            'posts_per_page' => $perPage,
            'post_status' => 'publish',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ];

        $query = new \WP_Query($args);
        $posts = [];

        if ($searchQuery !== '') {
            if (ctype_digit($searchQuery)) {
                $posts[] = get_post(absint($searchQuery));
            }

            $posts[] = get_page_by_path($searchQuery, OBJECT, array_keys($postTypes));
        }

        $posts = array_merge($posts, $query->posts);
        $data = [];
        $seenPostIds = [];

        foreach ($posts as $post) {
            if (is_array($post)) {
                $post = (object) $post;
            }

            if (
                !$post instanceof \WP_Post
                || $post->post_status !== 'publish'
                || !isset($postTypes[$post->post_type])
                || isset($seenPostIds[$post->ID])
            ) {
                continue;
            }

            $seenPostIds[$post->ID] = true;
            $response = rest_ensure_response($post);

            $filtered = apply_filters("mailerpress_rest_prepare_{$post->post_type}", $response, $post, $request);
            $data[] = $filtered instanceof WP_REST_Response ? $filtered->get_data() : $filtered;

            if (count($data) >= $perPage) {
                break;
            }
        }

        return rest_ensure_response($data);
    }

    #[Endpoint(
        'pages/search',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canReadEditorMetadata']
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
        $rawTitle = get_the_title($post) ?: sprintf(__('Page #%d', 'mailerpress'), $post->ID);
        $title = html_entity_decode(wp_strip_all_tags($rawTitle), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');

        return [
            'id' => $post->ID,
            'title' => $title,
            'label' => $title,
            'slug' => $post->post_name,
            'url' => $url,
        ];
    }
}
