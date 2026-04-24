<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks;

\defined('ABSPATH') || exit;

use MailerPress\Core\Webhooks\Events\WebhookEventInterface;
use MailerPress\Core\Webhooks\Events\WebhookEventRegistry;

/**
 * Gestionnaire principal des webhooks
 * 
 * Orchestre la réception et l'envoi de webhooks
 * 
 * @since 1.2.0
 */
class WebhookManager
{
    private WebhookDispatcher $dispatcher;
    private WebhookEventRegistry $eventRegistry;
    private array $webhookConfigs = [];
    private static ?WebhookManager $instance = null;

    public function __construct(?WebhookDispatcher $dispatcher = null)
    {
        $this->dispatcher = $dispatcher ?? new WebhookDispatcher();
        $this->eventRegistry = new WebhookEventRegistry();
        $this->loadWebhookConfigs();
    }

    /**
     * Instance singleton
     * 
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Vérifie si MailerPress Pro est actif
     * 
     * @return bool True si Pro est actif
     */
    private function isProActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active')
            && is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    /**
     * Enregistre un événement personnalisé
     * 
     * @param string $key Clé unique de l'événement
     * @param string $class Classe de l'événement (doit implémenter WebhookEventInterface)
     * @return void
     */
    public function registerEvent(string $key, string $class): void
    {
        $this->eventRegistry->register($key, $class);
    }

