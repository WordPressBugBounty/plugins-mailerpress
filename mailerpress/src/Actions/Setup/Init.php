<?php

declare(strict_types=1);

namespace MailerPress\Actions\Setup;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\Workflows\Handlers\AddTagStepHandler;
use MailerPress\Core\Workflows\Handlers\SendEmailStepHandler;
use MailerPress\Core\Workflows\WorkflowSystem;

class Init
{
    private const WP_THEME_STYLESHEET_OPTION = 'mailerpress_wp_theme_stylesheet';

    /** Bump this value to re-run hardenStorage() on every site at the next admin page load. */
    private const STORAGE_HARDENING_VERSION = '2026-09-23.1';
    private const STORAGE_HARDENING_OPTION = 'mailerpress_storage_hardening';

    #[Action('init')]
    public function rewriteRule(): void
    {
        $this->registerDefaultTemplateCategories();
        $this->disableEmojiConversion();
        \MailerPress\Core\ProExtensions::registerWordPressEmailsEndpoints();
        if (\function_exists('wc_get_order')) {
            \MailerPress\Core\ProExtensions::registerWooCommerceEmailsEndpoints();
        }

        // Allow Pro plugin to register its endpoints
        \MailerPress\Core\ProExtensions::registerEmbedEndpoints();
        \MailerPress\Core\ProExtensions::registerWebhookEndpoints();
        add_rewrite_rule(
            '^unsubscribe/([0-9]+)/([^/]+)/?',
            'index.php?unsubscribe_user=$matches[1]&unsubscribe_token=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            '^tracking-link/([^/]+)/?',
            'index.php?mailerpress_tracking_token=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^mp/o/([^/]+)/?',
            'index.php?mailerpress_open_token=$matches[1]',
            'top'
        );
    }

    /**
     * Désactive la conversion automatique des emojis en images par WordPress
     * uniquement dans le contexte d'envoi d'emails MailerPress.
     *
     * On utilise un filtre sur wp_mail avec une priorité élevée pour désactiver
     * temporairement wp_staticize_emoji_for_email uniquement pour les emails MailerPress.
     */
    private function disableEmojiConversion(): void
    {
        // Désactiver le filtre d'emoji juste avant wp_mail pour les emails MailerPress
        // Priorité 5 pour s'exécuter avant wp_staticize_emoji_for_email (priorité 10)
        add_filter('wp_mail', function ($args) {
            $message = $args['message'] ?? '';

            // Vérifier si c'est un email MailerPress en cherchant des marqueurs spécifiques
            $isMailerPressEmail = (
                strpos($message, 'mj-') !== false ||           // Tags MJML
                strpos($message, 'data-emoji-id') !== false || // Spans d'emoji MailerPress
                strpos($message, 'mailerpress') !== false      // Autres marqueurs MailerPress
            );

            if ($isMailerPressEmail) {
                // Désactiver temporairement le filtre d'emoji pour cet email
                remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

                // Le réactiver après l'envoi via shutdown pour ne pas affecter les autres emails
                add_action('shutdown', function () {
                    if (!has_filter('wp_mail', 'wp_staticize_emoji_for_email')) {
                        add_filter('wp_mail', 'wp_staticize_emoji_for_email', 10);
                    }
                }, 999);
            }

            return $args;
        }, 5);
    }

    #[Action('admin_init')]
    public function syncCap(): void
    {
        $current_version = defined('MAILERPRESS_VERSION_DEV')
            ? MAILERPRESS_VERSION_DEV   // dev override
            : MAILERPRESS_VERSION;      // release version

        $stored_version = get_option('mailerpress_version');
        $stored_capability_schema = get_option('mailerpress_capability_schema_version');

        // If the option doesn't exist, $stored_version will be false
        if (
            $stored_version === false
            || $stored_version !== $current_version
            || $stored_capability_schema !== \MailerPress\Core\CapabilitiesManager::SCHEMA_VERSION
        ) {
            \MailerPress\Core\CapabilitiesManager::addCapabilities();
            update_option('mailerpress_version', $current_version);
            update_option('mailerpress_capability_schema_version', \MailerPress\Core\CapabilitiesManager::SCHEMA_VERSION);

            // Flush rewrite rules when version changes to ensure new rewrite rules are registered
            $this->flushRewriteRules();
        }
    }

    /**
     * Rename stored capability grants before syncCap() refreshes the roles.
     */
    #[Action('init', priority: 5)]
    public function migrateLegacyCapabilities(): void
    {
        \MailerPress\Core\CapabilitiesManager::migrateLegacyCapabilities();
    }

    /**
     * Keep current_user_can() working with former capability names.
     */
    #[Filter('map_meta_cap', acceptedArgs: 2)]
    public function mapLegacyCapability(array $caps, string $cap): array
    {
        return \MailerPress\Core\CapabilitiesManager::mapLegacyCapability($caps, $cap);
    }

