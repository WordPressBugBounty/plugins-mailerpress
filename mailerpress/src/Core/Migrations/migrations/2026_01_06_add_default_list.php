<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    global $wpdb;

    // Add is_default column to mailerpress_lists table
    $listTable = $wpdb->prefix . 'mailerpress_lists';
    $listExists = $wpdb->get_var("SHOW TABLES LIKE '{$listTable}'") === $listTable;

    if ($listExists) {
        $schema->table(Tables::MAILERPRESS_LIST, function (CustomTableManager $table) {
            // Add is_default column (default to 0, only one list can be default)
            $table->boolean('is_default')->default(0)->after('sync');

            // Add unique index to ensure only one default list
            // This will be enforced at application level since MySQL doesn't support conditional unique indexes
            
            $table->setVersion('1.2.0');
        });

        // Check if is_default column actually exists before querying it
        $columnExists = $wpdb->get_var("SHOW COLUMNS FROM {$listTable} LIKE 'is_default'");

        if ($columnExists) {
            // Set the first list as default if no default list exists
            $hasDefault = $wpdb->get_var("SELECT COUNT(*) FROM {$listTable} WHERE is_default = 1");
            if (!$hasDefault) {
                // Get the first list by creation date
                $firstList = $wpdb->get_row("SELECT list_id FROM {$listTable} ORDER BY created_at ASC LIMIT 1");
                if ($firstList) {
                    $wpdb->update(
                        $listTable,
                        ['is_default' => 1],
                        ['list_id' => $firstList->list_id],
                        ['%d'],
                        ['%d']
                    );
                }
            }
        }
    }
};

