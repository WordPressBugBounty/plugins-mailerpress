<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Notification shown when the primary provider is disabled
 */
class PrimaryProviderDisabledNotification extends AbstractNotificationMessage
{
    protected string $type = 'error';
    protected ?int $duration = null; // Manual dismiss only
    protected bool $dismissible = false; // Cannot be dismissed

    public function getMessage(): string
    {
        return __('Your primary email provider is disabled. MailerPress will not be able to send emails.', 'mailerpress');
    }

    public function shouldDisplay(): bool
    {
        // Check if primary provider is disabled
        $options = get_option('mailerpress_providers', []);
        $primary = $options['primary'] ?? null;

        if (!$primary) {
            return false;
        }

        $providers = $options['list'] ?? [];
        return isset($providers[$primary]) && !($providers[$primary]['enabled'] ?? false);
    }

    public function getAction(): ?array
    {
        return [
            'label' => __('Configure', 'mailerpress'),
            'url' => admin_url('admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings&activeView=Providers'),
        ];
    }
}
