<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use WP_Error;
use WP_REST_Request;

class Fonts
{
    #[Endpoint(
        'fonts',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function addFont(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $font = $request->get_param('font');
        $fonts = get_option('mailerpress_fonts');

        if (!is_array($font)) {
            return new \WP_Error('invalid_font', 'Font data must be an array.', ['status' => 400]);
        }

        if (empty($fonts)) {
            update_option('mailerpress_fonts', $font);
        } else {
            update_option('mailerpress_fonts', array_merge($fonts, $font));
        }

        return new \WP_REST_Response(get_option('mailerpress_fonts'));
    }

    #[Endpoint(
        'fonts',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function deleteFont(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $fontKey = $request->get_param('font'); // e.g., "nunito-400-normal"
        $fonts = get_option('mailerpress_fonts_v2', []);

        if (!$fonts || !isset($fonts[$fontKey])) {
            return new \WP_Error('font_not_found', __('Font not found.', 'mailerpress'), ['status' => 404]);
        }

        $fontData = $fonts[$fontKey];

        // Delete all files associated with this font
        if (!empty($fontData['sources']) && is_array($fontData['sources'])) {
            foreach ($fontData['sources'] as $fileUrl) {
                $filePath = str_replace(trailingslashit(wp_upload_dir()['baseurl']),
                    trailingslashit(wp_upload_dir()['basedir']), $fileUrl);
                if (file_exists($filePath)) {
                    @unlink($filePath); // suppress warning if fails
                }
            }
        }

        // Remove the font from option
        unset($fonts[$fontKey]);
        update_option('mailerpress_fonts_v2', $fonts);

        return new \WP_REST_Response($fonts);
    }


    #[Endpoint('google-fonts', permissionCallback: [Permissions::class, 'canEdit'])]
    public function googleFonts(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        $url = 'https://s.w.org/images/fonts/wp-6.7/collections/google-fonts-with-preview.json';
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'fetch_failed',
                __('Failed to fetch Google Fonts JSON', 'mailerpress'),
                ['status' => 500]
            );
        }

        $body = wp_remote_retrieve_body($response);

        // Decode JSON string into array
        $data = json_decode($body, true);
        if (null === $data) {
            return new \WP_Error(
                'invalid_json',
                __('Invalid JSON received from Google Fonts URL', 'mailerpress'),
                ['status' => 500]
            );
        }

        // Return as proper REST response
        return rest_ensure_response($data);
    }

    private const ALLOWED_FONT_HOSTS = [
        'fonts.gstatic.com',
        'fonts.googleapis.com',
        's.w.org',
    ];

    private const MAX_FONT_SIZE = 2 * 1024 * 1024; // 2 MB

    #[Endpoint('install-font', methods: 'POST', permissionCallback: [Permissions::class, 'canManageSettings'])]
    public function mailerpress_install_font(WP_REST_Request $request)
    {
        $olfFontOption = get_option('mailerpress_fonts');
        if ($olfFontOption) {
            delete_option('mailerpress_fonts');
        }

        $files = (array)$request['files'];
        if (empty($files)) {
            return new WP_Error('no_files', __('No font files provided', 'mailerpress'), ['status' => 400]);
        }

        $upload_dir = wp_upload_dir();
        $base_path = trailingslashit($upload_dir['basedir']) . 'mailerpress-fonts';
        $base_url = trailingslashit($upload_dir['baseurl']) . 'mailerpress-fonts';

        if (!wp_mkdir_p($base_path)) {
            return new WP_Error('mkdir_failed', __('Failed to create font directory', 'mailerpress'),
                ['status' => 500]);
        }

        $existingFonts = get_option('mailerpress_fonts_v2', []);
        $installedFonts = [];

        foreach ($files as $file) {
            $family = sanitize_title($file['family']);
            $displayName = sanitize_text_field($file['displayName'] ?? $family);
            $fontFamily = sanitize_text_field($file['fontFamily'] ?? $family);
            $file_url = esc_url_raw($file['src']);
            $weight = sanitize_text_field($file['weight'] ?? '400');
            $style = sanitize_text_field($file['style'] ?? 'normal');
            $preview_url = esc_url_raw($file['preview'] ?? '');

            // Validate URL scheme
            if (!str_starts_with($file_url, 'https://')) {
                continue;
            }

            // Validate host against allowlist
            $parsed_url = wp_parse_url($file_url);
            if (empty($parsed_url['host']) || !in_array($parsed_url['host'], self::ALLOWED_FONT_HOSTS, true)) {
                continue;
            }

            // Validate file extension
            $path = $parsed_url['path'] ?? '';
            if (!preg_match('/\.(woff2?|ttf|otf|eot)$/i', $path)) {
                continue;
            }

            $key = "{$family}-{$weight}-{$style}";

            // Initialize family if not exists
            if (!isset($existingFonts[$family])) {
                $existingFonts[$family] = [
                    'displayName' => $displayName,
                    'fontFamily' => $fontFamily,
                    'sources' => [],
                    'variants' => [],
                    'previews' => [],
                ];
            }

            // Skip if variant already exists
            if (isset($existingFonts[$family]['sources'][$key])) {
                $installedFonts[$family] = $existingFonts[$family];
                continue;
            }

            // Download the font using wp_safe_remote_get (blocks private/internal IPs)
            $response = wp_safe_remote_get($file_url, [
                'timeout' => 15,
                'redirection' => 0,
            ]);
            if (is_wp_error($response)) {
                continue;
            }

            // Validate content type
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            $allowed_types = ['font/woff2', 'font/woff', 'font/ttf', 'font/otf', 'application/font-woff2', 'application/font-woff', 'application/octet-stream', 'application/x-font-woff'];
            $type_valid = false;
            foreach ($allowed_types as $allowed) {
                if (str_starts_with($content_type, $allowed)) {
                    $type_valid = true;
                    break;
                }
            }
            if (!$type_valid) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            if (empty($body) || strlen($body) > self::MAX_FONT_SIZE) {
                continue;
            }

            $filename = "{$key}.woff2";
            $save_path = $base_path . '/' . $filename;
            file_put_contents($save_path, $body);
            $file_url_final = trailingslashit($base_url) . $filename;

            // Add variant
            $existingFonts[$family]['sources'][$key] = $file_url_final;
            $existingFonts[$family]['variants'][] = $key;
            if ($preview_url) {
                $existingFonts[$family]['previews'][$key] = $preview_url;
            }

            $installedFonts[$family] = $existingFonts[$family];
        }

        update_option('mailerpress_fonts_v2', $existingFonts);

        return $installedFonts;
    }

}
