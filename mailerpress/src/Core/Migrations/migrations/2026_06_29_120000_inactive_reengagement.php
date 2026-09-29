<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_CONTACT, function (CustomTableManager $table) {
        $table->addColumn('reengagement_sent_at', 'DATETIME NULL AFTER `inactivation_reason`');
        $table->addColumn('reengagement_confirmed_at', 'DATETIME NULL AFTER `reengagement_sent_at`');

        $table->addIndex('reengagement_sent_at');
        $table->addIndex('reengagement_confirmed_at');

        $table->setVersion('2.0.9');
    });
};
