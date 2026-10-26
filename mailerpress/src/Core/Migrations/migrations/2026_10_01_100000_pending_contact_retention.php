<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

return function ( SchemaBuilder $schema ) {
	$schema->table(
		Tables::MAILERPRESS_CONTACT,
		function ( CustomTableManager $table ) {
			$table->datetime( 'pending_since' )->nullable();
			$table->addIndex( array( 'subscription_status', 'contact_id' ) );
		}
	);
};
