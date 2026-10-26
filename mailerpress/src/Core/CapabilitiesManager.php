<?php

declare(strict_types=1);

namespace MailerPress\Core;

use WP_Roles;

\defined('ABSPATH') || exit;

class CapabilitiesManager
{
    public const SCHEMA_VERSION = '2026_06_10_ai_usage_capability';
    public const LEGACY_RENAMES_VERSION = '2026_10_12_edit_others_campaigns';
    public const LEGACY_RENAMES_OPTION = 'mailerpress_capability_renames_version';

    /**
     * Former capability names mapped to their current name.
     *
     * @return array<string, string>
     */
    public static function getLegacyCapabilityRenames(): array
    {
        return [
            Capabilities::LEGACY_EDIT_OTHERS_CAMPAIGNS => Capabilities::EDIT_OTHERS_CAMPAIGNS,
        ];
    }

    /**
     * Move grants stored under a former capability name to its current name.
     *
     * Existing grants are copied as they are, without recalculating them from the
     * default mapping, so role editor customizations are preserved.
     */
    public static function migrateLegacyCapabilities(): void
    {
        if (get_option(self::LEGACY_RENAMES_OPTION) === self::LEGACY_RENAMES_VERSION) {
            return;
        }

        global $wpdb;

        $renames = self::getLegacyCapabilityRenames();
        $roles = wp_roles();

        foreach (array_keys($roles->roles) as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }

            foreach ($renames as $legacy_cap => $cap) {
                if (!array_key_exists($legacy_cap, $role->capabilities)) {
                    continue;
                }

                if (!array_key_exists($cap, $role->capabilities)) {
                    $role->add_cap($cap, (bool) $role->capabilities[$legacy_cap]);
                }
                $role->remove_cap($legacy_cap);
            }
        }

