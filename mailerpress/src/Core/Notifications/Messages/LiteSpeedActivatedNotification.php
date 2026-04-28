<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Capabilities;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Notification shown when LiteSpeed plugin is activated
 * This is a persistent notification - it will always show when the condition is met
 */
class LiteSpeedActivatedNotification extends AbstractNotificationMessage
{
    protected string $type = 'warning';
    protected ?int $duration = null; // No auto-dismiss for persistent notifications
    protected bool $dismissible = false; // Cannot be dismissed
    protected bool $persistent = true; // Will always show when condition is true

    public function getMessage(): string
    {
        return __('LiteSpeed Cache plugin detected. Some REST API endpoints used by MailerPress may be cached. We recommend disabling this caching feature.', 'mailerpress');
    }


    public function getRequiredCapability(): string
    {
        // Use standard WordPress capability for administrators
        // This ensures all admins can see this notification
        return Capabilities::MANAGE_SETTINGS;
    }

    public function shouldDisplay(): bool
    {
          // First check if user has required capability
          if (!$this->userHasCapability()) {
            return false;
        }
        
        // Check if LiteSpeed is active
        if (!is_plugin_active('litespeed-cache/litespeed-cache.php')) {
            return false;
        }

        // Check if REST API endpoints are disabled in LiteSpeed
        // LiteSpeed by default may disable REST API endpoints for performance
        // We need to check if REST endpoints are accessible for our API

        // Check LiteSpeed cache configuration
        $litespeed_cache_settings = get_option('litespeed.conf.cache-rest', true);

        if ($litespeed_cache_settings === "1") {
            return true;
        }

        // By default, if LiteSpeed is active, show notification as precaution
        // User should verify REST API endpoints are working
        return false;
    }

    public function getAction(): ?array
    {
        return [
            'label' => __('Fix it', 'mailerpress'),
            'url' => admin_url('admin.php?page=litespeed-cache'),
        ];
    }
}
