<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_EMBED_API_KEYS,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            // API Key (stored as SHA-256 hash)
            $table->string('api_key', 64)->unique();

            // Metadata
            $table->string('name', 255); // User-friendly identifier
            $table->string('allowed_domain', 255)->nullable(); // Comma-separated whitelist
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->text('notes')->nullable();

            // Usage tracking
            $table->integer('request_count')->unsigned()->default(0);
            $table->addColumn('last_used_at', 'TIMESTAMP NULL');

            // Rate limiting configuration
            $table->integer('rate_limit_requests')->unsigned()->default(100); // Requests per window
            $table->integer('rate_limit_window')->unsigned()->default(3600); // Window in seconds (1 hour)

            // Timestamps
            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

            // Indexes for performance
            $table->addIndex('api_key', 'UNIQUE');
            $table->addIndex('status');
            $table->addIndex('allowed_domain');
            $table->addIndex('created_at');

            $table->setVersion('1.3.0');
        }
    );
};
