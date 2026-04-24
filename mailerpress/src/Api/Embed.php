<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\EmbedApiKey;
use MailerPress\Services\RateLimiter;
use MailerPress\Services\RateLimitConfig;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Embed
{
    /**
     * Check if Pro version is active
     *
     * @return bool
     */
    private function isProActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active')
            && is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    /**
     * Test/validate API key (for debugging)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/validate',
        methods: 'GET'
    )]
    public function validateApiKey(WP_REST_Request $request): WP_REST_Response
    {
        $apiKey = $request->get_header('X-MailerPress-API-Key') ?: $request->get_param('key');

        if (empty($apiKey)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('API key is required', 'mailerpress'),
            ], 401);
        }

        $keyData = EmbedApiKey::validate($apiKey);

        if (!$keyData) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Authentication failed', 'mailerpress'),
            ], 403);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('API key is valid', 'mailerpress'),
            'data' => [
                'name' => $keyData->name,
                'status' => $keyData->status,
                'rate_limit' => $keyData->rate_limit_requests,
                'domain' => $keyData->allowed_domain ?: 'any',
            ]
        ], 200);
    }

    /**
     * Submit contact from embedded form
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/contact',
        methods: 'POST'
    )]
    public function submitContact(WP_REST_Request $request): WP_REST_Response
    {
        // 0. Check if Pro is active
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        // 1. Validate API Key
        $apiKey = $request->get_header('X-MailerPress-API-Key');
        if (empty($apiKey)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('API key is required', 'mailerpress'),
            ], 401);
        }

        $keyData = EmbedApiKey::validate($apiKey);
        if (!$keyData) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Authentication failed', 'mailerpress'),
            ], 403);
        }

        // 2. Validate Origin (Domain whitelist)
        if (!empty($keyData->allowed_domain)) {
            $origin = $request->get_header('Origin') ?: $request->get_header('Referer');
            if ($origin) {
                $originDomain = parse_url($origin, PHP_URL_HOST);
                $allowedDomains = array_map('trim', explode(',', $keyData->allowed_domain));

                if (!in_array($originDomain, $allowedDomains, true)) {
                    return new WP_REST_Response([
                        'success' => false,
                        'message' => __('Domain not authorized', 'mailerpress'),
                    ], 403);
                }
            }
        }

        // 3. Check Rate Limiting
        $ipAddress = $this->getClientIp();
        $keyHash = EmbedApiKey::hash($apiKey);

        if (!RateLimiter::checkLimit(
            $keyHash,
            $ipAddress,
            (int)$keyData->rate_limit_requests,
            (int)$keyData->rate_limit_window
        )) {
            $retryAfter = (int)$keyData->rate_limit_window;
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'mailerpress'),
                'retry_after' => $retryAfter,
            ], 429);
        }

        // 4. Honeypot Check (Bot Protection)
        if (RateLimitConfig::isHoneypotEnabled()) {
            $honeypot = $request->get_param('website');
            if (!empty($honeypot)) {
                // Silently reject bot submissions
                return new WP_REST_Response([
                    'success' => true,
                    'message' => __('Subscription successful', 'mailerpress'),
                ], 200);
            }
        }

        // 5. Sanitize and Validate Input
        $email = sanitize_email($request->get_param('email'));
        if (empty($email) || !is_email($email)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Invalid email address', 'mailerpress'),
            ], 400);
        }

        $firstName = sanitize_text_field($request->get_param('firstName') ?? '');
        $lastName = sanitize_text_field($request->get_param('lastName') ?? '');
        $lists = $request->get_param('lists') ?? [];
        $tags = $request->get_param('tags') ?? [];
        $customFields = $request->get_param('customFields') ?? [];
        $gdprConsent = $request->get_param('gdprConsent');
        $gdprRequired = (bool)$request->get_param('gdprRequired');

        // Validate GDPR consent if required
        if ($gdprRequired && !$gdprConsent) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('You must accept the terms to subscribe.', 'mailerpress'),
            ], 400);
        }

        // 6. Check double opt-in setting
        $subscriptionOption = get_option('mailerpress_signup_confirmation', null);
        if (null !== $subscriptionOption) {
            if (is_string($subscriptionOption)) {
                $subscriptionOption = json_decode($subscriptionOption, true);
            }
            if (!is_array($subscriptionOption)) {
                $subscriptionOption = null;
            }
        }

        $doubleOptInEnabled = false;
        if (is_array($subscriptionOption) && isset($subscriptionOption['enableSignupConfirmation'])) {
            $doubleOptInEnabled = (bool)$subscriptionOption['enableSignupConfirmation'];
        }
        $contactStatus = $doubleOptInEnabled ? 'pending' : 'subscribed';

        // 7. Call Existing Contact Creation Logic
        $contactData = [
            'contactEmail' => $email,
            'contactFirstName' => $firstName,
            'contactLastName' => $lastName,
            'contactStatus' => $contactStatus,
            'tags' => is_array($tags) ? $tags : [],
            'lists' => is_array($lists) ? $lists : [],
            'opt_in_source' => 'embed_form',
            'custom_fields' => is_array($customFields) ? $customFields : [],
        ];

        // Add GDPR consent to custom fields
        if ($gdprConsent) {
            $contactData['custom_fields']['gdpr_consent'] = true;
            $contactData['custom_fields']['gdpr_consent_date'] = current_time('mysql');
        }

        // Use existing Contacts API endpoint logic
        $contactsApi = new Contacts();
        $contactRequest = new WP_REST_Request('POST', '/mailerpress/v1/contact');
        $contactRequest->set_body_params($contactData);

        $result = $contactsApi->add($contactRequest);

        // 8. Update API Key Usage Statistics
        EmbedApiKey::updateUsage($keyData->id);

        // 9. Return Response
        if ($result instanceof WP_Error) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400);
        }

        $responseData = $result instanceof WP_REST_Response ? $result->get_data() : $result;

        // Custom message based on double opt-in
        $message = $doubleOptInEnabled
            ? __('Please check your email to confirm your subscription.', 'mailerpress')
            : __('Subscription successful!', 'mailerpress');

        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'contact_id' => $responseData['contact_id'] ?? null,
            'requires_confirmation' => $doubleOptInEnabled,
        ], 200);
    }

    /**
     * Get form configuration
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/config',
        methods: 'GET'
    )]
    public function getFormConfig(WP_REST_Request $request): WP_REST_Response
    {
        // Check if Pro is active
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        $apiKey = $request->get_param('key');

        if (empty($apiKey)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('API key is required', 'mailerpress'),
            ], 401);
        }

        $keyData = EmbedApiKey::validate($apiKey);
        if (!$keyData) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Authentication failed', 'mailerpress'),
            ], 403);
        }

        // Return public configuration
        return new WP_REST_Response([
            'success' => true,
            'config' => [
                'lists' => $this->getPublicLists(),
                'customFields' => $this->getPublicFields(),
            ],
        ]);
    }

    /**
     * Get public lists
     *
     * @return array
     */
    private function getPublicLists(): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_LIST);
        $results = $wpdb->get_results(
            "SELECT list_id as id, name FROM {$table} ORDER BY name ASC",
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Get public custom fields
     *
     * @return array
     */
    private function getPublicFields(): array
    {
        // Use the CustomFields model to get all fields
        $customFieldsModel = new \MailerPress\Models\CustomFields();
        $fields = $customFieldsModel->all();

        // Format for public API
        $publicFields = [];
        foreach ($fields as $field) {
            $publicFields[] = [
                'field_key' => $field->field_key,
                'label' => $field->label,
                'type' => $field->type,
                'required' => (bool)$field->required,
                'options' => !empty($field->options) ? $field->options : null,
            ];
        }

        return $publicFields;
    }

    /**
     * Get custom field definitions (public endpoint with API key validation)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'embed/custom-fields',
        methods: 'GET'
    )]
    public function getCustomFields(WP_REST_Request $request): WP_REST_Response
    {
        // Check if Pro is active
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Embed Forms is available only in the Pro version of MailerPress.', 'mailerpress'),
            ], 403);
        }

        // Validate API Key
        $apiKey = $request->get_header('X-MailerPress-API-Key');

        if (empty($apiKey)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('API key is required', 'mailerpress'),
            ], 401);
        }

        $keyData = EmbedApiKey::validate($apiKey);
        if (!$keyData) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Authentication failed', 'mailerpress'),
            ], 403);
        }

        return new WP_REST_Response([
            'success' => true,
            'fields' => $this->getPublicFields(),
        ], 200);
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private function getClientIp(): string
    {
        // Use REMOTE_ADDR as the reliable, non-spoofable source
        // Forwarded headers (X-Forwarded-For, etc.) can be manipulated by clients
        // Only trust them if a trusted proxy list is configured via filter
        $trustedProxies = apply_filters('mailerpress_trusted_proxies', []);
        $remoteAddr = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');

        if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
            // Request comes from a trusted proxy, use forwarded header
            $forwarded = '';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $forwarded = $_SERVER['HTTP_X_REAL_IP'];
            }

            if (!empty($forwarded)) {
                // Take the first (client) IP from the chain
                if (strpos($forwarded, ',') !== false) {
                    $ips = explode(',', $forwarded);
                    return sanitize_text_field(trim($ips[0]));
                }
                return sanitize_text_field(trim($forwarded));
            }
        }

        return $remoteAddr;
    }
}
