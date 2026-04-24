<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Classe abstraite de base pour les événements de webhook
 * 
 * Fournit une implémentation de base pour faciliter la création d'événements personnalisés
 * 
 * @since 1.2.0
 */
abstract class AbstractWebhookEvent implements WebhookEventInterface
{
    protected array $data;
    protected array $metadata;

    /**
     * @param array $data Données de l'événement
     * @param array $metadata Métadonnées optionnelles
     */
    public function __construct(array $data = [], array $metadata = [])
    {
        $this->data = $data;
        $this->metadata = array_merge([
            'timestamp' => current_time('mysql'),
            'event_key' => $this->getKey(),
            'event_name' => $this->getName(),
        ], $metadata);
    }

    /**
     * Retourne les données de l'événement
     * 
     * @return array
     */
    public function getPayload(): array
    {
        return [
            'event' => $this->getKey(),
            'data' => $this->data,
            'metadata' => $this->getMetadata(),
        ];
    }

    /**
     * Retourne les métadonnées de l'événement
     * 
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Définit une donnée
     * 
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setData(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Récupère une donnée
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getData(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Définit une métadonnée
     * 
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }
}

