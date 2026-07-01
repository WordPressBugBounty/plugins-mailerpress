<?php

declare(strict_types=1);

namespace MailerPress\Api;

use MailerPress\Core\ApiAuthentication;
use MailerPress\Core\Capabilities;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Repositories\AutomationRepository;

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

    private static function checkPrivilegedAuth(\WP_REST_Request $request, string $capability, string $requiredWpCapability = 'manage_options'): bool|\WP_Error
    {
        $auth = self::checkAuth($request, $capability);

        if ($auth !== true) {
            return $auth;
        }

        if (!empty($request->get_param('_api_key_id'))) {
            return true;
        }

        if (!current_user_can($requiredWpCapability)) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        return true;
    }

    public static function canView($request): bool|\WP_Error
    {
        return self::checkAuth($request, 'edit_posts');
    }

    public static function canViewMailerPress($request): bool|\WP_Error
    {
        $auth = self::checkAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (current_user_can('edit_posts')) {
            return true;
        }

        foreach (Capabilities::get_capabilities() as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }

        return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
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
        return self::checkPrivilegedAuth($request, Capabilities::MANAGE_SETTINGS);
    }

    public static function canManageAudience($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_CONTACTS);
    }

    public static function canReadAudience($request): bool|\WP_Error
    {
        $api_auth = ApiAuthentication::authenticate($request);

        if ($api_auth === true) {
            if (!ApiAuthentication::hasPermission($request, 'contacts:read')) {
                return new \WP_Error(
                    'rest_forbidden',
                    sprintf(__('API key missing required scope: %s', 'mailerpress'), 'contacts:read'),
                    ['status' => 403]
                );
            }

            return true;
        }

        if (is_wp_error($api_auth)) {
            return $api_auth;
        }

        $auth = self::checkAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (current_user_can(Capabilities::MANAGE_CONTACTS)) {
            return true;
        }

        return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
    }

    public static function canReadContactImportStatus($request): bool|\WP_Error
    {
        $auth = self::checkAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (
            !empty($request->get_param('_api_key_id'))
            || current_user_can(Capabilities::MANAGE_CONTACTS)
            || current_user_can(Capabilities::MANAGE_CAMPAIGNS)
        ) {
            return true;
        }

        return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
    }

    public static function canDeleteContacts($request): bool|\WP_Error
    {
        return self::checkPrivilegedAuth($request, Capabilities::DELETE_CONTACTS);
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
        $auth = self::checkAuth($request, Capabilities::DELETE_EMAIL_CAMPAIGNS);

        if ($auth !== true) {
            return $auth;
        }

        if (!empty($request->get_param('_api_key_id')) || current_user_can('edit_others_posts')) {
            return true;
        }

        $ids = $request->get_param('ids');
        if ($ids === null) {
            $ids = $request->get_param('id');
        }

        if ($ids === 'all' || $ids === null || $ids === '') {
            return true;
        }

        $campaignIds = self::normalizeIds($ids);

        if (empty($campaignIds)) {
            return true;
        }

        return self::canAccessCampaignOwners($campaignIds);
    }

    public static function canUpdateCampaignStatus($request): bool|\WP_Error
    {
        $status = sanitize_text_field((string) $request->get_param('status'));

        if ($status === 'trash') {
            return self::canDeleteCampaigns($request);
        }

        return self::canManageCampaign($request);
    }

    private static function normalizeIds(mixed $ids): array
    {
        if (is_string($ids) && str_contains($ids, ',')) {
            $ids = explode(',', $ids);
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    private static function canAccessCampaignOwners(array $campaignIds): bool|\WP_Error
    {
        global $wpdb;

        $userId = get_current_user_id();
        if (!$userId) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        $placeholders = implode(',', array_fill(0, count($campaignIds), '%d'));
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT campaign_id, user_id FROM {$table} WHERE campaign_id IN ({$placeholders})",
                ...$campaignIds
            ),
            ARRAY_A
        );

        if (count($rows) !== count($campaignIds)) {
            return new \WP_Error('not_found', __('Campaign not found.', 'mailerpress'), ['status' => 404]);
        }

        foreach ($rows as $row) {
            if ((int) $row['user_id'] !== $userId) {
                return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
            }
        }

        return true;
    }

    public static function canViewNotifications($request): bool|\WP_Error
    {
        return self::checkAuth($request, 'edit_posts');
    }

    public static function canManageAutomations($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_AUTOMATIONS);
    }

    private static function checkAutomationWriteAuth(\WP_REST_Request $request): bool|\WP_Error
    {
        $api_auth = ApiAuthentication::authenticate($request);

        if ($api_auth === true) {
            if (!ApiAuthentication::hasPermission($request, 'automations:write')) {
                return new \WP_Error(
                    'rest_forbidden',
                    sprintf(__('API key missing required scope: %s', 'mailerpress'), 'automations:write'),
                    ['status' => 403]
                );
            }

            return true;
        }

        if (is_wp_error($api_auth)) {
            return $api_auth;
        }

        $auth = self::checkAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (
            current_user_can(Capabilities::MANAGE_AUTOMATIONS)
            || current_user_can('publish_posts')
        ) {
            return true;
        }

        return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
    }

    private static function canAccessAutomationOwner(int $automationId): bool|\WP_Error
    {
        if (current_user_can('edit_others_posts')) {
            return true;
        }

        if ($automationId <= 0) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        $automation = (new AutomationRepository())->find($automationId);

        if ($automation === null) {
            return true;
        }

        $automationData = $automation->toArray();
        $author = isset($automationData['author']) ? (int) $automationData['author'] : 0;

        if ($author > 0 && $author === get_current_user_id()) {
            return true;
        }

        return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
    }

    public static function canEditAutomation($request): bool|\WP_Error
    {
        $auth = self::checkAutomationWriteAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (!empty($request->get_param('_api_key_id'))) {
            return true;
        }

        return self::canAccessAutomationOwner((int) $request->get_param('id'));
    }

    public static function canDeleteAutomation($request): bool|\WP_Error
    {
        return self::canEditAutomation($request);
    }

    public static function canDeleteAutomations($request): bool|\WP_Error
    {
        return self::checkPrivilegedAuth($request, Capabilities::MANAGE_AUTOMATIONS, 'edit_others_posts');
    }

    public static function canUpdateAutomationStatus($request): bool|\WP_Error
    {
        $auth = self::checkAutomationWriteAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (!empty($request->get_param('_api_key_id')) || current_user_can('edit_others_posts')) {
            return true;
        }

        $ids = $request->get_param('ids');

        if ($ids === 'all' || $ids === null) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_filter(array_map('intval', $ids));

        if (empty($ids)) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        foreach ($ids as $automationId) {
            $access = self::canAccessAutomationOwner($automationId);

            if ($access !== true) {
                return $access;
            }
        }

        return true;
    }
}
