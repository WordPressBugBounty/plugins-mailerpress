<?php

declare(strict_types=1);

namespace MailerPress\Actions\Cron;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class RegisterCronJobs
{
    /**
     * Register cron schedules
     */
    #[Action('init', priority: 10, acceptedArgs: 0)]
    public function registerSchedules(): void
    {
        // Register hourly cleanup cron
        if (!wp_next_scheduled('mailerpress_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'mailerpress_cleanup');
        }
    }

    /**
     * Deactivation hook to clear scheduled events
     */
    public static function clearSchedules(): void
    {
        $timestamp = wp_next_scheduled('mailerpress_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mailerpress_cleanup');
        }
    }
}
