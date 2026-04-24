<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_TEMPLATES,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->string('name', 255);
            $table->longText('content');
            $table->text('description');

            $table->column('path', 'MEDIUMTEXT')->nullable();

            $table->datetime('created_at')->nullable()->default('CURRENT_TIMESTAMP');
            $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

            $table->boolean('internal')->nullable();
            
            $table->setVersion('0.0.1');
        }
    );
};
