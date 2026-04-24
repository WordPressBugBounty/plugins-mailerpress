<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;

class Media
{
    #[Endpoint(
        'upload-image',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function uploadImageByUrl(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $input = $request->get_param('url');

        if (empty($input)) {
            return new \WP_Error('missing_param', 'The "url" parameter is required.', ['status' => 400]);
        }

        // --- Handle base64-encoded images ---
        if (preg_match('/^data:image\/(\w+);base64,/', $input, $matches)) {
            $ext = strtolower($matches[1]); // png, jpg, gif...
            $data = substr($input, strpos($input, ',') + 1);
            $data = base64_decode($data);

            if ($data === false) {
                return new \WP_Error('invalid_base64', 'Invalid base64 encoded image.', ['status' => 400]);
            }

            // Save temp file
            $filename = 'base64-image-' . time() . ".$ext";
            $tmp = wp_tempnam($filename);
            file_put_contents($tmp, $data);

            $file_array = [
                'name' => $filename,
                'tmp_name' => $tmp,
            ];

            $id = media_handle_sideload($file_array, 0);
            if (is_wp_error($id)) {
                @unlink($tmp);
                return $id;
            }

            return rest_ensure_response([
                'id' => $id,
                'sizes' => $this->getAttachmentSizes($id),
            ]);
        }

        // --- Handle remote URL ---
        if (!wp_http_validate_url($input)) {
            return new \WP_Error('invalid_url', 'URL is not valid or not http(s).', ['status' => 400]);
        }

        $tmp = download_url($input);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $filename = basename(parse_url($input, PHP_URL_PATH));
        $filetype = wp_check_filetype($filename);

        if (empty($filetype['ext']) || strpos($filetype['type'], 'image/') !== 0) {
            $headers = wp_remote_head($input);
            $mime = wp_remote_retrieve_header($headers, 'content-type');

            if (!$mime || strpos($mime, 'image/') !== 0) {
                wp_delete_file($tmp);
                return new \WP_Error('invalid_filetype', 'Only image files are allowed.', ['status' => 400]);
            }

            $ext = explode('/', $mime)[1] ?? 'jpg';
            $filename .= ".$ext";
        }

        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, 0);
        if (is_wp_error($id)) {
            wp_delete_file($tmp);
            return $id;
        }

        return rest_ensure_response([
            'id' => $id,
            'sizes' => $this->getAttachmentSizes($id),
        ]);
    }

    // Helper to get all sizes for an attachment
    private function getAttachmentSizes(int $id): array
    {
        $sizes = [];
        $wanted = ['thumbnail', 'medium', 'large', 'full']; // only these

        foreach ($wanted as $size) {
            $src = wp_get_attachment_image_src($id, $size);
            if ($src) {
                $sizes[$size] = [
                    'url'    => $src[0],
                    'width'  => $src[1],
                    'height' => $src[2],
                ];
            }
        }

        return $sizes;
    }

}
