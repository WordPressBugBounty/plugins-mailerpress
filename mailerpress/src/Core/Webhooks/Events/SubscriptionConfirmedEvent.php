<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Abonnement confirmé (double opt-in)
 *
 * Déclenché lorsqu'un contact confirme son abonnement via double opt-in
 *
 * @since 1.5.0
 */
class SubscriptionConfirmedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'subscription.confirmed';
    }

    public function getName(): string
    {
        return __('Subscription Confirmed', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a contact confirms their subscription (double opt-in)', 'mailerpress');
    }
}
