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
    private const CAMPAIGN_TYPES = [
        'newsletter',
        'ab_test',
        'automated',
        'automation',
        'wp_email',
        'wc_email',
        'confirm_email',
    ];

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

    /**
     * Authorize against any of the provided MailerPress capabilities.
     *
     * API keys must have at least one matching scope. WordPress users must have
     * at least one matching capability.
     */
    private static function checkAnyAuth(\WP_REST_Request $request, array $capabilities): bool|\WP_Error
    {
        $auth = self::checkAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (ApiAuthentication::isApiKeyRequest($request)) {
            $scopes = [];

            foreach ($capabilities as $capability) {
                $scope = self::capabilityToScope($capability, $request->get_method());
                if ($scope === null) {
                    continue;
                }

                $scopes[] = $scope;
                if (ApiAuthentication::hasPermission($request, $scope)) {
                    return true;
                }
            }

            return new \WP_Error(
                'rest_forbidden',
                sprintf(
                    __('API key missing required scope: one of %s', 'mailerpress'),
                    implode(', ', array_unique($scopes))
                ),
                ['status' => 403]
            );
        }

        foreach ($capabilities as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }

        return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
    }

    private static function checkPrivilegedAuth(\WP_REST_Request $request, string $capability, string $requiredWpCapability = 'manage_options'): bool|\WP_Error
    {
        $auth = self::checkAuth($request, $capability);

        if ($auth !== true) {
            return $auth;
        }

        if (ApiAuthentication::isApiKeyRequest($request)) {
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
        return self::checkAnyAuth($request, Capabilities::get_capabilities());
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

    public static function canCreateCampaign($request): bool|\WP_Error
    {
        $campaignTypeParam = $request->get_param('campaign_type');

        if ($campaignTypeParam === null || $campaignTypeParam === '') {
            $campaignType = 'newsletter';
        } elseif (!is_string($campaignTypeParam)) {
            return new \WP_Error(
                'rest_invalid_param',
                __('campaign_type must be a string.', 'mailerpress'),
                ['status' => 400]
            );
        } else {
            $campaignType = sanitize_key($campaignTypeParam);
        }

        if (!in_array($campaignType, self::CAMPAIGN_TYPES, true)) {
            return new \WP_Error(
                'rest_invalid_param',
                __('Invalid campaign_type.', 'mailerpress'),
                ['status' => 400]
            );
        }

        $capability = self::getCampaignTypeCapability($campaignType);
        $auth = self::checkAuth($request, $capability);

        if (
            $auth !== true
            || $campaignType !== 'automation'
            || ApiAuthentication::isApiKeyRequest($request)
        ) {
            return $auth;
        }

        $automationId = absint($request->get_param('automation_id'));

        return $automationId > 0
            ? self::canAccessAutomationOwner($automationId)
            : true;
    }

    public static function canReadCampaign($request): bool|\WP_Error
    {
        return self::canAccessCampaignRequest($request, false);
    }

    public static function canEditCampaign($request): bool|\WP_Error
    {
        return self::canAccessCampaignRequest($request, true);
    }

    private static function canAccessCampaignRequest(\WP_REST_Request $request, bool $isWrite): bool|\WP_Error
    {
        $auth = self::checkAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        $campaignIds = self::getCampaignRequestIds($request);
        if (empty($campaignIds)) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        $campaigns = self::getCampaignAccessRows($campaignIds);
        if (is_wp_error($campaigns)) {
            return $campaigns;
        }

        $scopeMethod = $isWrite ? 'POST' : 'GET';
        $capabilities = [];

        foreach ($campaigns as $campaign) {
            $capabilities[] = self::getCampaignTypeCapability((string) $campaign['campaign_type']);
        }

        $isApiKey = ApiAuthentication::isApiKeyRequest($request);

        foreach (array_unique($capabilities) as $capability) {
            if ($isApiKey) {
                $scope = self::capabilityToScope($capability, $scopeMethod);

                if ($scope !== null && !ApiAuthentication::hasPermission($request, $scope)) {
                    return new \WP_Error(
                        'rest_forbidden',
                        sprintf(__('API key missing required scope: %s', 'mailerpress'), $scope),
                        ['status' => 403]
                    );
                }
            } elseif (!current_user_can($capability)) {
                return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
            }
        }

        if ($isApiKey) {
            return true;
        }

        $currentUserId = get_current_user_id();

        foreach ($campaigns as $campaign) {
            $campaignType = (string) $campaign['campaign_type'];

            if (self::isSettingsEmailType($campaignType)) {
                continue;
            }

            if ((int) $campaign['user_id'] === $currentUserId) {
                continue;
            }

            $canEditOthers = $campaignType === 'automation'
                ? current_user_can('edit_others_posts')
                : current_user_can(Capabilities::EDIT_OTHERS_CAMPAIGNS);

            if (!$canEditOthers) {
                return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
            }
        }

        return true;
    }

    private static function getCampaignTypeCapability(string $campaignType): string
    {
        if ($campaignType === 'automation') {
            return Capabilities::MANAGE_AUTOMATIONS;
        }

        if (self::isSettingsEmailType($campaignType)) {
            return Capabilities::MANAGE_SETTINGS;
        }

        return Capabilities::MANAGE_CAMPAIGNS;
    }

    private static function isSettingsEmailType(string $campaignType): bool
    {
        return in_array($campaignType, ['wp_email', 'wc_email', 'confirm_email'], true);
    }

    public static function canPublishCampaign($request): bool|\WP_Error
    {
        $auth = self::checkAuth($request, Capabilities::PUBLISH_CAMPAIGNS);

        if ($auth !== true) {
            return $auth;
        }

        if (
            ApiAuthentication::isApiKeyRequest($request)
            || current_user_can(Capabilities::EDIT_OTHERS_CAMPAIGNS)
        ) {
            return true;
        }

        $campaignIds = self::getCampaignRequestIds($request);
        if (empty($campaignIds)) {
            return true;
        }

        return self::canAccessCampaignOwners($campaignIds);
    }

    public static function canManageSettings($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_SETTINGS);
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
        return self::checkAnyAuth($request, [
            Capabilities::MANAGE_CONTACTS,
            Capabilities::MANAGE_CAMPAIGNS,
        ]);
    }

    public static function canDeleteContacts($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::DELETE_CONTACTS);
    }

    public static function canReadLists($request): bool|\WP_Error
    {
        return self::checkAnyAuth($request, [
            Capabilities::MANAGE_LISTS,
            Capabilities::MANAGE_CONTACTS,
            Capabilities::MANAGE_CAMPAIGNS,
            Capabilities::MANAGE_AUTOMATIONS,
        ]);
    }

    public static function canManageLists($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_LISTS);
    }

    public static function canManageTags($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_TAGS);
    }

    public static function canReadTags($request): bool|\WP_Error
    {
        return self::checkAnyAuth($request, [
            Capabilities::MANAGE_TAGS,
            Capabilities::MANAGE_CONTACTS,
            Capabilities::MANAGE_CAMPAIGNS,
            Capabilities::MANAGE_AUTOMATIONS,
        ]);
    }

    public static function canManageTemplates($request): bool|\WP_Error
    {
        return self::checkAuth($request, Capabilities::MANAGE_TEMPLATES);
    }

    public static function canReadTemplates($request): bool|\WP_Error
    {
        return self::checkAnyAuth($request, [
            Capabilities::MANAGE_SETTINGS,
            Capabilities::MANAGE_TEMPLATES,
            Capabilities::MANAGE_CAMPAIGNS,
            Capabilities::MANAGE_AUTOMATIONS,
        ]);
    }

    public static function canReadEditorMetadata($request): bool|\WP_Error
    {
        return self::checkAnyAuth($request, [
            Capabilities::MANAGE_SETTINGS,
            Capabilities::MANAGE_CAMPAIGNS,
            Capabilities::MANAGE_TEMPLATES,
            Capabilities::MANAGE_AUTOMATIONS,
        ]);
    }

    public static function canUseEditorContent($request): bool|\WP_Error
    {
        return self::checkAnyAuth($request, [
            Capabilities::MANAGE_SETTINGS,
            Capabilities::MANAGE_CAMPAIGNS,
            Capabilities::MANAGE_TEMPLATES,
            Capabilities::MANAGE_AUTOMATIONS,
        ]);
    }

    public static function canPreviewEditorContact($request): bool|\WP_Error
    {
        return self::canReadAudience($request);
    }

    public static function canReadCustomFields($request): bool|\WP_Error
    {
        return self::checkAnyAuth($request, [
            Capabilities::MANAGE_SETTINGS,
            Capabilities::MANAGE_CONTACTS,
            Capabilities::MANAGE_CONTACT_SEGMENTATION,
            Capabilities::MANAGE_CAMPAIGNS,
            Capabilities::MANAGE_TEMPLATES,
            Capabilities::MANAGE_AUTOMATIONS,
        ]);
    }

    public static function canManageFonts($request): bool|\WP_Error
    {
        return self::checkAnyAuth($request, [
            Capabilities::MANAGE_SETTINGS,
            Capabilities::MANAGE_CAMPAIGNS,
            Capabilities::MANAGE_TEMPLATES,
            Capabilities::MANAGE_AUTOMATIONS,
        ]);
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

        if (
            ApiAuthentication::isApiKeyRequest($request)
            || current_user_can(Capabilities::EDIT_OTHERS_CAMPAIGNS)
        ) {
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

        $auth = self::canManageCampaign($request);

        if ($auth !== true) {
            return $auth;
        }

        if (
            ApiAuthentication::isApiKeyRequest($request)
            || current_user_can(Capabilities::EDIT_OTHERS_CAMPAIGNS)
        ) {
            return true;
        }

        $ids = $request->get_param('ids');
        if ($ids === null) {
            $ids = $request->get_param('id');
        }

        if ($ids === 'all') {
            return true;
        }

        if ($ids === null || $ids === '') {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        $campaignIds = self::normalizeIds($ids);
        if (empty($campaignIds)) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        return self::canAccessCampaignOwners($campaignIds);
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

    private static function getCampaignRequestIds(\WP_REST_Request $request): array
    {
        global $wpdb;

        $ids = [];

        foreach (['ids', 'id', 'campaignId', 'campaign_id', 'postEdit', 'post'] as $parameter) {
            $value = $request->get_param($parameter);
            if ($value === null || $value === '') {
                continue;
            }

            $ids = array_merge($ids, self::normalizeIds($value));
        }

        $batchIds = $request->get_param('batchId');
        if ($batchIds === null) {
            $batchIds = $request->get_param('batch_id');
        }

        $batchIds = self::normalizeIds($batchIds);

        $chunkIds = $request->get_param('chunkId');
        if ($chunkIds === null) {
            $chunkIds = $request->get_param('chunk_id');
        }

        $chunkIds = self::normalizeIds($chunkIds);
        if (!empty($chunkIds)) {
            $placeholders = implode(',', array_fill(0, count($chunkIds), '%d'));
            $table = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);
            $chunkBatchIds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT batch_id FROM {$table} WHERE id IN ({$placeholders})",
                    ...$chunkIds
                )
            );

            $batchIds = array_values(array_unique(array_merge(
                $batchIds,
                self::normalizeIds($chunkBatchIds)
            )));
        }

        if (!empty($batchIds)) {
            $placeholders = implode(',', array_fill(0, count($batchIds), '%d'));
            $table = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
            $batchCampaignIds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT campaign_id FROM {$table} WHERE id IN ({$placeholders})",
                    ...$batchIds
                )
            );

            $ids = array_merge($ids, self::normalizeIds($batchCampaignIds));
        }

        return array_values(array_unique($ids));
    }

    private static function getCampaignAccessRows(array $campaignIds): array|\WP_Error
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($campaignIds), '%d'));
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaigns = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT campaign_id, user_id, campaign_type FROM {$table} WHERE campaign_id IN ({$placeholders})",
                ...$campaignIds
            ),
            ARRAY_A
        );

        if (count($campaigns) !== count($campaignIds)) {
            return new \WP_Error('not_found', __('Campaign not found.', 'mailerpress'), ['status' => 404]);
        }

        return $campaigns;
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
        return self::canViewMailerPress($request);
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

        if (current_user_can(Capabilities::MANAGE_AUTOMATIONS)) {
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

    private static function getAutomationRequestId(\WP_REST_Request $request): int
    {
        foreach (['id', 'automationId', 'automation_id'] as $parameter) {
            $automationId = absint($request->get_param($parameter));
            if ($automationId > 0) {
                return $automationId;
            }
        }

        return 0;
    }

    public static function canReadAutomation($request): bool|\WP_Error
    {
        $auth = self::checkAuth($request, Capabilities::MANAGE_AUTOMATIONS);

        if ($auth !== true) {
            return $auth;
        }

        if (ApiAuthentication::isApiKeyRequest($request)) {
            return true;
        }

        return self::canAccessAutomationOwner(self::getAutomationRequestId($request));
    }

    public static function canEditAutomation($request): bool|\WP_Error
    {
        $auth = self::checkAutomationWriteAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (ApiAuthentication::isApiKeyRequest($request)) {
            return true;
        }

        return self::canAccessAutomationOwner(self::getAutomationRequestId($request));
    }

    public static function canDeleteAutomation($request): bool|\WP_Error
    {
        return self::canEditAutomation($request);
    }

    public static function canDeleteAutomations($request): bool|\WP_Error
    {
        return self::checkPrivilegedAuth($request, Capabilities::MANAGE_AUTOMATIONS, 'edit_others_posts');
    }

    public static function canUseAutomationAi($request): bool|\WP_Error
    {
        $auth = self::checkAuth($request, Capabilities::MANAGE_AUTOMATIONS);

        if ($auth !== true) {
            return $auth;
        }

        if (ApiAuthentication::isApiKeyRequest($request)) {
            return true;
        }

        if (!current_user_can(Capabilities::USE_AI)) {
            return new \WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 403]);
        }

        return true;
    }

    public static function canUpdateAutomationStatus($request): bool|\WP_Error
    {
        $auth = self::checkAutomationWriteAuth($request);

        if ($auth !== true) {
            return $auth;
        }

        if (ApiAuthentication::isApiKeyRequest($request) || current_user_can('edit_others_posts')) {
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
