<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Contact ajouté à une liste
 * 
 * @since 1.2.0
 */
class ContactListAddedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.list.added';
    }

    public function getName(): string
    {
        return __('Contact Added to List', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a contact is added to a list', 'mailerpress');
    }
}

