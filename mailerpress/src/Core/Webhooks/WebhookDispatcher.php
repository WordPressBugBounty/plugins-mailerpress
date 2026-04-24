<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks;

\defined('ABSPATH') || exit;

use MailerPress\Core\Webhooks\Events\WebhookEventInterface;

/**
 * Gestionnaire d'envoi de webhooks
 * 
 * Permet d'envoyer des webhooks vers des URLs externes
 * 
 * @since 1.2.0
 */
class WebhookDispatcher
{
    private array $defaultHeaders;

    public function __construct()
    {
        $this->defaultHeaders = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'MailerPress/1.2.0',
        ];
    }

    /**
     * Envoie un webhook vers une URL
     * 
     * @param string $url URL de destination
     * @param WebhookEventInterface $event Événement à envoyer
     * @param array $options Options supplémentaires (headers, timeout, etc.)
     * @return array Réponse WordPress avec 'response', 'body', 'headers'
     * @throws \Exception
     */
    public function dispatch(string $url, WebhookEventInterface $event, array $options = []): array
    {
        $headers = array_merge(
            $this->defaultHeaders,
            $options['headers'] ?? []
        );

        // La signature est obligatoire pour la sécurité
        if (empty($options['secret'])) {
            throw new \InvalidArgumentException('Secret key is required for webhook security. Cannot send webhook without signature.');
        }

        $payload = json_encode($event->getPayload());
        $signature = $this->generateSignature($payload, $options['secret']);
        $headers['X-Webhook-Signature'] = $signature;

        $timeout = $options['timeout'] ?? 30;

        // Validate URL against SSRF (block internal/private IPs)
        if (!wp_http_validate_url($url)) {
            throw new \InvalidArgumentException('Invalid webhook URL: ' . esc_url($url));
        }

        // Utiliser wp_safe_remote_post de WordPress
        $response = wp_safe_remote_post($url, [
            'headers' => $headers,
            'body' => $payload,
            'timeout' => $timeout,
            'sslverify' => true,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Webhook request failed: ' . $response->get_error_message());
        }

        return $response;
    }

    /**
     * Envoie un webhook vers plusieurs URLs
     * 
     * @param array $urls Liste des URLs
     * @param WebhookEventInterface $event Événement à envoyer
     * @param array $options Options supplémentaires
     * @return array Tableau de réponses indexées par URL
     */
    public function dispatchMultiple(array $urls, WebhookEventInterface $event, array $options = []): array
    {
        $responses = [];

        foreach ($urls as $url) {
            try {
                $response = $this->dispatch($url, $event, $options);
                $statusCode = wp_remote_retrieve_response_code($response);
                $responses[$url] = $response;
            } catch (\Exception $e) {
                $responses[$url] = [
                    'error' => true,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $responses;
    }

    /**
     * Génère une signature HMAC pour sécuriser le webhook
     * 
     * @param string $payload
     * @param string $secret
     * @return string
     */
    private function generateSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Vérifie la signature d'un webhook reçu
     * 
     * @param string $payload
     * @param string $signature
     * @param string $secret
     * @return bool
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        if (!str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }
}
