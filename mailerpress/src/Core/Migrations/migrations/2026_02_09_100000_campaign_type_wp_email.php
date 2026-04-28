<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    global $wpdb;

    $campaignsTable = $wpdb->prefix . Tables::MAILERPRESS_CAMPAIGNS;
    $campaignsExists = $wpdb->get_var("SHOW TABLES LIKE '{$campaignsTable}'") === $campaignsTable;

    if ($campaignsExists) {
        $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
            $table->modifyColumn('campaign_type', "ENUM(
                'newsletter',
                'automated',
                'automation',
                'wp_email'
            ) NOT NULL DEFAULT 'newsletter'");

            $table->setVersion('2.0.0');
        });
    }
};
