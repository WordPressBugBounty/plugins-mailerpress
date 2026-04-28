<?php

namespace MailerPress\Core\Notifications;

defined('ABSPATH') || exit;

class NotificationStorage
{
    private const OPTION_NAME = 'mailerpress_dismissed_notifications';

    /**
     * Get all dismissed notification IDs for the current user
     *
     * @return array
     */
    public static function getDismissedNotifications(): array
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return [];
        }

        $all_dismissed = get_option(self::OPTION_NAME, []);
        if (!is_array($all_dismissed)) {
            $all_dismissed = [];
        }

        return isset($all_dismissed[$user_id]) && is_array($all_dismissed[$user_id]) 
            ? $all_dismissed[$user_id] 
            : [];
    }

    /**
     * Check if a notification is dismissed
     *
     * @param string $notification_id
     * @return bool
     */
    public static function isNotificationDismissed(string $notification_id): bool
    {
        $dismissed = self::getDismissedNotifications();
        return in_array($notification_id, $dismissed, true);
    }

    /**
     * Dismiss a notification
     *
     * @param string $notification_id
     * @return bool
     */
    public static function dismissNotification(string $notification_id): bool
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $all_dismissed = get_option(self::OPTION_NAME, []);
        if (!is_array($all_dismissed)) {
            $all_dismissed = [];
        }

        if (!isset($all_dismissed[$user_id]) || !is_array($all_dismissed[$user_id])) {
            $all_dismissed[$user_id] = [];
        }

        if (!in_array($notification_id, $all_dismissed[$user_id], true)) {
            $all_dismissed[$user_id][] = $notification_id;
        }

        return update_option(self::OPTION_NAME, $all_dismissed);
    }

    /**
     * Dismiss multiple notifications
     *
     * @param array $notification_ids
     * @return bool
     */
    public static function dismissNotifications(array $notification_ids): bool
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $all_dismissed = get_option(self::OPTION_NAME, []);
        if (!is_array($all_dismissed)) {
            $all_dismissed = [];
        }

        if (!isset($all_dismissed[$user_id]) || !is_array($all_dismissed[$user_id])) {
            $all_dismissed[$user_id] = [];
        }

        foreach ($notification_ids as $id) {
            if (!in_array($id, $all_dismissed[$user_id], true)) {
                $all_dismissed[$user_id][] = $id;
            }
        }

        return update_option(self::OPTION_NAME, $all_dismissed);
    }

    /**
     * Clear all dismissed notifications for the current user
     *
     * @return bool
     */
    public static function clearAllDismissed(): bool
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $all_dismissed = get_option(self::OPTION_NAME, []);
        if (!is_array($all_dismissed)) {
            return true;
        }

        unset($all_dismissed[$user_id]);
        return update_option(self::OPTION_NAME, $all_dismissed);
    }
}
