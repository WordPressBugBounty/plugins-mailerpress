<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use MailerPress\Services\RateLimitConfig;
use WP_Error;
use Webklex\PHPIMAP\ClientManager;

enum CONNEXION_RESULT: string
{
    case NOTOK = 'KO';
    case OK = 'OK';
}

class Options
{
    #[Endpoint(
        'get-active-provider',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function activeProvider(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        return new \WP_REST_Response(
            get_option('mailerpress_email_services', [
                'default_service' => 'php',
                'activated' => ['php'],
                'services' => [
                    'php' => [
                        'conf' => [
                            'default_email' => '',
                            'default_name' => '',
                        ],
                    ],
                ],
            ])
        );
    }


    #[Endpoint(
        'connect-provider',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function post(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $key = $request->get_param('key');
        $activated = $request->get_param('activated');
        $config = $request->get_param('config');

        Kernel::getContainer()->get(EmailServiceManager::class)->saveServiceConfiguration($key, $config, $activated);

        return rest_ensure_response(
            get_option('mailerpress_email_services')
        );
    }

    #[Endpoint(
        'connect-provider',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function remove(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $key = $request->get_param('key');

        Kernel::getContainer()->get(EmailServiceManager::class)->removeService($key);

        return rest_ensure_response(
            get_option('mailerpress_email_services')
        );
    }

    #[Endpoint(
        'set-primary-email-service',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function setPrimaryEmailService(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $key = $request->get_param('key');

        Kernel::getContainer()->get(EmailServiceManager::class)->setActiveService($key);

        return rest_ensure_response(
            get_option('mailerpress_email_services')
        );
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    #[Endpoint(
        'send-email',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function sendEmail(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $to = $request->get_param('to');
        $html = $request->get_param('html');
        $key = $request->get_param('key');
        $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveServiceByKey($key);
        $config = $mailer->getConfig();

        if (
            empty($config['conf']['default_email'])
            || empty($config['conf']['default_name'])
        ) {
            // Primary fallback: global default settings (updated by Settings page)
            $defaultSettings = get_option('mailerpress_default_settings', []);
            if (is_string($defaultSettings)) {
                $defaultSettings = json_decode($defaultSettings, true) ?: [];
            }

            if (!empty($defaultSettings['fromAddress']) && !empty($defaultSettings['fromName'])) {
                $config['conf']['default_email'] = $defaultSettings['fromAddress'];
                $config['conf']['default_name'] = $defaultSettings['fromName'];
            } else {
                // Secondary fallback: global email senders (set during wizard)
                $globalSender = get_option('mailerpress_global_email_senders');
                if (is_string($globalSender)) {
                    $globalSender = json_decode($globalSender, true);
                }
                if (is_array($globalSender)) {
                    $config['conf']['default_email'] = $globalSender['fromAddress'] ?? '';
                    $config['conf']['default_name'] = $globalSender['fromName'] ?? '';
                }
            }
        }

        $testSubject = __('This is a Sending Method Test', 'mailerpress');
        $result = $mailer->sendEmail([
            'to' => $to,
            'html' => $html,
            'body' => __('Yup, it works! You can start blasting emails to the moon.', 'mailerpress'),
            'subject' => $testSubject,
            'sender_name' => $config['conf']['default_name'],
            'sender_to' => $config['conf']['default_email'],
            'apiKey' => $config['conf']['api_key'] ?? '',
            'isTest' => true,
        ]);

        // Si c'est un WP_Error, retourner une réponse d'erreur avec le message détaillé
        if (is_wp_error($result)) {
            return new \WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                ['status' => 400]
            );
        }

        // Si c'est false, essayer de récupérer le message d'erreur explicite depuis les logs
        if ($result === false) {
            $errorMessage = __('An error occurred while sending the test email. Please check your configuration and try again.', 'mailerpress');

            // Récupérer le dernier log d'erreur pour cet email de test
            try {
                $logger = Kernel::getContainer()->get(\MailerPress\Core\EmailManager\EmailLogger::class);
                $logs = $logger->getLogs([
                    'status' => 'error',
                    'service' => $key,
                    'to_email' => $to,
                    'limit' => 1,
                    'orderby' => 'created_at',
                    'order' => 'DESC',
                ]);

                // Si on trouve un log récent (moins de 5 secondes), utiliser son message d'erreur
                if (!empty($logs)) {
                    $log = $logs[0];
                    $logTime = strtotime($log['created_at']);
                    $currentTime = current_time('timestamp');

                    // Vérifier que le log est récent (moins de 5 secondes) et correspond au test
                    if (($currentTime - $logTime) < 5 &&
                        isset($log['error_message']) &&
                        !empty($log['error_message'])) {
                        $errorMessage = $log['error_message'];
                    }
                }
            } catch (\Throwable $e) {
            }

            return new \WP_Error(
                'send_email_failed',
                $errorMessage,
                ['status' => 400]
            );
        }

        return rest_ensure_response(['success' => true]);
    }

