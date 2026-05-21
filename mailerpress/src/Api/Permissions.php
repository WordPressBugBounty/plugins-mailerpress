<?php

declare(strict_types=1);

namespace MailerPress\Api;

use MailerPress\Core\ApiAuthentication;
use MailerPress\Core\Capabilities;

\defined('ABSPATH') || exit;

class Permissions
{
    /**
     * Map a WordPress capability + HTTP method to an API key scope.
     */
    private static function capabilityToScope(string $capability, string $method): ?string
    {
        $isWrite = in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $rw      = $isWrite ? 'write' : 'read';

        $map = [
            Capabilities::MANAGE_CONTACTS         => "contacts:{$rw}",
            Capabilities::DELETE_CONTACTS         => 'contacts:write',
            Capabilities::MANAGE_CAMPAIGNS        => "campaigns:{$rw}",
            Capabilities::PUBLISH_CAMPAIGNS       => 'campaigns:write',
            Capabilities::DELETE_EMAIL_CAMPAIGNS  => 'campaigns:write',
            Capabilities::MANAGE_SETTINGS         => "settings:{$rw}",
            Capabilities::MANAGE_LISTS            => "lists:{$rw}",
            Capabilities::DELETE_LISTS            => 'lists:write',
            Capabilities::MANAGE_TAGS             => "tags:{$rw}",
            Capabilities::DELETE_TAGS             => 'tags:write',
            Capabilities::MANAGE_TEMPLATES        => "templates:{$rw}",
            Capabilities::MANAGE_AUTOMATIONS      => "automations:{$rw}",
            'upload_files'                        => 'campaigns:write',
        ];

        return $map[$capability] ?? null;
    }

    /**
     * Try API key authentication, then fall back to WordPress capability check.
     *
     * @param \WP_REST_Request $request
     * @param string|null $capability WordPress capability to check as fallback
     * @return bool|\WP_Error
     */
    private static function checkAuth(\WP_REST_Request $request, ?string $capability = null): bool|\WP_Error
    {
        // Try custom API key authentication first
        $api_auth = ApiAuthentication::authenticate($request);

        if ($api_auth === true) {
            // Enforce scope if a capability is mapped to one
            if ($capability !== null) {
                $scope = self::capabilityToScope($capability, $request->get_method());
                if ($scope !== null && !ApiAuthentication::hasPermission($request, $scope)) {
                    return new \WP_Error(
                        'rest_forbidden',
                        sprintf(__('API key missing required scope: %s', 'mailerpress'), $scope),
                        ['status' => 403]
                    );
                }
            }
            return true;
        }

        if (is_wp_error($api_auth)) {
            return $api_auth;
        }

        // API auth returned false (no API key headers), try WordPress authentication
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 401]);
        }

        // Verify nonce for cookie-based auth (not for Application Passwords)
        $nonce = $request->get_header('X-WP-Nonce');
        $auth_header = $request->get_header('Authorization');
        $is_application_password = !empty($auth_header) && str_starts_with($auth_header, 'Basic ');

        if (!$is_application_password) {
            // Cookie-based auth requires a valid nonce to prevent CSRF
            if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie nonce verification failed.', ['status' => 403]);
            }
        }

        // If a specific capability is required, check it
        if ($capability !== null && !current_user_can($capability)) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        return true;
    }

    public static function canView($request): bool|\WP_Error
    {
        return self::checkAuth($request, 'edit_posts');
    }

    public static function canEdit($request): bool|\WP_Error
    {
        return self::checkAuth($request, 'edit_posts');
    }

    public static function canUploadMedia($request): bool|\WP_Error
    {
        return self::checkAuth($request, 'upload_files');
    }

    public static function canManageCampaign($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_CAMPAIGNS);
    }

    public static function canPublishCampaign($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::PUBLISH_CAMPAIGNS);
    }

    public static function canManageSettings($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_SETTINGS);
    }

    public static function canManageAudience($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_CONTACTS);
    }

    public static function canManageLists($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_LISTS);
    }

    public static function canManageTags($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_TAGS);
    }

    public static function canManageTemplates($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_TEMPLATES);
    }

    public static function canDeleteLists($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::DELETE_LISTS);
    }

    public static function canDeleteTags($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::DELETE_TAGS);
    }

    public static function canDeleteCampaigns($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::DELETE_EMAIL_CAMPAIGNS);
    }

    public static function canViewNotifications($request): bool|\WP_Error
    {
        return self::checkAuth($request, 'edit_posts');
    }
    public static function canManageAutomations($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_AUTOMATIONS);
    }
}
