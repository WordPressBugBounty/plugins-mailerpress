<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

// Earlier installations could drop the unique index without creating its replacement.
// A separate migration also repairs sites where that migration is already completed.
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_EMAIL_TRACKING, function (CustomTableManager $table) {
        $table->dropIndex('batch_id_contact_id_unique');
        $table->addIndex(['batch_id', 'contact_id']);
        $table->setVersion('2.1.0');
    });
};
