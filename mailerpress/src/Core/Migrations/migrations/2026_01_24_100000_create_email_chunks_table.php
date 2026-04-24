<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(Tables::MAILERPRESS_EMAIL_CHUNKS, function (CustomTableManager $table) {
        // Primary key
        $table->bigInteger('id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('id');

        // Relations
        $table->bigInteger('batch_id')->unsigned();
        $table->integer('chunk_index')->unsigned();

        // Status tracking
        $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'retry'])
              ->default('pending');

        // Data storage
        $table->text('contact_ids')->nullable(); // JSON: [123, 456, 789]
        $table->longText('chunk_data')->nullable(); // JSON: {html, subject, config...}

        // Retry logic
        $table->integer('retry_count')->unsigned()->default(0);
        $table->text('error_message')->nullable();

        // Timing
        $table->addColumn('scheduled_at', 'DATETIME NULL');
        $table->addColumn('started_at', 'DATETIME NULL');
        $table->addColumn('completed_at', 'DATETIME NULL');
        $table->addColumn('created_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
        $table->addColumn('updated_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        // Indexes
        $table->addIndex('batch_id');
        $table->addIndex('status');
        $table->addIndex(['batch_id', 'status']);
        $table->addIndex('scheduled_at');

        // Foreign key
        $table->addForeignKey('batch_id', Tables::MAILERPRESS_EMAIL_BATCHES, 'id', 'CASCADE', 'CASCADE');

        $table->setVersion('1.3.0');
    });
};
