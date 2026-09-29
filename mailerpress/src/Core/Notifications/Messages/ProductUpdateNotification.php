<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Notification for MailerPress 2.1 product update
 * This is NOT persistent - once dismissed, it won't show again
 */
class ProductUpdateNotification extends AbstractNotificationMessage
{
    protected string $type = 'info';
    protected ?int $duration = null; // No auto-dismiss
    protected bool $dismissible = true;
    protected bool $persistent = false; // Once dismissed, gone forever

    public function getMessage(): string
    {
        return __('MailerPress 2.1 is here! 🚀 Discover the new A/B testing features and optimize your email campaigns.', 'mailerpress');
    }

    public function shouldDisplay(): bool
    {
        // Always show (dismissal is handled by the storage system)
        return true;
    }

    public function getAction(): ?array
    {
        return [
            'label' => __('Read Blog Post', 'mailerpress'),
            'url' => \MailerPress\Core\ExternalLinks::get('blog.v2'),
            'target' => '_blank',
        ];
    }
}
