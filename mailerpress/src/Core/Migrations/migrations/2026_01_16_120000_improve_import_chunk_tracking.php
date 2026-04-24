<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

/**
 * Migration to improve import chunk tracking with timing and retry columns.
 *
 * Uses SchemaBuilder API so getExpectedTableStructure() can replay correctly.
 */
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_IMPORT_CHUNKS, function (CustomTableManager $table) {
        $table->column('processing_started_at', 'TIMESTAMP')->nullable()->after('processed');
        $table->column('processing_completed_at', 'TIMESTAMP')->nullable()->after('processing_started_at');
        $table->column('retry_count', 'INT UNSIGNED')->default(0)->after('processing_completed_at');
        $table->string('error_message', 255)->nullable()->after('retry_count');
        $table->addIndex(['batch_id', 'processed']);
        $table->addIndex('processing_started_at');
        $table->setVersion('1.2.2');
    });
};
