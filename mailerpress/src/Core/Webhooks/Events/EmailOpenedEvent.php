<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Email ouvert
 *
 * Déclenché lorsqu'un contact ouvre un email
 *
 * @since 1.5.0
 */
class EmailOpenedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'email.opened';
    }

    public function getName(): string
    {
        return __('Email Opened', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a contact opens an email', 'mailerpress');
    }
}
