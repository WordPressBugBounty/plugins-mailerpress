<?php

declare(strict_types=1);

namespace MailerPress\Actions\Admin;

\defined('ABSPATH') || exit;

use MailerPress\Blocks\PatternsCategories;
use MailerPress\Blocks\TemplatesCategories;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\CapabilitiesManager;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\EmailManager\EmailServiceInterface;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use MailerPress\Models\Lists;
use MailerPress\Models\Patterns as PatternModel;
use MailerPress\Models\Posts;
use MailerPress\Models\Tags;
use MailerPress\Services\ThemeStyles;

use function MailerPress\Helpers\formatPatternsForEditor;
use function MailerPress\Helpers\formatPostForApi;

class Editor
{
    /**
     * @return mixed|string
     */
    #[Action('admin_enqueue_scripts', priority: 10)]
    public function enqueueAssets()
    {

        if (false === $this->isMailerPressEditor()) {
            return;
        }

        global $post;

        wp_enqueue_style('wp-editor');
        wp_enqueue_media();
        remove_action('admin_print_styles', 'wp_print_font_faces', 50);
        remove_action('admin_print_styles', 'wp_print_font_faces_from_style_variations', 50);
        // load editor assets
        do_action('mailpress_enqueue_scripts');


        if (file_exists(Kernel::$config['root'] . '/build/dist/js/mail-editor.asset.php')) {
            $asset_file = include Kernel::$config['root'] . '/build/dist/js/mail-editor.asset.php';

            wp_register_script(
                'mail-editor',
                Kernel::$config['rootUrl'] . 'build/dist/js/mail-editor.js',
                $asset_file['dependencies'],
                $asset_file['version'],
                ['in_footer' => true]
            );

            wp_set_script_translations(
                'mail-editor', // must match enqueued handle
                'mailerpress'
            );

            wp_enqueue_script('mail-editor');


            $globalSender = get_option('mailerpress_global_email_senders', json_encode([
                'fromAddress' => get_bloginfo('admin_email'),
                'fromName' => get_bloginfo('name'),
            ]));

            $whiteLabel = apply_filters('mailerpress_white_label_options', [
                'white_label_active' => false,
                'hide_esp_badge' => false,
            ]);

            $globalSenderDecoded = is_string($globalSender) ? json_decode($globalSender) : null;
            $userPreferences = get_user_meta(get_current_user_id(), 'mailerpress_preferences', true);
            // Ensure user_preferences is always an array
            if (!is_array($userPreferences)) {
                $userPreferences = [];
            }
            $pages = get_pages(['number' => 100, 'sort_column' => 'post_title']);
            $globalTypographySettings = get_option('mailerpress_global_typography');
            // Ensure it's an array if it exists
            if ($globalTypographySettings && is_string($globalTypographySettings)) {
                $globalTypographySettings = json_decode($globalTypographySettings, true);
            }

            wp_localize_script('mail-editor', 'jsVars', [
                'licenceActivated' => get_option('mailerpress_license_activated', false),
                'autoSave' => apply_filters('mailerpress_editor_auto_save', MINUTE_IN_SECONDS),
                'userCaps' => CapabilitiesManager::getCurrentUserCaps(),
                'bounceConfig' => get_option('mailerpress_bounce_config'),
                'hasCompletedSetup' => get_user_meta(
                    get_current_user_id(),
                    'mailerpress_setup_completed',
                    true
                ) === 'yes',
                'version' => MAILERPRESS_VERSION,
                'user_preferences' => array_merge([
                    'topToolbar' => false,
                    'secondarySidebarOpen' => true,
                    'blockLibraryOpen' => true,
                    'codeEditorTheme' => 'light',
                ], $userPreferences),
                'home' => home_url(),
                'activeTheme' => get_option('mailerpress_theme', 'Core'),
                'frequencySending' => get_option('mailerpress_frequency_sending', false),
                'adminEmail' => get_bloginfo('admin_email'),
                'campaign' => $post,
                'patternCategories' => Kernel::getContainer()->get(PatternsCategories::class)->getCategories(),
                'templateCategories' => Kernel::getContainer()->get(TemplatesCategories::class)->getCategories(),
                'templatesMapping' => Kernel::getContainer()->get(TemplatesCategories::class)->getTemplatesGroupByCategories(),
                'adminUrl' => admin_url('admin.php'),
                'adminReturn' => admin_url(),
                'pluginInited' => $this->checkPluginInit(),
                'gptAi' => get_option('mailerpress_ai_config'),
                'imagesSizes' => wp_get_registered_image_subsizes(),
                'categories' => get_categories([
                    'hide_empty' => true,
                    'orderby' => 'name',
                ]),
                'esp' => array_reduce(
                    Kernel::getContainer()->get(EmailServiceManager::class)->getServices(),
                    static function ($acc, EmailServiceInterface $service) {
                        $acc[] = $service->config();

                        return $acc;
                    },
                    []
                ),
                'defaultSettings' => get_option('mailerpress_default_settings', [
                    'fromAddress' => $globalSenderDecoded->fromAddress ?? '',
                    'fromName' => $globalSenderDecoded->fromName ?? '',
                    'unsubpage' => [
                        'useDefault' => true,
                        'pageId' => $pages[0]->ID
                    ],
                    'subpage' => [
                        'useDefault' => true,
                        'pageId' => $pages[0]->ID
                    ],
                ]),
                'whiteLabelData' => $whiteLabel,
                'whiteLabelMenu' => !defined('MAILERPRESS_WHITE_LABEL_ACTIVE') || constant('MAILERPRESS_WHITE_LABEL_ACTIVE') === true,
                'showNoticeLienceActivation' => !defined('MAILERPRESS_SHOW_NOTICE_LICENCE_ACTIVATION') || constant('MAILERPRESS_SHOW_NOTICE_LICENCE_ACTIVATION') === true,
                'lists' => Kernel::getContainer()->get(Lists::class)->getLists(),
                'sender' => $globalSender,
                'latestPosts' => formatPostForApi(Kernel::getContainer()->get(Posts::class)->getLatest()),
                'savedPatterns' => formatPatternsForEditor(Kernel::getContainer()->get(PatternModel::class)->getAll()),
                'contactTags' => Kernel::getContainer()->get(Tags::class)->getAll(),
                'endpointBase' => \sprintf('/%s/', esc_html(Kernel::getContainer()->get('rest_namespace'))),
                'themeStyles' => Kernel::getContainer()->get(ThemeStyles::class)->getThemeStyles(),
                'globalStyles' => wp_get_global_styles(),
                'globalSettings' => wp_get_global_settings(),
                'defaultBlocksSettings' => Kernel::getContainer()->get(ThemeStyles::class)->loadJsonSettings(),
                'isBlockTheme' => function_exists('wp_is_block_theme') ? wp_is_block_theme() : false,
                'emailServiceConfiguration' => Kernel::getContainer()->get(EmailServiceManager::class)->getConfigurations(),
                'globalSender' => $globalSender,
                'nonce' => wp_create_nonce('wp_rest'),
                'pages' => $pages,
                'editorFonts' => get_option('mailerpress_fonts_v2', []),
                'pluginDirUrl' => Kernel::$config['rootUrl'],
                'mailerPressSignupConfirmation' => get_option('mailerpress_signup_confirmation', wp_json_encode([
                    'enableSignupConfirmation' => true,
                    'emailSubject' => 'Confirm your subscription to [site:title]',
                    'emailContent' => 'Hello [contact:firstName] [contact:lastName],

You have received this email regarding your subscription to [site:title]. Please confirm it to receive emails from us:

[activation_link]Click here to confirm your subscription[/activation_link]

If you received this email in error, simply delete it. You will no longer receive emails from us if you do not confirm your subscription using the link above.

Thank you,

<a target="_blank" href=" [site:homeURL]">[site:title]</a>'
                ])),
                'isPro' => is_plugin_active('mailerpress-pro/mailerpress-pro.php'),
                'isProPresent' => file_exists(WP_PLUGIN_DIR . '/mailerpress-pro/mailerpress-pro.php'),
                'acfActive' => function_exists('acf_get_field_groups'),
                'hasWooCommerce' => function_exists('wc_get_products'),
                'locale' => get_user_locale(),
                'manage_link' => [
                    'subscription' => mailerpress_get_page('unsub_page'),
                    'manage' => mailerpress_get_page('manage_page'),
                ],
                'currentUser' => wp_get_current_user()->ID,
                'typography' => $globalTypographySettings ?: '',
            ]);
        }

        // Localiser jsVars pour tous les scripts admin MailerPress
        // Créer un script inline pour rendre jsVars disponible globalement
        $jsVarsData = [
            'licenceActivated' => get_option('mailerpress_license_activated', false),
            'autoSave' => apply_filters('mailerpress_editor_auto_save', MINUTE_IN_SECONDS),
            'userCaps' => CapabilitiesManager::getCurrentUserCaps(),
            'bounceConfig' => get_option('mailerpress_bounce_config'),
            'hasCompletedSetup' => get_user_meta(
                get_current_user_id(),
                'mailerpress_setup_completed',
                true
            ) === 'yes',
            'version' => MAILERPRESS_VERSION,
            'adminUrl' => admin_url('admin.php'),
            'adminReturn' => admin_url(),
            'pluginInited' => $this->checkPluginInit(),
            'isPro' => is_plugin_active('mailerpress-pro/mailerpress-pro.php'),
            'isProPresent' => file_exists(WP_PLUGIN_DIR . '/mailerpress-pro/mailerpress-pro.php'),
        ];

        // Enregistrer un script minimal pour rendre jsVars disponible globalement
        wp_register_script(
            'mailerpress-jsvars',
            '',
            [],
            MAILERPRESS_VERSION,
            false
        );
        wp_enqueue_script('mailerpress-jsvars');
        wp_add_inline_script('mailerpress-jsvars', 'var jsVars = ' . wp_json_encode($jsVarsData) . ';', 'before');

        $buildPath = Kernel::$config['root'] . '/build/';
        $buildUrl = rtrim(Kernel::$config['rootUrl'], '/') . '/build/';

        foreach (glob($buildPath . '*.asset.php') as $assetFile) {
            $vendorFile = include $assetFile;
            $jsFile = basename(str_replace('.asset.php', '.js', $assetFile));
            $handle = 'mailerpress-editor-js-' . pathinfo($jsFile, PATHINFO_FILENAME);

            wp_register_script(
                $handle,
                $buildUrl . $jsFile,
                array_merge($vendorFile['dependencies'], ['wp-i18n']),
                $vendorFile['version'] ?? false,
                true // in footer
            );

            wp_enqueue_script($handle);
        }

        if (file_exists(Kernel::$config['root'] . '/build/dist/css/mail-editor.asset.php')) {
            $assetCssFile = include Kernel::$config['root'] . '/build/dist/css/mail-editor.asset.php';
            wp_enqueue_style(
                'mailerpress-editor-css',
                Kernel::$config['rootUrl'] . 'build/dist/css/mail-editor.css',
                ['wp-components'],
                $assetCssFile['version']
            );
        }

        if (file_exists(\MailerPress\Core\Kernel::$config['root'] . '/build/dist/js/mailerpress-pro-workflow.asset.php')) {
            $asset_file_2 = include(Kernel::$config['root'] . '/build/dist/js/mailerpress-pro-workflow.asset.php');
            wp_enqueue_script(
                'mailerpress-pro-workflow',
                Kernel::$config['rootUrl'] . 'build/dist/js/mailerpress-pro-workflow.js',
                $asset_file_2['dependencies'],
                $asset_file_2['version'],
                ['in_footer' => true]
            );
        }

        wp_enqueue_style(
            'xyflow-react-style',
            Kernel::$config['rootUrl'] . 'build/public/xyflow-react.css',
            [], // no dependencies or add if needed
            MAILERPRESS_VERSION
        );
    }

