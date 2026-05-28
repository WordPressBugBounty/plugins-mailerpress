<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use WP_Error;

class Newsletter
{
    private const REMOTE_NEWSLETTER_ENDPOINT = 'https://mailerpress.com/wp-json/mailerpress-site/v1/setup-newsletter/subscribe';
    private const REMOTE_CHALLENGE_ENDPOINT = 'https://mailerpress.com/wp-json/mailerpress-site/v1/setup-newsletter/challenge';

    #[Endpoint(
        'newsletter-subscribe',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function subscribe(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $email = $this->getValidEmail($request);

        if (is_wp_error($email)) {
            return $email;
        }

        $payload = $this->getRemotePayload($email, $request);
        $challenge = $this->getRemoteChallenge($payload);

        if (is_wp_error($challenge)) {
            return $challenge;
        }

        $payload['challenge'] = $challenge;

        $response = wp_remote_post($this->getRemoteEndpoint(), [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'MailerPress/' . (defined('MAILERPRESS_VERSION') ? MAILERPRESS_VERSION : 'unknown'),
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'mailerpress_newsletter_request_failed',
                __('Unable to subscribe right now. Please try again later.', 'mailerpress'),
                ['status' => 502]
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = $this->decodeResponseBody(wp_remote_retrieve_body($response));

        if ($statusCode < 200 || $statusCode >= 300) {
            return new WP_Error(
                $this->getResponseErrorCode($body),
                $this->getResponseErrorMessage($body),
                ['status' => $statusCode ?: 502]
            );
        }

        return rest_ensure_response([
            'success' => true,
        ]);
    }

    private function getValidEmail(\WP_REST_Request $request): string|WP_Error
    {
        $email = sanitize_email((string) $request->get_param('email'));

        if (empty($email) || !is_email($email)) {
            return new WP_Error(
                'mailerpress_invalid_newsletter_email',
                __('Please enter a valid email address.', 'mailerpress'),
                ['status' => 400]
            );
        }

        return $email;
    }

    private function getRemoteEndpoint(): string
    {
        $endpoint = apply_filters(
            'mailerpress_newsletter_remote_endpoint',
            self::REMOTE_NEWSLETTER_ENDPOINT
        );

        return is_string($endpoint) && $endpoint !== ''
            ? $endpoint
            : self::REMOTE_NEWSLETTER_ENDPOINT;
    }

    private function getChallengeEndpoint(): string
    {
        $endpoint = apply_filters(
            'mailerpress_newsletter_challenge_endpoint',
            self::REMOTE_CHALLENGE_ENDPOINT
        );

        return is_string($endpoint) && $endpoint !== ''
            ? $endpoint
            : self::REMOTE_CHALLENGE_ENDPOINT;
    }

    private function getRemotePayload(string $email, \WP_REST_Request $request): array
    {
        $payload = [
            'email' => $email,
            'site_url' => home_url('/'),
            'plugin_version' => defined('MAILERPRESS_VERSION') ? MAILERPRESS_VERSION : '',
            'locale' => get_locale(),
            'source' => 'setup_wizard',
        ];

        $filteredPayload = apply_filters(
            'mailerpress_newsletter_remote_payload',
            $payload,
            $email,
            $request
        );

        return is_array($filteredPayload) ? $filteredPayload : $payload;
    }

    private function getRemoteChallenge(array $payload): string|WP_Error
    {
        $response = wp_remote_get(add_query_arg([
            'site_url' => $payload['site_url'] ?? '',
            'plugin_version' => $payload['plugin_version'] ?? '',
            'locale' => $payload['locale'] ?? '',
            'source' => $payload['source'] ?? '',
        ], $this->getChallengeEndpoint()), [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'MailerPress/' . (defined('MAILERPRESS_VERSION') ? MAILERPRESS_VERSION : 'unknown'),
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'mailerpress_newsletter_challenge_failed',
                __('Unable to subscribe right now. Please try again later.', 'mailerpress'),
                ['status' => 502]
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = $this->decodeResponseBody(wp_remote_retrieve_body($response));

        if ($statusCode < 200 || $statusCode >= 300 || !is_array($body) || empty($body['challenge']) || !is_string($body['challenge'])) {
            return new WP_Error(
                $this->getResponseErrorCode($body),
                $this->getResponseErrorMessage($body),
                ['status' => $statusCode ?: 502]
            );
        }

        return $body['challenge'];
    }

    private function decodeResponseBody(string $responseBody): mixed
    {
        if ($responseBody === '') {
            return null;
        }

        $decoded = json_decode($responseBody, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $responseBody;
    }

    private function getResponseErrorCode(mixed $body): string
    {
        if (is_array($body) && isset($body['code']) && is_string($body['code']) && $body['code'] !== '') {
            return $body['code'];
        }

        return 'mailerpress_newsletter_request_failed';
    }

    private function getResponseErrorMessage(mixed $body): string
    {
        if (is_array($body) && isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return wp_strip_all_tags($body['message']);
        }

        if (is_string($body) && $body !== '') {
            return wp_strip_all_tags($body);
        }

        return __('Unable to subscribe right now. Please try again later.', 'mailerpress');
    }
}
