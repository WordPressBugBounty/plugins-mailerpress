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

    /**
     * Token rendered with forms whose block disables double opt-in.
     * Lets the public contact endpoint honour a "subscribed" status chosen by the site owner.
     */
    public static function getSkipConfirmationToken(): string
    {
        return hash_hmac('sha256', 'mailerpress_form_skip_confirmation', wp_salt('nonce'));
    }

    public static function isValidSkipConfirmationToken(mixed $token): bool
    {
        return is_string($token) && '' !== $token && hash_equals(self::getSkipConfirmationToken(), $token);
    }

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
				/** Lets add-ons enqueue the front-end assets they need for MailerPress forms. */
				do_action('mailerpress_form_assets', 'native_forms');

				if ( class_exists('WP_HTML_Tag_Processor') ) {
					$processor = new \WP_HTML_Tag_Processor($blockContent);
					if ( $processor->next_tag(['tag_name' => 'form', 'class_name' => 'mailerpress-optin-form']) ) {
						// Legacy blocks saved data-double-optin="true" even without an explicit choice.
						$doubleOptin = $block['attrs']['double_optin'] ?? null;
						$mode = is_bool($doubleOptin) ? ( $doubleOptin ? 'enabled' : 'disabled' ) : 'global';
						$processor->set_attribute('data-double-optin-mode', $mode);
						if ( false === $doubleOptin ) {
							// Sign the owner's opt-out so visitors cannot bypass global confirmation.
							$processor->set_attribute('data-confirmation-token', self::getSkipConfirmationToken());
						} else {
							$processor->remove_attribute('data-confirmation-token');
						}
						$blockContent = $processor->get_updated_html();
					}
				}
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
     * Inject the REST endpoint and nonce for the mailerpress-form block view script.
     * Runs before footer scripts so window.mailerpressFormConfig is available to view.js.
     */
    #[Action('wp_print_footer_scripts', priority: 0)]
    public function injectFormConfig(): void
    {
        if ( ! self::$formBlockRendered ) {
            return;
        }

        /** Lets add-ons extend the public configuration read by MailerPress forms. */
        $config = apply_filters(
            'mailerpress_public_form_config',
            [
                'apiUrl' => rest_url('mailerpress/v1/contact'),
                'nonce' => wp_create_nonce('wp_rest'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'i18n' => [
                    'success' => __('Successfully subscribed!', 'mailerpress'),
                    'error' => __('An error occurred. Please try again.', 'mailerpress'),
                    'unexpected' => __('Unexpected error. Please try again later.', 'mailerpress'),
                ],
            ],
            'native_forms'
        );

        echo '<script>window.mailerpressFormConfig=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n";
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