    #[Endpoint(
        'disconnect-provider',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function disconnectProvider(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        delete_option('mailerpress_esp_config');
        delete_option('mailerpress_senders');
        delete_transient('mailerpress_list');

        return rest_ensure_response('done');
    }

    #[Endpoint(
        'save-theme',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function saveTheme(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $theme = $request->get_param('name');
        if (empty($theme)) {
            return new \WP_Error('invalid_theme', 'Theme name is required.', ['status' => 400]);
        }
        update_option('mailerpress_theme', sanitize_text_field($theme), 'Core');
        return rest_ensure_response([]);
    }

    /**
     * Options that must be stored as native PHP arrays (not JSON strings)
     * for compatibility with WPML admin-texts.
     */
    private static function isAllowedOption(string $name): bool
    {
        return str_starts_with($name, 'mailerpress_')
            || str_starts_with($name, 'mailerpress-')
            || str_starts_with($name, 'woocommerce_mailerpress')
            || str_starts_with($name, 'pmpro_mailerpress')
            || str_starts_with($name, 'surecart_mailerpress')
            || str_starts_with($name, 'fluentcart_mailerpress')
            || in_array($name, self::NATIVE_ARRAY_OPTIONS, true);
    }

    private const NATIVE_ARRAY_OPTIONS = [
        'mailerpress_signup_confirmation',
        'woocommerce_mailerpress_settings',
        'woocommerce_my_account_settings',
        'pmpro_mailerpress_settings',
        'pmpro_my_account_settings',
        'surecart_mailerpress_settings',
        'fluentcart_mailerpress_settings',
        'fluentcart_my_account_settings',
    ];

    #[Endpoint(
        'create-option',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function createOrUpdateOption(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $optionName = $request->get_param('name');
        $optionValue = $request->get_param('value');
        if (empty($optionName)) {
            return new \WP_Error('invalid_option', 'Option name is required.', ['status' => 400]);
        }
        $optionName = sanitize_key($optionName);

        if (!self::isAllowedOption($optionName)) {
            return new \WP_Error('forbidden_option', 'Only mailerpress options can be created or updated.', ['status' => 403]);
        }

        // Options that need native PHP array storage (for WPML compatibility)
        if (in_array($optionName, self::NATIVE_ARRAY_OPTIONS, true)) {
            if (is_string($optionValue)) {
                $decoded = json_decode($optionValue, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $optionValue = $decoded;
                }
            }
            // $optionValue is now a PHP array — WordPress will serialize it natively
            return rest_ensure_response(
                update_option($optionName, $optionValue)
            );
        }

        // For AI model settings, merge api_keys instead of overwriting: an empty
        // string from the frontend means "leave existing key unchanged".
        if ('mailerpress_ai_model_settings' === $optionName) {
            $incoming = is_string($optionValue) ? json_decode($optionValue, true) : $optionValue;
            if (is_array($incoming) && isset($incoming['api_keys']) && is_array($incoming['api_keys'])) {
                $existing_raw = get_option('mailerpress_ai_model_settings', '{}');
                $existing     = is_string($existing_raw) ? json_decode($existing_raw, true) : $existing_raw;
                $existing_keys = is_array($existing) && isset($existing['api_keys']) ? $existing['api_keys'] : [];

                foreach ($incoming['api_keys'] as $provider => $value) {
                    if ('' === (string) $value && isset($existing_keys[$provider]) && '' !== (string) $existing_keys[$provider]) {
                        // Empty value sent by frontend = "keep existing key"
                        $incoming['api_keys'][$provider] = $existing_keys[$provider];
                    }
                }
                $optionValue = wp_json_encode($incoming);
                return rest_ensure_response(update_option($optionName, $optionValue));
            }
        }

        // Si c'est une chaîne simple, ne pas l'encoder en JSON (évite le double encodage)
        // Si c'est un objet ou un tableau, l'encoder en JSON
        if (is_string($optionValue)) {
            // Vérifier si c'est déjà une chaîne JSON encodée (commence par " ou { ou [)
            $trimmed = trim($optionValue);
            if (
                (substr($trimmed, 0, 1) === '"' && substr($trimmed, -1) === '"') ||
                (substr($trimmed, 0, 1) === '{' && substr($trimmed, -1) === '}') ||
                (substr($trimmed, 0, 1) === '[' && substr($trimmed, -1) === ']')
            ) {
                // C'est déjà du JSON, décoder puis réencoder proprement
                $decoded = json_decode($optionValue, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // C'était du JSON, réencoder
                    $optionValue = wp_json_encode($decoded);
                } else {
                    // Ce n'était pas du JSON valide, garder tel quel mais sanitizer
                    $optionValue = sanitize_text_field($optionValue);
                }
            } else {
                // Chaîne simple, ne pas encoder en JSON
                $optionValue = sanitize_text_field($optionValue);
            }
        } else {
            // Objet ou tableau, encoder en JSON
            $optionValue = wp_json_encode($optionValue);
        }

        return rest_ensure_response(
            update_option($optionName, $optionValue)
        );
    }


