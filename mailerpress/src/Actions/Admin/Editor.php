<?php

declare(strict_types=1);

namespace MailerPress\Actions\Admin;

\defined('ABSPATH') || exit;

use MailerPress\Blocks\PatternsCategories;
use MailerPress\Blocks\TemplatesCategories;
use MailerPress\Api\Options;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Capabilities;
use MailerPress\Core\CapabilitiesManager;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\EmailManager\EmailServiceInterface;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use MailerPress\Models\Campaigns;
use MailerPress\Models\Contacts;
use MailerPress\Models\Lists;
use MailerPress\Models\Patterns as PatternModel;
use MailerPress\Models\Posts;
use MailerPress\Models\Tags;
use MailerPress\Services\ThemeStyles;
use MailerPress\Services\ScriptTranslations;
use function MailerPress\Helpers\formatPatternsForEditor;
use function MailerPress\Helpers\formatPostForApi;

class Editor
{
    #[Action('admin_enqueue_scripts', priority: 0)]
    public function disableWordPressEmojiReplacement(): void
    {
        if (!$this->isMailerPressEditor()) {
            return;
        }

        remove_action('admin_print_scripts', 'print_emoji_detection_script');
    }

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
            $script_path = Kernel::$config['root'] . '/build/dist/js/mail-editor.js';
            $script_version = $asset_file['version'] . '-' . (file_exists($script_path) ? filemtime($script_path) : time());

            wp_register_script(
                'mail-editor',
                Kernel::$config['rootUrl'] . 'build/dist/js/mail-editor.js',
                array_merge($asset_file['dependencies'], $this->registerInitialChunks('dist/js/mail-editor')),
                $script_version,
                ['in_footer' => true]
            );

            ScriptTranslations::enqueue('mail-editor', 'mailerpress', Kernel::$config['root'] . '/languages');

            wp_enqueue_script('mail-editor');


            $globalSender = get_option('mailerpress_global_email_senders', json_encode([
                'fromAddress' => get_bloginfo('admin_email'),
                'fromName' => get_bloginfo('name'),
            ]));

            $emailSenders = self::getEmailSenders();

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
            $globalTypographySettings = get_option('mailerpress_global_typography');
            // Ensure it's an array if it exists
            if ($globalTypographySettings && is_string($globalTypographySettings)) {
                $globalTypographySettings = json_decode($globalTypographySettings, true);
            }

            $themeStyles = Kernel::getContainer()->get(ThemeStyles::class)->getThemeStyles();
            $activeTheme = get_option('mailerpress_theme', 'Core');
            if (!is_string($activeTheme) || !isset($themeStyles[$activeTheme])) {
                $activeTheme = 'Core';
            }
            $isBlockTheme = function_exists('wp_is_block_theme') && wp_is_block_theme();
            $hasThemeJson = $isBlockTheme
                && function_exists('wp_theme_has_theme_json')
                && wp_theme_has_theme_json();

