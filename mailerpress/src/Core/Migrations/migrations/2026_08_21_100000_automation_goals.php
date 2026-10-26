<?php

/**
 * Automation goals (benchmarks).
 *
 * 1. Adds the GOAL value to the steps `type` ENUM so goal nodes can be stored.
 * 2. Adds WAITING to the log `status` ENUM (the repository currently falls back
 *    to COMPLETED + a flag in `data` when the value is missing).
 * 3. Adds an index used by the goal matcher to find live jobs of an automation.
 *
 * The goals table itself is created by MailerPress Pro, which implements goals.
 */

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;

return function (SchemaBuilder $schema) {
    global $wpdb;

    $stepsTable = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEPS;
    $logsTable = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_LOG;
    $jobsTable = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_JOBS;

    // ========================================
    // 1. Steps: allow the GOAL type
    // ========================================
    $stepsExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($stepsTable)));

    if ($stepsExists) {
        $typeCol = $wpdb->get_row("SHOW COLUMNS FROM `{$stepsTable}` WHERE Field = 'type'", ARRAY_A);
        $typeDefinition = strtoupper($typeCol['Type'] ?? '');

        if ($typeDefinition && false === strpos($typeDefinition, "'GOAL'")) {
            $wpdb->query(
                "ALTER TABLE `{$stepsTable}`
                 MODIFY COLUMN `type` ENUM('TRIGGER','ACTION','DELAY','CONDITION','GOAL') NOT NULL"
            );

            update_option('custom_table_' . Tables::MAILERPRESS_AUTOMATIONS_STEPS . '_version', '1.2.6');
        }
    }

    // ========================================
    // 2. Logs: allow the WAITING status
    // ========================================
    $logsExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($logsTable)));

    if ($logsExists) {
        $statusCol = $wpdb->get_row("SHOW COLUMNS FROM `{$logsTable}` WHERE Field = 'status'", ARRAY_A);
        $statusDefinition = strtoupper($statusCol['Type'] ?? '');

        if ($statusDefinition && false === strpos($statusDefinition, "'WAITING'")) {
            $wpdb->query(
                "ALTER TABLE `{$logsTable}`
                 MODIFY COLUMN `status` ENUM('PROCESSING','COMPLETED','EXITED','WAITING') NOT NULL"
            );

            update_option('custom_table_' . Tables::MAILERPRESS_AUTOMATIONS_LOG . '_version', '1.2.3');
        }
    }

    // ========================================
    // 3. Jobs: index used by the goal matcher
    // ========================================
    $jobsExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($jobsTable)));

    if ($jobsExists) {
        $existingIndex = $wpdb->get_var(
            "SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '{$jobsTable}'
               AND INDEX_NAME = 'mp_jobs_automation_user_status'"
        );

        if (!$existingIndex) {
            $wpdb->query(
                "ALTER TABLE `{$jobsTable}`
                 ADD INDEX `mp_jobs_automation_user_status` (`automation_id`, `user_id`, `status`)"
            );
        }
    }
};
