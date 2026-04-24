<?php

declare(strict_types=1);

namespace MailerPress\Api;

use MailerPress\Core\Capabilities;

\defined('ABSPATH') || exit;

class Permissions
{
    public static function canView($request): bool|\WP_Error
    {
        // Check if the user is logged in
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_forbidden', 'You do not have permission to view this resource.', ['status' => 403]);
        }

        // Verify nonce if necessary
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed.', ['status' => 403]);
        }

        return true;
    }

    public static function canEdit(): bool
    {
        return current_user_can('edit_posts');
    }

    public static function canManageCampaign(): bool
    {
        return current_user_can(Capabilities::MANAGE_CAMPAIGNS);
    }

    public static function canPublishCampaign(): bool
    {
        return current_user_can(Capabilities::PUBLISH_CAMPAIGNS);
    }

    public static function canManageSettings(): bool
    {
        return current_user_can(Capabilities::MANAGE_SETTINGS);
    }
    public static function canManageAudience(): bool
    {
        return current_user_can(Capabilities::MANAGE_CONTACTS);
    }
    public static function canManageLists(): bool
    {
        return current_user_can(Capabilities::MANAGE_LISTS);
    }
    public static function canManageTags(): bool
    {
        return current_user_can(Capabilities::MANAGE_TAGS);
    }
    public static function canManageTemplates(): bool
    {
        return current_user_can(Capabilities::MANAGE_TEMPLATES);
    }
    public static function canDeleteLists(): bool
    {
        return current_user_can(Capabilities::DELETE_LISTS);
    }
    public static function canDeleteTags(): bool
    {
        return current_user_can(Capabilities::DELETE_TAGS);
    }
    public static function canDeleteCampaigns(): bool
    {
        return current_user_can(Capabilities::DELETE_EMAIL_CAMPAIGNS);
    }
    public static function canManageAutomations(): bool
    {
        return current_user_can(Capabilities::MANAGE_AUTOMATIONS);
    }
}
