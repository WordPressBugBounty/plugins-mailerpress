<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

/**
 * Pro Extensions System
 *
 * Provides extension points for the Pro plugin to register its features.
 * This allows the free plugin to remain WordPress.org compliant by not
 * containing working code for Pro-only features.
 *
 * @since 2.0.0
 */
class ProExtensions
{
    /**
     * Check if Pro plugin is active
     *
     * @return bool
     */
    public static function isProActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active')
            && is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    /**
     * Register Embed API endpoints
     *
     * Pro plugin can hook into this to register its Embed endpoints
     */
    public static function registerEmbedEndpoints(): void
    {
        /**
         * Filter: mailerpress_register_embed_endpoints
         *
         * Allows Pro plugin to register Embed Form API endpoints
         *
         * @since 2.0.0
         */
        do_action('mailerpress_register_embed_endpoints');
    }

    /**
     * Register Webhook endpoints
     *
     * Pro plugin can hook into this to register its Webhook endpoints
     */
    public static function registerWebhookEndpoints(): void
    {
        /**
         * Filter: mailerpress_register_webhook_endpoints
         *
         * Allows Pro plugin to register Webhook API endpoints
         *
         * @since 2.0.0
         */
        do_action('mailerpress_register_webhook_endpoints');
    }

    /**
     * Get Embed settings component
     *
     * Returns the Embed settings React component.
     * In FREE: Returns null (Pro plugin will inject the real component)
     * In PRO: Pro plugin filters this to return the full component
     *
     * @return array|null Component data or null
     */
    public static function getEmbedSettingsComponent(): ?array
    {
        /**
         * Filter: mailerpress_embed_settings_component
         *
         * Allows Pro plugin to provide the Embed settings component
         *
         * @since 2.0.0
         * @param array|null $component Component data
         */
        return apply_filters('mailerpress_embed_settings_component', null);
    }

    /**
     * Get Incoming Webhooks settings component
     *
     * @return array|null Component data or null
     */
    public static function getIncomingWebhooksComponent(): ?array
    {
        /**
         * Filter: mailerpress_incoming_webhooks_component
         *
         * Allows Pro plugin to provide the Incoming Webhooks component
         *
         * @since 2.0.0
         * @param array|null $component Component data
         */
        return apply_filters('mailerpress_incoming_webhooks_component', null);
    }

    /**
     * Get Outgoing Webhooks settings component
     *
     * @return array|null Component data or null
     */
    public static function getOutgoingWebhooksComponent(): ?array
    {
        /**
         * Filter: mailerpress_outgoing_webhooks_component
         *
         * Allows Pro plugin to provide the Outgoing Webhooks component
         *
         * @since 2.0.0
         * @param array|null $component Component data
         */
        return apply_filters('mailerpress_outgoing_webhooks_component', null);
    }

    /**
     * Register WordPress Emails endpoints and interceptor
     *
     * Pro plugin can hook into this to register its WordPress Emails endpoints
     * and the email interceptor that customizes WP transactional emails.
     */
    public static function registerWordPressEmailsEndpoints(): void
    {
        /**
         * Action: mailerpress_register_wordpress_emails_endpoints
         *
         * Allows Pro plugin to register WordPress Emails API endpoints
         * and the WordPressEmailInterceptor.
         *
         * @since 2.0.0
         */
        do_action('mailerpress_register_wordpress_emails_endpoints');
    }

    /**
     * Register WooCommerce Emails endpoints and interceptor
     *
     * Pro plugin can hook into this to register its WooCommerce Emails endpoints
     * and the email interceptor that customizes WC transactional emails.
     */
    public static function registerWooCommerceEmailsEndpoints(): void
    {
        /**
         * Action: mailerpress_register_woocommerce_emails_endpoints
         *
         * Allows Pro plugin to register WooCommerce Emails API endpoints
         * and the WooCommerceEmailInterceptor.
         *
         * @since 2.0.0
         */
        do_action('mailerpress_register_woocommerce_emails_endpoints');
    }

    /**
     * Check if Embed Forms feature is available
     *
     * @return bool
     */
    public static function hasEmbedForms(): bool
    {
        return self::isProActive() && has_action('mailerpress_register_embed_endpoints');
    }

    /**
     * Check if Webhooks feature is available
     *
     * @return bool
     */
    public static function hasWebhooks(): bool
    {
        return self::isProActive() && has_action('mailerpress_register_webhook_endpoints');
    }
}
