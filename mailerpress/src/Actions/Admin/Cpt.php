<?php

declare(strict_types=1);

namespace MailerPress\Actions\Admin;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Kernel;

class Cpt
{
    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Action('init')]
    public function registerPostType(): void
    {
        $labels = [
            'name' => _x('MailerPress campaigns', 'post type general name', 'mailerpress'),
            'singular_name' => _x('Block builder', 'post type singular name', 'mailerpress'),
            'menu_name' => _x('Block builder', 'admin menu', 'mailerpress'),
            'name_admin_bar' => _x('Block', 'add new on admin bar', 'mailerpress'),
            'add_new' => _x('Add New', 'block', 'mailerpress'),
            'add_new_item' => __('Add New Block', 'mailerpress'),
            'new_item' => __('New Block', 'mailerpress'),
            'edit_item' => __('Edit Block', 'mailerpress'),
            'view_item' => __('View Block', 'mailerpress'),
            'all_items' => __('All Blocks', 'mailerpress'),
            'search_items' => __('Search Blocks', 'mailerpress'),
            'parent_item_colon' => __('Parent Blocks:', 'mailerpress'),
            'not_found' => __('No blocks found.', 'mailerpress'),
            'not_found_in_trash' => __('No blocks found in Trash.', 'mailerpress'),
        ];

        $args = [
            'labels' => $labels,
            'publicly_queryable' => false,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => current_user_can('edit_posts'),
            'menu_position' => 120,
            'capability_type' => 'post',
            'query_var' => true,
            'rewrite' => ['slug' => 'mailerpress-blocks'],
            'hierarchical' => true,
            'map_meta_cap' => true,
            'supports' => [
                'title',
                'editor',
                'revisions',
            ],
        ];

        register_post_type(Kernel::getContainer()->get('cpt-slug'), $args);

        $labelsPattern = [
            'name' => _x('MailerPress patterns', 'post type general name', 'mailerpress'),
            'singular_name' => _x('MailerPress pattern', 'post type singular name', 'mailerpress'),
        ];

        $argsPattern = [
            'labels' => $labelsPattern,
            'publicly_queryable' => false,
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => current_user_can('edit_posts'),
            'menu_position' => 120,
            'capability_type' => 'post',
            'query_var' => true,
            'menu_icon' => 'dashicons-admin-tools',
            'rewrite' => ['slug' => 'mailerpress-blocks'],
            'hierarchical' => true,
            'map_meta_cap' => true,
            'supports' => [
                'title',
                'editor',
                'revisions',
            ],
        ];

        register_post_type(Kernel::getContainer()->get('cpt-pattern-slug'), $argsPattern);

        register_post_type(
            Kernel::getContainer()->get('cpt-page-slug'),
            [
                'labels' => [
                    'name' => __('MailerPress Page', 'mailerpress'),
                    'singular_name' => __('MailerPress Page', 'mailerpress'),
                ],
                'public' => false,
                'has_archive' => false,
                'show_ui' => false,
                'show_in_menu' => false,
                'rewrite' => false,
                'show_in_nav_menus' => false,
                'can_export' => false,
                'publicly_queryable' => true,
                'exclude_from_search' => true,
            ]
        );
    }
}