    /**
     * Crée et envoie un webhook
     * 
     * @param string $eventKey Clé de l'événement
     * @param array $data Données de l'événement
     * @param array $options Options d'envoi (URL, secret, etc.)
     * @return array|null Réponse WordPress ou null en cas d'erreur
     */
    public function sendWebhook(string $eventKey, array $data = [], array $options = []): ?array
    {
        $event = $this->eventRegistry->create($eventKey, $data);

        if (!$event) {
            return null;
        }

        $url = $options['url'] ?? null;

        if (empty($url)) {
            return null;
        }

        try {
            return $this->dispatcher->dispatch($url, $event, $options);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Envoie un webhook vers plusieurs URLs
     * 
     * @param string $eventKey Clé de l'événement
     * @param array $data Données de l'événement
     * @param array $urls Liste des URLs
     * @param array $options Options d'envoi
     * @return array
     */
    public function sendWebhookMultiple(string $eventKey, array $data = [], array $urls = [], array $options = []): array
    {
        $event = $this->eventRegistry->create($eventKey, $data);

        if (!$event) {
            return [];
        }

        try {
            $responses = $this->dispatcher->dispatchMultiple($urls, $event, $options);return $responses;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Enregistre une configuration de webhook entrant
     *
     * @param string $webhookId Identifiant unique du webhook
     * @param array $config Configuration (description, enabled, handlers, etc.)
     * @return void
     */
    public function registerWebhookConfig(string $webhookId, array $config = []): void
    {
        // Préserver la configuration existante si elle existe
        $existingConfig = $this->webhookConfigs[$webhookId] ?? [];

        // Fusionner avec les valeurs par défaut, puis avec l'existant, puis avec la nouvelle config
        $mergedConfig = array_merge([
            'id' => $webhookId,
            'enabled' => true,
        ], $existingConfig, $config);

        // Générer un secret sécurisé si ce n'est pas un webhook existant avec secret
        // ou si le secret est explicitement demandé dans la config
        if (!isset($existingConfig['secret']) || (isset($config['regenerate_secret']) && $config['regenerate_secret'])) {
            $mergedConfig['secret'] = $this->generateWebhookSecret();
        } elseif (isset($existingConfig['secret'])) {
            // Préserver le secret existant
            $mergedConfig['secret'] = $existingConfig['secret'];
        }

        // Nettoyer le flag de régénération
        unset($mergedConfig['regenerate_secret']);

        $this->webhookConfigs[$webhookId] = $mergedConfig;

        $this->saveWebhookConfigs();
    }

    /**
     * Génère un secret sécurisé pour un webhook
     *
     * @return string Secret de 64 caractères hexadécimaux (32 bytes)
     */
    private function generateWebhookSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Régénère le secret d'un webhook entrant
     *
     * @param string $webhookId Identifiant du webhook
     * @return string|null Le nouveau secret ou null si le webhook n'existe pas
     */
    public function regenerateWebhookSecret(string $webhookId): ?string
    {
        if (!isset($this->webhookConfigs[$webhookId])) {
            return null;
        }

        $config = $this->webhookConfigs[$webhookId];
        $config['regenerate_secret'] = true;
        $this->registerWebhookConfig($webhookId, $config);

        return $this->webhookConfigs[$webhookId]['secret'] ?? null;
    }

    /**
     * Récupère la configuration d'un webhook
     *
     * @param string $webhookId
     * @param bool $maskSecret Si true, masque le secret avec '***'
     * @return array|null
     */
    public function getWebhookConfig(string $webhookId, bool $maskSecret = false): ?array
    {
        $config = $this->webhookConfigs[$webhookId] ?? null;

        if ($config && $maskSecret && isset($config['secret'])) {
            $config['secret'] = '***';
        }

        return $config;
    }

    /**
     * Récupère le secret d'un webhook (non masqué)
     *
     * @param string $webhookId
     * @return string|null
     */
    public function getWebhookSecret(string $webhookId): ?string
    {
        $config = $this->webhookConfigs[$webhookId] ?? null;
        return $config['secret'] ?? null;
    }

    /**
     * Récupère toutes les configurations de webhooks
     * 
     * @return array
     */
    public function getAllWebhookConfigs(): array
    {
        return $this->webhookConfigs;
    }

    /**
     * Supprime une configuration de webhook
     * 
     * @param string $webhookId
     * @return void
     */
    public function deleteWebhookConfig(string $webhookId): void
    {
        if (isset($this->webhookConfigs[$webhookId])) {
            unset($this->webhookConfigs[$webhookId]);
            $this->saveWebhookConfigs();
        }
    }

    /**
     * Charge les configurations depuis la base de données
     * 
     * @return void
     */
    private function loadWebhookConfigs(): void
    {
        $configs = get_option('mailerpress_webhook_configs', []);

        if (is_array($configs)) {
            $this->webhookConfigs = $configs;
        }
    }

    /**
     * Sauvegarde les configurations dans la base de données
     * 
     * @return void
     */
    private function saveWebhookConfigs(): void
    {
        $result = update_option('mailerpress_webhook_configs', $this->webhookConfigs);
        // Vérifier immédiatement après la sauvegarde
        $saved = get_option('mailerpress_webhook_configs', []);
    }

    /**
     * Retourne le registre d'événements
     * 
     * @return WebhookEventRegistry
     */
    public function getEventRegistry(): WebhookEventRegistry
    {
        return $this->eventRegistry;
    }

    /**
     * Retourne le dispatcher
     * 
     * @return WebhookDispatcher
     */
    public function getDispatcher(): WebhookDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * Enregistre une configuration de webhook sortant (outgoing)
     * 
     * @param string $eventKey Clé de l'événement
     * @param array $config Configuration (urls, secret, enabled)
     * @return void
     */
    public function registerOutgoingWebhookConfig(string $eventKey, array $config = []): void
    {
        $optionValue = get_option('mailerpress_outgoing_webhook_configs', []);

        // L'option peut être stockée en JSON string (via ApiService.createOption) ou directement en array
        $outgoingConfigs = [];
        if (is_string($optionValue)) {
            $decoded = json_decode($optionValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $outgoingConfigs = $decoded;
            } else {
                $outgoingConfigs = [];
            }
        } elseif (is_array($optionValue)) {
            $outgoingConfigs = $optionValue;
        }

        // Préserver les valeurs existantes si elles ne sont pas fournies
        $existingConfig = $outgoingConfigs[$eventKey] ?? [];

        // Construire la nouvelle configuration
        $newConfig = [
            'enabled' => isset($config['enabled']) ? (bool) $config['enabled'] : ($existingConfig['enabled'] ?? false),
            'urls' => isset($config['urls']) && is_array($config['urls']) ? $config['urls'] : ($existingConfig['urls'] ?? []),
            'secret' => isset($config['secret']) && !empty($config['secret']) ? $config['secret'] : ($existingConfig['secret'] ?? ''),
        ];

        $outgoingConfigs[$eventKey] = $newConfig;

        $result = update_option('mailerpress_outgoing_webhook_configs', $outgoingConfigs);

        // Vérifier immédiatement après la sauvegarde
        $verifyValue = get_option('mailerpress_outgoing_webhook_configs', []);

        // Décode si c'est une string JSON
        $verify = [];
        if (is_string($verifyValue)) {
            $decoded = json_decode($verifyValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $verify = $decoded;
            }
        } elseif (is_array($verifyValue)) {
            $verify = $verifyValue;
        }
    }

    /**
     * Récupère la configuration d'un webhook sortant
     * 
     * @param string $eventKey
     * @return array|null
     */
    public function getOutgoingWebhookConfig(string $eventKey): ?array
    {
        $optionValue = get_option('mailerpress_outgoing_webhook_configs', []);

        // L'option peut être stockée en JSON string (via ApiService.createOption) ou directement en array
        $outgoingConfigs = [];
        if (is_string($optionValue)) {
            $decoded = json_decode($optionValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $outgoingConfigs = $decoded;
            }
        } elseif (is_array($optionValue)) {
            $outgoingConfigs = $optionValue;
        }
        $config = $outgoingConfigs[$eventKey] ?? null;

        return $config;
    }

    /**
     * Récupère toutes les configurations de webhooks sortants
     * 
     * @return array
     */
    public function getAllOutgoingWebhookConfigs(): array
    {
        $optionValue = get_option('mailerpress_outgoing_webhook_configs', []);

        // L'option peut être stockée en JSON string (via ApiService.createOption) ou directement en array
        $configs = [];
        if (is_string($optionValue)) {
            $decoded = json_decode($optionValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $configs = $decoded;
            }
        } elseif (is_array($optionValue)) {
            $configs = $optionValue;
        }

        // Normaliser les configurations pour s'assurer qu'elles ont la bonne structure
        foreach ($configs as $key => &$config) {
            if (!is_array($config)) {
                unset($configs[$key]);
                continue;
            }

            // S'assurer que toutes les clés nécessaires sont présentes
            $config = array_merge([
                'enabled' => false,
                'urls' => [],
                'secret' => '',
            ], $config);
        }

        return $configs;
    }

    /**
     * Envoie automatiquement un webhook si configuré
     *
     * Par défaut, utilise Action Scheduler pour l'envoi asynchrone.
     * Cela améliore les performances en ne bloquant pas l'exécution PHP.
     *
     * @param string $eventKey Clé de l'événement
     * @param array $data Données de l'événement
     * @param bool $async Si true (défaut), envoie de manière asynchrone via Action Scheduler
     * @return void
     */
    public function triggerOutgoingWebhook(string $eventKey, array $data = [], bool $async = true): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        $config = $this->getOutgoingWebhookConfig($eventKey);
        if (!$config) {
            return;
        }

        if (empty($config['enabled'])) {
            return;
        }

        if (empty($config['urls'])) {
            return;
        }

        $urls = is_array($config['urls']) ? $config['urls'] : [$config['urls']];
        $urls = array_filter($urls); // Enlever les URLs vides

        if (empty($urls)) {
            return;
        }

        // Récupérer le secret global (obligatoire pour la sécurité)
        $globalSecret = get_option('mailerpress_outgoing_webhook_secret', '');

        if (empty($globalSecret)) {
            return;
        }

        $options = [
            'secret' => $globalSecret,
        ];

        // Vérifier si l'envoi asynchrone est désactivé globalement
        $disableAsync = get_option('mailerpress_webhooks_disable_async', false);
        if ($disableAsync) {
            $async = false;
        }

        if ($async && function_exists('as_schedule_single_action')) {
            // Envoi asynchrone via Action Scheduler
            $this->queueOutgoingWebhook($eventKey, $data, $urls, $options);
        } else {
            // Envoi synchrone (bloquant)
            $this->sendWebhookMultiple($eventKey, $data, $urls, $options);
        }
    }

    /**
     * Envoie un webhook de manière asynchrone via Action Scheduler
     *
     * @param string $eventKey Clé de l'événement
     * @param array $data Données de l'événement
     * @param array $urls URLs de destination
     * @param array $options Options d'envoi
     * @return void
     */
    private function queueOutgoingWebhook(string $eventKey, array $data, array $urls, array $options): void
    {
        // Planifier l'envoi immédiat via Action Scheduler
        as_enqueue_async_action(
            'mailerpress_process_outgoing_webhook',
            [
                'event_key' => $eventKey,
                'data' => $data,
                'urls' => $urls,
                'options' => $options,
                'attempt_number' => 1,
            ],
            'mailerpress-webhooks'
        );
    }

    /**
     * Active ou désactive l'envoi asynchrone des webhooks
     *
     * @param bool $enabled
     * @return void
     */
    public function setAsyncWebhooksEnabled(bool $enabled): void
    {
        update_option('mailerpress_webhooks_disable_async', !$enabled);
    }

    /**
     * Vérifie si l'envoi asynchrone est activé
     *
     * @return bool
     */
    public function isAsyncWebhooksEnabled(): bool
    {
        return !get_option('mailerpress_webhooks_disable_async', false) && function_exists('as_schedule_single_action');
    }
}
