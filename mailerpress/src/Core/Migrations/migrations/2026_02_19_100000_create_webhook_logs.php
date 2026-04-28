<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_WEBHOOK_LOGS,
        function (CustomTableManager $table) {
            $table->bigInteger('log_id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('log_id');

            $table->enum('direction', ['incoming', 'outgoing']);
            $table->string('webhook_id', 255)->nullable();
            $table->string('event_key', 255)->nullable();
            $table->integer('status_code')->nullable();
            $table->addColumn('success', 'TINYINT(1) DEFAULT 1');
            $table->text('url')->nullable();

            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');

            $table->addIndex('direction');
            $table->addIndex('created_at');

            $table->setVersion('2.0.0');
        }
    );
};
