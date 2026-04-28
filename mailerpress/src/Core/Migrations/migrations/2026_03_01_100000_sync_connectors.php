<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_SYNC_CONNECTORS,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->string('connector_key', 100)->unique();
            $table->string('label', 255);
            $table->enum('status', ['active', 'inactive', 'error'])->default('inactive');
            $table->longText('settings')->nullable();

            $table->addColumn('last_sync_at', 'TIMESTAMP NULL');
            $table->integer('last_sync_count')->unsigned()->default(0);
            $table->text('last_error')->nullable();

            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

            $table->addIndex('connector_key', 'UNIQUE');
            $table->addIndex('status');

            $table->setVersion('1.0.0');
        }
    );
};
