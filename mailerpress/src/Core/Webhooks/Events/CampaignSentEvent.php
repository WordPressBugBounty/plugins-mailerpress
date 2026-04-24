<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Campagne envoyée
 * 
 * Exemple d'événement pour l'envoi de webhooks
 * 
 * @since 1.2.0
 */
class CampaignSentEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'campaign.sent';
    }

    public function getName(): string
    {
        return __('Campaign Sent', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a campaign is sent', 'mailerpress');
    }
}

