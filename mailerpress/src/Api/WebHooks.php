<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Webhooks\WebhookManager;
use MailerPress\Core\Webhooks\WebhookDispatcher;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * API endpoints pour les webhooks
 *
 * @since 1.2.0
 */
class Webhooks
{
    /**
     * Vérifie si MailerPress Pro est actif
     *
     * @return bool True si Pro est actif
     */
    private function isProActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once \ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active')
            && \is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    private function getManager(): WebhookManager
    {
        return WebhookManager::getInstance();
    }

    /**
     * Endpoint REST API pour recevoir des webhooks
     *
     * Route: /wp-json/mailerpress/v1/webhooks/receive/{webhook_id}
     *
     * IMPORTANT: Les webhooks doivent inclure une signature HMAC SHA256
     * dans le header 'X-Webhook-Signature' au format 'sha256=<hash>'
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    #[Endpoint(
        'webhooks/receive/(?P<webhook_id>[a-zA-Z0-9_-]+)',
        methods: 'POST',
        permissionCallback: '__return_true'
    )]
    public function receiveWebhook(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $webhookId = $request->get_param('webhook_id');

        if (empty($webhookId)) {
            return new WP_Error(
                'invalid_webhook_id',
                __('Webhook ID is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Vérifier que la configuration du webhook existe
        $webhookConfig = $this->getManager()->getWebhookConfig($webhookId);

        if (!$webhookConfig) {
            return new WP_Error(
                'webhook_not_found',
                __('Webhook not found or has been deleted', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Vérifier si le webhook est activé
        if ($webhookConfig && isset($webhookConfig['enabled']) && !$webhookConfig['enabled']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Webhook is disabled', 'mailerpress'),
                'webhook_id' => $webhookId,
            ], 200);
        }

        // Récupérer le body brut pour la validation de signature
        $rawBody = $request->get_body();

        if (empty($rawBody)) {
            return new WP_Error(
                'empty_payload',
                __('Webhook payload is empty', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Vérifier si la signature est requise pour ce webhook (par défaut : non)
        $requireSignature = $webhookConfig['require_signature'] ?? false;

        if ($requireSignature) {
            // Récupérer le secret du webhook pour valider la signature
            $secret = $this->getManager()->getWebhookSecret($webhookId);

            if (empty($secret)) {
                return new WP_Error(
                    'webhook_configuration_error',
                    __('Webhook secret is not configured. Please regenerate the webhook secret in settings.', 'mailerpress'),
                    ['status' => 500]
                );
            }

            // Récupérer la signature du header
            $signature = $request->get_header('X-Webhook-Signature');

            if (empty($signature)) {
                // Compatibilité avec d'autres formats de header
                $signature = $request->get_header('X-Hub-Signature-256')
                          ?: $request->get_header('X-Signature');
            }

            if (empty($signature)) {
                return new WP_Error(
                    'missing_signature',
                    __('Webhook signature is missing. Please include X-Webhook-Signature header with sha256=<hmac> format.', 'mailerpress'),
                    ['status' => 401]
                );
            }

            // Valider la signature
            if (!WebhookDispatcher::verifySignature($rawBody, $signature, $secret)) {
                return new WP_Error(
                    'invalid_signature',
                    __('Webhook signature validation failed. The signature does not match the expected value.', 'mailerpress'),
                    ['status' => 401]
                );
            }
        }

        // Récupérer les données du webhook (maintenant qu'on a validé la signature)
        $payload = $request->get_json_params();

        if (empty($payload)) {
            $payload = $request->get_body_params();
        }

        if (empty($payload)) {
            // Si toujours vide, essayer de décoder le raw body
            $payload = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $payload = [];
            }
        }

        // Exécuter les handlers configurés pour ce webhook
        $handlerManager = \MailerPress\Core\Webhooks\IncomingWebhookHandlerManager::getInstance();
        $handlerResults = [];

        // Récupérer les handlers configurés pour ce webhook
        $configuredHandlers = $webhookConfig['handlers'] ?? [];
        $handlerConfig = $webhookConfig['handler_config'] ?? [];

        if (!empty($configuredHandlers) && is_array($configuredHandlers)) {
            // Exécuter uniquement les handlers configurés
            foreach ($configuredHandlers as $handlerId) {
                if ($handlerManager->isHandlerRegistered($handlerId)) {
                    try {
                        // Passer la configuration du handler au payload
                        $handlerPayload = $payload;
                        if (isset($handlerConfig[$handlerId])) {
                            $handlerPayload['_handler_config'] = $handlerConfig[$handlerId];
                        }

                        $result = $handlerManager->executeHandler($handlerId, $webhookId, $handlerPayload, $request);
                        $handlerResults[$handlerId] = $result;
                    } catch (\Exception $e) {
                        $handlerResults[$handlerId] = [
                            'success' => false,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
        }

        do_action('mailerpress_webhook_received', $webhookId, $payload, $request);

        // Retourner une réponse de succès avec les résultats des handlers
        return new WP_REST_Response([
            'success' => true,
            'message' => __('Webhook received successfully', 'mailerpress'),
            'webhook_id' => $webhookId,
            'handlers_executed' => $handlerResults,
        ], 200);
    }

    /**
     * Endpoint pour lister les webhooks configurés (pour debug/admin)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'webhooks',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function listWebhooks(WP_REST_Request $request): WP_REST_Response
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'error' => __('Webhooks require MailerPress Pro', 'mailerpress'),
            ], 403);
        }

        $webhooks = $this->getManager()->getAllWebhookConfigs();

        // Masquer les secrets dans la réponse
        foreach ($webhooks as &$webhook) {
            if (isset($webhook['secret'])) {
                $webhook['secret'] = '***';
            }
        }

        return new WP_REST_Response($webhooks, 200);
    }

    /**
     * Endpoint pour obtenir les configurations de webhooks entrants
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'webhooks/incoming',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getIncomingWebhooks(WP_REST_Request $request): WP_REST_Response
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'error' => __('Webhooks require MailerPress Pro', 'mailerpress'),
            ], 403);
        }

        $webhooks = $this->getManager()->getAllWebhookConfigs();

        // Masquer les secrets et ajouter les URLs
        foreach ($webhooks as &$webhook) {
            // Masquer le secret (afficher *** si défini)
            if (isset($webhook['secret']) && !empty($webhook['secret'])) {
                $webhook['secret'] = '***';
            }
            // Ajouter l'URL du webhook
            if (isset($webhook['id'])) {
                $webhook['url'] = rest_url('mailerpress/v1/webhooks/receive/' . $webhook['id']);
            }
        }

        // Récupérer les handlers disponibles
        $handlerManager = \MailerPress\Core\Webhooks\IncomingWebhookHandlerManager::getInstance();
        $availableHandlers = $handlerManager->getDefaultHandlersInfo();

        return new WP_REST_Response([
            'webhooks' => $webhooks,
            'available_handlers' => $availableHandlers,
        ], 200);
    }

    /**
     * Endpoint pour sauvegarder les configurations de webhooks entrants
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    #[Endpoint(
        'webhooks/incoming',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function saveIncomingWebhooks(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_Error(
                'pro_required',
                __('Webhooks require MailerPress Pro', 'mailerpress'),
                ['status' => 403]
            );
        }

        $webhooks = $request->get_json_params();

        if (empty($webhooks)) {
            $webhooks = $request->get_body_params();
        }

        if (!is_array($webhooks)) {
            return new WP_Error(
                'invalid_data',
                __('Invalid webhook data', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Récupérer tous les webhooks existants pour détecter les suppressions
        $existingWebhooks = $this->getManager()->getAllWebhookConfigs();
        $webhookIdsToKeep = [];

        foreach ($webhooks as $webhookId => $config) {
            if (!is_array($config)) {
                continue;
            }

            // Valider le format de l'ID
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $webhookId)) {
                continue;
            }

            $webhookIdsToKeep[] = $webhookId;

            // Préparer la configuration
            $webhookConfig = [
                'id' => $webhookId,
                'enabled' => isset($config['enabled']) ? (bool) $config['enabled'] : true,
                'description' => sanitize_text_field($config['description'] ?? ''),
                'require_signature' => isset($config['require_signature']) ? (bool) $config['require_signature'] : false,
                'handlers' => isset($config['handlers']) && is_array($config['handlers'])
                    ? array_map('sanitize_text_field', $config['handlers'])
                    : [],
                'handler_config' => isset($config['handler_config']) && is_array($config['handler_config'])
                    ? $config['handler_config']
                    : [],
            ];

            // Le secret n'est plus utilisé pour les webhooks entrants

            $this->getManager()->registerWebhookConfig($webhookId, $webhookConfig);

            // Vérifier que le secret a bien été sauvegardé
            $savedConfig = $this->getManager()->getWebhookConfig($webhookId);
        }

        // Supprimer les webhooks qui ne sont plus dans la liste
        foreach ($existingWebhooks as $existingId => $existingConfig) {
            if (!in_array($existingId, $webhookIdsToKeep, true)) {
                // Supprimer le webhook via le manager
                $this->getManager()->deleteWebhookConfig($existingId);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Incoming webhook settings saved successfully', 'mailerpress'),
        ], 200);
    }

    /**
     * Endpoint pour obtenir les événements disponibles
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'webhooks/events',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getEvents(WP_REST_Request $request): WP_REST_Response
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'error' => __('Webhooks require MailerPress Pro', 'mailerpress'),
            ], 403);
        }

        $events = $this->getManager()->getEventRegistry()->getEventInfo();
        return new WP_REST_Response($events, 200);
    }

    /**
     * Endpoint pour créer ou mettre à jour un webhook
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    #[Endpoint(
        'webhooks/(?P<webhook_id>[a-zA-Z0-9_-]+)',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function saveWebhook(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_Error(
                'pro_required',
                __('Webhooks require MailerPress Pro', 'mailerpress'),
                ['status' => 403]
            );
        }

        $webhookId = $request->get_param('webhook_id');

        if (empty($webhookId)) {
            return new WP_Error(
                'invalid_webhook_id',
                __('Webhook ID is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Valider le format de l'ID (lettres, chiffres, tirets, underscores uniquement)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $webhookId)) {
            return new WP_Error(
                'invalid_webhook_id_format',
                __('Webhook ID can only contain letters, numbers, hyphens, and underscores', 'mailerpress'),
                ['status' => 400]
            );
        }

        $config = [
            'id' => $webhookId,
            'secret' => sanitize_text_field($request->get_param('secret') ?? ''),
            'enabled' => (bool) ($request->get_param('enabled') ?? true),
            'description' => sanitize_text_field($request->get_param('description') ?? ''),
        ];

        // Si le secret est vide, générer un nouveau secret
        if (empty($config['secret'])) {
            $existingConfig = $this->getManager()->getWebhookConfig($webhookId);
            if (!$existingConfig || empty($existingConfig['secret'])) {
                // Générer un nouveau secret
                $config['secret'] = bin2hex(random_bytes(16));
            } else {
                // Garder le secret existant
                $config['secret'] = $existingConfig['secret'];
            }
        }

        $this->getManager()->registerWebhookConfig($webhookId, $config);

        // Masquer le secret dans la réponse
        $responseConfig = $config;
        $responseConfig['secret'] = '***';

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Webhook saved successfully', 'mailerpress'),
            'webhook' => $responseConfig,
        ], 200);
    }

    /**
     * Endpoint pour supprimer un webhook
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    #[Endpoint(
        'webhooks/(?P<webhook_id>[a-zA-Z0-9_-]+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function deleteWebhook(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_Error(
                'pro_required',
                __('Webhooks require MailerPress Pro', 'mailerpress'),
                ['status' => 403]
            );
        }

        $webhookId = $request->get_param('webhook_id');

        if (empty($webhookId)) {
            return new WP_Error(
                'invalid_webhook_id',
                __('Webhook ID is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        $configs = $this->getManager()->getAllWebhookConfigs();

        if (!isset($configs[$webhookId])) {
            return new WP_Error(
                'webhook_not_found',
                __('Webhook not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        unset($configs[$webhookId]);
        update_option('mailerpress_webhook_configs', $configs);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Webhook deleted successfully', 'mailerpress'),
        ], 200);
    }

    /**
     * Endpoint pour obtenir les configurations de webhooks sortants
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    /**
     * Single endpoint returning all data needed for the outgoing webhooks settings page.
     * Replaces 4 separate API calls with 1.
     */
    #[Endpoint(
        'webhooks/outgoing-settings',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getOutgoingSettings(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'error' => __('Webhooks require MailerPress Pro', 'mailerpress'),
            ], 403);
        }

        $events = $this->getManager()->getEventRegistry()->getEventInfo();
        $configs = $this->getManager()->getAllOutgoingWebhookConfigs();
        if (!is_array($configs)) {
            $configs = [];
        }

        // Mask secrets
        foreach ($configs as &$config) {
            if (isset($config['secret']) && !empty($config['secret'])) {
                $config['secret'] = '***';
            }
        }

        $globalSecret = get_option('mailerpress_outgoing_webhook_secret', '');
        $disableAsync = get_option('mailerpress_webhooks_disable_async', '0');

        return new WP_REST_Response([
            'events' => $events,
            'configs' => $configs,
            'global_secret' => !empty($globalSecret) ? '***' : '',
            'async_enabled' => !($disableAsync === '1' || $disableAsync === 1 || $disableAsync === true),
        ], 200);
    }

    #[Endpoint(
        'webhooks/outgoing',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getOutgoingWebhooks(WP_REST_Request $request): WP_REST_Response
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_REST_Response([
                'error' => __('Webhooks require MailerPress Pro', 'mailerpress'),
            ], 403);
        }

        $configs = $this->getManager()->getAllOutgoingWebhookConfigs();

        // S'assurer que c'est un tableau
        if (!is_array($configs)) {
            $configs = [];
        }

        // Masquer les secrets dans la réponse
        foreach ($configs as &$config) {
            if (isset($config['secret']) && !empty($config['secret'])) {
                $config['secret'] = '***';
            }
        }

        return new WP_REST_Response($configs, 200);
    }

    /**
     * Endpoint pour sauvegarder les configurations de webhooks sortants
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    #[Endpoint(
        'webhooks/outgoing',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function saveOutgoingWebhooks(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_Error(
                'pro_required',
                __('Webhooks require MailerPress Pro', 'mailerpress'),
                ['status' => 403]
            );
        }

        // Essayer d'abord get_json_params, puis get_body_params
        $configs = $request->get_json_params();

        if (empty($configs)) {
            $configs = $request->get_body_params();
        }

        // Si toujours vide, essayer de parser le body directement
        if (empty($configs)) {
            $body = $request->get_body();
            if (!empty($body)) {
                $decoded = json_decode($body, true);
                $jsonError = json_last_error();
                if ($jsonError === JSON_ERROR_NONE && is_array($decoded)) {
                    $configs = $decoded;
                }
            }
        }

        if (!is_array($configs)) {
            return new WP_Error(
                'invalid_data',
                __('Invalid configuration data', 'mailerpress'),
                ['status' => 400]
            );
        }

        if (empty($configs)) {
            // Ne pas retourner d'erreur si c'est vide, juste logger
        }

        // Charger les configurations existantes pour préserver les secrets non modifiés
        $existingConfigs = $this->getManager()->getAllOutgoingWebhookConfigs();

        foreach ($configs as $eventKey => $config) {
            if (!is_array($config)) {
                continue;
            }

            // S'assurer que 'enabled' est un booléen strict
            $config['enabled'] = isset($config['enabled']) && ($config['enabled'] === true || $config['enabled'] === 'true' || $config['enabled'] === 1 || $config['enabled'] === '1');

            // Si le secret est '***', garder le secret existant
            if (isset($config['secret']) && $config['secret'] === '***') {
                $existingConfig = $existingConfigs[$eventKey] ?? null;
                if ($existingConfig && !empty($existingConfig['secret'])) {
                    $config['secret'] = $existingConfig['secret'];
                } else {
                    unset($config['secret']);
                }
            }

            // Valider et nettoyer les URLs
            if (isset($config['urls']) && is_array($config['urls'])) {
                $config['urls'] = array_values(array_filter(
                    array_map('esc_url_raw', $config['urls']),
                    function ($url) {
                        return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
                    }
                ));
            } else {
                $config['urls'] = [];
            }

            // Le secret n'est plus géré par événement, mais globalement
            // On supprime toute référence au secret dans la config de l'événement
            if (isset($config['secret'])) {
                unset($config['secret']);
            }

            $this->getManager()->registerOutgoingWebhookConfig($eventKey, $config);
        }

        // Attendre un peu pour s'assurer que la sauvegarde est terminée
        usleep(100000); // 100ms

        // Vérifier que les données ont bien été sauvegardées
        $savedConfigs = $this->getManager()->getAllOutgoingWebhookConfigs();

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Outgoing webhook settings saved successfully', 'mailerpress'),
            'saved_count' => count($savedConfigs),
        ], 200);
    }

    /**
     * Endpoint pour régénérer le secret d'un webhook entrant
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    #[Endpoint(
        'webhooks/incoming/(?P<webhook_id>[a-zA-Z0-9_-]+)/regenerate-secret',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function regenerateWebhookSecret(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_Error(
                'pro_required',
                __('Webhooks require MailerPress Pro', 'mailerpress'),
                ['status' => 403]
            );
        }

        $webhookId = $request->get_param('webhook_id');

        if (empty($webhookId)) {
            return new WP_Error(
                'invalid_webhook_id',
                __('Webhook ID is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Vérifier que le webhook existe
        $webhookConfig = $this->getManager()->getWebhookConfig($webhookId);

        if (!$webhookConfig) {
            return new WP_Error(
                'webhook_not_found',
                __('Webhook not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Régénérer le secret
        $newSecret = $this->getManager()->regenerateWebhookSecret($webhookId);

        if (!$newSecret) {
            return new WP_Error(
                'regeneration_failed',
                __('Failed to regenerate webhook secret', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Webhook secret regenerated successfully', 'mailerpress'),
            'webhook_id' => $webhookId,
            'secret' => $newSecret,
        ], 200);
    }

    /**
     * Endpoint pour obtenir le secret d'un webhook (non masqué)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    #[Endpoint(
        'webhooks/incoming/(?P<webhook_id>[a-zA-Z0-9_-]+)/secret',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getWebhookSecret(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_Error(
                'pro_required',
                __('Webhooks require MailerPress Pro', 'mailerpress'),
                ['status' => 403]
            );
        }

        $webhookId = $request->get_param('webhook_id');

        if (empty($webhookId)) {
            return new WP_Error(
                'invalid_webhook_id',
                __('Webhook ID is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Vérifier que le webhook existe
        $secret = $this->getManager()->getWebhookSecret($webhookId);

        if (empty($secret)) {
            return new WP_Error(
                'secret_not_found',
                __('Webhook secret not found. Please regenerate the secret.', 'mailerpress'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'webhook_id' => $webhookId,
            'secret' => $secret,
        ], 200);
    }

    /**
     * Endpoint pour tester un webhook sortant
     * Envoie un payload de test vers les URLs configurées
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    #[Endpoint(
        'webhooks/outgoing/(?P<event_key>[a-zA-Z0-9_\\.\\-]+)/test',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageSettings']
    )]
    public function testOutgoingWebhook(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return new WP_Error(
                'pro_required',
                __('Webhooks require MailerPress Pro', 'mailerpress'),
                ['status' => 403]
            );
        }

        $eventKey = $request->get_param('event_key');

        if (empty($eventKey)) {
            return new WP_Error(
                'invalid_event_key',
                __('Event key is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Vérifier que l'événement existe
        $eventInfo = $this->getManager()->getEventRegistry()->getEventInfo()[$eventKey] ?? null;

        if (!$eventInfo) {
            return new WP_Error(
                'event_not_found',
                __('Event not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Récupérer la configuration du webhook
        // Priorité : données envoyées dans le body (permet de tester avant sauvegarde)
        $bodyUrls = $request->get_param('urls');
        $bodySecret = $request->get_param('secret');

        if (!empty($bodyUrls) && is_array($bodyUrls)) {
            $validatedUrls = array_filter(array_map('trim', $bodyUrls), function ($u) {
                return !empty($u) && wp_http_validate_url(esc_url_raw($u));
            });
            if (empty($validatedUrls)) {
                return new WP_Error(
                    'invalid_urls',
                    __('None of the provided URLs are valid', 'mailerpress'),
                    ['status' => 400]
                );
            }
            $config = ['urls' => $validatedUrls];
        } else {
            $config = $this->getManager()->getOutgoingWebhookConfig($eventKey);
        }

        if (!$config || empty($config['urls'])) {
            return new WP_Error(
                'no_urls_configured',
                __('No URLs configured for this webhook', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Vérifier que le secret global est configuré
        // Priorité : secret envoyé dans le body (permet de tester avant sauvegarde)
        $globalSecret = !empty($bodySecret) ? sanitize_text_field($bodySecret) : get_option('mailerpress_outgoing_webhook_secret', '');

        if (empty($globalSecret)) {
            return new WP_Error(
                'no_secret_configured',
                __('Global webhook secret is not configured. Please set a secret key in webhook settings.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Les données brutes du test — identiques aux vraies données envoyées par l'événement
        // Le champ _test permet au service destinataire de distinguer un test d'un vrai événement
        $testData = array_merge($this->getTestPayloadForEvent($eventKey), ['_test' => true]);

        $urls = is_array($config['urls']) ? $config['urls'] : [$config['urls']];
        $urls = array_filter($urls);

        if (empty($urls)) {
            return new WP_Error(
                'no_valid_urls',
                __('No valid URLs to test', 'mailerpress'),
                ['status' => 400]
            );
        }

        $options = [
            'secret' => $globalSecret,
        ];

        $results = [];
        $sentEvent = null;

        // Envoyer le webhook de test vers chaque URL (mode synchrone pour voir les résultats immédiatement)
        foreach ($urls as $url) {
            try {
                // Créer l'événement de test avec les mêmes données que le vrai événement
                $event = $this->getManager()->getEventRegistry()->create($eventKey, $testData);

                if (!$event) {
                    $results[$url] = [
                        'success' => false,
                        'error' => __('Failed to create test event', 'mailerpress'),
                        'status_code' => 0,
                    ];
                    continue;
                }

                // Conserver le payload pour l'afficher dans la réponse
                if ($sentEvent === null) {
                    $sentEvent = $event;
                }

                // Envoyer le webhook
                $response = $this->getManager()->getDispatcher()->dispatch($url, $event, $options);

                if (is_wp_error($response)) {
                    $results[$url] = [
                        'success' => false,
                        'error' => $response->get_error_message(),
                        'status_code' => 0,
                    ];
                } else {
                    $statusCode = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);

                    $results[$url] = [
                        'success' => $statusCode >= 200 && $statusCode < 300,
                        'status_code' => $statusCode,
                        'response_body' => $body ? substr($body, 0, 500) : '',
                    ];
                }
            } catch (\Exception $e) {
                $results[$url] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'status_code' => 0,
                ];
            }
        }

        // Déterminer si au moins une URL a réussi
        $hasSuccess = false;
        foreach ($results as $result) {
            if ($result['success']) {
                $hasSuccess = true;
                break;
            }
        }

        // Payload exact tel qu'il a été envoyé (même structure que les vrais événements)
        $sentPayload = $sentEvent ? $sentEvent->getPayload() : null;

        return new WP_REST_Response([
            'success' => $hasSuccess,
            'message' => $hasSuccess
                ? __('Test webhook sent successfully', 'mailerpress')
                : __('All test webhooks failed', 'mailerpress'),
            'results' => $results,
            'test_payload' => $sentPayload,
        ], 200);
    }

    /**
     * Génère un payload de test adapté à chaque type d'événement
     *
     * @param string $eventKey
     * @return array
     */
    private function getTestPayloadForEvent(string $eventKey): array
    {
        // Timestamp actuel pour les tests
        $now = current_time('mysql');

        // Personnaliser selon le type d'événement
        switch ($eventKey) {
            case 'contact.created':
            case 'contact.updated':
                return [
                    'contact_id' => 12345,
                    'email' => 'john.doe@example.com',
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'subscription_status' => 'subscribed',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'tags' => [
                        ['tag_id' => 1, 'tag_name' => 'VIP'],
                        ['tag_id' => 2, 'tag_name' => 'Customer'],
                    ],
                    'lists' => [
                        ['list_id' => 1, 'list_name' => 'Newsletter'],
                        ['list_id' => 2, 'list_name' => 'Promotions'],
                    ],
                    'custom_fields' => [
                        'company' => 'Acme Inc',
                        'phone' => '+1234567890',
                        'country' => 'US',
                    ],
                ];

            case 'contact.deleted':
                return [
                    'contact_id' => 12345,
                    'email' => 'john.doe@example.com',
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'deleted_at' => $now,
                ];

            case 'contact.tag.added':
            case 'contact.tag.removed':
                return [
                    'contact_id' => 12345,
                    'contact_email' => 'john.doe@example.com',
                    'tag_id' => 5,
                    'tag_name' => 'VIP',
                ];

            case 'contact.list.added':
            case 'contact.list.removed':
                return [
                    'contact_id' => 12345,
                    'contact_email' => 'john.doe@example.com',
                    'list_id' => 3,
                    'list_name' => 'Newsletter',
                ];

            case 'contact.custom_field.updated':
                return [
                    'contact_id' => 12345,
                    'contact_email' => 'john.doe@example.com',
                    'field_key' => 'company',
                    'field_value' => 'Acme Inc',
                ];

            case 'subscription.confirmed':
                return [
                    'contact_id' => 12345,
                    'email' => 'john.doe@example.com',
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'confirmed_at' => $now,
                    'optin_ip' => '192.168.1.1',
                ];

            case 'contact.unsubscribed':
            case 'subscription.unsubscribed':
                return [
                    'contact_id' => 12345,
                    'email' => 'john.doe@example.com',
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'unsubscribed_at' => $now,
                ];

            case 'campaign.created':
                return [
                    'campaign_id' => 123,
                    'campaign_name' => 'Summer Sale 2026',
                    'campaign_subject' => 'Get 50% off this summer!',
                    'status' => 'draft',
                    'campaign_type' => 'newsletter',
                    'created_at' => $now,
                ];

            case 'campaign.sent':
                return [
                    'campaign_id' => 123,
                    'campaign_name' => 'Summer Sale 2026',
                    'campaign_subject' => 'Get 50% off this summer!',
                    'sent_at' => $now,
                    'recipients_count' => 1542,
                    'sent_count' => 1542,
                    'status' => 'sent',
                ];

            case 'campaign.scheduled':
                return [
                    'campaign_id' => 123,
                    'campaign_name' => 'Summer Sale 2026',
                    'campaign_subject' => 'Get 50% off this summer!',
                    'status' => 'scheduled',
                    'campaign_type' => 'newsletter',
                    'created_at' => $now,
                    'scheduled_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
                ];

            case 'campaign.in_progress':
                return [
                    'campaign_id' => 123,
                    'campaign_name' => 'Summer Sale 2026',
                    'status' => 'sending',
                    'total_emails' => 1542,
                    'sent_emails' => 856,
                    'failed_emails' => 12,
                    'progress_percentage' => 55.51,
                ];

            case 'email.opened':
                return [
                    'contact_id' => 12345,
                    'contact_email' => 'john.doe@example.com',
                    'campaign_id' => 123,
                    'campaign_name' => 'Summer Sale 2026',
                    'campaign_subject' => 'Get 50% off this summer!',
                    'batch_id' => 456,
                    'opened_at' => $now,
                ];

            case 'email.clicked':
                return [
                    'contact_id' => 12345,
                    'contact_email' => 'john.doe@example.com',
                    'campaign_id' => 123,
                    'campaign_name' => 'Summer Sale 2026',
                    'campaign_subject' => 'Get 50% off this summer!',
                    'url' => 'https://example.com/summer-sale',
                    'clicked_at' => $now,
                ];

            case 'email.bounced':
                return [
                    'contact_id' => 12345,
                    'email' => 'john.doe@example.com',
                    'bounced_at' => $now,
                    'bounce_type' => 'hard',
                ];

            case 'email.complained':
                return [
                    'contact_id' => 12345,
                    'email' => 'john.doe@example.com',
                    'complained_at' => $now,
                ];

            default:
                // Payload générique pour les événements non définis
                return [
                    'id' => 12345,
                    'timestamp' => $now,
                    'data' => 'Test data for ' . $eventKey,
                ];
        }
    }
}
