<?php

declare(strict_types=1);

namespace MailerPress\Core;

use WP_Roles;

\defined('ABSPATH') || exit;

class CapabilitiesManager
{

    public static function addCapabilities(): void
    {
        // Your mapping
        $mapping = [
            Capabilities::MANAGE_SETTINGS => 'manage_options',
            Capabilities::MANAGE_CAMPAIGNS => 'edit_posts',
            Capabilities::EDIT_OTHERS_CAMPAIGNS => 'edit_others_posts',
            Capabilities::PUBLISH_CAMPAIGNS => 'publish_posts',
            Capabilities::DELETE_EMAIL_CAMPAIGNS => 'delete_published_posts',
            Capabilities::DELETE_CONTACTS => 'delete_posts',
            Capabilities::MANAGE_CONTACTS => 'edit_posts',
            Capabilities::MANAGE_LISTS => 'manage_categories',
            Capabilities::DELETE_LISTS => 'manage_categories',
            Capabilities::MANAGE_TAGS => 'manage_categories',
            Capabilities::DELETE_TAGS => 'manage_categories',
            Capabilities::MANAGE_TEMPLATES => 'edit_themes',
            Capabilities::MANAGE_AUTOMATIONS => 'edit_posts',
            Capabilities::MANAGE_CONTACT_SEGMENTATION => 'edit_posts',
        ];

        // Get all roles
        global $wp_roles;
        foreach ($wp_roles->roles as $role_name => $role_info) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }

            // Loop through mapping
            foreach ($mapping as $custom_cap => $base_cap) {
                // Only add the custom capability if the role has the base capability
                if ($role->has_cap($base_cap)) {
                    $role->add_cap($custom_cap);
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
