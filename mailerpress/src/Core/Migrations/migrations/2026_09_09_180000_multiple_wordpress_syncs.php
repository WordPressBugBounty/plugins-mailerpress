<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_SYNC_CONNECTORS, function (CustomTableManager $table) {
        $table->dropIndex('connector_key_unique');
        $table->addIndex(['connector_key', 'id']);
        $table->longText('run_state')->nullable();
        $table->setVersion('1.1.0');
    });

};
