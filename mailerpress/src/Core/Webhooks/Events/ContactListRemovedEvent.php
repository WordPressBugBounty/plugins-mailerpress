<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Contact retiré d'une liste
 * 
 * @since 1.2.0
 */
class ContactListRemovedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.list.removed';
    }

    public function getName(): string
    {
        return __('Contact Removed from List', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a contact is removed from a list', 'mailerpress');
    }
}