            wp_localize_script('mail-editor', 'jsVars', [
                'mergeTagGroups' => Kernel::getContainer()->get(\MailerPress\Core\MergeTags::class)->getEditorGroups(),
                'licenceActivated' => get_option('mailerpress_license_activated', false),
                'autoSave' => apply_filters('mailerpress_editor_auto_save', MINUTE_IN_SECONDS),
                'userCaps' => CapabilitiesManager::getCurrentUserCaps(),
                'canManageRoleAccess' => current_user_can('manage_options') && current_user_can('promote_users'),
                'bounceConfig' => current_user_can(Capabilities::MANAGE_SETTINGS)
                    ? get_option('mailerpress_bounce_config')
                    : [],
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
                'activeTheme' => $activeTheme,
                'frequencySending' => get_option('mailerpress_frequency_sending', false),
                'adminEmail' => get_bloginfo('admin_email'),
                'campaign' => $post,
                'patternCategories' => Kernel::getContainer()->get(PatternsCategories::class)->getCategories(),
                'templateCategories' => Kernel::getContainer()->get(TemplatesCategories::class)->getCategories(),
                'templatesMapping' => Kernel::getContainer()->get(TemplatesCategories::class)->getTemplatesGroupByCategories(),
                'newsletterTemplateCategories' => Kernel::getContainer()->get(TemplatesCategories::class)->getCategories('newsletter'),
                'newsletterTemplatesMapping' => Kernel::getContainer()->get(TemplatesCategories::class)->getTemplatesGroupByCategories('newsletter'),
                'adminUrl' => admin_url('admin.php'),
                'adminReturn' => admin_url(),
                'pluginInited' => $this->checkPluginInit(),
                'imagesSizes' => wp_get_registered_image_subsizes(),
                'categories' => get_categories([
                    'hide_empty' => true,
                    'orderby' => 'name',
                ]),
                'esp' => $this->getPublicEmailServices(),
                'defaultSettings' => get_option('mailerpress_default_settings', [
                    'fromAddress' => $globalSenderDecoded->fromAddress ?? '',
                    'fromName' => $globalSenderDecoded->fromName ?? '',
                    'unsubpage' => [
                        'useDefault' => true,
                        'pageId' => ''
                    ],
                    'subpage' => [
                        'useDefault' => true,
                        'pageId' => ''
                    ],
                ]),
                'whiteLabelData' => $whiteLabel,
                'whiteLabelMenu' => !defined('MAILERPRESS_WHITE_LABEL_ACTIVE') || constant('MAILERPRESS_WHITE_LABEL_ACTIVE') === true,
                'showNoticeLienceActivation' => !defined('MAILERPRESS_SHOW_NOTICE_LICENCE_ACTIVATION') || constant('MAILERPRESS_SHOW_NOTICE_LICENCE_ACTIVATION') === true,
                'lists' => Kernel::getContainer()->get(Lists::class)->getLists(),
                'contactCount' => (int) Kernel::getContainer()->get(Contacts::class)->count(),
                'campaignCount' => (int) Kernel::getContainer()->get(Campaigns::class)->count(),
                'automationCount' => (int) Kernel::getContainer()->get(\MailerPress\Core\Workflows\Repositories\AutomationRepository::class)->count(),

                'sender' => $globalSender,
                'latestPosts' => formatPostForApi(Kernel::getContainer()->get(Posts::class)->getLatest()),
                'savedPatterns' => formatPatternsForEditor(Kernel::getContainer()->get(PatternModel::class)->getAll()),
                'contactTags' => Kernel::getContainer()->get(Tags::class)->getAll(),
                'endpointBase' => \sprintf('/%s/', esc_html(Kernel::getContainer()->get('rest_namespace'))),
                'themeStyles' => $themeStyles,
                'globalStyles' => wp_get_global_styles([], ['transforms' => ['resolve-variables']]),
                'globalSettings' => wp_get_global_settings(),
                'defaultBlocksSettings' => Kernel::getContainer()->get(ThemeStyles::class)->loadJsonSettings(),
                'isBlockTheme' => $isBlockTheme,
                'hasThemeJson' => $hasThemeJson,
                'emailServiceConfiguration' => Options::redactEmailServicesForResponse(
                    Kernel::getContainer()->get(EmailServiceManager::class)->getConfigurations()
                ),
                'globalSender' => $globalSender,
                'emailSenders' => $emailSenders,
                'nonce' => wp_create_nonce('wp_rest'),
                'editorFonts' => get_option('mailerpress_fonts_v2', []),
                'pluginDirUrl' => Kernel::$config['rootUrl'],
                'mailerPressSignupConfirmation' => mailerpress_get_signup_confirmation_option(),
                'confirmEmailStarterTemplate' => mailerpress_get_confirm_email_starter_template(
                    mailerpress_get_signup_confirmation_option()['emailContent'] ?? ''
                ),
                'isPro' => is_plugin_active('mailerpress-pro/mailerpress-pro.php'),
                'proEsp' => apply_filters('mailerpress_pro_esp_configs', []),
                'isProPresent' => file_exists(WP_PLUGIN_DIR . '/mailerpress-pro/mailerpress-pro.php'),
                'addonsStatus' => [
                    'mailerpress-optin-forms' => [
                        'installed' => file_exists(WP_PLUGIN_DIR . '/mailerpress-optin/mailerpress-optin.php'),
                        'active'    => is_plugin_active('mailerpress-optin/mailerpress-optin.php'),
                    ],
                    'mailerpress-turbo' => [
                        'installed' => file_exists(WP_PLUGIN_DIR . '/mailerpress-turbo/mailerpress-turbo.php'),
                        'active'    => is_plugin_active('mailerpress-turbo/mailerpress-turbo.php'),
                    ],
                    'mailerpress-sms' => [
                        'installed' => file_exists(WP_PLUGIN_DIR . '/mailerpress-sms/mailerpress-sms.php'),
                        'active'    => is_plugin_active('mailerpress-sms/mailerpress-sms.php'),
                    ],
                ],
                'acfActive' => function_exists('acf_get_field_groups'),
                'dateFormat' => get_option( 'date_format', 'F j, Y' ),
                'timeFormat' => get_option( 'time_format', 'g:i a' ),
                'hasWooCommerce' => function_exists('wc_get_products'),
                'locale' => get_user_locale(),
                'adminLocale' => get_user_locale(),
                'siteLocale' => get_locale(),
                'links' => \MailerPress\Core\ExternalLinks::all(),
                'manage_link' => [
                    'subscription' => mailerpress_get_page('unsub_page'),
                    'manage' => mailerpress_get_page('manage_page'),
                ],
                'currentUser' => wp_get_current_user()->ID,
                'typography' => $globalTypographySettings ?: '',

                'wpEmailTypes' => apply_filters('mailerpress_wp_email_types_for_frontend', []),
                'wpEmailTemplates' => json_decode(get_option('mailerpress_wp_email_templates', '{}'), true) ?: [],
                'wcEmailTypes' => function_exists('wc_get_order')
                    ? apply_filters('mailerpress_wc_email_types_for_frontend', [])
                    : [],
                'wcEmailTemplates' => json_decode(get_option('mailerpress_wc_email_templates', '{}'), true) ?: [],
                'activeIntegrations' => [
                    'gravity_forms' => is_plugin_active('gravityforms/gravityforms.php'),
                    'cf7'           => is_plugin_active('contact-form-7/wp-contact-form-7.php'),
                    'kadence_forms' => is_plugin_active('kadence-blocks/kadence-blocks.php'),
                    'elementor'     => is_plugin_active('elementor/elementor.php'),
                    'bricks'        => is_plugin_active('bricks/bricks.php'),
                    'fluent_form'   => is_plugin_active('fluentform/fluentform.php'),
                    'surecart'      => is_plugin_active('surecart/surecart.php')
                        || class_exists('\SureCart\SureCart')
                        || class_exists('\SureCart\Models\Purchase')
                        || function_exists('surecart')
                        || defined('SURECART_PLUGIN_FILE'),
                    'fluentcart'    => is_plugin_active('fluent-cart/fluent-cart.php')
                        || is_plugin_active('fluentcart/fluentcart.php')
                        || defined('FLUENTCART_VERSION')
                        || defined('FLUENT_CART_DIR_FILE'),
                    'divi'          => defined('ET_BUILDER_VERSION'),
                    'woocommerce'   => is_plugin_active('woocommerce/woocommerce.php'),
                    'easy_digital_downloads' => is_plugin_active('easy-digital-downloads/easy-digital-downloads.php')
                        || is_plugin_active('easy-digital-downloads-pro/easy-digital-downloads.php'),
                    'pmpro'         => function_exists('pmpro_hasMembershipLevel'),

                    'bit_flows'     => is_plugin_active('bit-flows/bit-flows.php'),
                    'flowmattic'    => is_plugin_active('flowmattic/flowmattic.php'),
                    'ottokit'       => is_plugin_active('suretriggers/suretriggers.php'),
                    'sure_forms'    => is_plugin_active('sureforms/sureforms.php'),
                ],
            ]);
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
                array_merge($asset_file_2['dependencies'], $this->registerInitialChunks('dist/js/mailerpress-pro-workflow')),
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

