<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Lien cliqué dans un email
 *
 * Déclenché lorsqu'un contact clique sur un lien dans un email
 *
 * @since 1.5.0
 */
class EmailClickedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'email.clicked';
    }

    public function getName(): string
    {
        return __('Email Link Clicked', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a contact clicks a link in an email', 'mailerpress');
    }
}
