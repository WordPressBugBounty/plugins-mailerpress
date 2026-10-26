<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

// The dashboard counts contacts and campaigns by creation date, and every admin
// screen counts the automation jobs that failed recently.
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_CONTACT, function (CustomTableManager $table) {
        $table->addIndex('created_at');
        $table->setVersion('2.2.0');
    });

    $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
        $table->addIndex('created_at');
        $table->setVersion('2.2.0');
    });

    $schema->table(Tables::MAILERPRESS_AUTOMATIONS_JOBS, function (CustomTableManager $table) {
        $table->addIndex(['status', 'updated_at']);
        $table->setVersion('2.2.0');
    });
};
