<?php

namespace MailerPress\Core\Notifications;

defined('ABSPATH') || exit;

use MailerPress\Core\Notifications\Messages\PrimaryProviderDisabledNotification;
use MailerPress\Core\Notifications\Messages\WelcomeNotification;
use MailerPress\Core\Notifications\Messages\LiteSpeedActivatedNotification;
use MailerPress\Core\Notifications\Messages\ProductUpdateNotification;
use MailerPress\Core\Notifications\Messages\ThirdPartySMTPDetectedNotification;
use MailerPress\Core\Notifications\Messages\DisableWpCronNotification;
use MailerPress\Core\Notifications\Messages\WorkflowFailedJobsNotification;
use MailerPress\Core\Notifications\Messages\DatabaseUpdateRequiredNotification;
use MailerPress\Core\Notifications\Messages\PasswordProtectedActivatedNotification;
use MailerPress\Core\Notifications\Messages\WafRestApiNotification;

class NotificationBootstrap
{
    /**
     * Initialize and register default notification messages
     *
     * @return void
     */
    public static function init(): void
    {
        // Register default notification messages
        NotificationMessageFactory::register(
            'primary_provider_disabled',
            PrimaryProviderDisabledNotification::class
        );

        NotificationMessageFactory::register(
            'product_update_1_2',
            ProductUpdateNotification::class
        );

        NotificationMessageFactory::register(
            'litespeed_activated',
            LiteSpeedActivatedNotification::class
        );

        NotificationMessageFactory::register(
            'disable_wp_cron',
            DisableWpCronNotification::class
        );

        NotificationMessageFactory::register(
            'workflow_failed_jobs',
            WorkflowFailedJobsNotification::class
        );

        NotificationMessageFactory::register(
            'password_protected_activated',
            PasswordProtectedActivatedNotification::class
        );

        NotificationMessageFactory::register(
            'database_update_required',
            DatabaseUpdateRequiredNotification::class
        );

        NotificationMessageFactory::register(
            'waf_rest_api',
            WafRestApiNotification::class
        );

        // Allow plugins and extensions to register their own messages
        do_action('mailerpress_register_notifications');
    }
}
