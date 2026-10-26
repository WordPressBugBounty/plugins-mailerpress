<?php

declare(strict_types=1);

namespace MailerPress\Actions;

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

        // Prevent reverse proxies (Varnish, Nginx, CDNs) from caching MailerPress REST responses
        add_filter('rest_post_dispatch', [$this, 'addNoCacheHeaders'], 10, 3);
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
     * Add no-cache headers to all MailerPress REST responses so reverse proxies
     * (Varnish, Nginx caches, CDNs) never store them.
     *
     * @param \WP_HTTP_Response $result
     * @param \WP_REST_Server   $server
     * @param \WP_REST_Request  $request
     * @return \WP_HTTP_Response
     */
    public function addNoCacheHeaders(\WP_HTTP_Response $result, \WP_REST_Server $server, \WP_REST_Request $request): \WP_HTTP_Response
    {
        if (strpos($request->get_route(), '/mailerpress/') === false) {
            return $result;
        }

        if ($result instanceof \WP_REST_Response) {
            $result->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $result->header('Pragma', 'no-cache');
            $result->header('Expires', '0');
        }

        return $result;
    }

    /**
     * Add custom CORS headers for the public embed endpoints
     *
     * @param mixed $value
     * @return mixed
     */
    public function customCorsHeaders($value)
    {
        if (!$this->isPublicEmbedRequest()) {
            // For other endpoints, including the embed/keys admin routes, use default WordPress CORS
            return rest_send_cors_headers($value);
        }

        $this->sendEmbedCorsHeaders();

        return $value;
    }

    /**
     * Handle OPTIONS preflight requests
     */
    public function handlePreflight(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ('OPTIONS' !== $method || !$this->isPublicEmbedRequest()) {
            return;
        }

        $this->sendEmbedCorsHeaders();

        // Send 200 OK and exit to prevent WordPress from processing the request
        status_header(200);
        exit;
    }

    /**
     * Whether the request targets a public embed endpoint (the embed/keys admin routes are excluded)
     */
    private function isPublicEmbedRequest(): bool
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        return false !== strpos($requestUri, '/wp-json/mailerpress/v1/embed/')
            && false === strpos($requestUri, '/wp-json/mailerpress/v1/embed/keys');
    }

    /**
     * Send the CORS headers of the public embed endpoints
     */
    private function sendEmbedCorsHeaders(): void
    {
        $allowedOrigins = $this->getAllowedOrigins();

        if ([] === $allowedOrigins) {
            // No restriction configured: embed endpoints use API key auth (not cookies), so any origin may call them
            header('Access-Control-Allow-Origin: *');
        } else {
            header('Vary: Origin', false);

            $origin = isset($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
            $origin = $this->normalizeOrigin($origin);
            if ('' !== $origin && $this->isAllowedOrigin($origin, $allowedOrigins)) {
                header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            }
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-MailerPress-API-Key');
        header('Access-Control-Max-Age: 86400'); // 24 hours
    }

    /**
     * Get the normalized origins allowed to call the public embed endpoints
     *
     * An empty list means that every origin is allowed. Otherwise the list also contains the site origin.
     *
     * @return string[]
     */
    private function getAllowedOrigins(): array
    {
        /**
         * Filters the origins allowed to call the public embed endpoints
         * (/wp-json/mailerpress/v1/embed/*, except the embed/keys admin routes).
         *
         * Each entry is either a full origin with its scheme, host and optional port, without path
         * or trailing slash (e.g. 'https://www.example.com' or 'http://localhost:8080'), or a bare
         * host (e.g. 'www.example.com'), which allows that host with any scheme and port.
         *
         * When the list is empty (default), every origin is allowed with "Access-Control-Allow-Origin: *".
         * Otherwise only the site origin and the listed origins receive an "Access-Control-Allow-Origin"
         * header; browsers block cross-origin requests from any other origin.
         *
         * @param string[] $origins Allowed origins or hosts. Default empty array.
         */
        $entries = apply_filters('mailerpress_cors_allowed_origins', []);
        if (!is_array($entries)) {
            return [];
        }

        $allowedOrigins = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            $normalized = false === strpos($entry, '://')
                ? strtolower(rtrim($entry, '/'))
                : $this->normalizeOrigin($entry);

            if ('' !== $normalized) {
                $allowedOrigins[] = $normalized;
            }
        }

        if ([] === $allowedOrigins) {
            return [];
        }

        $siteOrigin = $this->normalizeOrigin(get_site_url());
        if ('' !== $siteOrigin) {
            $allowedOrigins[] = $siteOrigin;
        }

        return array_values(array_unique($allowedOrigins));
    }

    /**
     * Check whether a normalized origin matches one of the allowed origins or hosts
     *
     * @param string[] $allowedOrigins
     */
    private function isAllowedOrigin(string $origin, array $allowedOrigins): bool
    {
        $host = (string) wp_parse_url($origin, PHP_URL_HOST);

        foreach ($allowedOrigins as $allowed) {
            if ($origin === $allowed || (false === strpos($allowed, '://') && $host === $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a URL to its lowercase origin (scheme://host[:port]), or an empty string if it is invalid
     */
    private function normalizeOrigin(string $url): string
    {
        $parts = wp_parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $origin = strtolower($parts['scheme'] . '://' . $parts['host']);
        if (!empty($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }
}
