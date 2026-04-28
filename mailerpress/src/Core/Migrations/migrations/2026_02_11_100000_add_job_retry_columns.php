<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

/**
 * Add retry tracking columns to automation jobs.
 *
 * Builder calls are unconditional so getExpectedTableStructure() replays correctly.
 * CustomTableManager::migrate() handles idempotency internally.
 */
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_AUTOMATIONS_JOBS, function (CustomTableManager $table) {
        $table->integer('retry_count')->default(0)->after('status');
        $table->integer('max_retries')->default(3)->after('retry_count');
        $table->text('last_error')->nullable()->after('max_retries');
        $table->setVersion('2.0.0');
    });
};
