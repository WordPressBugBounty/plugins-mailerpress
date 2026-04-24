<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Contact supprimé
 *
 * Déclenché lorsqu'un contact est supprimé de MailerPress
 *
 * @since 1.5.0
 */
class ContactDeletedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'contact.deleted';
    }

    public function getName(): string
    {
        return __('Contact Deleted', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a contact is deleted from MailerPress', 'mailerpress');
    }
}
