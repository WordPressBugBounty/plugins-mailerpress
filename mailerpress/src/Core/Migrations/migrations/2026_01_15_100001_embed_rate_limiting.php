<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_EMBED_RATE_LIMIT,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            // Reference to API key (hashed)
            $table->string('api_key_hash', 64);

            // Client identification
            $table->string('ip_address', 45); // Supports both IPv4 and IPv6

            // Rate limit tracking
            $table->integer('request_count')->unsigned()->default(1);
            $table->addColumn('window_start', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('window_end', 'TIMESTAMP NULL');

            // Composite index for fast lookups (most common query pattern)
            $table->addIndex('api_key_hash, window_start');

            // Index for cleanup queries
            $table->addIndex('window_end');

            $table->setVersion('1.3.0');
        }
    );
};