    /** Register webpack's initial dependencies, leaving lazy chunks to its runtime. */
    private function registerInitialChunks(string $entry): array
    {
        $buildPath = Kernel::$config['root'] . '/build/';
        $buildUrl = rtrim(Kernel::$config['rootUrl'], '/') . '/build/';
        $manifestPath = $buildPath . 'entrypoints.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $files = $manifest[$entry] ?? [];
        } else {
            // Support installations built before the entrypoint manifest was introduced.
            $files = array_map(
                static fn ($file) => basename(str_replace('.asset.php', '.js', $file)),
                glob($buildPath . '*.asset.php') ?: []
            );
        }

        $handles = [];
        foreach ($files as $file) {
            if ($file === $entry . '.js') {
                continue;
            }
            $assetPath = $buildPath . substr($file, 0, -3) . '.asset.php';
            $asset = file_exists($assetPath) ? include $assetPath : [];
            $handle = 'mailerpress-editor-js-' . pathinfo($file, PATHINFO_FILENAME);
            wp_register_script(
                $handle,
                $buildUrl . $file,
                array_merge($asset['dependencies'] ?? [], ['wp-i18n']),
                $asset['version'] ?? (string) filemtime($buildPath . $file),
                true
            );
            $handles[] = $handle;
        }

        return $handles;
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
                echo 'font-family: "' . esc_html($firstFont) . '";';
                echo 'src: url("' . esc_url($url) . '") format("woff2");';
                echo 'font-weight: ' . esc_html($weight) . ';';
                echo 'font-style: ' . esc_html($style) . ';';
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

