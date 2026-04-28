<?php

namespace MailerPress\Core;

/**
 * Centralized external links management with i18n support.
 *
 * Usage PHP:
 *   ExternalLinks::get('pricing')           // returns URL for current locale
 *   ExternalLinks::get('docs.getting_started', 'fr')  // force French
 *   ExternalLinks::all()                    // returns all links for current locale
 *
 * Usage JS:
 *   window.mailerpress.links.pricing
 *   window.mailerpress.links['docs.getting_started']
 */
class ExternalLinks
{
    /**
     * Default links (English / fallback).
     * Keys use dot notation for grouping: 'docs.getting_started', 'pricing', etc.
     */
    private static array $defaults = [
        // Main site
        'home'                          => 'https://mailerpress.com/',
        'pricing'                       => 'https://mailerpress.com/pricing',
        'support'                       => 'https://mailerpress.com/support/',
        'community'                     => 'https://mailerpress.com/community/',
        'feature_request'               => 'https://mailerpress.com/feature-request/',
        'reviews'                       => 'https://wordpress.org/support/plugin/mailerpress/reviews/',

        // Documentation
        'docs'                          => 'https://mailerpress.com/docs/',
        'docs.getting_started'          => 'https://mailerpress.com/docs/getting-started/',
        'docs.create_campaign'          => 'https://mailerpress.com/docs/create-an-email-campaign/',
        'docs.managing_audiences'       => 'https://mailerpress.com/docs/managing-audiences/',
        'docs.integrations_esp'         => 'https://mailerpress.com/docs/integrations-email-service-providers/',
        'docs.auto_posts'               => 'https://mailerpress.com/docs/send-your-latest-posts-automatically-by-mail/',
        'docs.integrations_plugins'     => 'https://mailerpress.com/docs/integrations-third-party-plugins/',
        'docs.cron_setup'               => 'https://mailerpress.com/docs/how-to-set-up-a-wordpress-cron-job-from-your-server/',
        'docs.white_label'              => 'https://mailerpress.com/docs/enable-the-white-label-feature/',
        'docs.caching_issues'           => 'https://mailerpress.com/docs/solving-caching-issues/',
        'docs.subscription_pages'       => 'https://mailerpress.com/docs/manage-mailerpress-subscription-and-unsubscribe-pages/',
        'docs.editor_guide'             => 'https://mailerpress.com/docs/how-to-use-the-mailerpress-editor/',
        'docs.bounce_tracking'          => 'https://mailerpress.com/docs/bounce-tracking',

        // Integration docs
        'docs.int_gravity_forms'        => 'https://mailerpress.com/docs/how-to-connect-gravity-forms-to-mailerpress',
        'docs.int_cf7'                  => 'https://mailerpress.com/docs/how-to-connect-mailerpress-with-contact-form-7',
        'docs.int_elementor'            => 'https://mailerpress.com/docs/how-to-integrate-mailerpress-with-elementor',
        'docs.int_bricks'               => 'https://mailerpress.com/docs/how-to-integrate-mailerpress-with-bricks-builder',
        'docs.int_fluent_form'          => 'https://mailerpress.com/docs/how-to-connect-mailerpress-with-fluent-forms',
        'docs.int_woocommerce'          => 'https://mailerpress.com/docs/how-to-integrate-mailerpress-with-woocommerce',
        'docs.int_pmpro'                => 'https://mailerpress.com/docs/how-to-integrate-mailerpress-with-paid-memberships-pro',

        // Add-ons
        'addons.optin'                  => 'https://mailerpress.com/add-ons/optin-forms',
        'addons.turbo'                  => 'https://mailerpress.com/add-ons/turbo',

        // Blog / Announcements
        'blog.v2'                       => 'https://mailerpress.com/mailerpress-2-0/',

        // Account
        'my_account'                    => 'https://mailerpress.com/my-account/',
    ];

