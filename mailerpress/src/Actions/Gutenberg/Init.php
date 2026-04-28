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
    private static bool $formBlockRendered = false;
    #[Action('init')]
    public function registerBlockType()
    {
        register_block_type(Kernel::$config['root'] . '/packages/gutenberg/mailerpress-form');
        register_block_type(Kernel::$config['root'] . '/packages/gutenberg/mailerpress-form-input');
        register_block_type(Kernel::$config['root'] . '/packages/gutenberg/mailerpress-form-button');
        register_block_type(Kernel::$config['root'] . '/packages/gutenberg/mailerpress-archive');

        // Detect form block rendering in any context (post content, FSE templates, patterns, widgets).
        // has_block() only checks $post->post_content and misses blocks in FSE pattern files.
        add_filter('render_block', static function ( string $blockContent, array $block ): string {
            if ( 'mailerpress/mailerpress-form' === ( $block['blockName'] ?? '' ) ) {
                self::$formBlockRendered = true;
            }
            return $blockContent;
        }, 10, 2 );
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


    /**
     * Inject a WP REST nonce for the mailerpress-form block view script.
     * Runs before footer scripts so window.mailerpressFormConfig is available to view.js.
     */
    #[Action('wp_print_footer_scripts', priority: 0)]
    public function injectFormConfig(): void
    {
        if ( ! self::$formBlockRendered ) {
            return;
        }

        echo '<script>window.mailerpressFormConfig=' . wp_json_encode(
            ['nonce' => wp_create_nonce('wp_rest')],
            JSON_HEX_TAG | JSON_HEX_AMP
        ) . ';</script>' . "\n";
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
