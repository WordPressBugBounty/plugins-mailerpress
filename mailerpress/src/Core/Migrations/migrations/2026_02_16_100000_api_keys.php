<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(
        Tables::MAILERPRESS_API_KEYS,
        function (CustomTableManager $table) {
            $table->bigInteger('key_id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('key_id');

            // User who owns this API key
            $table->bigInteger('user_id')->unsigned();

            // API Key and Secret (both stored as SHA-256 hashes for security)
            $table->string('api_key_hash', 64)->unique();
            $table->string('api_secret_hash', 64);

            // Metadata
            $table->string('name', 255); // User-friendly identifier (e.g., "Production Server", "Mobile App")
            $table->text('description')->nullable();

            // Permissions (JSON array of scopes)
            // Examples: ["contacts:read", "contacts:write", "campaigns:read", "campaigns:write"]
            $table->text('permissions')->nullable();

            // Status
            $table->enum('status', ['active', 'revoked', 'expired'])->default('active');

            // Rate limiting (per key)
            $table->integer('rate_limit_requests')->unsigned()->default(1000); // Requests per window
            $table->integer('rate_limit_window')->unsigned()->default(3600); // Window in seconds (1 hour)

            // IP whitelisting (optional)
            $table->text('allowed_ips')->nullable(); // Comma-separated list of IPs or CIDR ranges

            // Usage tracking
            $table->integer('request_count')->unsigned()->default(0);
            $table->addColumn('last_used_at', 'TIMESTAMP NULL');

            // Expiration
            $table->addColumn('expires_at', 'TIMESTAMP NULL');

            // Timestamps
            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

            // Indexes for performance
            $table->addIndex('api_key_hash', 'UNIQUE');
            $table->addIndex('user_id');
            $table->addIndex('status');
            $table->addIndex('created_at');
            $table->addIndex('last_used_at');

            $table->setVersion('2.0.0');
        }
    );
};
