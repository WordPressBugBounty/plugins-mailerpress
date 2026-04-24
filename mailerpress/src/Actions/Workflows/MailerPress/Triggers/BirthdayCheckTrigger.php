<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Triggers;

\defined('ABSPATH') || exit;

use MailerPress\Models\CustomFields;
use MailerPress\Core\Enums\Tables;

/**
 * Birthday Check Trigger
 * 
 * A scheduled trigger that runs daily to check for contacts whose birthday is today.
 * This trigger fires once per day and checks all contacts with a birthday custom field.
 * 
 * For each contact whose birthday matches today's date, it triggers workflows
 * configured to use this trigger.
 * 
 * Data available in the workflow context:
 * - contact_id: The unique identifier of the contact
 * - field_key: The key of the birthday custom field
 * - field_value: The birthday date value
 * - user_id: The WordPress user ID associated with the contact (if exists)
 * - birthday_date: The birthday date (formatted)
 * 
 * @since 1.2.0
 */
class BirthdayCheckTrigger
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'birthday_check';

    /**
     * WordPress hook name to listen to
     */
    public const HOOK_NAME = 'mailerpress_check_birthdays';

    /**
     * Register the custom trigger
     * 
     * @param \MailerPress\Core\Workflows\Services\TriggerManager $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        // Only load custom field options in admin/REST context (not needed on frontend)
        $fieldOptions = [
            [
                'value' => '',
                'label' => __('Select a field...', 'mailerpress'),
            ],
        ];
        if (is_admin() || wp_is_json_request()) {
            $customFieldsModel = new CustomFields();
            $customFields = $customFieldsModel->all();
            foreach ($customFields as $field) {
                // Only show date fields
                if ($field->type === 'date') {
                    $fieldOptions[] = [
                        'value' => $field->field_key,
                        'label' => $field->label ?? $field->field_key,
                    ];
                }
            }
        }

        $definition = [
            'label' => __('Birthday Check (Daily)', 'mailerpress'),
            'description' => __('Runs daily to check for contacts whose birthday is today. Configure the custom field containing the birthday date. Perfect for sending automatic and personalized birthday messages.', 'mailerpress'),
            'icon' => 'mailerpress',
            'category' => 'mailerpress',
            'settings_schema' => [
                [
                    'key' => 'birthday_field_key',
                    'label' => __('Birthday Field', 'mailerpress'),
                    'type' => 'select',
                    'required' => true,
                    'options' => $fieldOptions,
                    'help' => __('Select the custom field that contains the birthday date. This field is required.', 'mailerpress'),
                ],
                [
                    'key' => 'check_time',
                    'label' => __('Check Time', 'mailerpress'),
                    'type' => 'time',
                    'required' => false,
                    'default' => '09:00',
                    'help' => __('Time of day to check for birthdays (24-hour format)', 'mailerpress'),
                ],
            ],
        ];

        // Register the trigger
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            self::HOOK_NAME,
            null, // No context builder needed - we'll build context in the cron handler
            $definition
        );

        // Schedule the daily check using Action Scheduler if not already scheduled
        if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action(self::HOOK_NAME)) {
            // Calculate the first run time (today at 9:00 AM, or tomorrow if already past)
            $firstRun = strtotime('today 09:00');
            if ($firstRun < time()) {
                $firstRun = strtotime('tomorrow 09:00');
            }

            // Schedule recurring action to run daily
            as_schedule_recurring_action(
                $firstRun,
                DAY_IN_SECONDS, // Run every day
                self::HOOK_NAME,
                [],
                'mailerpress' // Group name
            );
        }

        // Add the action handler
        add_action(self::HOOK_NAME, [self::class, 'checkBirthdays']);
    }

    /**
     * Check for contacts whose birthday is today and trigger workflows
     * Improved version with robust date comparison using WordPress timezone
     */
    public static function checkBirthdays(): void
    {
        global $wpdb;

        $customFieldsTable = $wpdb->prefix . Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS;
        $contactsTable = $wpdb->prefix . 'mailerpress_contact';

        // Get WordPress timezone - this is critical for accurate date comparison
        $timezone = wp_timezone(); // Available in WP 5.3+

        // Get today's date in WordPress timezone
        $today = new \DateTime('now', $timezone);
        $todayMonth = (int) $today->format('n'); // n = 1-12 without leading zeros
        $todayDay = (int) $today->format('j');   // j = 1-31 without leading zeros

        // Get all enabled automations with birthday_check trigger
        $system = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
        $workflowManager = $system->getManager();

        // Get automations using the repository
        $automationRepo = new \MailerPress\Core\Workflows\Repositories\AutomationRepository();
        $stepRepo = new \MailerPress\Core\Workflows\Repositories\StepRepository();
        $jobRepo = new \MailerPress\Core\Workflows\Repositories\AutomationJobRepository();
        $automations = $automationRepo->findByStatus('ENABLED');

        foreach ($automations as $automation) {
            $trigger = $stepRepo->findTriggerByKey($automation->getId(), self::TRIGGER_KEY);
            if (!$trigger) {
                continue;
            }

            // Get trigger settings to find the birthday field key
            $settings = $trigger->getSettings() ?? [];
            $birthdayFieldKey = $settings['birthday_field_key'] ?? '';

            // Birthday field key is required - skip if not configured
            if (empty($birthdayFieldKey)) {
                continue;
            }

            // Find contacts with birthday matching today
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT c.contact_id, c.email, cf.field_value as birthday_value
             FROM {$contactsTable} c
             INNER JOIN {$customFieldsTable} cf ON c.contact_id = cf.contact_id
             WHERE cf.field_key = %s
             AND cf.field_value IS NOT NULL
             AND cf.field_value != ''",
                $birthdayFieldKey
            ));

            foreach ($results as $row) {
                $birthdayValue = is_serialized($row->birthday_value)
                    ? unserialize($row->birthday_value, ['allowed_classes' => false])
                    : $row->birthday_value;
                if (empty($birthdayValue)) {
                    continue;
                }

                // Parse the birthday date
                $birthdayDateTime = self::parseBirthdayDate($birthdayValue, $timezone);

                if (!$birthdayDateTime) {
                    continue;
                }

                // Extract month and day from birthday
                $birthdayMonth = (int) $birthdayDateTime->format('n');
                $birthdayDay = (int) $birthdayDateTime->format('j');

                // Compare only month and day (ignore year)
                if ($birthdayMonth !== $todayMonth || $birthdayDay !== $todayDay) {
                    continue; // CRITICAL: This must prevent workflow from starting
                }

                // Build context for the workflow
                $contactId = (int) $row->contact_id;
                $userId = null;

                // Try to find WordPress user by email
                $user = get_user_by('email', $row->email);
                if ($user) {
                    $userId = $user->ID;
                }

                // If no user found, use contact_id as user_id
                if (!$userId) {
                    $userId = $contactId;
                }

                $context = [
                    'contact_id' => $contactId,
                    'field_key' => $birthdayFieldKey,
                    'field_value' => $birthdayValue,
                    'user_id' => $userId,
                    'birthday_date' => $birthdayDateTime->format('Y-m-d'),
                ];

                // Check for existing active jobs
                $existingJob = $jobRepo->findActiveByAutomationAndContact(
                    $automation->getId(),
                    $contactId,
                    true // includeWaiting
                );

                if ($existingJob) {
                    continue;
                }

                // Check if already sent this year
                $currentYear = (int) $today->format('Y');
                $completedJobThisYear = $jobRepo->findCompletedByAutomationAndContact(
                    $automation->getId(),
                    $contactId
                );

                if ($completedJobThisYear) {
                    $jobDate = $completedJobThisYear->getUpdatedAt() ?: $completedJobThisYear->getCreatedAt();
                    if ($jobDate) {
                        $jobYear = (int) date('Y', strtotime($jobDate));
                        if ($jobYear === $currentYear) {
                            continue;
                        }
                    }
                }

                // Check run_once_per_subscriber setting
                if ($automation && $automation->isRunOncePerSubscriber()) {
                    $completedJob = $jobRepo->findCompletedByAutomationAndContact(
                        $automation->getId(),
                        $contactId
                    );

                    if ($completedJob) {
                        continue;
                    }
                }

                // Additional check: prevent duplicates in last 5 minutes
                $recentJob = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mailerpress_automations_jobs
                 WHERE automation_id = %d
                 AND user_id = %d
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                 LIMIT 1",
                    $automation->getId(),
                    $userId
                ));

                if ($recentJob) {
                    continue;
                }

                // Start the workflow
                $workflowManager->startWorkflow($automation->getId(), $userId, $context);
            }
        }
    }

    /**
     * Parse a birthday date value into a DateTime object
     * Handles multiple date formats commonly used in WordPress
     * 
     * @param mixed $birthdayValue The birthday value from the database
     * @param \DateTimeZone $timezone The WordPress timezone
     * @return \DateTime|null The parsed DateTime or null on failure
     */
    private static function parseBirthdayDate($birthdayValue, \DateTimeZone $timezone): ?\DateTime
    {
        if (empty($birthdayValue)) {
            return null;
        }

        // Handle numeric timestamp
        if (is_numeric($birthdayValue)) {
            $dt = new \DateTime('now', $timezone);
            $dt->setTimestamp((int) $birthdayValue);
            return $dt;
        }

        // Handle string dates
        if (!is_string($birthdayValue)) {
            return null;
        }

        $birthdayValue = trim($birthdayValue);

        // List of formats to try, in order of preference
        $formats = [
            'Y-m-d',           // ISO format: 2025-11-07 (most common)
            'Y-m-d H:i:s',     // ISO with time
            'd/m/Y',           // European: 07/11/2025
            'm/d/Y',           // US: 11/07/2025
            'd.m.Y',           // German: 07.11.2025
            'Y/m/d',           // Alternative ISO
            'Ymd',             // Compact: 20251107
        ];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $birthdayValue, $timezone);

            if ($dt === false) {
                continue;
            }

            // Validate parsing - check for errors
            $errors = \DateTime::getLastErrors();
            if ($errors && ($errors['error_count'] > 0 || $errors['warning_count'] > 0)) {
                continue;
            }

            // Additional validation: verify the date is realistic for a birthday
            $year = (int) $dt->format('Y');
            $month = (int) $dt->format('n');
            $day = (int) $dt->format('j');

            // Basic sanity checks
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                continue;
            }

            // Year should be reasonable (1900-current year)
            $currentYear = (int) date('Y');
            if ($year < 1900 || $year > $currentYear) {
                continue;
            }

            return $dt;
        }

        // Fallback: try strtotime (more lenient but less reliable)
        $timestamp = strtotime($birthdayValue);
        if ($timestamp !== false) {
            $dt = new \DateTime('now', $timezone);
            $dt->setTimestamp($timestamp);

            // Validate the result
            $month = (int) $dt->format('n');
            $day = (int) $dt->format('j');

            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return $dt;
            }
        }

        return null;
    }
}
