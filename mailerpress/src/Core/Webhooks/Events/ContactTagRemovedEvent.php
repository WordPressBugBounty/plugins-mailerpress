<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Tag retiré d'un contact
 * 
 * @since 1.2.0
 */
class ContactTagRemovedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.tag.removed';
    }

    public function getName(): string
    {
        return __('Contact Tag Removed', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a tag is removed from a contact', 'mailerpress');
    }
}

