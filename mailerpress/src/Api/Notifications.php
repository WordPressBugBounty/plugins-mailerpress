<?php

namespace MailerPress\Api;

defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Notifications\NotificationMessageFactory;
use MailerPress\Core\Notifications\NotificationStorage;

class Notifications
{
    #[Endpoint(
        'notifications/messages',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canViewNotifications'],
    )]
    public function getNotificationMessages(\WP_REST_Request $request)
    {
        // Get dismissed notifications for current user
        $dismissed = NotificationStorage::getDismissedNotifications();
        
        // Get all registered messages
        $allMessages = [];
        
        $reflectionClass = new \ReflectionClass(NotificationMessageFactory::class);
        $messagesProperty = $reflectionClass->getProperty('messages');
        $messagesProperty->setAccessible(true);
        $registeredMessages = $messagesProperty->getValue(null);
        
        foreach ($registeredMessages as $id => $class) {
            $notification_id = $class; // Class name is the ID
            
            $instance = new $class();
            
            // Skip if shouldDisplay returns false (condition not met)
            if (!$instance->shouldDisplay()) {
                continue;
            }
            
            // Skip if dismissed (both persistent and non-persistent)
            if (in_array($notification_id, $dismissed, true)) {
                continue;
            }
            
            $data = [
                'id' => $notification_id,
                'message' => $instance->getMessage(),
                'type' => $instance->getType(),
                'action' => $instance->getAction(),
                'duration' => $instance->getDuration(),
                'dismissible' => $instance->isDismissible(),
                'persistent' => $instance->isPersistent(),
            ];
            
            $allMessages[] = $data;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $allMessages,
            'count' => count($allMessages),
        ]);
    }

    #[Endpoint(
        'notifications/dismiss',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canViewNotifications'],
    )]
    public function dismissNotification(\WP_REST_Request $request)
    {
        $notification_id = $request->get_param('notification_id');

        if (empty($notification_id)) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'Missing notification_id',
            ]);
        }

        // Store dismissed notification
        $success = NotificationStorage::dismissNotification($notification_id);

        return rest_ensure_response([
            'success' => $success,
            'message' => $success ? 'Notification dismissed' : 'Failed to dismiss notification',
            'dismissed_id' => $notification_id,
        ]);
    }

    #[Endpoint(
        'notifications/dismiss-all',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canViewNotifications'],
    )]
    public function dismissAllNotifications(\WP_REST_Request $request)
    {
        $reflectionClass = new \ReflectionClass(NotificationMessageFactory::class);
        $messagesProperty = $reflectionClass->getProperty('messages');
        $messagesProperty->setAccessible(true);
        $registeredMessages = $messagesProperty->getValue(null);
        
        // Dismiss all dismissible notifications (including persistent ones)
        $notification_ids = [];
        foreach ($registeredMessages as $id => $class) {
            $instance = new $class();
            if ($instance->isDismissible()) {
                $notification_ids[] = $class;
            }
        }

        $success = NotificationStorage::dismissNotifications($notification_ids);

        return rest_ensure_response([
            'success' => $success,
            'message' => $success ? 'All notifications dismissed' : 'Failed to dismiss notifications',
            'count' => count($notification_ids),
        ]);
    }
}
