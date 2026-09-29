<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_AI_USAGE_EVENTS,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->bigInteger('user_id')->unsigned()->default(0);
            $table->string('feature', 64);
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->enum('status', ['completed', 'failed'])->default('completed');

            $table->integer('prompt_tokens')->unsigned()->default(0);
            $table->integer('cached_prompt_tokens')->unsigned()->default(0);
            $table->integer('completion_tokens')->unsigned()->default(0);
            $table->integer('total_tokens')->unsigned()->default(0);
            $table->integer('image_count')->unsigned()->default(0);
            $table->bigInteger('estimated_cost_micros')->unsigned()->default(0);
            $table->string('currency', 8)->default('usd');
            $table->longText('raw_usage_json')->nullable();

            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');

            $table->addIndex('created_at');
            $table->addIndex('provider, created_at');
            $table->addIndex('user_id, created_at');
            $table->addIndex('feature, created_at');

            $table->setVersion('2.1.0');
        }
    );
};
