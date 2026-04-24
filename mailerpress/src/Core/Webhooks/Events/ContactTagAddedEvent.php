<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Tag ajouté à un contact
 * 
 * @since 1.2.0
 */
class ContactTagAddedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.tag.added';
    }

    public function getName(): string
    {
        return __('Contact Tag Added', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a tag is added to a contact', 'mailerpress');
    }
}

