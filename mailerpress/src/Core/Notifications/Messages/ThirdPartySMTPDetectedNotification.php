<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Capabilities;
use MailerPress\Core\CapabilitiesManager;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Notification shown when third-party SMTP plugins are detected
 * These plugins cannot send MailerPress emails, ESP must be configured
 */
class ThirdPartySMTPDetectedNotification extends AbstractNotificationMessage
{
    protected string $type = 'warning';
    protected ?int $duration = null; // No auto-dismiss
    protected bool $dismissible = true;
    protected bool $persistent = false; // Can be dismissed permanently

    /**
     * List of third-party SMTP plugins to check
     */
    private const SMTP_PLUGINS = [
        'fluent-smtp/fluent-smtp.php' => 'Fluent SMTP',
        'gravityforms/gravityforms.php' => 'Gravity Forms (SMTP)',
        'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
        'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP',
        'post-smtp/postman-smtp.php' => 'Post SMTP',
        'wp-smtp/wp-smtp.php' => 'WP SMTP',
    ];

    public function getMessage(): string
    {
        $detectedPlugins = $this->getDetectedSMTPPlugins();
        $pluginNames = implode(', ', $detectedPlugins);

        return sprintf(
            __('Third-party SMTP plugins detected (%s). These plugins cannot send MailerPress emails. Please configure your ESP (Email Service Provider) in MailerPress settings for sending emails.', 'mailerpress'),
            $pluginNames
        );
    }

    public function shouldDisplay(): bool
    {
        // First check if user has required capability
        if (!$this->userHasCapability()) {
            return false;
        }

        // Then check if any third-party SMTP plugin is active
        return !empty($this->getDetectedSMTPPlugins());
    }

    /**
     * Only show to administrators
     * Using 'manage_options' which is the standard WordPress capability for administrators
     *
     * @return string
     */
    public function getRequiredCapability(): string
    {
        // Use standard WordPress capability for administrators
        // This ensures all admins can see this notification
        return Capabilities::MANAGE_SETTINGS;
    }

    public function getAction(): ?array
    {
        return [
            'label' => __('Configure ESP', 'mailerpress'),
            'url' => admin_url('admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome%2Fintegrations'),
        ];
    }

    /**
     * Get list of detected active SMTP plugins
     *
     * @return array
     */
    private function getDetectedSMTPPlugins(): array
    {
        $detected = [];

        foreach (self::SMTP_PLUGINS as $pluginFile => $pluginName) {
            if (is_plugin_active($pluginFile)) {
                $detected[] = $pluginName;
            }
        }

        return $detected;
    }
}
