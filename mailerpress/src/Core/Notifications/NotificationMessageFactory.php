<?php

namespace MailerPress\Core\Notifications;

defined('ABSPATH') || exit;

use MailerPress\Interfaces\NotificationMessageInterface;

class NotificationMessageFactory
{
    /**
     * Array of notification message classes
     *
     * @var array<string, string>
     */
    private static array $messages = [];

    /**
     * Register a notification message class
     *
     * @param string $id Unique identifier for the message
     * @param string $class Fully qualified class name implementing NotificationMessageInterface
     */
    public static function register(string $id, string $class): void
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Class {$class} does not exist");
        }

        if (!in_array(NotificationMessageInterface::class, class_implements($class) ?: [])) {
            throw new \InvalidArgumentException(
                "Class {$class} must implement " . NotificationMessageInterface::class
            );
        }

        self::$messages[$id] = $class;
    }

    /**
     * Create a notification message instance
     *
     * @param string $id The message identifier
     * @return NotificationMessageInterface|null
     */
    public static function create(string $id): ?NotificationMessageInterface
    {
        if (!isset(self::$messages[$id])) {
            return null;
        }

        $class = self::$messages[$id];
        return new $class();
    }

    /**
     * Get all notification messages
     *
     * @return NotificationMessageInterface[]
     */
    public static function getAll(): array
    {
        $messages = [];

        foreach (self::$messages as $id => $class) {
            $instance = new $class();
            if ($instance->shouldDisplay()) {
                $messages[] = $instance;
            }
        }

        return $messages;
    }

    /**
     * Get all messages as array
     *
     * @return array[]
     */
    public static function getAllAsArray(): array
    {
        $messages = [];
        foreach (self::getAll() as $msg) {
            $data = $msg->toArray();
            // Use full class name as ID (consistent across requests)
            $data['id'] = get_class($msg);
            $messages[] = $data;
        }
        return $messages;
    }

    /**
     * Check if a message ID is registered
     *
     * @param string $id
     * @return bool
     */
    public static function has(string $id): bool
    {
        return isset(self::$messages[$id]);
    }

    /**
     * Clear all registered messages
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$messages = [];
    }
}
