<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(Tables::MAILERPRESS_SEGMENTS, function (CustomTableManager $table) {
        $table->id();
        $table->string('name'); // Name of the segment
        $table->longText('conditions'); // Store the JSON conditions
        $table->column('created_at', 'DATETIME')->nullable()->default('CURRENT_TIMESTAMP');
        $table->column('updated_at', 'DATETIME')->nullable()->default('CURRENT_TIMESTAMP');
        $table->addIndex('name');
        $table->setVersion('0.0.2');
    });
};
