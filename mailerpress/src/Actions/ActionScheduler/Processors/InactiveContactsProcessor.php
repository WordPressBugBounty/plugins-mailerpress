<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Services\InactiveContactManager;
use MailerPress\Services\Logger;

class InactiveContactsProcessor
{
    private const HOOK = 'mailerpress_deactivate_inactive_contacts';
    private const GROUP = 'mailerpress';
    private const SCHEDULE_CHECK_TRANSIENT = 'mailerpress_inactive_contacts_daily_schedule_check';
    private const RECURRENCE = DAY_IN_SECONDS;

    public function __construct(private readonly InactiveContactManager $inactiveContactManager)
    {
    }

    #[Action('init', priority: 20)]
    public function schedule(): void
    {
        if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        if (get_transient(self::SCHEDULE_CHECK_TRANSIENT)) {
            return;
        }

        set_transient(self::SCHEDULE_CHECK_TRANSIENT, 1, HOUR_IN_SECONDS);

        if (!$this->inactiveContactManager->isEnabled()) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(self::HOOK, [], self::GROUP);
            }
            return;
        }

        $needsSchedule = !as_next_scheduled_action(self::HOOK, [], self::GROUP);

        if (
            !$needsSchedule
            && function_exists('as_get_scheduled_actions')
            && function_exists('as_unschedule_all_actions')
        ) {
            $actions = as_get_scheduled_actions([
                'hook' => self::HOOK,
                'group' => self::GROUP,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ], 'ARRAY_A');

            $hasDailyAction = false;
            foreach ($actions as $action) {
                $schedule = $action['schedule'] ?? null;
                if ($schedule instanceof \ActionScheduler_IntervalSchedule && (int) $schedule->get_recurrence() === self::RECURRENCE) {
                    $hasDailyAction = true;
                    break;
                }
            }

            if (!$hasDailyAction) {
                as_unschedule_all_actions(self::HOOK, [], self::GROUP);
                $needsSchedule = true;
            }
        }

        if ($needsSchedule) {
            as_schedule_recurring_action(
                time() + HOUR_IN_SECONDS,
                self::RECURRENCE,
                self::HOOK,
                [],
                self::GROUP
            );
        }
    }

    #[Action(self::HOOK)]
    public function process(): void
    {
        $result = $this->inactiveContactManager->deactivateInactiveContacts();

        Logger::info('Inactive contacts processor completed', $result);
    }
}
