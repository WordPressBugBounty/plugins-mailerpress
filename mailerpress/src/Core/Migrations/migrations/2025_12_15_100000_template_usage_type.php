<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_TEMPLATES, function (CustomTableManager $table) {
        // Add usage_type column to distinguish between newsletter and automation templates
        $table->enum('usage_type', ['newsletter', 'automation'])->default('newsletter');
        $table->addIndex('usage_type');
        $table->setVersion('1.2.0');
    });
};

