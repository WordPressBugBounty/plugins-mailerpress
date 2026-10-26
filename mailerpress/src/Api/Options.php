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
use MailerPress\Services\PendingContactManager;
use MailerPress\Services\SendingFrequencyConfig;
use WP_Error;
use Webklex\PHPIMAP\ClientManager;

enum CONNEXION_RESULT: string
{
    case NOTOK = 'KO';
    case OK = 'OK';
}

class Options
{
    private static function maybeRefreshAutomatedCampaignSenders(string $optionName): void
    {
        if (
            in_array($optionName, [
                'mailerpress_default_settings',
                'mailerpress_global_email_senders',
                'mailerpress_email_senders',
                'mailerpress_email_services',
            ], true)
            && function_exists('mailerpress_refresh_active_automated_campaign_sender_configs')
        ) {
            mailerpress_refresh_active_automated_campaign_sender_configs();
        }
    }

    #[Endpoint(
        'get-active-provider',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings'],
    )]
    public function activeProvider(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        return new \WP_REST_Response(
            self::redactEmailServicesForResponse(get_option('mailerpress_email_services', [
                'default_service' => 'wordpress',
                'activated' => ['wordpress'],
                'services' => [
                    'wordpress' => [
                        'conf' => [
                            'default_email' => '',
                            'default_name' => '',
                        ],
                    ],
                ],
            ]))
        );
    }


    #[Endpoint(
        'connect-provider',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings'],
    )]
    public function post(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $key = $request->get_param('key');
        $activated = $request->get_param('activated');
        $config = $request->get_param('config');

        Kernel::getContainer()->get(EmailServiceManager::class)->saveServiceConfiguration($key, $config, $activated);
        self::maybeRefreshAutomatedCampaignSenders('mailerpress_email_services');

        return rest_ensure_response(
            self::redactEmailServicesForResponse(get_option('mailerpress_email_services'))
        );
    }

    #[Endpoint(
        'connect-provider',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageSettings'],
    )]
    public function remove(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $key = $request->get_param('key');

        Kernel::getContainer()->get(EmailServiceManager::class)->removeService($key);
        self::maybeRefreshAutomatedCampaignSenders('mailerpress_email_services');

        return rest_ensure_response(
            self::redactEmailServicesForResponse(get_option('mailerpress_email_services'))
        );
    }

    #[Endpoint(
        'set-primary-email-service',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings'],
    )]
    public function setPrimaryEmailService(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $key = $request->get_param('key');

        Kernel::getContainer()->get(EmailServiceManager::class)->setActiveService($key);
        self::maybeRefreshAutomatedCampaignSenders('mailerpress_email_services');

        return rest_ensure_response(
            self::redactEmailServicesForResponse(get_option('mailerpress_email_services'))
        );
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    #[Endpoint(
        'send-email',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
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

        $serviceName = (string) ($mailer->config()['name'] ?? $key);
        $testSubject = __('MailerPress test: your sending method works', 'mailerpress');
        $testBody = sprintf(
            /* translators: %s: sending method name. */
            __('Good news: MailerPress delivered this test email through %s. Your sending method is set up correctly, so your campaigns and automations are ready to go.', 'mailerpress'),
            $serviceName
        );

        if (true === $html) {
            $testBody = $this->renderTestEmail(
                $serviceName,
                (string) ($config['conf']['default_name'] ?? ''),
                (string) ($config['conf']['default_email'] ?? ''),
                (string) $to
            );
        }

        $result = $mailer->sendEmail([
            'to' => $to,
            'html' => $html,
            'body' => $testBody,
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

    /**
     * Render the branded HTML body of the sending method test email.
     */
    private function renderTestEmail(string $service_name, string $sender_name, string $sender_email, string $recipient): string
    {
        $logo_url = MAILERPRESS_PLUGIN_DIR_URL . 'build/public/images/logo-mailerpress.png';
        $check_url = MAILERPRESS_PLUGIN_DIR_URL . 'build/public/images/email-check.png';
        $sender = '' !== $sender_name ? sprintf('%s <%s>', $sender_name, $sender_email) : $sender_email;
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $site_url = home_url('/');
        $sent_at = wp_date(get_option('date_format')) . ' · ' . wp_date(get_option('time_format'));
        $links = [
            'dashboard' => admin_url('admin.php?page=mailerpress/campaigns.php&path=/home'),
            'contacts' => admin_url('admin.php?page=mailerpress/campaigns.php&path=/home/contacts'),
            'campaign' => admin_url('admin.php?page=mailerpress/campaigns.php&path=/home&view=create-campaign'),
            'workflow' => admin_url('admin.php?page=mailerpress/campaigns.php&path=/home/workflow'),
        ];

        ob_start();
        include MAILERPRESS_PLUGIN_DIR_PATH . 'templates/test-email.php';

        return (string) ob_get_clean();
    }

    #[Endpoint(
        'disconnect-provider',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
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
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function saveTheme(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $theme = $request->get_param('name');
        if (empty($theme)) {
            return new \WP_Error('invalid_theme', __('Theme name is required.', 'mailerpress'), ['status' => 400]);
        }
        update_option('mailerpress_theme', sanitize_text_field($theme), 'Core');

        // The style variation whose palette is applied to emails, when it differs from the theme style.
        $palette = $request->get_param('palette');
        if (\is_string($palette) && '' !== $palette) {
            update_option('mailerpress_theme_palette', sanitize_text_field($palette), false);
        } else {
            delete_option('mailerpress_theme_palette');
        }

        // Palette colors edited for emails; they never change the site styles.
        if ($request->has_param('colors')) {
            $colors = self::sanitizePaletteColors($request->get_param('colors'));
            if (isset($colors['theme']) || [] !== $colors['custom']) {
                update_option('mailerpress_palette_colors', $colors, false);
            } else {
                delete_option('mailerpress_palette_colors');
            }
        }

        // Font size presets edited for emails; they never change the site styles.
        if ($request->has_param('fontSizes')) {
            $fontSizes = self::sanitizeFontSizes($request->get_param('fontSizes'));
            if (isset($fontSizes['theme']) || [] !== $fontSizes['custom']) {
                update_option('mailerpress_font_sizes', $fontSizes, false);
            } else {
                delete_option('mailerpress_font_sizes');
            }
        }

        return rest_ensure_response([]);
    }

    /**
     * @param mixed $value Font size presets sent by the email editor.
     * @return array{theme?: array<int, array<string, mixed>>, custom: array<int, array<string, mixed>>}
     */
    private static function sanitizeFontSizes($value): array
    {
        if (\is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!\is_array($value)) {
            return ['custom' => []];
        }

        // CSS lengths such as 1.125rem, 18px or clamp(1rem, 2vw, 1.5rem).
        $sanitizeSize = static function ($size): string {
            $size = trim((string) $size);

            return \strlen($size) <= 100 && preg_match('/^[0-9a-z.,()%+\-\s*\/]+$/i', $size) ? $size : '';
        };
        $sanitizePresets = static function ($presets) use ($sanitizeSize): array {
            if (!\is_array($presets)) {
                return [];
            }

            $result = [];
            foreach ($presets as $preset) {
                if (!\is_array($preset)) {
                    continue;
                }
                $slug = sanitize_key((string) ($preset['slug'] ?? ''));
                $size = $sanitizeSize($preset['size'] ?? '');
                if ('' === $slug || '' === $size) {
                    continue;
                }

                $entry = [
                    'name' => sanitize_text_field((string) ($preset['name'] ?? $slug)),
                    'slug' => $slug,
                    'size' => $size,
                ];
                $fluid = $preset['fluid'] ?? null;
                if (\is_bool($fluid)) {
                    $entry['fluid'] = $fluid;
                } elseif (\is_array($fluid)) {
                    $entry['fluid'] = array_filter([
                        'min' => $sanitizeSize($fluid['min'] ?? ''),
                        'max' => $sanitizeSize($fluid['max'] ?? ''),
                    ]);
                }
                $result[] = $entry;
            }

            return $result;
        };

        $sanitized = ['custom' => $sanitizePresets($value['custom'] ?? [])];
        if (isset($value['theme']) && \is_array($value['theme'])) {
            $sanitized['theme'] = $sanitizePresets($value['theme']);
        }

        return $sanitized;
    }

    /**
     * @param mixed $value Palette colors sent by the email editor.
     * @return array{theme?: array<int, array{name: string, slug: string, color: string}>, custom: array<int, array{name: string, slug: string, color: string}>}
     */
    private static function sanitizePaletteColors($value): array
    {
        if (\is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!\is_array($value)) {
            return ['custom' => []];
        }

        $sanitizeColors = static function ($colors): array {
            if (!\is_array($colors)) {
                return [];
            }

            $result = [];
            foreach ($colors as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }
                $color = sanitize_hex_color((string) ($entry['color'] ?? ''));
                $slug = sanitize_key((string) ($entry['slug'] ?? ''));
                if (!$color || '' === $slug) {
                    continue;
                }
                $result[] = [
                    'name' => sanitize_text_field((string) ($entry['name'] ?? $slug)),
                    'slug' => $slug,
                    'color' => $color,
                ];
            }

            return $result;
        };

        $sanitized = ['custom' => $sanitizeColors($value['custom'] ?? [])];
        if (isset($value['theme']) && \is_array($value['theme'])) {
            $sanitized['theme'] = $sanitizeColors($value['theme']);
        }

        return $sanitized;
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
        'mailerpress_default_settings',
        'mailerpress_inactive_subscribers',
        'mailerpress_signup_confirmation',
        'woocommerce_mailerpress_settings',
        'woocommerce_my_account_settings',
        'pmpro_mailerpress_settings',
        'pmpro_my_account_settings',
        'mailerpress_edd_settings',
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
            return new \WP_Error('invalid_option', __('Option name is required.', 'mailerpress'), ['status' => 400]);
        }
        $optionName = sanitize_key($optionName);

        if (!self::isAllowedOption($optionName)) {
            return new \WP_Error('forbidden_option', __('Only mailerpress options can be created or updated.', 'mailerpress'), ['status' => 403]);
        }

        if (SendingFrequencyConfig::OPTION === $optionName) {
            $settings = SendingFrequencyConfig::save($optionValue);
            return is_wp_error($settings) ? $settings : rest_ensure_response($settings);
        }

        // Options that need native PHP array storage (for WPML compatibility)
        if (in_array($optionName, self::NATIVE_ARRAY_OPTIONS, true)) {
            if (is_string($optionValue)) {
                $decoded = json_decode($optionValue, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $optionValue = $decoded;
                }
            }
            if (PendingContactManager::OPTION_NAME === $optionName) {
                if (!is_array($optionValue)) {
                    return new \WP_Error('invalid_pending_retention', __('Invalid sign-up confirmation settings.', 'mailerpress'), ['status' => 400]);
                }
                $optionValue = PendingContactManager::validateSettings($optionValue);
                if (is_wp_error($optionValue)) {
                    return $optionValue;
                }
            }
            // $optionValue is now a PHP array — WordPress will serialize it natively
            $updated = update_option($optionName, $optionValue);
            self::maybeRefreshAutomatedCampaignSenders($optionName);
            return rest_ensure_response($updated);
        }

        if ('mailerpress_bounce_config' === $optionName) {
            $bounceConfig = is_string($optionValue) ? json_decode($optionValue, true) : $optionValue;
            if (is_array($bounceConfig) && self::MASKED_SECRET === ($bounceConfig['password'] ?? null)) {
                $bounceConfig['password'] = self::savedBouncePassword();
                $optionValue = wp_json_encode($bounceConfig);
            }
        }

        /**
         * Lets add-ons validate or transform an option value before it is stored.
         * Return a WP_Error to reject the update.
         *
         * @param mixed  $optionValue Submitted value.
         * @param string $optionName  MailerPress option name.
         */
        $optionValue = apply_filters('mailerpress_pre_update_option_value', $optionValue, $optionName);
        if (is_wp_error($optionValue)) {
            return $optionValue;
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

        $updated = update_option($optionName, $optionValue);
        self::maybeRefreshAutomatedCampaignSenders($optionName);

        return rest_ensure_response($updated);
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
     * Value returned instead of a saved secret. Submitting it back keeps the saved secret.
     */
    public const MASKED_SECRET = '••••••••';

    /**
     * Credential keys stored inside mailerpress_email_services service configs.
     */
    private const EMAIL_SERVICE_SECRET_KEYS = [
        'api_key',
        'api_secret',
        'password',
        'auth_password',
        'client_secret',
        'secret_access_key',
        'webhook_signing_key',
        'webhook_verification_key',
        'access_token',
        'refresh_token',
    ];

    /**
     * Never send provider credentials to the browser: saved secrets are replaced with MASKED_SECRET, so the
     * settings screens know a secret exists without receiving it.
     */
    public static function redactEmailServicesForResponse(mixed $services): mixed
    {
        $wasJsonString = is_string($services);
        $decoded = $wasJsonString ? json_decode($services, true) : $services;

        if (!is_array($decoded) || empty($decoded['services']) || !is_array($decoded['services'])) {
            return $services;
        }

        foreach ($decoded['services'] as &$service) {
            if (empty($service['conf']) || !is_array($service['conf'])) {
                continue;
            }

            foreach (self::EMAIL_SERVICE_SECRET_KEYS as $secretKey) {
                if (array_key_exists($secretKey, $service['conf'])) {
                    $service['conf'][$secretKey] = '' !== (string) $service['conf'][$secretKey] ? self::MASKED_SECRET : '';
                }
            }
        }
        unset($service);

        return $wasJsonString ? wp_json_encode($decoded) : $decoded;
    }

    /**
     * Puts back the saved provider credentials submitted as MASKED_SECRET.
     *
     * @param mixed $services Configurations about to be saved (array or JSON string).
     * @param mixed $previous Saved configurations.
     */
    public static function restoreEmailServiceSecrets(mixed $services, mixed $previous): mixed
    {
        $wasJsonString = is_string($services);
        $decoded = $wasJsonString ? json_decode($services, true) : $services;
        $saved = is_string($previous) ? json_decode($previous, true) : $previous;

        if (!is_array($decoded) || empty($decoded['services']) || !is_array($decoded['services'])) {
            return $services;
        }

        foreach ($decoded['services'] as $serviceKey => &$service) {
            if (empty($service['conf']) || !is_array($service['conf'])) {
                continue;
            }

            foreach (self::EMAIL_SERVICE_SECRET_KEYS as $secretKey) {
                if (self::MASKED_SECRET === ($service['conf'][$secretKey] ?? null)) {
                    $service['conf'][$secretKey] = (string) ($saved['services'][$serviceKey]['conf'][$secretKey] ?? '');
                }
            }
        }
        unset($service);

        return $wasJsonString ? wp_json_encode($decoded) : $decoded;
    }

    /** IMAP bounce settings without their password, for the browser. */
    private static function maskBounceConfig(mixed $config): mixed
    {
        $decoded = is_string($config) ? json_decode($config, true) : $config;
        if (!is_array($decoded) || '' === (string) ($decoded['password'] ?? '')) {
            return $config;
        }

        $decoded['password'] = self::MASKED_SECRET;

        return is_string($config) ? wp_json_encode($decoded) : $decoded;
    }

    /** Saved IMAP bounce password, used when the settings screen submits MASKED_SECRET. */
    private static function savedBouncePassword(): string
    {
        $saved = get_option('mailerpress_bounce_config');
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;

        return is_array($saved) ? (string) ($saved['password'] ?? '') : '';
    }

    /**
     * Mask API keys in the AI model settings option before returning to the frontend.
     * Non-empty keys are replaced with a placeholder so the UI knows a key exists
     * without the actual secret being transmitted.
     */
    #[Endpoint(
        'option/(?P<name>[a-zA-Z0-9-_]+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function getOption(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $option_name = $request->get_param('name');

        if (!self::isAllowedOption($option_name)) {
            return new \WP_Error('forbidden_option', __('Only mailerpress options can be read.', 'mailerpress'), ['status' => 403]);
        }

        $option_value = get_option($option_name);

        if (SendingFrequencyConfig::OPTION === $option_name) {
            $option_value = SendingFrequencyConfig::get();
        }

        if (is_null($option_value)) {
            return new \WP_Error('no_option', __('Option not found', 'mailerpress'), ['status' => 404]);
        }

        /**
         * Lets add-ons adjust an option value before it is returned, for example to mask secrets.
         *
         * @param mixed  $option_value Stored value.
         * @param string $option_name  MailerPress option name.
         */
        $option_value = apply_filters('mailerpress_option_response_value', $option_value, $option_name);

        if ('mailerpress_email_services' === $option_name) {
            $option_value = self::redactEmailServicesForResponse($option_value);
        }

        if ('mailerpress_bounce_config' === $option_name) {
            $option_value = self::maskBounceConfig($option_value);
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
            return new \WP_Error('invalid_option', __('Option name is required.', 'mailerpress'), ['status' => 400]);
        }

        $optionName = sanitize_key($optionName);

        if (!self::isAllowedOption($optionName)) {
            return new \WP_Error('forbidden_option', __('Only mailerpress options can be deleted.', 'mailerpress'), ['status' => 403]);
        }

        $deleted = delete_option($optionName);

        return rest_ensure_response($deleted);
    }


    #[Endpoint(
        'user/setup-completed',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canViewMailerPress']
    )]
    public function markSetupCompleted(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return new \WP_Error('no_user', __('User not authenticated.', 'mailerpress'), ['status' => 401]);
        }

        $completed = $request->get_param('completed');

        if (!in_array($completed, ['yes', 'no'], true)) {
            return new \WP_Error('invalid_param', __('The completed value must be "yes" or "no".', 'mailerpress'), ['status' => 400]);
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
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function testBounceConnection(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $host = $request->get_param('host');
        $port = $request->get_param('port') ?? 993;
        $username = $request->get_param('username');
        $password = $request->get_param('password');
        if (self::MASKED_SECRET === $password) {
            $password = self::savedBouncePassword();
        }
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
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function getRateLimitSettings(\WP_REST_Request $request): \WP_REST_Response
    {
        $config = RateLimitConfig::get();

        return new \WP_REST_Response([
            'success' => true,
            'data' => array_intersect_key($config, array_flip(['enabled', 'requests', 'window', 'honeypot_enabled'])),
        ], 200);
    }

    #[Endpoint(
        'options/rate-limit',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function updateRateLimitSettings(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
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

		$current = RateLimitConfig::get();
		$config = array_merge($current, $config);

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
                'label' => __('Sending frequency', 'mailerpress'),
                'sensitive' => false,
            ],
            'inactive_contacts' => [
                'option_key' => 'mailerpress_inactive_subscribers',
                'label' => __('Inactive Contacts', 'mailerpress'),
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
                'sensitive' => true,
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
            'theme' => [
                'option_key' => 'mailerpress_theme',
                'label' => __('Theme', 'mailerpress'),
                'sensitive' => false,
            ],
            'signup_confirmation' => [
                'option_key' => 'mailerpress_signup_confirmation',
                'label' => __('Sign-up Confirmation', 'mailerpress'),
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

            if (SendingFrequencyConfig::OPTION === $expectedKey) {
                $saved = SendingFrequencyConfig::save($value);
                if (is_wp_error($saved)) {
                    $skipped[] = $group;
                    continue;
                }
            } else {
                update_option($expectedKey, $value);
            }
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
