<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

/**
 * Migration to add anonymous_key column for anonymous tracking.
 *
 * Adds anonymous_key column to email_tracking and click_tracking tables
 * with indexes for performance.
 *
 * Uses SchemaBuilder API so getExpectedTableStructure() can replay correctly.
 */
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_EMAIL_TRACKING, function (CustomTableManager $table) {
        $table->string('anonymous_key', 64)->nullable()->after('contact_id');
        $table->addIndex('anonymous_key');
        $table->addIndex(['batch_id', 'anonymous_key']);
        $table->setVersion('1.5.1');
    });

    $schema->table(Tables::MAILERPRESS_CLICK_TRACKING, function (CustomTableManager $table) {
        $table->string('anonymous_key', 64)->nullable()->after('contact_id');
        $table->addIndex('anonymous_key');
        $table->addIndex(['campaign_id', 'anonymous_key']);
        $table->setVersion('1.5.1');
    });
};
