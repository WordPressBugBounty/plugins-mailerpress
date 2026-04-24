<?php

namespace MailerPress\Actions\Gutenberg;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\Kernel;
use MailerPress\Models\Lists;
use MailerPress\Models\Tags;

class Init
{
    #[Action('init')]
    public function registerBlockType()
    {
        register_block_type(Kernel::$config['root'] . '/packages/gutenberg/mailerpress-form');
        register_block_type(Kernel::$config['root'] . '/packages/gutenberg/mailerpress-form-input');
        register_block_type(Kernel::$config['root'] . '/packages/gutenberg/mailerpress-form-button');
        register_block_type(Kernel::$config['root'] . '/packages/gutenberg/mailerpress-archive');
    }

    #[Action('enqueue_block_editor_assets')]
    public function block_editor_assets()
    {
        $root = Kernel::$config['root'];
        if (file_exists($root . '/build/dist/js/editor-blocks.asset.php')) {
            $assetBlocksfile = include $root . '/build/dist/js/editor-blocks.asset.php';
            wp_register_script(
                'mailerpress-editor-blocks-js',
                Kernel::$config['rootUrl'] . '/build/dist/js/editor-blocks.js',
                $assetBlocksfile['dependencies'],
                $assetBlocksfile['version'],
                ['in_footer' => true]
            );

            wp_enqueue_script('mailerpress-editor-blocks-js');

            wp_set_script_translations(
                'mailerpress-editor-blocks-js', // must match enqueued handle
                'mailerpress'
            );

            wp_enqueue_style(
                'mailerpress-editor-blocks-css',
                Kernel::$config['rootUrl'] . '/build/dist/js/editor-blocks.css',
                [],
                $assetBlocksfile['version'],
            );
        }
    }


    #[Action('enqueue_block_assets')]
    public function block_assets()
    {
        $root = Kernel::$config['root'];
        if (file_exists($root . '/build/dist/js/editor-blocks.asset.php')) {
            $assetBlocksfile = include $root . '/build/dist/js/editor-blocks.asset.php';
            wp_enqueue_style(
                'mailerpress-editor-blocks-css',
                Kernel::$config['rootUrl'] . '/build/dist/js/editor-blocks.css',
                [],
                $assetBlocksfile['version'],
            );
        }
    }

    #[Filter('block_categories_all')]
    public function registerMailerPressCategory($categories)
    {
        $categories[] = array(
            'slug' => 'mailerpress',
            'title' => 'MailerPress'
        );

        return $categories;
    }
}
