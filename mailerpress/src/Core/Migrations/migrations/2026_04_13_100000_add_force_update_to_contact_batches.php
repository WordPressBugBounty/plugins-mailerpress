<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

/**
 * Add force_update column to contact batches table.
 * Required so background chunk processors know whether to update existing contacts,
 * since the original forceUpdate flag is only available at request time.
 */
return function (SchemaBuilder $schema) {
	$schema->table(Tables::MAILERPRESS_CONTACT_BATCHES, function (CustomTableManager $table) {
		$table->addColumn('force_update', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `subscription_status`');
		$table->setVersion('2.0.2');
	});
};
