<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    global $wpdb;

    // Check if tables exist before modifying them
    $campaignsTable = $wpdb->prefix . Tables::MAILERPRESS_CAMPAIGNS;
    $emailBatchesTable = $wpdb->prefix . Tables::MAILERPRESS_EMAIL_BATCHES;
    $contactTable = $wpdb->prefix . Tables::MAILERPRESS_CONTACT;
    $listTable = $wpdb->prefix . Tables::MAILERPRESS_LIST;

    // Only modify campaigns table if it exists
    $campaignsExists = $wpdb->get_var("SHOW TABLES LIKE '{$campaignsTable}'") === $campaignsTable;
    if ($campaignsExists) {
        $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
            // Modify the status ENUM column to include 'active' and 'inactive'
            $table->modifyColumn('status', "ENUM(
                'draft',
                'scheduled',
                'in_progress',
                'sent',
                'pending',
                'error',
                'trash',
                'active',
                'inactive'
            ) NOT NULL DEFAULT 'draft'");

            // Add campaign_type column if it doesn't exist
            $table->enum('campaign_type', ['newsletter', 'automated', 'automation'])->default('newsletter')->after('subject');
            $table->bigInteger('automation_id')->unsigned()->nullable()->after('campaign_type');

            $table->addIndex('campaign_type');
            $table->addIndex('automation_id');

            // Only add foreign key if automation table exists (will be checked in migrate())
            // Note: Foreign key will only be added if the referenced table exists
            $table->addForeignKey('automation_id', Tables::MAILERPRESS_AUTOMATIONS, 'id', 'CASCADE', 'RESTRICT');

            $table->setVersion('0.3.0');
        });
    }

    // Only modify email_batches table if it exists
    $emailBatchesExists = $wpdb->get_var("SHOW TABLES LIKE '{$emailBatchesTable}'") === $emailBatchesTable;
    if ($emailBatchesExists) {
        $schema->table(Tables::MAILERPRESS_EMAIL_BATCHES, function (CustomTableManager $table) {
            // Modify the status ENUM column to include 'sent'
            $table->modifyColumn('status', "ENUM(
            'pending',
            'in_progress',
            'completed',
            'failed',
            'scheduled',
            'sent'
        ) NOT NULL DEFAULT 'pending'");

            $table->setVersion('0.3.0');
        });
    }

    // Only modify contact table if it exists
    $contactExists = $wpdb->get_var("SHOW TABLES LIKE '{$contactTable}'") === $contactTable;
    if ($contactExists) {
        $schema->table(Tables::MAILERPRESS_CONTACT, function (CustomTableManager $table) {
            $table->string('access_token', 64)->nullable()->after('email'); // allow NULLs initially
            // no unique() here yet
            $table->setVersion('0.3.0');
        });
    }

    // Only modify list table if it exists
    $listExists = $wpdb->get_var("SHOW TABLES LIKE '{$listTable}'") === $listTable;
    if ($listExists) {
        $schema->table(Tables::MAILERPRESS_LIST, function (CustomTableManager $table) {
            $table->text('description')->nullable()->after('name');
            $table->setVersion('0.3.0'); // Important: bump version
        });
    }
};
