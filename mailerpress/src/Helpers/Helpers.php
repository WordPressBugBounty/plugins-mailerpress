<?php

declare(strict_types=1);

namespace MailerPress\Helpers;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;

function formatPostForApi(array $posts)
{
    return array_reduce($posts, static function ($acc, \WP_Post $post) {
        $acc[] = [
            'id' => $post->ID,
            'title' => [
                'rendered' => get_the_title($post),
            ],
            'excerpt' => [
                'rendered' => get_the_excerpt(
                    $post
                ),
            ],
            'link' => get_the_permalink($post),
            'images' => [
                'thumbnail' => get_the_post_thumbnail_url($post, 'thumbnail'),
                'medium' => get_the_post_thumbnail_url($post, 'medium'),
                'medium_large' => get_the_post_thumbnail_url($post, 'medium_large'),
                'large' => get_the_post_thumbnail_url($post, 'large'),
                'full' => get_the_post_thumbnail_url($post, 'full'),
            ],
        ];

        return $acc;
    }, []);
}

function formatPatternsForEditor(array $posts)
{
    global $wpdb;
    $table_name = Tables::get(Tables::MAILERPRESS_CATEGORIES);

    return array_map(function ($post) use ($wpdb, $table_name) {
        if ($post instanceof \WP_Post) {
            // Get linked category ID from post meta
            $category_id = get_post_meta($post->ID, 'mailerpress_category_id', true);
            $category_name = '';

            if (!empty($category_id)) {
                // Query your custom categories table for the name
                $category_name = $wpdb->get_var(
                    $wpdb->prepare("SELECT slug FROM {$table_name} WHERE category_id = %d", (int) $category_id)
                );
            }

            return [
                'id'       => $post->ID,
                'title'    => get_the_title($post),
                'content'  => $post->post_content,
                'category' => $category_name ?: '', // fallback empty string if not found
            ];
        } else {
            $category_slug = isset($post['category']) ? sanitize_title($post['category']) : 'custom';

            // Check if category already exists in your custom table
            $existing_category_id = $wpdb->get_var(
                $wpdb->prepare("SELECT category_id FROM {$table_name} WHERE slug = %s", $category_slug)
            );

            // If not exists, insert it
            if (!$existing_category_id) {
                $wpdb->insert(
                    $table_name,
                    [
                        'name' => $category_slug,
                        'slug' => $category_slug,
                        'type' => 'pattern',
                    ],
                    ['%s', '%s', '%s']
                );

                $existing_category_id = $wpdb->insert_id;
            }

            return [
                'id'       => $post['ID'],
                'title'    => $post['post_title'],
                'content'  => $post['post_content'],
                'category' => $category_slug,
                'category_id' => $existing_category_id,
            ];
        }
    }, $posts);
}


function assetPath(string $assetPath): string
{
    if (file_exists(Kernel::$config['root'] . '/.local')) {
        return \sprintf(
            '%s/assets/public/%s',
            Kernel::$config['rootUrl'],
            $assetPath
        );
    }

    return \sprintf(
        '%s/dist/%s',
        Kernel::$config['rootUrl'],
        $assetPath
    );
}
