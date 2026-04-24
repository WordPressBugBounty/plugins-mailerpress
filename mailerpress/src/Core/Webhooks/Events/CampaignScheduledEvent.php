<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Campagne planifiée
 *
 * Déclenché lorsqu'une campagne est planifiée pour un envoi ultérieur
 *
 * @since 1.2.0
 */
class CampaignScheduledEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'campaign.scheduled';
    }

    public function getName(): string
    {
        return __('Campaign Scheduled', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a campaign is scheduled', 'mailerpress');
    }
}
