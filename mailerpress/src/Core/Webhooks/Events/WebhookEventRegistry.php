<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Registre des événements de webhook
 * 
 * Permet d'enregistrer et de créer des événements personnalisés
 * 
 * @since 1.2.0
 */
class WebhookEventRegistry
{
    private array $events = [];

    /**
     * Enregistre un événement
     * 
     * @param string $key Clé unique de l'événement
     * @param string $class Classe de l'événement
     * @return void
     */
    public function register(string $key, string $class): void
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Class {$class} does not exist");
        }

        if (!is_subclass_of($class, WebhookEventInterface::class)) {
            throw new \InvalidArgumentException("Class {$class} must implement WebhookEventInterface");
        }

        $this->events[$key] = $class;
    }

    /**
     * Crée une instance d'événement
     * 
     * @param string $key Clé de l'événement
     * @param array $data Données de l'événement
     * @return WebhookEventInterface|null
     */
    public function create(string $key, array $data = []): ?WebhookEventInterface
    {
        if (!isset($this->events[$key])) {
            return null;
        }

        $class = $this->events[$key];
        
        return new $class($data);
    }

    /**
     * Vérifie si un événement est enregistré
     * 
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->events[$key]);
    }

    /**
     * Retourne tous les événements enregistrés
     * 
     * @return array
     */
    public function getAll(): array
    {
        return $this->events;
    }

    /**
     * Retourne les informations de tous les événements
     * 
     * @return array
     */
    public function getEventInfo(): array
    {
        $info = [];

        foreach ($this->events as $key => $class) {
            try {
                $instance = new $class([]);
                $info[$key] = [
                    'key' => $key,
                    'name' => $instance->getName(),
                    'description' => $instance->getDescription(),
                ];
            } catch (\Exception $e) {
                // Ignore les erreurs de création
            }
        }

        return $info;
    }
}

