<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

/**
 * Migration to add automation_id to campaigns with CASCADE foreign key.
 *
 * Builder calls are unconditional so getExpectedTableStructure() replays correctly.
 * CustomTableManager::migrate() handles idempotency internally.
 */
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
        $table->bigInteger('automation_id')->unsigned()->nullable()->after('campaign_type');
        $table->addIndex('automation_id');

        // Drop old FK (if any) and add new one with CASCADE
        $table->dropForeign('automation_id');
        $table->addForeignKey('automation_id', Tables::MAILERPRESS_AUTOMATIONS, 'id', 'CASCADE', 'RESTRICT');

        $table->setVersion('1.3.0');
    });

    // Synchroniser les campagnes email des steps avec leur automation
    add_action('mailerpress_migration_completed', function () {
        global $wpdb;
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $stepsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_STEPS);

        // Vérifier que les tables existent
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
            AND `key` = 'send_email'
            AND settings IS NOT NULL"
        );

        foreach ($steps as $step) {
            $automationId = (int) $step->automation_id;
            $settings = json_decode($step->settings, true);
            $templateId = isset($settings['template_id']) ? (int) $settings['template_id'] : null;

            if ($templateId && $automationId) {
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
                            SET automation_id = %d
                            WHERE campaign_id = %d
                            AND (automation_id IS NULL OR automation_id != %d)",
                            $automationId,
                            $templateId,
                            $automationId
                        )
                    );
                }
            }
        }
    });
};
