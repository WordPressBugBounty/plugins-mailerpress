<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

// Legacy installations may name the unique index `batch_id` instead of
// `batch_id_contact_id_unique`. Match its columns, regardless of its name.
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_EMAIL_TRACKING, function (CustomTableManager $table) {
        $table->dropUniqueIndex(['batch_id', 'contact_id']);
        $table->addIndex(['batch_id', 'contact_id']);
        $table->setVersion('2.1.0');
    });
};