    private function getPublicEmailServices(): array
    {
        $services = array_reduce(
            Kernel::getContainer()->get(EmailServiceManager::class)->getServices(),
            static function ($acc, EmailServiceInterface $service) {
                $acc[] = $service->config();

                return $acc;
            },
            []
        );

        return array_values(array_filter(
            $services,
            static function (array $service): bool {
                $key = strtolower((string) ($service['key'] ?? ''));
                $name = strtolower((string) ($service['name'] ?? ''));
                $description = strtolower((string) ($service['description'] ?? ''));

                $isE2eCaptureProvider = str_contains($key, 'e2e')
                    || $name === 'e2e capture'
                    || str_contains($description, 'local functional tests')
                    || (str_contains($key, 'capture') && str_contains($description, 'email payload'));

                return !$isE2eCaptureProvider;
            }
        ));
    }

    public static function getEmailSenders(): array
    {
        $senders = get_option( 'mailerpress_email_senders', '' );

        if ( is_string( $senders ) && ! empty( $senders ) ) {
            $senders = json_decode( $senders, true );
        }

        if ( is_array( $senders ) && ! empty( $senders ) ) {
            return $senders;
        }

        $defaultSettings = get_option( 'mailerpress_default_settings', [] );
        if ( is_string( $defaultSettings ) ) {
            $defaultSettings = json_decode( $defaultSettings, true ) ?: [];
        }

        $globalSender = get_option( 'mailerpress_global_email_senders', '' );
        if ( is_string( $globalSender ) && ! empty( $globalSender ) ) {
            $globalSender = json_decode( $globalSender, true ) ?: [];
        }

        $fromAddress = $defaultSettings['fromAddress']
            ?? ( is_array( $globalSender ) ? ( $globalSender['fromAddress'] ?? '' ) : '' );
        $fromName = $defaultSettings['fromName']
            ?? ( is_array( $globalSender ) ? ( $globalSender['fromName'] ?? '' ) : '' );
        $replyToAddress = $defaultSettings['replyToAddress'] ?? '';
        $replyToName = $defaultSettings['replyToName'] ?? '';

        if ( empty( $fromAddress ) ) {
            $fromAddress = get_bloginfo( 'admin_email' );
        }
        if ( empty( $fromName ) ) {
            $fromName = get_bloginfo( 'name' );
        }

        return [
            [
                'id'             => 'default',
                'fromAddress'    => $fromAddress,
                'fromName'       => $fromName,
                'replyToAddress' => $replyToAddress,
                'replyToName'    => $replyToName,
                'isDefault'      => true,
            ],
        ];
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
