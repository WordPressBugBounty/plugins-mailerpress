<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(Tables::MAILERPRESS_CAMPAIGN_REVISIONS, function (CustomTableManager $table) {
        $table->bigInteger('revision_id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('revision_id');
        $table->bigInteger('campaign_id')->unsigned();
        $table->longText('json')->nullable();
        $table->bigInteger('created_by')->unsigned()->nullable();
        $table->dateTime('created_at')->nullable()->default('CURRENT_TIMESTAMP');

        $table->addIndex('campaign_id');
        $table->addIndex('created_by');
        $table->addForeignKey('campaign_id', Tables::MAILERPRESS_CAMPAIGNS, 'campaign_id', 'CASCADE', 'CASCADE');

        $table->setVersion('0.3.4');
    });

    $schema->table(Tables::MAILERPRESS_TEMPLATES, function (CustomTableManager $table) {
        $table->string('version', 64);
        // no unique() here yet
        $table->setVersion('0.3.1');
    });
};
