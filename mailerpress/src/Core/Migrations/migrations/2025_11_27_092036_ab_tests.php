<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    // Table pour les tests A/B
    $schema->create(
        'mailerpress_ab_tests',
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');
            $table->string('step_id', 255);
            $table->bigInteger('automation_id')->unsigned();
            $table->string('test_name', 255);
            $table->bigInteger('version_a_template_id')->unsigned();
            $table->text('version_a_subject')->nullable();
            $table->bigInteger('version_b_template_id')->unsigned();
            $table->text('version_b_subject')->nullable();
            $table->integer('split_percentage')->default(50);
            $table->integer('test_duration')->default(24);
            $table->string('winning_criteria', 50)->default('open_rate');
            $table->string('status', 50)->default('running');
            $table->string('winner', 1)->nullable();
            $table->decimal('winner_metric', 10, 2)->nullable();
            $table->datetime('created_at');
            $table->datetime('completed_at')->nullable();
            $table->addIndex('step_id');
            $table->addIndex('automation_id');
            $table->addIndex('status');
            $table->setVersion('1.2.0');
        }
    );

    // Table pour les participants aux tests A/B
    $schema->create(
        'mailerpress_ab_test_participants',
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');
            $table->bigInteger('test_id')->unsigned();
            $table->bigInteger('user_id');
            $table->string('test_group', 1);
            $table->datetime('sent_at');
            $table->datetime('opened_at')->nullable();
            $table->datetime('clicked_at')->nullable();
            $table->addIndex('test_id');
            $table->addIndex('user_id');
            $table->addIndex('test_group');
            $table->setVersion('1.2.0');
        }
    );
};

