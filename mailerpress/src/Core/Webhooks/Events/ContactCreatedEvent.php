<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Contact créé
 * 
 * Exemple d'événement pour l'envoi de webhooks
 * 
 * @since 1.2.0
 */
class ContactCreatedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.created';
    }

    public function getName(): string
    {
        return __('Contact Created', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a new contact is created in MailerPress', 'mailerpress');
    }
}

