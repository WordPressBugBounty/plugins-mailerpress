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

        $args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $request->get_param('per_page') ?? 10,
            'orderby' => $request->get_param('orderby') ?? 'date',
            'order' => $request->get_param('order') ?? 'DESC',
            's' => $request->get_param('search'),
            'paged' => $request->get_param('page') ?? 1,
            // ✅ Optimization: Suppress filters to avoid heavy hooks from WooCommerce and other plugins
            'suppress_filters' => true,
        ];

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
            if (in_array($paramKey, ['postType', 'per_page', 'orderby', 'order', 'search', 'page', 'author'])) {
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

        $query = new \WP_Query($args);

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

    #[Endpoint(
        'acf-fields',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getAcfFields(WP_REST_Request $request): WP_Error|WP_HTTP_Response|WP_REST_Response
    {
        // Check if ACF is active
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return rest_ensure_response([]);
        }

        $post_type = $request->get_param('post_type') ?? 'post';

        // First, try ACF's built-in filtering by post type
        $field_groups = acf_get_field_groups([
            'post_type' => $post_type,
        ]);

        // If no groups found, get all groups and filter manually
        if (empty($field_groups)) {
            $all_groups = acf_get_field_groups();

            foreach ($all_groups as $group) {
                $location = $group['location'] ?? [];

                // If no location rules, include it (applies to all)
                if (empty($location)) {
                    $field_groups[] = $group;
                    continue;
                }

                // ACF location rules use OR logic between groups, AND logic within groups
                // We need at least one rule group to match
                foreach ($location as $rule_group) {
                    $rule_group_matches = true;

                    // All rules in a group must match (AND logic)
                    foreach ($rule_group as $rule) {
                        if (isset($rule['param']) && $rule['param'] === 'post_type') {
                            $operator = $rule['operator'] ?? '==';
                            $value = $rule['value'] ?? '';

                            // Handle different value formats
                            $rule_values = is_array($value) ? $value : [$value];
                            $match = in_array($post_type, $rule_values, true);

                            if ($operator === '==' && !$match) {
                                $rule_group_matches = false;
                                break;
                            }
                            if ($operator === '!=' && $match) {
                                $rule_group_matches = false;
                                break;
                            }
                        }
                        // If rule is not about post_type, we skip it for now
                        // In a full implementation, we'd need to check all rule types
                    }

                    // If this rule group matches, the field group applies
                    if ($rule_group_matches) {
                        $field_groups[] = $group;
                        break; // Found a matching rule group, no need to check others
                    }
                }
            }
        }

        // List of ACF field types compatible with emailing
        // These are simple field types that can be rendered as text or images in emails
        $compatible_field_types = [
            // Text fields
            'text',
            'textarea',
            'email',
            'url',
            'number',
            // Selection fields (can be displayed as text)
            'select',
            // Date/Time fields (can be formatted as text)
            'date',
            'date_time_picker',
            'time_picker',
            'time',
            // Media fields
            'image',
            // WYSIWYG (can contain HTML, but we'll render it)
            'wysiwyg',
        ];

        $fields = [];

        foreach ($field_groups as $field_group) {
            // Get field group identifier - try multiple methods
            $group_id = $field_group['ID'] ?? $field_group['id'] ?? null;
            $group_key = $field_group['key'] ?? null;

            // Try to get fields by ID first
            $group_fields = null;
            if ($group_id) {
                $group_fields = acf_get_fields($group_id);
            }

            // If that fails, try using the field group key
            if (empty($group_fields) && $group_key) {
                $group_fields = acf_get_fields($group_key);
            }

            // Last resort: try getting fields directly from the group array
            if (empty($group_fields) && isset($field_group['fields']) && is_array($field_group['fields'])) {
                $group_fields = $field_group['fields'];
            }

            if ($group_fields && is_array($group_fields)) {
                foreach ($group_fields as $field) {
                    // Only include top-level fields (not sub-fields)
                    $parent = $field['parent'] ?? 0;
                    $field_parent_key = $field['parent'] ?? '';

                    // A field is a sub-field if its parent is not 0 and not the field group key
                    $is_sub_field = false;
                    if (!empty($parent) && $parent !== 0 && $parent !== '0') {
                        // Check if parent matches the field group key (means it's a top-level field)
                        if ($parent !== $group_key && $parent !== $group_id) {
                            $is_sub_field = true;
                        }
                    }

                    $field_type = $field['type'] ?? 'text';

                    // Only include compatible field types
                    if (!$is_sub_field && !empty($field['name']) && in_array($field_type, $compatible_field_types, true)) {
                        $fields[] = [
                            'name' => $field['name'],
                            'label' => $field['label'] ?? $field['name'],
                            'type' => $field_type,
                            'key' => $field['key'] ?? $field['name'],
                        ];
                    }
                }
            }
        }

        // If still no fields found, try getting all fields from all groups (for debugging)
        // But still filter by compatible types
        if (empty($fields)) {
            $all_groups = acf_get_field_groups();
            foreach ($all_groups as $group) {
                $group_id = $group['ID'] ?? $group['id'] ?? $group['key'] ?? null;
                if ($group_id) {
                    $group_fields = acf_get_fields($group_id);
                    if ($group_fields && is_array($group_fields)) {
                        foreach ($group_fields as $field) {
                            $parent = $field['parent'] ?? 0;
                            $field_type = $field['type'] ?? 'text';

                            if ((empty($parent) || $parent === 0 || $parent === '0')
                                && !empty($field['name'])
                                && in_array($field_type, $compatible_field_types, true)
                            ) {
                                $fields[] = [
                                    'name' => $field['name'] ?? '',
                                    'label' => $field['label'] ?? $field['name'] ?? '',
                                    'type' => $field_type,
                                    'key' => $field['key'] ?? $field['name'] ?? '',
                                ];
                            }
                        }
                    }
                }
            }
        }

        return rest_ensure_response($fields);
    }
}
