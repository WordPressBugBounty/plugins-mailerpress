<?php

/**
 * Automation goals (benchmarks).
 *
 * 1. Adds the GOAL value to the steps `type` ENUM so goal nodes can be stored.
 * 2. Adds WAITING to the log `status` ENUM (the repository currently falls back
 *    to COMPLETED + a flag in `data` when the value is missing).
 * 3. Creates the goals table tracking, per job, whether a goal was achieved.
 * 4. Adds an index used by the goal matcher to find live jobs of an automation.
 */

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
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
    // 3. Goals table
    // ========================================
    $schema->create(Tables::MAILERPRESS_AUTOMATIONS_GOALS, function (CustomTableManager $table) {
        $table->id('id');
        $table->unsignedBigInteger('automation_id');
        $table->string('step_id', 192);
        $table->unsignedBigInteger('job_id')->nullable();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('contact_id')->nullable();
        $table->string('goal_event', 192);
        $table->enum('status', ['PENDING', 'ACHIEVED', 'EXPIRED', 'SKIPPED'])->default('PENDING');
        $table->string('match_hash', 64)->nullable();
        $table->json('data')->nullable();
        $table->column('expires_at', 'DATETIME')->nullable();
        $table->column('achieved_at', 'DATETIME')->nullable();
        $table->column('created_at', 'TIMESTAMP')->nullable();
        $table->column('updated_at', 'TIMESTAMP')->nullable();

        $table->addIndex('step_id');
        $table->addIndex('automation_id, status');
        $table->addIndex('user_id, status');
        $table->addIndex('goal_event');
        $table->addIndex('expires_at');

        $table->addForeignKey('automation_id', Tables::MAILERPRESS_AUTOMATIONS, 'id', 'CASCADE');

        $table->setVersion('1.0.0');
    });

    // ========================================
    // 4. Goals: one row per (job, goal step)
    // ========================================
    // Declared in raw SQL rather than through the schema builder so the
    // step_id prefix stays short: the builder always prefixes long VARCHARs at
    // 191 chars, and 191*4 bytes + job_id would exceed the 767-byte index limit
    // of older MySQL versions (InnoDB, COMPACT row format).
    $goalsTable = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_GOALS;
    $goalsExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($goalsTable)));

    if ($goalsExists) {
        $existingUnique = $wpdb->get_var(
            "SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '{$goalsTable}'
               AND INDEX_NAME = 'mp_goals_job_step'"
        );

        if (!$existingUnique) {
            // NULL job_id rows (a goal credited outside any run) stay allowed:
            // MySQL does not consider NULLs equal in a unique index.
            $wpdb->query(
                "ALTER TABLE `{$goalsTable}`
                 ADD UNIQUE INDEX `mp_goals_job_step` (`job_id`, `step_id`(150))"
            );
        }
    }

    // ========================================
    // 5. Jobs: index used by the goal matcher
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
