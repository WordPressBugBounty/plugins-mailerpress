<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_CLICK_TRACKING, function (CustomTableManager $table) {
        $table->string('link_id', 64)->nullable()->after('url');
        $table->addIndex('link_id');
        $table->addIndex(['campaign_id', 'link_id']);
        $table->setVersion('2.1.1');
    });
};
