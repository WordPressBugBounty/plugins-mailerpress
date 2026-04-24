<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks\Events;

\defined('ABSPATH') || exit;

/**
 * Événement : Campagne créée
 * 
 * @since 1.2.0
 */
class CampaignCreatedEvent extends AbstractWebhookEvent
{
    public function getKey(): string
    {
        return 'campaign.created';
    }

    public function getName(): string
    {
        return __('Campaign Created', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Triggered when a new campaign is created in MailerPress', 'mailerpress');
    }
}

