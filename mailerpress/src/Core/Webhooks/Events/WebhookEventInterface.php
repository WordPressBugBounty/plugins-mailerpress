<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Interface pour les événements de webhook
 * 
 * Permet de créer des événements personnalisés pour l'envoi de webhooks
 * 
 * @since 1.2.0
 */
interface WebhookEventInterface
{
    /**
     * Retourne la clé unique de l'événement
     * 
     * @return string
     */
    public function getKey(): string;

    /**
     * Retourne le nom de l'événement
     * 
     * @return string
     */
    public function getName(): string;

    /**
     * Retourne la description de l'événement
     * 
     * @return string
     */
    public function getDescription(): string;

    /**
     * Retourne les données de l'événement à envoyer
     * 
     * @return array
     */
    public function getPayload(): array;

    /**
     * Retourne les métadonnées de l'événement
     * 
     * @return array
     */
    public function getMetadata(): array;
}

