<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

/**
 * Migration to allow anonymous tracking (contact_id = 0) in tracking tables.
 *
 * This migration:
 * 1. Removes foreign key constraints on contact_id in email_tracking and click_tracking tables
 * 2. Removes the unique index on (batch_id, contact_id) in email_tracking to allow multiple anonymous records
 * 3. Adds a non-unique index instead for performance
 *
 * All operations use SchemaBuilder API so the repair/diagnostic system tracks them.
 * dropForeign + dropIndex are combined in a single ALTER TABLE — FK is dropped first by MySQL.
 */
return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_EMAIL_TRACKING, function (CustomTableManager $table) {
        $table->dropForeign('contact_id');
        $table->dropIndex('batch_id_contact_id_unique');
        $table->addIndex(['batch_id', 'contact_id']);
        $table->setVersion('1.5.0');
    });

    $schema->table(Tables::MAILERPRESS_CLICK_TRACKING, function (CustomTableManager $table) {
        $table->dropForeign('contact_id');
        $table->setVersion('1.5.0');
    });
};
