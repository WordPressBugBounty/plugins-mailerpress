<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Contact désinscrit
 *
 * Déclenché lorsqu'un contact se désinscrit
 *
 * @since 1.5.0
 */
class ContactUnsubscribedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.unsubscribed';
    }

    public function getName(): string
    {
        return __('Contact Unsubscribed', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a contact unsubscribes', 'mailerpress');
    }
}
