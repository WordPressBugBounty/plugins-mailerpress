<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Notification for welcome message on first setup
 */
class WelcomeNotification extends AbstractNotificationMessage
{
    protected string $type = 'success';
    protected ?int $duration = 8000; // Auto-dismiss after 8 seconds
    protected bool $dismissible = true;

    public function getMessage(): string
    {
        return __('Welcome to MailerPress! 🎉 Start by creating your first campaign or contact list.', 'mailerpress');
    }

    public function shouldDisplay(): bool
    {
        // Show only for new installations (no campaigns created yet)
        $option = get_option('mailerpress_welcome_notification_shown');
        return !$option && current_user_can('manage_options');
    }

    public function getAction(): ?array
    {
        return [
            'label' => __('Get Started', 'mailerpress'),
            'url' => admin_url('admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome%2Fcampaigns'),
        ];
    }
}
