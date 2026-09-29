<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

return function (SchemaBuilder $schema) {
    $schema->create(Tables::MAILERPRESS_MIGRATION_RUNS, function (CustomTableManager $table) {
        $table->bigInteger('id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('id');

        $table->string('source_key', 100);
        $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending');
        $table->enum('mode', ['import', 'dry_run'])->default('import');
        $table->longText('settings')->nullable();
        $table->longText('totals')->nullable();
        $table->integer('processed_count')->unsigned()->default(0);
        $table->integer('skipped_count')->unsigned()->default(0);
        $table->integer('error_count')->unsigned()->default(0);
        $table->text('last_error')->nullable();

        $table->addColumn('started_at', 'TIMESTAMP NULL');
        $table->addColumn('completed_at', 'TIMESTAMP NULL');
        $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
        $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        $table->addIndex('source_key');
        $table->addIndex('status');
        $table->addIndex(['source_key', 'status']);

        $table->setVersion('2.0.7');
    });

    $schema->create(Tables::MAILERPRESS_MIGRATION_CHUNKS, function (CustomTableManager $table) {
        $table->bigInteger('id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('id');

        $table->bigInteger('run_id')->unsigned();
        $table->string('source_key', 100);
        $table->string('entity_type', 100);
        $table->longText('payload')->nullable();
        $table->enum('status', ['pending', 'queued', 'processing', 'completed', 'failed', 'skipped', 'cancelled'])->default('pending');
        $table->integer('processed_count')->unsigned()->default(0);
        $table->integer('skipped_count')->unsigned()->default(0);
        $table->integer('error_count')->unsigned()->default(0);
        $table->text('error_message')->nullable();
        $table->integer('retry_count')->unsigned()->default(0);

        $table->addColumn('scheduled_at', 'TIMESTAMP NULL');
        $table->addColumn('started_at', 'TIMESTAMP NULL');
        $table->addColumn('completed_at', 'TIMESTAMP NULL');
        $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
        $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        $table->addIndex('run_id');
        $table->addIndex('source_key');
        $table->addIndex('entity_type');
        $table->addIndex('status');
        $table->addIndex(['run_id', 'status']);

        $table->setVersion('2.0.7');
    });

    $schema->create(Tables::MAILERPRESS_MIGRATION_MAPPINGS, function (CustomTableManager $table) {
        $table->bigInteger('id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('id');

        $table->string('source_key', 100);
        $table->string('source_entity', 100);
        $table->string('source_id', 191);
        $table->string('target_entity', 100);
        $table->string('target_id', 191);
        $table->longText('metadata')->nullable();
        $table->bigInteger('run_id')->unsigned()->nullable();

        $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
        $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        $table->addIndex(['source_key', 'source_entity', 'source_id'], 'UNIQUE');
        $table->addIndex(['target_entity', 'target_id']);
        $table->addIndex('run_id');

        $table->setVersion('2.0.7');
    });
};
