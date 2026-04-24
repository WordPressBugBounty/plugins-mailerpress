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
            $globalSender = get_option('mailerpress_global_email_senders');
            if (is_string($globalSender)) {
                $globalSender = json_decode($globalSender, true);
            }
            $config['conf']['default_email'] = $globalSender['fromAddress'];
            $config['conf']['default_name'] = $globalSender['fromName'];
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
                // Si la récupération du log échoue, utiliser le message par défaut
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MailerPress: Failed to retrieve error log - ' . $e->getMessage());
                }
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

        $allowedOptions = ['woocommerce_mailerpress_settings', 'pmpro_mailerpress_settings'];
        if (!str_starts_with($optionName, 'mailerpress_') && !str_starts_with($optionName, 'mailerpress-') && !in_array($optionName, $allowedOptions, true)) {
            return new \WP_Error('forbidden_option', 'Only mailerpress options can be modified.', ['status' => 403]);
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


    #[Endpoint(
        'option/(?P<name>[a-zA-Z0-9-_]+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canEdit']
    )]
    public function getOption(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $option_name = $request->get_param('name');

        $allowedOptions = ['woocommerce_mailerpress_settings', 'pmpro_mailerpress_settings'];
        if (!str_starts_with($option_name, 'mailerpress_') && !str_starts_with($option_name, 'mailerpress-') && !in_array($option_name, $allowedOptions, true)) {
            return new WP_Error('forbidden_option', 'Only mailerpress options can be read.', ['status' => 403]);
        }

        $option_value = get_option($option_name);

        if (is_null($option_value)) {
            return new WP_Error('no_option', 'Option not found', ['status' => 404]);
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

        $allowedOptions = ['woocommerce_mailerpress_settings', 'pmpro_mailerpress_settings'];
        if (!str_starts_with($optionName, 'mailerpress_') && !str_starts_with($optionName, 'mailerpress-') && !in_array($optionName, $allowedOptions, true)) {
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
}
