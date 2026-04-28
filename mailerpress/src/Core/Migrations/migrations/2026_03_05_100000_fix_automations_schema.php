<?php

/**
 * Fix automations & steps table schema for sites upgraded from old versions.
 *
 * Issues fixed:
 * 1. automations table: PK column named "automation_id" instead of "id"
 * 2. automations table: obsolete columns (description, trigger_type, trigger_data)
 * 3. automations table: status stored lowercase instead of uppercase
 * 4. steps table: step_id/next_step_id/alternative_step_id stored as INT/BIGINT
 *    instead of VARCHAR(192), causing UUIDs to be truncated to numbers
 */

return function (\MailerPress\Core\Migrations\SchemaBuilder $schema) {
    global $wpdb;

    $automationsTable = $wpdb->prefix . 'mailerpress_automations';
    $stepsTable = $wpdb->prefix . 'mailerpress_automations_steps';
    $branchesTable = $wpdb->prefix . 'mailerpress_automations_branches';
    $jobsTable = $wpdb->prefix . 'mailerpress_automations_jobs';
    $logsTable = $wpdb->prefix . 'mailerpress_automations_log';
    $metaTable = $wpdb->prefix . 'mailerpress_automations_meta';

    // ========================================
    // FIX 1: Automations table - rename PK
    // ========================================
    $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($automationsTable)));
    if ($tableExists) {
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$automationsTable}`", 0);
        $hasOldColumn = in_array('automation_id', $columns, true);
        $hasNewColumn = in_array('id', $columns, true);

        if ($hasOldColumn && !$hasNewColumn) {
            // Drop all foreign keys referencing the automations table
            $childTables = [$stepsTable, $jobsTable, $logsTable, $metaTable];
            foreach ($childTables as $childTable) {
                $childExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($childTable)));
                if (!$childExists) {
                    continue;
                }
                $fks = $wpdb->get_results(
                    "SELECT CONSTRAINT_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = '{$childTable}'
                       AND REFERENCED_TABLE_NAME = '{$automationsTable}'"
                );
                foreach ($fks as $fk) {
                    $wpdb->query("ALTER TABLE `{$childTable}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                }
            }

            // Rename automation_id to id
            $wpdb->query("ALTER TABLE `{$automationsTable}` CHANGE COLUMN `automation_id` `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT");

            // Recreate foreign keys
            foreach ($childTables as $childTable) {
                $childExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($childTable)));
                if (!$childExists) {
                    continue;
                }
                $childCols = $wpdb->get_col("SHOW COLUMNS FROM `{$childTable}`", 0);
                if (!in_array('automation_id', $childCols, true)) {
                    continue;
                }
                $fkName = "fk_{$childTable}_automation_id";
                $existingFK = $wpdb->get_var(
                    "SELECT CONSTRAINT_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = '{$childTable}'
                       AND CONSTRAINT_NAME = '{$fkName}'"
                );
                if (!$existingFK) {
                    $wpdb->query(
                        "ALTER TABLE `{$childTable}`
                         ADD CONSTRAINT `{$fkName}`
                         FOREIGN KEY (`automation_id`) REFERENCES `{$automationsTable}` (`id`)
                         ON DELETE CASCADE ON UPDATE CASCADE"
                    );
                }
            }
        }

        // Drop obsolete columns
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$automationsTable}`", 0);
        $obsoleteColumns = ['description', 'trigger_type', 'trigger_data'];
        foreach ($obsoleteColumns as $col) {
            if (in_array($col, $columns, true)) {
                $wpdb->query("ALTER TABLE `{$automationsTable}` DROP COLUMN `{$col}`");
            }
        }

        // Fix status ENUM: old schema uses ('active','inactive','draft'), new uses ('DRAFT','ENABLED')
        $statusCol = $wpdb->get_row("SHOW COLUMNS FROM `{$automationsTable}` WHERE Field = 'status'", ARRAY_A);
        if ($statusCol && preg_match('/ENUM/i', $statusCol['Type'])) {
            $hasOldValues = preg_match("/'active'|'inactive'|'draft'/i", $statusCol['Type']);
            if ($hasOldValues) {
                // Map old values to new ones: active → ENABLED, inactive/draft → DRAFT
                $wpdb->query("UPDATE `{$automationsTable}` SET `status` = 'ENABLED' WHERE `status` = 'active'");
                $wpdb->query("UPDATE `{$automationsTable}` SET `status` = 'DRAFT' WHERE `status` IN ('inactive', 'draft')");
                // Change ENUM definition
                $wpdb->query("ALTER TABLE `{$automationsTable}` MODIFY COLUMN `status` ENUM('DRAFT','ENABLED') NOT NULL DEFAULT 'DRAFT'");
            }
        }

        // Ensure status uses uppercase values (fallback for other edge cases)
        $wpdb->query("UPDATE `{$automationsTable}` SET `status` = UPPER(`status`) WHERE `status` != UPPER(`status`)");

        update_option("custom_table_mailerpress_automations_version", "1.2.6");
    }

    // ========================================
    // FIX 2: Steps table - fix column types
    // ========================================
    $stepsExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($stepsTable)));
    if ($stepsExists) {
        // Check column types - if step_id is not VARCHAR, it needs fixing
        $columnInfo = $wpdb->get_results("SHOW COLUMNS FROM `{$stepsTable}`", ARRAY_A);
        $columnTypes = [];
        foreach ($columnInfo as $col) {
            $columnTypes[$col['Field']] = strtoupper($col['Type']);
        }

        $needsFix = false;

        // Check if step_id is a string type (VARCHAR/TEXT) or numeric (INT/BIGINT)
        if (isset($columnTypes['step_id']) && !preg_match('/VARCHAR|TEXT|CHAR/i', $columnTypes['step_id'])) {
            $needsFix = true;
        }

        if ($needsFix) {
            // Drop FKs on steps table first (branches table references steps)
            $branchesExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($branchesTable)));
            if ($branchesExists) {
                $fks = $wpdb->get_results(
                    "SELECT CONSTRAINT_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = '{$branchesTable}'
                       AND REFERENCED_TABLE_NAME = '{$stepsTable}'"
                );
                foreach ($fks as $fk) {
                    $wpdb->query("ALTER TABLE `{$branchesTable}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                }
            }

            // Drop any indexes on step_id that might block the type change
            $indexes = $wpdb->get_results("SHOW INDEX FROM `{$stepsTable}` WHERE Column_name = 'step_id'", ARRAY_A);
            foreach ($indexes as $idx) {
                if ($idx['Key_name'] !== 'PRIMARY') {
                    $wpdb->query("ALTER TABLE `{$stepsTable}` DROP INDEX `{$idx['Key_name']}`");
                }
            }

            // Change step_id from INT/BIGINT to VARCHAR(192)
            $wpdb->query("ALTER TABLE `{$stepsTable}` MODIFY COLUMN `step_id` VARCHAR(192) NOT NULL");

            // Also fix next_step_id and alternative_step_id if they are numeric
            if (isset($columnTypes['next_step_id']) && !preg_match('/VARCHAR|TEXT|CHAR/i', $columnTypes['next_step_id'])) {
                $wpdb->query("ALTER TABLE `{$stepsTable}` MODIFY COLUMN `next_step_id` VARCHAR(192) DEFAULT NULL");
            }
            if (isset($columnTypes['alternative_step_id']) && !preg_match('/VARCHAR|TEXT|CHAR/i', $columnTypes['alternative_step_id'])) {
                $wpdb->query("ALTER TABLE `{$stepsTable}` MODIFY COLUMN `alternative_step_id` VARCHAR(192) DEFAULT NULL");
            }

            // Also fix key column if it's not VARCHAR
            if (isset($columnTypes['key']) && !preg_match('/VARCHAR|TEXT|CHAR/i', $columnTypes['key'])) {
                $wpdb->query("ALTER TABLE `{$stepsTable}` MODIFY COLUMN `key` VARCHAR(192) NOT NULL");
            }

            // Delete corrupted workflow steps (numeric step_ids can't be reconnected)
            // These are from workflows created before this fix - UUIDs were truncated to numbers
            $corruptedSteps = $wpdb->get_col(
                "SELECT DISTINCT automation_id FROM `{$stepsTable}` WHERE `step_id` REGEXP '^[0-9]+$'"
            );

            if (!empty($corruptedSteps)) {
                $ids = implode(',', array_map('intval', $corruptedSteps));
                // Delete corrupted steps
                $wpdb->query("DELETE FROM `{$stepsTable}` WHERE automation_id IN ({$ids})");
                // Delete the parent automations too (they have no usable steps)
                if ($tableExists) {
                    $wpdb->query("DELETE FROM `{$automationsTable}` WHERE id IN ({$ids})");
                }
            }

            // Recreate FK from branches to steps if needed
            if ($branchesExists) {
                $stepsColInfo = $wpdb->get_results("SHOW COLUMNS FROM `{$stepsTable}`", ARRAY_A);
                $hasId = false;
                foreach ($stepsColInfo as $col) {
                    if ($col['Field'] === 'id') {
                        $hasId = true;
                        break;
                    }
                }
                if ($hasId) {
                    $fkName = "fk_{$branchesTable}_step_id";
                    $existingFK = $wpdb->get_var(
                        "SELECT CONSTRAINT_NAME
                         FROM information_schema.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = '{$branchesTable}'
                           AND CONSTRAINT_NAME = '{$fkName}'"
                    );
                    if (!$existingFK) {
                        $branchesCols = $wpdb->get_col("SHOW COLUMNS FROM `{$branchesTable}`", 0);
                        if (in_array('step_id', $branchesCols, true)) {
                            $wpdb->query(
                                "ALTER TABLE `{$branchesTable}`
                                 ADD CONSTRAINT `{$fkName}`
                                 FOREIGN KEY (`step_id`) REFERENCES `{$stepsTable}` (`id`)
                                 ON DELETE CASCADE ON UPDATE CASCADE"
                            );
                        }
                    }
                }
            }
        }

        // Fix type ENUM values: old schema may use lowercase ('trigger','action',...)
        // New schema requires uppercase ('TRIGGER','ACTION','DELAY','CONDITION')
        $typeCol = $columnTypes['type'] ?? '';
        if ($typeCol && preg_match('/ENUM/i', $typeCol) && strpos($typeCol, "'trigger'") !== false) {
            // Old ENUM with lowercase values - change to uppercase
            $wpdb->query("ALTER TABLE `{$stepsTable}` MODIFY COLUMN `type` ENUM('TRIGGER','ACTION','DELAY','CONDITION') NOT NULL");
        } elseif ($typeCol && !preg_match('/ENUM/i', $typeCol)) {
            // Column is VARCHAR or other type - convert to proper ENUM
            // First uppercase existing data
            $wpdb->query("UPDATE `{$stepsTable}` SET `type` = UPPER(`type`)");
            $wpdb->query("ALTER TABLE `{$stepsTable}` MODIFY COLUMN `type` ENUM('TRIGGER','ACTION','DELAY','CONDITION') NOT NULL");
        }
        // Also uppercase any remaining lowercase type values
        $wpdb->query("UPDATE `{$stepsTable}` SET `type` = UPPER(`type`) WHERE `type` != UPPER(`type`)");

        update_option("custom_table_mailerpress_automations_steps_version", "1.2.5");
    }

    // ========================================
    // FIX 3: Branches table - fix column types
    // ========================================
    $branchesExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($branchesTable)));
    if ($branchesExists) {
        $columnInfo = $wpdb->get_results("SHOW COLUMNS FROM `{$branchesTable}`", ARRAY_A);
        $columnTypes = [];
        foreach ($columnInfo as $col) {
            $columnTypes[$col['Field']] = strtoupper($col['Type']);
        }

        if (isset($columnTypes['next_step_id']) && !preg_match('/VARCHAR|TEXT|CHAR/i', $columnTypes['next_step_id'])) {
            $wpdb->query("ALTER TABLE `{$branchesTable}` MODIFY COLUMN `next_step_id` VARCHAR(192) NOT NULL");
        }

        update_option("custom_table_mailerpress_automations_branches_version", "1.2.2");
    }
};
