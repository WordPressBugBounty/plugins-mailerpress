<?php

namespace MailerPress\Core\Notifications;

defined('ABSPATH') || exit;

use MailerPress\Interfaces\NotificationMessageInterface;

abstract class AbstractNotificationMessage implements NotificationMessageInterface
{
    protected string $message = '';
    protected string $type = 'info';
    protected ?array $action = null;
    protected ?int $duration = 5000;
    protected bool $dismissible = true;

    /**
     * If true, notification is condition-based and cannot be permanently dismissed.
     * It will always show when shouldDisplay() returns true.
     * Examples: plugin activation warnings, database issues, etc.
     */
    protected bool $persistent = false;

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Override this method in child classes to add custom display logic
     * (e.g., check if a plugin is active)
     * 
     * Note: For non-persistent notifications, dismissed status is checked separately in the API
     * This method also checks user capabilities automatically
     */
    public function shouldDisplay(): bool
    {
        // Check if user has required capability
        if (!$this->userHasCapability()) {
            return false;
        }

        return true;
    }

    public function getAction(): ?array
    {
        return $this->action;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function isDismissible(): bool
    {
        return $this->dismissible;
    }

    /**
     * Check if this notification is persistent (condition-based)
     * Persistent notifications cannot be permanently dismissed
     */
    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    /**
     * Get required capability/role for viewing this notification
     * Return null to show to all users
     * Return string for single capability
     * Return array for multiple capabilities (user needs at least one)
     *
     * @return string[]|string|null
     */
    public function getRequiredCapability(): string|array|null
    {
        return null; // Default: show to all users
    }

    /**
     * Check if current user has required capability to view this notification
     *
     * @return bool
     */
    protected function userHasCapability(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $required = $this->getRequiredCapability();

        // No requirement = show to all
        if ($required === null || empty($required)) {
            return true;
        }

        // Single capability
        if (is_string($required)) {
            return current_user_can($required);
        }

        // Multiple capabilities (user needs at least one)
        if (is_array($required)) {
            foreach ($required as $capability) {
                if (current_user_can($capability)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'type' => $this->getType(),
            'action' => $this->getAction(),
            'duration' => $this->getDuration(),
            'dismissible' => $this->isDismissible(),
            'persistent' => $this->isPersistent(),
        ];
    }
}
