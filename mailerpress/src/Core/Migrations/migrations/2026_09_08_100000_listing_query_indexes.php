<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_EMAIL_CHUNKS, function (CustomTableManager $table) {
        $table->addIndex(['batch_id', 'status', 'scheduled_at']);
        $table->setVersion('2.1.0');
    });
};
