<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_EMAIL_LOGS,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            // Email details
            $table->string('to_email', 255);
            $table->string('subject', 500)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('reply_to', 255)->nullable();

            // Service and status
            $table->string('service', 50)->nullable(); // 'php', 'smtp', 'brevo', etc.
            $table->enum('status', ['success', 'error', 'pending'])->default('pending');
            $table->text('error_message')->nullable();

            // Related entities
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->bigInteger('contact_id')->unsigned()->nullable();
            $table->bigInteger('batch_id')->unsigned()->nullable();
            $table->bigInteger('job_id')->unsigned()->nullable(); // For automation emails

            // Additional metadata
            $table->text('headers')->nullable(); // JSON encoded headers
            $table->longText('body_preview')->nullable(); // First 500 chars of body for debugging
            $table->boolean('is_html')->default(1);

            // WordPress wp_mail result
            $table->boolean('wp_mail_result')->nullable(); // true/false from wp_mail()
            $table->text('wp_error')->nullable(); // WP_Error message if any

            // Timestamps
            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('sent_at', 'DATETIME NULL');

            // Indexes for performance
            $table->addIndex('to_email');
            $table->addIndex('status');
            $table->addIndex('service');
            $table->addIndex('campaign_id');
            $table->addIndex('contact_id');
            $table->addIndex('batch_id');
            $table->addIndex('created_at');
            $table->addIndex('sent_at');

            $table->setVersion('1.2.0');
        }
    );
};