    /**
     * Options that require manage_settings capability to read (contain credentials).
     */
    private const SENSITIVE_OPTIONS = [
        'mailerpress_ai_model_settings',
        'mailerpress_email_services',
        'mailerpress_bounce_config',
        'mailerpress_ai_config',
    ];

    /**
     * Keys inside mailerpress_ai_model_settings.api_keys that must be masked.
     */
    private const MASKED_PLACEHOLDER = '••••••••';

    /**
     * Mask API keys in the AI model settings option before returning to the frontend.
     * Non-empty keys are replaced with a placeholder so the UI knows a key exists
     * without the actual secret being transmitted.
     */
    private static function maskAiModelSettings(mixed $option_value): mixed
    {
        $decoded = is_string($option_value) ? json_decode($option_value, true) : $option_value;

        if (!is_array($decoded) || !isset($decoded['api_keys']) || !is_array($decoded['api_keys'])) {
            return $option_value;
        }

        foreach ($decoded['api_keys'] as $provider => $key) {
            $decoded['api_keys'][$provider] = ('' !== (string) $key) ? self::MASKED_PLACEHOLDER : '';
        }

        return is_string($option_value) ? wp_json_encode($decoded) : $decoded;
    }

    #[Endpoint(
        'option/(?P<name>[a-zA-Z0-9-_]+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function getOption(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $option_name = $request->get_param('name');

        if (!self::isAllowedOption($option_name)) {
            return new \WP_Error('forbidden_option', 'Only mailerpress options can be read.', ['status' => 403]);
        }

        $option_value = get_option($option_name);

        if (is_null($option_value)) {
            return new \WP_Error('no_option', 'Option not found', ['status' => 404]);
        }

        if ('mailerpress_ai_model_settings' === $option_name) {
            $option_value = self::maskAiModelSettings($option_value);
        }

