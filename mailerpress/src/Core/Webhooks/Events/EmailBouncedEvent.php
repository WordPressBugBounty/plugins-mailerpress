<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Email rebondi
 *
 * Déclenché lorsqu'un email rebondit (hard bounce)
 *
 * @since 1.5.0
 */
class EmailBouncedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'email.bounced';
    }

    public function getName(): string
    {
        return __('Email Bounced', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when an email bounces (hard bounce)', 'mailerpress');
    }
}