    /**
     * One-shot hardening of files left on disk by previous versions. Keyed by its own marker,
     * NOT by the plugin version: it must also run when the same version is re-deployed with
     * this fix, and again whenever STORAGE_HARDENING_VERSION is bumped.
     */
    #[Action('admin_init', priority: 6)]
    public function hardenStorage(): void
    {
        if (get_option(self::STORAGE_HARDENING_OPTION) === self::STORAGE_HARDENING_VERSION) {
            return;
        }

        try {
            // Send logs (with recipient emails) used to live in the world-readable
            // wp-content/mailerpress-logs/: remove it, and protect the shared uploads log directory.
            \MailerPress\Services\DebugFileLogger::purgeLegacyDirectory();
            \MailerPress\Services\DebugFileLogger::protectLegacyUploadsDirectory();

            // Contact exports left in uploads/mailerpress_exports: protect the directory now and
            // delete archives older than their download link.
            \MailerPress\Actions\ActionScheduler\Processors\ExportContact::hardenExistingExports();
        } catch (\Throwable $e) {
            // Never take the admin down for a housekeeping task: leave the marker unset so the
            // routine retries on the next admin page load.
            return;
        }

        update_option(self::STORAGE_HARDENING_OPTION, self::STORAGE_HARDENING_VERSION, false);
    }

    #[Action('admin_init', priority: 5)]
    public function migrateJsonOptions(): void
    {
        if (get_option('mailerpress_migrated_json_options_v3')) {
            return;
        }

        $optionNames = [
            'mailerpress_default_settings',
            'mailerpress_signup_confirmation',
            'woocommerce_mailerpress_settings',
            'woocommerce_my_account_settings',
            'pmpro_mailerpress_settings',
            'pmpro_my_account_settings',
            'mailerpress_edd_settings',
            'surecart_mailerpress_settings',
            'fluentcart_mailerpress_settings',
            'fluentcart_my_account_settings',
        ];

        foreach ($optionNames as $optionName) {
            $value = get_option($optionName);
            if (is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    update_option($optionName, $decoded);
                }
            }
        }

        update_option('mailerpress_migrated_json_options_v3', true);
    }

    #[Action('admin_init', priority: 5)]
    public function syncThemeStylesheet(): void
    {
        $stylesheet = get_stylesheet();
        $previousStylesheet = get_option(self::WP_THEME_STYLESHEET_OPTION);

        if ($previousStylesheet === $stylesheet) {
            return;
        }

        update_option(self::WP_THEME_STYLESHEET_OPTION, $stylesheet, false);

        // A theme changed without switch_theme (e.g. while MailerPress was inactive).
        // Before a theme was recorded, block theme email styles are kept.
        if (false !== $previousStylesheet || !function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            $this->resetMailerPressThemeStyles();
        }
    }

    /**
     * Flush rewrite rules to ensure new rules are registered
     * This is called automatically when the plugin version changes
     */
    private function flushRewriteRules(): void
    {
        // Delete the rewrite rules option to force WordPress to regenerate them
        delete_option('rewrite_rules');

        // Flush rewrite rules on next request
        // Using a transient to ensure it only happens once per version change
        $current_version = defined('MAILERPRESS_VERSION_DEV')
            ? MAILERPRESS_VERSION_DEV
            : MAILERPRESS_VERSION;

        $flush_key = 'mailerpress_flush_rewrite_' . $current_version;

        if (!get_transient($flush_key)) {
            flush_rewrite_rules(false); // false = don't hard flush, just update the option
            set_transient($flush_key, true, DAY_IN_SECONDS);
        }
    }


    #[Filter('query_vars')]
    public function queryVars($vars)
    {
        $vars[] = 'unsubscribe_user';
        $vars[] = 'unsubscribe_token';
        $vars[] = 'mailerpress_tracking_token';
        $vars[] = 'mailerpress_open_token';

        return $vars;
    }

    #[Action('switch_theme', acceptedArgs: 2)]
    public function resetThemeStylesOnSwitch(string $newName, \WP_Theme $newTheme): void
    {
        update_option(
            self::WP_THEME_STYLESHEET_OPTION,
            $newTheme->get_stylesheet(),
            false
        );
        $this->resetMailerPressThemeStyles();
    }

    // The email styles taken from a theme belong to it: the next theme sets its own.
    private function resetMailerPressThemeStyles(): void
    {
        update_option('mailerpress_theme', 'Core');
        delete_option('mailerpress_theme_palette');
        // Edited theme colors belong to the previous theme; custom colors stay.
        $paletteColors = get_option('mailerpress_palette_colors');
        if (\is_array($paletteColors) && isset($paletteColors['theme'])) {
            unset($paletteColors['theme']);
            update_option('mailerpress_palette_colors', $paletteColors, false);
        }
        $fontSizes = get_option('mailerpress_font_sizes');
        if (\is_array($fontSizes) && isset($fontSizes['theme'])) {
            unset($fontSizes['theme']);
            update_option('mailerpress_font_sizes', $fontSizes, false);
        }
        delete_option('mailerpress_global_typography');
    }

    private function registerDefaultTemplateCategories(): void
    {
        if (\function_exists('mailerpress_register_templates_category')) {
            mailerpress_register_templates_category([
                'mailerpress/core/communication' => [
                    'label' => __('Marketing communication', 'mailerpress'),
                ],
                'mailerpress/core/ecommerce' => [
                    'label' => __('Ecommerce', 'mailerpress'),
                ],
            ]);
        }
    }
}
