<?php

declare(strict_types=1);

namespace MailerPress\Middleware;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class CorsMiddleware
{
    /**
     * Initialize CORS handling
     */
    #[Action('rest_api_init')]
    public function init(): void
    {
        // Remove default WordPress CORS headers
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

        // Add custom CORS headers
        add_filter('rest_pre_serve_request', [$this, 'customCorsHeaders'], 15);
    }

    /**
     * Initialize early CORS handling for preflight requests
     * This must run before WordPress processes the request
     * Using parse_request which fires very early for all requests including REST API
     */
    #[Action('parse_request', priority: 1)]
    public function initEarly(): void
    {
        // Handle OPTIONS preflight requests early
        $this->handlePreflight();
    }

    /**
     * Add custom CORS headers for embed endpoints
     *
     * @param mixed $value
     * @return mixed
     */
    public function customCorsHeaders($value)
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Only apply CORS to embed endpoints
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, '/wp-json/mailerpress/v1/embed/') === false) {
            // For non-embed endpoints, use default WordPress CORS
            return rest_send_cors_headers($value);
        }

        // Validate origin before reflecting it
        if ($origin && $this->isAllowedOrigin($origin)) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
        } else {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-MailerPress-API-Key');
        header('Access-Control-Max-Age: 86400'); // 24 hours

        return $value;
    }

    /**
     * Handle OPTIONS preflight requests
     */
    public function handlePreflight(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
            return;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, '/wp-json/mailerpress/v1/embed/') === false) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin && $this->isAllowedOrigin($origin)) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
        } else {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-MailerPress-API-Key');
        header('Access-Control-Max-Age: 86400');

        // Send 200 OK and exit to prevent WordPress from processing the request
        status_header(200);
        exit;
    }

    /**
     * Check if origin is allowed based on site URL and configured embed domains
     */
    private function isAllowedOrigin(string $origin): bool
    {
        $origin = rtrim(strtolower($origin), '/');

        // Always allow same-site origin
        $siteUrl = rtrim(strtolower(get_site_url()), '/');
        if ($origin === $siteUrl) {
            return true;
        }

        // Allow origins matching configured embed API key domains
        $allowedDomains = apply_filters('mailerpress_cors_allowed_origins', []);
        foreach ($allowedDomains as $domain) {
            $domain = rtrim(strtolower($domain), '/');
            if ($origin === $domain) {
                return true;
            }
        }

        // For embed endpoints, we allow all origins since they use API key auth (not cookies)
        // but we sanitize the origin value to prevent header injection
        return true;
    }
}
