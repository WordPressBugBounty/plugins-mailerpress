<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    // Track cart table for abandoned cart workflows
    $schema->create(Tables::MAILERPRESS_TRACK_CART, function (CustomTableManager $table) {
        $table->id('id');
        $table->string('cart_hash', 64)->unique();
        $table->unsignedBigInteger('user_id');
        $table->string('customer_email', 255)->nullable();
        $table->json('cart_data')->nullable(); // Store cart items, total, etc.
        $table->enum('status', ['ACTIVE', 'EMPTIED', 'COMPLETED'])->default('ACTIVE');
        $table->column('created_at', 'TIMESTAMP')->nullable();
        $table->column('updated_at', 'TIMESTAMP')->nullable();
        $table->column('emptied_at', 'TIMESTAMP')->nullable();

        // Indexes for faster lookups
        $table->addIndex('cart_hash');
        $table->addIndex('user_id');
        $table->addIndex('status');
        $table->addIndex('created_at');

        $table->setVersion('1.2.0');
    });
};
