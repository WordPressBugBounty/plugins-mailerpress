<?php

declare(strict_types=1);

namespace MailerPress\Core;

use WP_Roles;

\defined('ABSPATH') || exit;

class CapabilitiesManager
{
    public const SCHEMA_VERSION = '2026_06_10_ai_usage_capability';

    public static function addCapabilities(): void
    {
        // Map MailerPress capabilities to the minimum WordPress capability a role must already have.
        $mapping = [
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

        // Get all roles
        global $wp_roles;
        foreach ($wp_roles->roles as $role_name => $role_info) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }

            foreach ($mapping as $custom_cap => $base_cap) {
                if ($role->has_cap($base_cap)) {
                    $role->add_cap($custom_cap);
                } else {
                    $role->remove_cap($custom_cap);
                }
            }
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $user->for_site(get_current_blog_id()); // sets the site context
            // now reload roles/capabilities if needed
            wp_set_current_user($user->ID); // refresh WP_User object
        }
    }


    public static function removeCapabilities(): void
    {
        $caps = Capabilities::get_capabilities(); // all your custom capabilities

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
