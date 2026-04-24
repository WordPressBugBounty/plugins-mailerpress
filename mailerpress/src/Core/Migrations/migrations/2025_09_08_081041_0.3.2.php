<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
        $table->bigInteger('editing_user_id')->nullable();
        $table->datetime('editing_started_at')->nullable();
        // no unique() here yet
        $table->setVersion('0.3.2');
    });
};