    /**
     * Localized overrides per language code.
     * Only add URLs that differ from the default.
     */
    private static array $locales = [
        'fr' => [
            'home'                      => 'https://mailerpress.com/fr/',
            'pricing'                   => 'https://mailerpress.com/fr/tarifs/',
            'support'                   => 'https://mailerpress.com/fr/support/',
            'community'                 => 'https://mailerpress.com/fr/community/',
            'feature_request'           => 'https://mailerpress.com/fr/feature-request/',
            'docs'                      => 'https://mailerpress.com/fr/docs/',
            'docs.getting_started'      => 'https://mailerpress.com/fr/docs/pour-commencer/',
            'docs.create_campaign'      => 'https://mailerpress.com/fr/docs/creer-campagne-e-mailing/',
            'docs.managing_audiences'   => 'https://mailerpress.com/fr/docs/gestion-audiences/',
            'docs.integrations_esp'     => 'https://mailerpress.com/fr/docs/integrations-fournisseurs-services-courrier-electronique/',
            'docs.auto_posts'           => 'https://mailerpress.com/fr/docs/envoyer-dernieres-publications-automatiquement-email/',
            'docs.integrations_plugins' => 'https://mailerpress.com/fr/docs/integrations-extensions-tierces/',
            'docs.cron_setup'           => 'https://mailerpress.com/fr/docs/comment-configurer-tache-cron-wordpress-serveur/',
            'docs.white_label'          => 'https://mailerpress.com/fr/docs/activer-fonctionnalite-marque-blanche/',
            'docs.caching_issues'       => 'https://mailerpress.com/fr/docs/solving-caching-issues/',
            'docs.subscription_pages'   => 'https://mailerpress.com/fr/docs/manage-mailerpress-subscription-and-unsubscribe-pages/',
            'docs.editor_guide'         => 'https://mailerpress.com/fr/docs/how-to-use-the-mailerpress-editor/',
            'docs.bounce_tracking'      => 'https://mailerpress.com/fr/docs/bounce-tracking',
            'docs.int_gravity_forms'    => 'https://mailerpress.com/fr/docs/comment-connecter-gravity-forms-mailerpress',
            'docs.int_cf7'              => 'https://mailerpress.com/fr/docs/comment-connecter-mailerpress-contact-form-7',
            'docs.int_elementor'        => 'https://mailerpress.com/fr/docs/ajouter-formulaire-mailerpress-elementor-capturer-emails',
            'docs.int_bricks'           => 'https://mailerpress.com/fr/docs/ajouter-formulaire-mailerpress-bricks-builder-capturer-emails',
            'docs.int_fluent_form'      => 'https://mailerpress.com/fr/docs/comment-connecter-mailerpress-a-fluent-forms',
            'docs.int_woocommerce'      => 'https://mailerpress.com/fr/docs/comment-integrer-mailerpress-woocommerce',
            'docs.int_pmpro'            => 'https://mailerpress.com/fr/docs/comment-integrer-mailerpress-a-paid-memberships-pro',
            'addons.optin'              => 'https://mailerpress.com/fr/add-ons/optin-forms',
            'addons.turbo'              => 'https://mailerpress.com/fr/add-ons/turbo',
            'blog.v2'                   => 'https://mailerpress.com/fr/mailerpress-2-0/',
            'my_account'                => 'https://mailerpress.com/fr/my-account/',
        ],
        // Add more locales here:
        // 'es' => [ ... ],
        // 'de' => [ ... ],
    ];

    /**
     * Get the current WordPress admin language code (2-letter).
     */
    public static function getLocale(): string
    {
        $userLocale = function_exists('get_user_locale')
            ? get_user_locale()
            : get_locale();

        return substr($userLocale ?: 'en', 0, 2);
    }

    /**
     * Get a single link by key, localized to current user's language.
     */
    public static function get(string $key, ?string $locale = null): string
    {
        $lang = $locale ?? self::getLocale();

        // Check locale override first, then fallback to default
        if (isset(self::$locales[$lang][$key])) {
            return self::$locales[$lang][$key];
        }

        return self::$defaults[$key] ?? '';
    }

    /**
     * Get all links merged for the current locale (defaults + overrides).
     * Suitable for passing to wp_localize_script.
     */
    public static function all(?string $locale = null): array
    {
        $lang = $locale ?? self::getLocale();
        $localized = self::$locales[$lang] ?? [];

        return array_merge(self::$defaults, $localized);
    }

    /**
     * Register a custom link or override an existing one at runtime.
     * Useful for pro plugin extensions.
     */
    public static function register(string $key, string $url, ?string $locale = null): void
    {
        if ($locale) {
            self::$locales[$locale][$key] = $url;
        } else {
            self::$defaults[$key] = $url;
        }
    }
}
