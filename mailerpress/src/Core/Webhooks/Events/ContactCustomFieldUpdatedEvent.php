<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Champ personnalisé d'un contact mis à jour
 * 
 * @since 1.2.0
 */
class ContactCustomFieldUpdatedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.custom_field.updated';
    }

    public function getName(): string
    {
        return __('Contact Custom Field Updated', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a custom field of a contact is updated', 'mailerpress');
    }
}

