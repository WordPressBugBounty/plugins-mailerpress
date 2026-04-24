<?php

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Users
{
    #[Endpoint(
        'save-user-preferences',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function post(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_Error('no_user', 'User not logged in', ['status' => 403]);
        }

        $newPreferences = $request->get_json_params(); // entire payload is preference object

        if (!is_array($newPreferences)) {
            return new \WP_Error('invalid_data', 'Invalid preferences payload', ['status' => 400]);
        }

        // Sanitize preference values while preserving types (booleans, integers)
        $newPreferences = map_deep($newPreferences, static function ($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return $value;
            }
            if (is_float($value)) {
                return $value;
            }
            if (is_string($value)) {
                return sanitize_text_field($value);
            }
            return $value;
        });

        $existingPrefs = get_user_meta($user_id, 'mailerpress_preferences', true);
        if (!is_array($existingPrefs)) {
            $existingPrefs = [];
        }

        // Merge new preferences into existing ones
        $merged = array_merge($existingPrefs, $newPreferences);

        update_user_meta($user_id, 'mailerpress_preferences', $merged);

        return rest_ensure_response([
            'success' => true,
            'preferences' => $merged,
        ]);
    }

    #[Endpoint(
        'get-user-preferences',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_Error('no_user', 'User not logged in', ['status' => 403]);
        }

        $preferences = get_user_meta($user_id, 'mailerpress_preferences', true);
        if (!is_array($preferences)) {
            $preferences = [];
        }

        return rest_ensure_response([
            'success' => true,
            'preferences' => $preferences,
        ]);
    }

    #[Endpoint(
        'save-user-meta',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function saveUserMeta(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_Error('no_user', 'User not logged in', ['status' => 403]);
        }

        // Retrieve from request parameters instead of JSON
        $meta_name = sanitize_key($request->get_param('name'));
        $meta_value = $request->get_param('value');

        if (empty($meta_name)) {
            return new \WP_Error('invalid_meta_key', 'Meta name is required', ['status' => 400]);
        }

        // Optional whitelist for security
        $allowed_keys = [
            'mailerpress_fullscreen',
            'mailerpress_preferences',
            'mailerpress_settings'
        ];
        if (!in_array($meta_name, $allowed_keys, true)) {
            return new \WP_Error('unauthorized_meta_key', 'Meta key not allowed', ['status' => 403]);
        }

        update_user_meta($user_id, $meta_name, $meta_value);

        return rest_ensure_response([
            'success' => true,
            'name' => $meta_name,
            'value' => $meta_value,
        ]);
    }

}