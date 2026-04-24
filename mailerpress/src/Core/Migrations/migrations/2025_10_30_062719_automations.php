<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    global $wpdb;

    /**
     * First, remove automation references from campaigns (only if table exists)
     */
    $campaignsTable = $wpdb->prefix . Tables::MAILERPRESS_CAMPAIGNS;
    $campaignsExists = $wpdb->get_var("SHOW TABLES LIKE '{$campaignsTable}'") === $campaignsTable;

    if ($campaignsExists) {
        $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
            // Drop foreign key if exists
            $table->dropForeign('automation_id');

            // Drop the column
            $table->dropColumn('automation_id');

            // Optional: drop index if it exists
            $table->dropIndex('automation_id');

            $table->setVersion('1.2.2');
        });
    }

    /**
     * Now create automation tables safely
     * Note: If old tables were dropped, create() will create new ones
     */

    // Automations
    $schema->create(Tables::MAILERPRESS_AUTOMATIONS, function (CustomTableManager $table) {
        $table->id('id');
        $table->string('name')->nullable();
        $table->unsignedBigInteger('author')->nullable();
        $table->enum('status', ['DRAFT', 'ENABLED'])->default('DRAFT');
        $table->column('created_at', 'TIMESTAMP')->nullable();
        $table->column('updated_at', 'TIMESTAMP')->nullable();
        $table->boolean('run_once_per_subscriber')->default(0);
        $table->setVersion('1.2.6');
    });

    // Steps
    $schema->create(Tables::MAILERPRESS_AUTOMATIONS_STEPS, function (CustomTableManager $table) {
        $table->id('id');
        $table->unsignedBigInteger('automation_id');
        $table->string('step_id', 192);
        $table->enum('type', ['TRIGGER', 'ACTION', 'DELAY', 'CONDITION']);
        $table->string('key', 192);
        $table->json('settings')->nullable();
        $table->string('next_step_id', 192)->nullable();
        $table->string('alternative_step_id', 192)->nullable();
        $table->column('created_at', 'TIMESTAMP')->nullable();
        $table->column('updated_at', 'TIMESTAMP')->nullable();

        $table->addForeignKey('automation_id', Tables::MAILERPRESS_AUTOMATIONS, 'id', 'CASCADE');

        $table->setVersion('1.2.5');
    });

    // Step branches
    $schema->create(Tables::MAILERPRESS_AUTOMATIONS_STEP_BRANCHES, function (CustomTableManager $table) {
        $table->id('id');
        $table->unsignedBigInteger('step_id');
        $table->json('condition');
        $table->string('next_step_id', 192);
        $table->column('created_at', 'TIMESTAMP')->nullable();
        $table->column('updated_at', 'TIMESTAMP')->nullable();

        $table->addForeignKey('step_id', Tables::MAILERPRESS_AUTOMATIONS_STEPS, 'id', 'CASCADE');

        $table->setVersion('1.2.2');
    });

    // Jobs
    $schema->create(Tables::MAILERPRESS_AUTOMATIONS_JOBS, function (CustomTableManager $table) {
        $table->id('id');
        $table->unsignedBigInteger('automation_id');
        $table->unsignedBigInteger('user_id');
        $table->string('next_step_id', 192)->nullable();
        $table->enum('status', ['ACTIVE', 'PROCESSING', 'COMPLETED', 'FAILED', 'PAUSED', 'CANCELLED', 'WAITING'])->default('ACTIVE');
        $table->column('scheduled_at', 'TIMESTAMP')->nullable();
        $table->column('created_at', 'TIMESTAMP')->nullable();
        $table->column('updated_at', 'TIMESTAMP')->nullable();

        $table->addForeignKey('automation_id', Tables::MAILERPRESS_AUTOMATIONS, 'id', 'CASCADE');

        $table->setVersion('1.2.2');
    });

    // Logs
    $schema->create(Tables::MAILERPRESS_AUTOMATIONS_LOG, function (CustomTableManager $table) {
        $table->id('id');
        $table->unsignedBigInteger('automation_id');
        $table->string('step_id', 192);
        $table->unsignedBigInteger('user_id');
        $table->enum('status', ['PROCESSING', 'COMPLETED', 'EXITED']);
        $table->json('data')->nullable();
        $table->column('created_at', 'TIMESTAMP')->nullable();
        $table->column('updated_at', 'TIMESTAMP')->nullable();

        $table->addForeignKey('automation_id', Tables::MAILERPRESS_AUTOMATIONS, 'id', 'CASCADE');

        $table->setVersion('1.2.2');
    });

    // Meta
    $schema->create(Tables::MAILERPRESS_AUTOMATIONS_META, function (CustomTableManager $table) {
        $table->id('id');
        $table->unsignedBigInteger('automation_id');
        $table->string('meta_key', 50)->nullable();
        $table->json('meta_value')->nullable();
        $table->column('created_at', 'TIMESTAMP')->nullable();
        $table->column('updated_at', 'TIMESTAMP')->nullable();

        $table->addForeignKey('automation_id', Tables::MAILERPRESS_AUTOMATIONS, 'id', 'CASCADE');

        $table->setVersion('1.2.2');
    });
};
