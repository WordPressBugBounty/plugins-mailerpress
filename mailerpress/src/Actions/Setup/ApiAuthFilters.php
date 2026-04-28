<?php

declare(strict_types=1);

namespace MailerPress\Actions\Setup;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Filter;

/**
 * API Authentication Filters
 *
 * Enables WordPress Application Passwords for MailerPress REST API endpoints
 */
class ApiAuthFilters
{
    /**
     * Mark MailerPress REST API requests as API requests for Application Password support
     *
     * This allows WordPress Application Passwords to work with MailerPress endpoints
     * Users can generate Application Passwords in WordPress Admin > Users > Profile
     * and use HTTP Basic Auth to authenticate API requests
     */
    #[Filter('application_password_is_api_request')]
    public function enableApplicationPasswords(bool $is_api_request): bool
    {
        // If already determined to be an API request, keep it that way
        if ($is_api_request) {
            return true;
        }

        // Check if this is a MailerPress REST API request
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        if (strpos($request_uri, '/wp-json/mailerpress/') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Add custom authentication error messages
     */
    #[Filter('rest_authentication_errors')]
    public function customAuthErrors($result)
    {
        // If already authenticated or error, return as-is
        if (!empty($result)) {
            return $result;
        }

        // Check if this is a MailerPress endpoint
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, '/wp-json/mailerpress/') === false) {
            return $result;
        }

        // Public endpoints — no authentication required
        $public_patterns = [
            // Email open-tracking pixel (loaded by email clients as an image)
            '/campaign/track-open',
            // ESP bounce webhooks called by external providers (PostMark, Brevo, Mailgun…)
            // They handle their own signature verification
            '/esp/bounce/',
            // Incoming outgoing-webhook receiver (Pro) — handles its own auth
            '/webhooks/receive/',
            // MailerPress SaaS batch-status callback
            '/webhook/notify',
            // Public archive block — lists sent campaigns (no sensitive data)
            '/campaigns/sent',
            // Visitor subscribe form — must work for non-logged-in users
            '/my-lists/subscribe',
        ];

        foreach ($public_patterns as $pattern) {
            if (strpos($request_uri, $pattern) !== false) {
                return $result;
            }
        }

        // Skip auth check for internal PHP calls (e.g. add_mailerpress_contact via rest_do_request)
        if (!empty($GLOBALS['mailerpress_internal_php_call'])) {
            return $result;
        }

        // If no authentication provided at all, give helpful error
        if (!is_user_logged_in() && empty($_SERVER['PHP_AUTH_USER'])) {
            // Use $_SERVER for header lookups — PHP normalises all headers to
            // uppercase with hyphens replaced by underscores, prefixed HTTP_.
            // This avoids getallheaders() case-sensitivity issues (e.g. X-WP-Nonce
            // vs X-Wp-Nonce) which broke internal wp_remote_post() calls.
            $has_api_key = !empty($_SERVER['HTTP_X_MAILERPRESS_API_KEY']);

            // Also allow requests with a valid WP REST nonce (frontend forms: Gutenberg block, shortcode,
            // and internal server-side calls via add_mailerpress_contact / wp_remote_post)
            $nonce          = isset($_SERVER['HTTP_X_WP_NONCE'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE']))
                : '';
            $has_valid_nonce = $nonce && false !== wp_verify_nonce($nonce, 'wp_rest');

            if (!$has_api_key && !$has_valid_nonce) {
                return new \WP_Error(
                    'rest_not_authenticated',
                    __('Authentication required.', 'mailerpress'),
                    ['status' => 401]
                );
            }
        }

        return $result;
    }
}
