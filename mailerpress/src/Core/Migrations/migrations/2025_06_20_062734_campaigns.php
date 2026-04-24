<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_CAMPAIGNS,
        function (CustomTableManager $table) {
            $table->bigInteger('campaign_id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('campaign_id');

            $table->bigInteger('user_id')->unsigned();
            $table->string('name', 255);
            $table->string('subject', 255)->nullable();

            $table->enum('status', [
                'draft',
                'scheduled',
                'in_progress',
                'sent',
                'pending',
                'error',
                'trash'
            ])->default('draft');

            $table->enum('email_type', ['plain_text', 'html'])->default('html');

            $table->longText('content_html')->nullable();
            $table->longText('content_plain_text')->nullable();
            $table->longText('config')->nullable();

            $table->datetime('created_at')->nullable()->default('CURRENT_TIMESTAMP');
            $table->datetime('updated_at')->nullable()->default('CURRENT_TIMESTAMP')->extra('ON UPDATE CURRENT_TIMESTAMP');

            $table->integer('batch_id')->nullable();

            $table->addIndex('user_id');
            $table->addIndex('updated_at');
            $table->addIndex('name');
            $table->addIndex('status');

            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_EMAIL_BATCHES,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->bigInteger('campaign_id')->unsigned();

            $table->datetime('created_at')->nullable()->default('CURRENT_TIMESTAMP');
            $table->datetime('updated_at')->nullable();

            $table->enum('status', [
                'pending',
                'in_progress',
                'completed',
                'failed',
                'scheduled'
            ])->default('pending');

            $table->integer('total_emails')->unsigned()->nullable()->default(0);
            $table->integer('total_open')->unsigned()->nullable();
            $table->integer('sent_emails')->unsigned()->nullable()->default(0);
            $table->integer('error_emails')->unsigned()->nullable()->default(0);

            $table->text('error_message')->nullable();

            $table->datetime('scheduled_at')->nullable();

            $table->string('sender_name', 255)->nullable();
            $table->string('sender_to', 255)->nullable();
            $table->string('subject', 255)->nullable();

            $table->integer('offset')->nullable()->default(0);

            $table->addIndex('status');
            $table->addIndex('campaign_id');
            $table->addIndex('scheduled_at');

            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_EMAIL_QUEUE,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->bigInteger('batch_id')->unsigned()->nullable();
            $table->integer('contact_id')->unsigned()->nullable();

            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->nullable();
            $table->text('error_message')->nullable();

            $table->longText('html_content')->nullable();

            $table->integer('emails_opened')->unsigned()->nullable()->default(0);
            $table->integer('emails_clicked')->unsigned()->nullable()->default(0);

            $table->boolean('unsubscribed')->nullable()->default(0);

            $table->datetime('processed_at')->nullable();

            $table->addIndex('batch_id');
            $table->addIndex('contact_id');

            $table->addForeignKey('batch_id', Tables::MAILERPRESS_EMAIL_BATCHES, 'id', 'CASCADE', 'RESTRICT');
            $table->addForeignKey('contact_id', Tables::MAILERPRESS_CONTACT, 'contact_id', 'RESTRICT', 'RESTRICT');

            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_EMAIL_TRACKING,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->bigInteger('batch_id')->unsigned();
            $table->integer('contact_id')->unsigned();

            $table->datetime('opened_at')->nullable();
            $table->integer('clicks')->unsigned()->nullable()->default(0);
            $table->datetime('unsubscribed_at')->nullable();

            // Indexes
            $table->addIndex('contact_id');
            $table->addIndex(['batch_id', 'contact_id'], 'UNIQUE');

            // Foreign Keys
            $table->addForeignKey('batch_id', Tables::MAILERPRESS_EMAIL_BATCHES, 'id', 'CASCADE', 'RESTRICT');
            $table->addForeignKey('contact_id', Tables::MAILERPRESS_CONTACT, 'contact_id', 'CASCADE', 'RESTRICT');

            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_QUEUE_JOB,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->autoIncrement();
            $table->setPrimaryKey('id');

            $table->longText('job');
            $table->boolean('attempts')->default(0);

            $table->datetime('reserved_at')->nullable();
            $table->datetime('available_at');
            $table->datetime('created_at');

            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_QUEUE_JOB_FAILURE,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->autoIncrement();
            $table->setPrimaryKey('id');

            $table->longText('job');
            $table->text('error')->nullable();

            $table->datetime('failed_at');

            $table->setVersion('0.0.1');
        }
    );

};
