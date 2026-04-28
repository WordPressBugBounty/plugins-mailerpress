<?php

namespace MailerPress\Interfaces;

defined('ABSPATH') || exit;

interface NotificationMessageInterface
{
    /**
     * Get the notification message
     *
     * @return string The message content
     */
    public function getMessage(): string;

    /**
     * Get the notification type
     *
     * @return string One of: 'info', 'success', 'warning', 'error'
     */
    public function getType(): string;

    /**
     * Check if this notification should be displayed
     *
     * @return bool True if the notification should be shown
     */
    public function shouldDisplay(): bool;

    /**
     * Get optional action for the notification
     *
     * @return array|null Array with 'label' and 'url' keys, or null if no action
     */
    public function getAction(): ?array;

    /**
     * Get notification duration (in ms)
     * null = manual dismiss only, 0 or positive number = auto-dismiss
     *
     * @return int|null
     */
    public function getDuration(): ?int;

    /**
     * Check if notification is dismissible
     *
     * @return bool
     */
    public function isDismissible(): bool;

    /**
     * Check if notification is persistent (condition-based)
     * Persistent notifications cannot be permanently dismissed
     * They will show again when their condition is true
     *
     * @return bool
     */
    public function isPersistent(): bool;

    /**
     * Check if the current user has the required role/capability to see this notification
     * Return null or empty array to show to all users
     *
     * @return string[]|string|null Array of required capabilities, single capability string, or null for all users
     */
    public function getRequiredCapability(): string|array|null;

    /**
     * Convert to array for JSON response
     *
     * @return array
     */
    public function toArray(): array;
}