        return rest_ensure_response([
            'option_name' => $option_name,
            'option_value' => $option_value,
        ]);
    }

    #[Endpoint(
        'delete-option',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function deleteOption(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $optionName = $request->get_param('name');
        if (empty($optionName)) {
            return new \WP_Error('invalid_option', 'Option name is required.', ['status' => 400]);
        }

        $optionName = sanitize_key($optionName);

        if (!self::isAllowedOption($optionName)) {
            return new \WP_Error('forbidden_option', 'Only mailerpress options can be deleted.', ['status' => 403]);
        }

        $deleted = delete_option($optionName);

        return rest_ensure_response($deleted);
    }


    #[Endpoint(
        'user/setup-completed',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit']  // You already have this in your code
    )]
    public function markSetupCompleted(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return new \WP_Error('no_user', 'User not authenticated.', ['status' => 401]);
        }

        $completed = $request->get_param('completed');

        if (!in_array($completed, ['yes', 'no'], true)) {
            return new \WP_Error('invalid_param', 'The completed value must be "yes" or "no".', ['status' => 400]);
        }

        update_user_meta($userId, 'mailerpress_setup_completed', $completed);

        return rest_ensure_response([
            'success' => true,
            'completed' => $completed,
        ]);
    }

    #[Endpoint(
        'test-bounce-connection',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function testBounceConnection(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $host = $request->get_param('host');
        $port = $request->get_param('port') ?? 993;
        $username = $request->get_param('username');
        $password = $request->get_param('password');
        $validateCert = $request->get_param('validateCert') ?? true;

        if (empty($host) || empty($username) || empty($password)) {
            return new \WP_Error('invalid_params', __('Missing IMAP credentials.', 'mailerpress'), ['status' => 400]);
        }

        try {
            $clientManager = new ClientManager();

            $client = $clientManager->make([
                'host' => $host,
                'port' => $port,
                'encryption' => 'ssl',
                'validate_cert' => $validateCert,
                'username' => $username,
                'password' => $password,
                'protocol' => 'imap',
                'timeout' => 5
            ]);

            // Tenter de se connecter
            $client->connect();

            // Vérifier qu'on peut accéder à INBOX
            $folder = $client->getFolder('INBOX');

            if (!$folder) {
                $client->disconnect();
                return new \WP_Error(
                    'imap_connection_failed',
                    __('Unable to access INBOX folder.', 'mailerpress'),
                    ['status' => 400]
                );
            }

            $client->disconnect();
            return rest_ensure_response(['success' => true]);
        } catch (\Exception $e) {
            return new \WP_Error(
                'imap_connection_failed',
                sprintf(__('Unable to connect to IMAP server: %s', 'mailerpress'), $e->getMessage()),
                ['status' => 400]
            );
        }
    }

    #[Endpoint(
        'options/rate-limit',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getRateLimitSettings(\WP_REST_Request $request): \WP_REST_Response
    {
        $config = RateLimitConfig::get();

        return new \WP_REST_Response([
            'success' => true,
            'data' => $config,
        ], 200);
    }

    #[Endpoint(
        'options/rate-limit',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function updateRateLimitSettings(\WP_REST_Request $request): \WP_REST_Response
    {
        // Use filter_var for proper boolean conversion (handles "false" string correctly)
        $enabled = filter_var($request->get_param('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $enabled = $enabled === null ? true : $enabled; // Default to true if not provided

        $requests = max(1, min(100, (int)$request->get_param('requests')));
        $window = max(10, min(3600, (int)$request->get_param('window')));

        $honeypotEnabled = filter_var($request->get_param('honeypot_enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $honeypotEnabled = $honeypotEnabled === null ? true : $honeypotEnabled; // Default to true if not provided

        $config = [
            'enabled' => $enabled,
            'requests' => $requests,
            'window' => $window,
            'honeypot_enabled' => $honeypotEnabled,
        ];

        $success = RateLimitConfig::update($config);

        return new \WP_REST_Response([
            'success' => $success,
            'message' => $success
                ? __('Rate limit settings saved successfully.', 'mailerpress')
                : __('Failed to save rate limit settings.', 'mailerpress'),
        ], $success ? 200 : 500);
    }

    private static function getExportableSettingsMap(): array
    {
        $map = [
            'general' => [
                'option_key' => 'mailerpress_default_settings',
                'label' => __('General Settings', 'mailerpress'),
                'sensitive' => false,
            ],
            'global_email_senders' => [
                'option_key' => 'mailerpress_global_email_senders',
                'label' => __('Global Email Senders', 'mailerpress'),
                'sensitive' => false,
            ],
            'esp_configuration' => [
                'option_key' => 'mailerpress_email_services',
                'label' => __('Email Service Providers', 'mailerpress'),
                'sensitive' => true,
            ],
            'sending_frequency' => [
                'option_key' => 'mailerpress_frequency_sending',
                'label' => __('Sending Frequency', 'mailerpress'),
                'sensitive' => false,
            ],
            'bounce_management' => [
                'option_key' => 'mailerpress_bounce_config',
                'label' => __('Bounce Management', 'mailerpress'),
                'sensitive' => true,
            ],
            'spam_protection' => [
                'option_key' => 'mailerpress_contact_rate_limit',
                'label' => __('Spam Protection', 'mailerpress'),
                'sensitive' => false,
            ],
            'incoming_webhooks' => [
                'option_key' => 'mailerpress_webhook_configs',
                'label' => __('Incoming Webhooks', 'mailerpress'),
                'sensitive' => false,
            ],
            'custom_fonts' => [
                'option_key' => 'mailerpress_fonts_v2',
                'label' => __('Custom Fonts', 'mailerpress'),
                'sensitive' => false,
            ],
            'global_typography' => [
                'option_key' => 'mailerpress_global_typography',
                'label' => __('Global Typography', 'mailerpress'),
                'sensitive' => false,
            ],
            'ai_config' => [
                'option_key' => 'mailerpress_ai_model_settings',
                'label' => __('AI Configuration', 'mailerpress'),
                'sensitive' => true,
            ],
            'theme' => [
                'option_key' => 'mailerpress_theme',
                'label' => __('Theme', 'mailerpress'),
                'sensitive' => false,
            ],
            'signup_confirmation' => [
                'option_key' => 'mailerpress_signup_confirmation',
                'label' => __('Signup Confirmation', 'mailerpress'),
                'sensitive' => false,
            ],
            'wp_email_templates' => [
                'option_key' => 'mailerpress_wp_email_templates',
                'label' => __('WordPress Email Templates', 'mailerpress'),
                'sensitive' => false,
            ],
            'wc_email_templates' => [
                'option_key' => 'mailerpress_wc_email_templates',
                'label' => __('WooCommerce Email Templates', 'mailerpress'),
                'sensitive' => false,
            ],
        ];

        return apply_filters('mailerpress_exportable_settings_map', $map);
    }

    #[Endpoint(
        'export-settings',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function exportSettings(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        $groups = $request->get_param('groups');

        if (empty($groups) || !is_array($groups)) {
            return new \WP_Error('invalid_groups', __('Please select at least one settings group to export.', 'mailerpress'), ['status' => 400]);
        }

        $map = self::getExportableSettingsMap();
        $settings = [];

        foreach ($groups as $group) {
            if (!isset($map[$group])) {
                continue;
            }

            $optionKey = $map[$group]['option_key'];
            $value = get_option($optionKey, null);

            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            $settings[$group] = [
                'option_key' => $optionKey,
                'value' => $value,
            ];
        }

        $version = defined('MAILERPRESS_VERSION') ? MAILERPRESS_VERSION : 'unknown';

        return new \WP_REST_Response([
            'mailerpress_export' => true,
            'version' => $version,
            'exported_at' => gmdate('c'),
            'site_url' => get_site_url(),
            'settings' => $settings,
        ], 200);
    }

    #[Endpoint(
        'import-settings',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function importSettings(\WP_REST_Request $request): \WP_Error|\WP_REST_Response
    {
        $data = $request->get_param('data');
        $groups = $request->get_param('groups');

        if (empty($data) || !is_array($data)) {
            return new \WP_Error('invalid_data', __('Invalid import data.', 'mailerpress'), ['status' => 400]);
        }

        if (empty($data['mailerpress_export']) || $data['mailerpress_export'] !== true) {
            return new \WP_Error('invalid_file', __('This file is not a valid MailerPress settings export.', 'mailerpress'), ['status' => 400]);
        }

        if (empty($data['settings']) || !is_array($data['settings'])) {
            return new \WP_Error('no_settings', __('No settings found in the export file.', 'mailerpress'), ['status' => 400]);
        }

        if (empty($groups) || !is_array($groups)) {
            return new \WP_Error('invalid_groups', __('Please select at least one settings group to import.', 'mailerpress'), ['status' => 400]);
        }

        $map = self::getExportableSettingsMap();
        $imported = [];
        $skipped = [];

        foreach ($groups as $group) {
            if (!isset($map[$group]) || !isset($data['settings'][$group])) {
                $skipped[] = $group;
                continue;
            }

            $setting = $data['settings'][$group];
            $expectedKey = $map[$group]['option_key'];

            if (!isset($setting['option_key']) || $setting['option_key'] !== $expectedKey) {
                $skipped[] = $group;
                continue;
            }

            $value = $setting['value'];

            // Sanitize imported values
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                } else {
                    $value = sanitize_text_field($value);
                }
            } elseif (is_array($value)) {
                $value = map_deep($value, 'sanitize_text_field');
            }

            update_option($expectedKey, $value);
            $imported[] = $group;
        }

        // Mark setup as completed if core settings were imported
        if (
            in_array('esp_configuration', $imported, true)
            && in_array('global_email_senders', $imported, true)
        ) {
            update_user_meta(get_current_user_id(), 'mailerpress_setup_completed', 'yes');
        }

        return new \WP_REST_Response([
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
        ], 200);
    }
}
