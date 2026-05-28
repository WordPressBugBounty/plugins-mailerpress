<?php

namespace MailerPress\Api;

use MailerPress\Core\Attributes\Endpoint;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

class Postype
{
    #[Endpoint(
        'public-post-types',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getPublicPostTypes(WP_REST_Request $request): WP_Error|WP_HTTP_Response|WP_REST_Response
    {
        // Get public post types as objects
        $post_types = get_post_types(
            [
                'public' => true,
                'show_in_rest' => true,
            ],
            'objects'
        );

        unset(
            $post_types['attachment'],
            $post_types['seopress_rankings'],
            $post_types['seopress_backlinks'],
            $post_types['seopress_404'],
            $post_types['elementor_library'],
            $post_types['customer_discount'],
            $post_types['cuar_private_file'],
            $post_types['cuar_private_page'],
            $post_types['ct_template'],
            $post_types['bricks_template'],
            $post_types['e-floating-buttons']
        );


        $post_types = apply_filters('mailerpress_post_types_public', $post_types);

        $data = [];

        foreach ($post_types as $slug => $post_type) {
            $data[] = [
                'label' => $post_type->labels->name,
                'value' => $slug,
            ];
        }

        return rest_ensure_response($data);
    }


    #[Endpoint(
        'posts',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getPosts(WP_REST_Request $request): WP_Error|WP_HTTP_Response|WP_REST_Response
    {
        $post_type = $request->get_param('postType') ?? 'post';

        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_post_type', 'Invalid post type.', ['status' => 400]);
        }

        $search = $request->get_param('search');

        $args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $request->get_param('per_page') ?? 10,
            'orderby' => $request->get_param('orderby') ?? 'date',
            'order' => $request->get_param('order') ?? 'DESC',
            'paged' => $request->get_param('page') ?? 1,
            'suppress_filters' => false,
        ];

        if ( ! empty( $search ) ) {
            $args['s'] = sanitize_text_field( $search );
        }

        // Handle taxonomy filters
        $taxQuery = [];

        // Get all taxonomies for this post type
        $taxonomies = get_object_taxonomies($post_type, 'objects');

        $taxonomyMap = [];
        foreach ($taxonomies as $taxonomy) {
            // Special mapping for standard taxonomies
            if ($taxonomy->name === 'category') {
                $taxonomyMap['categories'] = $taxonomy;
            } elseif ($taxonomy->name === 'post_tag') {
                $taxonomyMap['tags'] = $taxonomy;
            }

            // Use rest_base as the main key
            $restBase = $taxonomy->rest_base ?? $taxonomy->name;
            $taxonomyMap[$restBase] = $taxonomy;

            // Also map by taxonomy name (for compatibility)
            $taxonomyMap[$taxonomy->name] = $taxonomy;
        }

        // Get all request parameters
        $allParams = $request->get_params();

        // Loop through all parameters to find those that match taxonomies
        foreach ($allParams as $paramKey => $paramValue) {
            // Ignore already processed or non-taxonomy parameters
            if (in_array($paramKey, ['postType', 'per_page', 'orderby', 'order', 'search', 'page', 'author', 'metaFilters', 'metaRelation'])) {
                continue;
            }

            // Check if this parameter corresponds to a taxonomy
            if (isset($taxonomyMap[$paramKey]) && !empty($paramValue)) {
                $taxonomy = $taxonomyMap[$paramKey];

                // Convert value to array of IDs
                $termIds = is_array($paramValue) ? $paramValue : explode(',', $paramValue);
                $termIds = array_filter(array_map('intval', $termIds));

                if (!empty($termIds)) {
                    $taxQuery[] = [
                        'taxonomy' => $taxonomy->name,
                        'field' => 'term_id',
                        'terms' => $termIds,
                    ];
                }
            }
        }

        // Add tax_query if taxonomies are specified
        if (!empty($taxQuery)) {
            $args['tax_query'] = $taxQuery;
        }

        // Handle author filters
        $author = $request->get_param('author');
        if (!empty($author)) {
            $authorIds = is_array($author) ? $author : explode(',', $author);
            $args['author__in'] = array_map('intval', $authorIds);
        }

        $args = apply_filters('mailerpress_posts_query_args', $args, $request);

        $query = new \WP_Query($args);

        // Fallback: some CPT plugins (The Events Calendar, etc.) hook into
        // pre_get_posts / parse_query / posts_pre_query and intercept queries.
        // If the first query returns nothing, retry with ALL query hooks removed.
        if ( ! $query->have_posts() ) {
            global $wp_filter;

            $hooks_to_bypass = [ 'posts_pre_query', 'pre_get_posts', 'parse_query' ];
            $saved_hooks     = [];

            foreach ( $hooks_to_bypass as $hook ) {
                if ( isset( $wp_filter[ $hook ] ) ) {
                    $saved_hooks[ $hook ] = clone $wp_filter[ $hook ];
                    remove_all_filters( $hook );
                }
            }

            $args['suppress_filters'] = true;
            $query = new \WP_Query( $args );

            foreach ( $saved_hooks as $hook => $filter_obj ) {
                $wp_filter[ $hook ] = $filter_obj;
            }
        }

        // Ultimate fallback: direct SQL query bypassing WP_Query entirely.
        // Catches CPTs that override the query at levels we can't intercept.
        if ( ! $query->have_posts() && empty($args['tax_query']) && empty($args['meta_query']) && empty($args['author__in']) && empty($args['s']) ) {
            global $wpdb;

            $per_page  = absint( $request->get_param( 'per_page' ) ?? 10 );
            $paged     = absint( $request->get_param( 'page' ) ?? 1 );
            $offset_db = ( $paged - 1 ) * $per_page;
            $orderby   = in_array( $request->get_param( 'orderby' ), [ 'date', 'title', 'modified' ], true )
                ? 'post_' . $request->get_param( 'orderby' )
                : 'post_date';
            $order     = strtoupper( $request->get_param( 'order' ) ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $raw_posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                    $post_type,
                    $per_page,
                    $offset_db
                )
            );

            if ( ! empty( $raw_posts ) ) {
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                        $post_type
                    )
                );

                $posts = array_map( fn( $row ) => new \WP_Post( $row ), $raw_posts );

                $data = [];
                foreach ( $posts as $post ) {
                    $response = rest_ensure_response( $post );
                    $filtered = apply_filters( "mailerpress_rest_prepare_{$post_type}", $response, $post, $request );
                    $data[]   = $filtered instanceof WP_REST_Response ? $filtered->get_data() : $filtered;
                }

                $response = rest_ensure_response( $data );
                $response->header( 'X-WP-Total', $total );
                $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

                return $response;
            }
        }

        $data = [];

        foreach ($query->posts as $post) {
            // ensure $post is object WP_Post
            if (is_array($post)) {
                $post = (object)$post;
            }
            $response = rest_ensure_response($post);

            $filtered = apply_filters("mailerpress_rest_prepare_{$post_type}", $response, $post, $request);
            $data[] = $filtered instanceof WP_REST_Response ? $filtered->get_data() : $filtered;
        }

        // Optional: add pagination headers
        $total = (int)$query->found_posts;
        $max_pages = (int)$query->max_num_pages;

        $response = rest_ensure_response($data);
        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', $max_pages);

        return $response;
    }

}