        $meta_key = $wpdb->get_blog_prefix() . 'capabilities';
        foreach ($renames as $legacy_cap => $cap) {
            $user_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
                    $meta_key,
                    '%' . $wpdb->esc_like('"' . $legacy_cap . '"') . '%'
                )
            );

            foreach ($user_ids as $user_id) {
                $user = new \WP_User((int) $user_id);
                if (!$user->exists() || !array_key_exists($legacy_cap, $user->caps)) {
                    continue;
                }

                if (!array_key_exists($cap, $user->caps)) {
                    $user->add_cap($cap, (bool) $user->caps[$legacy_cap]);
                }
                $user->remove_cap($legacy_cap);
            }
        }

        update_option(self::LEGACY_RENAMES_OPTION, self::LEGACY_RENAMES_VERSION);

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $user->for_site(get_current_blog_id());
            wp_set_current_user($user->ID);
        }
    }

    /**
     * Resolve checks against a former capability name with its current name.
     *
     * @param string[] $caps
     * @return string[]
     */
    public static function mapLegacyCapability(array $caps, string $cap): array
    {
        $renames = self::getLegacyCapabilityRenames();
        if (!isset($renames[$cap])) {
            return $caps;
        }

        _deprecated_argument(
            'current_user_can',
            '2.2.0',
            sprintf(
                /* translators: 1: deprecated capability name, 2: replacement capability name. */
                esc_html__('The %1$s capability is deprecated. Use %2$s instead.', 'mailerpress'),
                esc_html($cap),
                esc_html($renames[$cap])
            )
        );

        return array_map(
            static fn(string $mapped_cap): string => $mapped_cap === $cap ? $renames[$cap] : $mapped_cap,
            $caps
        );
    }

    /**
     * Capabilities whose default assignment has already been applied on this site.
     */
    public const INITIALIZED_CAPABILITIES_OPTION = 'mailerpress_initialized_capabilities';

    public static function getDefaultCapabilityMapping(): array
    {
        return [
            Capabilities::MANAGE_SETTINGS => 'manage_options',
            Capabilities::MANAGE_CAMPAIGNS => 'edit_posts',
            Capabilities::EDIT_OTHERS_CAMPAIGNS => 'edit_others_posts',
            Capabilities::PUBLISH_CAMPAIGNS => 'publish_posts',
            Capabilities::DELETE_EMAIL_CAMPAIGNS => 'delete_posts',
            Capabilities::DELETE_CONTACTS => 'manage_options',
            Capabilities::MANAGE_CONTACTS => 'edit_others_posts',
            Capabilities::MANAGE_LISTS => 'manage_categories',
            Capabilities::DELETE_LISTS => 'manage_categories',
            Capabilities::MANAGE_TAGS => 'manage_categories',
            Capabilities::DELETE_TAGS => 'manage_categories',
            Capabilities::MANAGE_TEMPLATES => 'edit_themes',
            Capabilities::MANAGE_AUTOMATIONS => 'publish_posts',
            Capabilities::MANAGE_CONTACT_SEGMENTATION => 'edit_others_posts',
            Capabilities::USE_AI => 'manage_options',
        ];
    }

    /**
     * Grant the default assignment of the capabilities this site has never initialized.
     *
     * Capabilities already initialized are left untouched, so assignments changed with a
     * role editor survive plugin updates and reactivation. Use the
     * `mailerpress_role_capability_enabled` filter to control the default assignment.
     */
    public static function addCapabilities(): void
    {
        $mapping = self::getDefaultCapabilityMapping();
        $initialized = self::getInitializedCapabilities();
        $new_caps = array_diff_key($mapping, array_flip($initialized));

        if ([] !== $new_caps) {
            foreach (array_keys(wp_roles()->roles) as $role_name) {
                $role = get_role($role_name);
                if (!$role) {
                    continue;
                }

                foreach ($new_caps as $custom_cap => $base_cap) {
                    $default_enabled = $role->has_cap($base_cap);
                    $enabled = (bool) apply_filters(
                        'mailerpress_role_capability_enabled',
                        $default_enabled,
                        $role_name,
                        $custom_cap,
                        $base_cap
                    );

                    if ($enabled) {
                        $role->add_cap($custom_cap);
                    }
                }
            }
        }

        $known = array_values(array_unique(array_merge($initialized, array_keys($mapping))));
        if (get_option(self::INITIALIZED_CAPABILITIES_OPTION) !== $known) {
            update_option(self::INITIALIZED_CAPABILITIES_OPTION, $known, false);
        }

        if ([] === $new_caps) {
            return;
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $user->for_site(get_current_blog_id()); // sets the site context
            // now reload roles/capabilities if needed
            wp_set_current_user($user->ID); // refresh WP_User object
        }
    }

    /**
     * Return the capabilities already initialized on this site.
     *
     * Sites updated from a version without the option count a capability as initialized
     * when at least one role already defines it, granted or denied.
     */
    private static function getInitializedCapabilities(): array
    {
        $stored = get_option(self::INITIALIZED_CAPABILITIES_OPTION);
        if (is_array($stored)) {
            return array_values(array_filter($stored, 'is_string'));
        }

        $initialized = [];
        foreach (wp_roles()->roles as $role_info) {
            $role_caps = isset($role_info['capabilities']) && is_array($role_info['capabilities'])
                ? $role_info['capabilities']
                : [];

            foreach (array_keys(self::getDefaultCapabilityMapping()) as $custom_cap) {
                if (array_key_exists($custom_cap, $role_caps)) {
                    $initialized[$custom_cap] = true;
                }
            }
        }

        return array_keys($initialized);
    }


    public static function removeCapabilities(): void
    {
        // All custom capabilities, plus former names that may remain on roles.
        $caps = array_merge(Capabilities::get_capabilities(), array_keys(self::getLegacyCapabilityRenames()));

        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        foreach ($wp_roles->roles as $role_name => $role_info) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }

            foreach ($caps as $cap) {
                $role->remove_cap($cap);
            }
        }
    }


    public static function getCurrentUserCaps(): array
    {
        $all_caps = Capabilities::get_capabilities();

        $user_caps = [];

        foreach ($all_caps as $cap) {
            $user_caps[$cap] = current_user_can($cap);
        }


        return $user_caps;
    }
}
