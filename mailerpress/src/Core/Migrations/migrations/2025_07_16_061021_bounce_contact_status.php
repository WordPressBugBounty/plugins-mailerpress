<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    global $wpdb;

    // Check if table exists before modifying it
    $contactTable = $wpdb->prefix . Tables::MAILERPRESS_CONTACT;
    $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$contactTable}'") === $contactTable;

    if ($tableExists) {
        $schema->table(Tables::MAILERPRESS_CONTACT, function (CustomTableManager $table) {
            $table->modifyColumn('subscription_status', "
                ENUM('subscribed', 'unsubscribed', 'pending', 'bounced')
                NULL DEFAULT 'subscribed'
            ");

            $table->setVersion('0.0.2');
        });
    }
};
