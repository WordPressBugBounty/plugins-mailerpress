<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

/**
 * Migration to add step_id to campaigns for automation step tracking.
 *
 * Builder calls are unconditional so getExpectedTableStructure() replays correctly.
 * CustomTableManager::migrate() handles idempotency internally.
 */
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
        $table->string('step_id', 192)->nullable()->after('automation_id');
        $table->addIndex('step_id');
        $table->setVersion('1.2.0');
    });

    // Synchroniser les campagnes existantes avec leur step_id
    add_action('mailerpress_migration_completed', function () {
        global $wpdb;
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $stepsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_STEPS);

        $campaignsExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s",
                DB_NAME,
                $campaignsTable
            )
        );

        $stepsExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s",
                DB_NAME,
                $stepsTable
            )
        );

        if (!$campaignsExists || !$stepsExists) {
            return;
        }

        $steps = $wpdb->get_results(
            "SELECT automation_id, step_id, settings
            FROM {$stepsTable}
            WHERE type = 'ACTION'
            AND (`key` = 'send_email' OR `key` = 'send_mail')
            AND settings IS NOT NULL"
        );

        foreach ($steps as $step) {
            $stepId = $step->step_id;
            $settings = json_decode($step->settings, true);
            $templateId = isset($settings['template_id']) ? (int) $settings['template_id'] : null;

            if ($templateId && $stepId) {
                $campaignExists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$campaignsTable} WHERE campaign_id = %d",
                        $templateId
                    )
                );

                if ($campaignExists) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$campaignsTable}
                            SET step_id = %s
                            WHERE campaign_id = %d
                            AND (step_id IS NULL OR step_id != %s)",
                            $stepId,
                            $templateId,
                            $stepId
                        )
                    );
                }
            }
        }
    });
};
