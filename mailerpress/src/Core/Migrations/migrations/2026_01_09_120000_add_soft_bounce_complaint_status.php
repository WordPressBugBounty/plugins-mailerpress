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
            // Add 'soft_bounce' and 'complaint' to the subscription_status enum
            $table->modifyColumn('subscription_status', "
                ENUM('subscribed', 'unsubscribed', 'pending', 'bounced', 'soft_bounce', 'complaint')
                NULL DEFAULT 'subscribed'
            ");

            $table->setVersion('1.2.0');
        });
    }
};
