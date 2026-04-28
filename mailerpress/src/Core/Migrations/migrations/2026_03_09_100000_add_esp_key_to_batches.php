<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

/**
 * Add esp_key column to email batches.
 *
 * Builder calls are unconditional so getExpectedTableStructure() replays correctly.
 * CustomTableManager::migrate() handles idempotency internally.
 */
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_EMAIL_BATCHES, function (CustomTableManager $table) {
        $table->string('esp_key', 50)->nullable()->after('subject');
        $table->setVersion('2.0.1');
    });
};