    #[Action('admin_head')]
    public function preloadEditorFonts()
    {
        if (!$this->isMailerPressEditor()) {
            return;
        }

        $fonts = get_option('mailerpress_fonts_v2', []);

        foreach ($fonts as $family => $fontData) {
            $sources = $fontData['sources'] ?? [];
            $variants = $fontData['variants'] ?? [];

            foreach ($variants as $variant) {
                if (!isset($sources[$variant])) {
                    continue;
                }

                // parse variant name: e.g. "abeezee-400-italic"
                if (preg_match('/-(\d+)-(normal|italic)$/', $variant, $matches)) {
                    $weight = $matches[1];
                    $style = $matches[2];
                } else {
                    $weight = '400';
                    $style = 'normal';
                }

                $url = esc_url($sources[$variant]);

                $fontFamilyRaw = $fontData['fontFamily'] ?? '';
                if (!$fontFamilyRaw) {
                    continue; // skip if no font family defined
                }

                $firstFont = explode(',', $fontFamilyRaw)[0];
                $firstFont = trim($firstFont, "\"' ");

                echo '<style ';
                echo 'data-font-family="' . esc_attr($firstFont) . '" ';
                echo 'data-variant="' . esc_attr($variant) . '"';
                echo '>';
                echo '@font-face {';
                echo 'font-family: "' . $firstFont . '";';
                echo 'src: url("' . $url . '") format("woff2");';
                echo 'font-weight: ' . $weight . ';';
                echo 'font-style: ' . $style . ';';
                echo '}';
                echo '</style>';
            }
        }
    }

    #[Filter('script_loader_tag', priority: 10, acceptedArgs: 3)]
    public function deferScript($tag, $handle, $src)
    {
        if (str_starts_with($handle, 'mailerpress-editor')) {
            // Use defer for execution after parsing
            return '<script src="' . esc_url($src) . '" defer></script>';
        }
        return $tag;
    }

    /**
     * Gets whether the current screen is the GCB editor.
     *
     * @return bool whether this is the GCB editor
     */
    public function isMailerPressEditor(): bool
    {
        $screen = get_current_screen();

        if (!is_object($screen)) {
            return false;
        }

        return str_contains($screen->id, 'mailerpress');
    }


    public function checkPluginInit(): bool
    {
        $sendersOption = get_option('mailerpress_global_email_senders');
        $data = get_option('mailerpress_email_services', [
            'default_service' => 'php',
            'activated' => ['php'],
            'services' => [
                'php' => [
                    'conf' => [
                        'default_email' => '',
                        'default_name' => '',
                    ],
                ],
            ],
        ]);

        return isset($data['default_service'])
            && \is_array($data['activated'])
            && \array_key_exists($data['default_service'], $data['services']) && !empty($sendersOption);
    }
}
