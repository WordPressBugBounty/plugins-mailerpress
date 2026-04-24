<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    $schema->create(Tables::MAILERPRESS_CATEGORIES, function (CustomTableManager $table) {
        $table->id('category_id');
        $table->string('name');
        $table->string('slug')->unique();
        $table->enum('type', ['template', 'pattern'])->default('template');
        $table->column('created_at', 'DATETIME')->nullable()->default('CURRENT_TIMESTAMP');

        $table->addIndex('type');
        $table->setVersion('0.0.1');
    });

    $schema->create(
        Tables::MAILERPRESS_TEMPLATES,
        function (CustomTableManager $table) {
            $table->dropColumn('category');
            // Set category as a foreign key to another table (e.g., 'mailerpress_categories')
            $table->unsignedBigInteger('cat_id')->nullable();
            // Clé étrangère
            $table->addForeignKey(
                'cat_id',
                Tables::MAILERPRESS_CATEGORIES,
                'category_id'
            );
            $table->setVersion('0.0.2');
        }
    );
};
