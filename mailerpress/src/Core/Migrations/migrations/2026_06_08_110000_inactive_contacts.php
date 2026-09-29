<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_CONTACT, function (CustomTableManager $table) {
        $table->modifyColumn('subscription_status', "
            ENUM('subscribed', 'unsubscribed', 'pending', 'bounced', 'soft_bounce', 'complaint', 'inactive')
            NULL DEFAULT 'subscribed'
        ");

        $table->addColumn('last_engagement_at', 'DATETIME NULL AFTER `subscription_status`');
        $table->addColumn('last_open_at', 'DATETIME NULL AFTER `last_engagement_at`');
        $table->addColumn('last_click_at', 'DATETIME NULL AFTER `last_open_at`');
        $table->addColumn('last_sending_at', 'DATETIME NULL AFTER `last_click_at`');
        $table->addColumn('last_subscribed_at', 'DATETIME NULL AFTER `last_sending_at`');
        $table->addColumn('email_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_subscribed_at`');
        $table->addColumn('inactivated_at', 'DATETIME NULL AFTER `email_count`');
        $table->addColumn('inactivation_reason', 'VARCHAR(100) NULL AFTER `inactivated_at`');

        $table->addIndex('last_engagement_at');
        $table->addIndex('last_sending_at');
        $table->addIndex('last_subscribed_at');
        $table->addIndex('email_count');

        $table->setVersion('2.0.8');
    });

    $schema->table(Tables::MAILERPRESS_CONTACT_BATCHES, function (CustomTableManager $table) {
        $table->modifyColumn('subscription_status', "
            ENUM('subscribed', 'unsubscribed', 'pending', 'inactive')
            DEFAULT 'pending'
        ");

        $table->setVersion('2.0.7');
    });
};
