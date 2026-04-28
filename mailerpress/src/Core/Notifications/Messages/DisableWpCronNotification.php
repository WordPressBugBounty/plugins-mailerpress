<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Capabilities;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Notification shown when DISABLE_WP_CRON is defined in wp-config.php
 * This indicates that WP-Cron is disabled and a server cron job should be set up
 */
class DisableWpCronNotification extends AbstractNotificationMessage
{
    protected string $type = 'error';
    protected ?int $duration = null; // Manual dismiss only
    protected bool $dismissible = true;
    protected bool $persistent = true; // Condition-based, always show when condition is met

    public function getMessage(): string
    {
        return __('WP-Cron is disabled on your site. MailerPress requires a working cron system to send scheduled emails. If you have already configured a real server cron job, you can safely ignore this notification. Otherwise, please set up a server cron job to ensure reliable email delivery.', 'mailerpress');
    }

    public function shouldDisplay(): bool
    {
        // First check if user has required capability
        if (!$this->userHasCapability()) {
            return false;
        }

        // Check if DISABLE_WP_CRON is defined in wp-config.php
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true;
    }

    /**
     * Only show to administrators
     *
     * @return string
     */
    public function getRequiredCapability(): string
    {
        return Capabilities::MANAGE_SETTINGS;
    }

    public function getAction(): ?array
    {
        return [
            'label' => __('View Documentation', 'mailerpress'),
            'url' => \MailerPress\Core\ExternalLinks::get('docs.cron_setup'),
        ];
    }
}
