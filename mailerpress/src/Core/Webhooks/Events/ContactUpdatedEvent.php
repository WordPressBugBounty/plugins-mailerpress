<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Contact mis à jour
 * 
 * @since 1.2.0
 */
class ContactUpdatedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.updated';
    }

    public function getName(): string
    {
        return __('Contact Updated', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a contact is updated in MailerPress', 'mailerpress');
    }
}

