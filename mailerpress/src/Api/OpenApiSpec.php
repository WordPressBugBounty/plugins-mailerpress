<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;

/**
 * OpenAPI Specification Endpoint
 *
 * Serves the OpenAPI/Swagger specification for API documentation tools
 */
class OpenApiSpec
{
    /**
     * Get OpenAPI specification
     *
     * Returns the complete OpenAPI 3.0.3 specification in JSON format
     * Can be used with Swagger UI, Postman, Insomnia, etc.
     */
    #[Endpoint(
        'openapi.json',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function getSpec(\WP_REST_Request $request): \WP_REST_Response
    {
        $spec_file = dirname(dirname(__DIR__)) . '/openapi.json';

        if (!file_exists($spec_file)) {
            return new \WP_REST_Response([
                'error' => 'OpenAPI specification file not found'
            ], 404);
        }

        $spec = file_get_contents($spec_file);
        $spec_data = json_decode($spec, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_REST_Response([
                'error' => 'Invalid OpenAPI specification format'
            ], 500);
        }

        // Dynamically update server URL with current site
        $site_url = get_site_url();
        $spec_data['servers'][0]['url'] = $site_url . '/wp-json/mailerpress/v1';

        return new \WP_REST_Response($spec_data, 200, [
            'Content-Type' => 'application/json',
            'Access-Control-Allow-Origin' => '*',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

}
