<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {

    $schema->create(
        Tables::MAILERPRESS_IMPORT_CHUNKS,
        function (CustomTableManager $table) {
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->bigInteger('batch_id')->unsigned();
            $table->longText('chunk_data');

            $table->boolean('processed')->nullable()->default(0);

            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');

            $table->setVersion('0.0.1');
        }
    );


    $schema->create(Tables::MAILERPRESS_CONTACT,
        function (CustomTableManager $table) {
            $table->integer('contact_id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('contact_id');

            $table->string('email', 255)->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();

            $table->enum('subscription_status', ['subscribed', 'unsubscribed', 'pending'])
                ->nullable()
                ->default('subscribed');

            $table->string('opt_in_source', 255); // varchar(255), pas précisé nullable donc NOT NULL
            $table->text('opt_in_details')->nullable();

            // Les timestamps avec gestion automatique
            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

            $table->string('unsubscribe_token', 64)->nullable();

            // Index
            $table->addIndex('email', 'UNIQUE');

            // Version de la table
            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_CONTACT_NOTE,
        function (CustomTableManager $table) {
            // Clé primaire auto-incrémentée
            $table->integer('note_id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('note_id');

            // Colonnes
            $table->integer('contact_id')->unsigned();
            $table->text('content');

            // Datetimes
            $table->datetime('created_at')->nullable()->default('CURRENT_TIMESTAMP');
            $table->datetime('updated_at')->nullable()->default('CURRENT_TIMESTAMP');

            // Index
            $table->addIndex('contact_id');

            // Clé étrangère
            $table->addForeignKey(
                'contact_id',
                Tables::MAILERPRESS_CONTACT,
                'contact_id',
                'CASCADE'
            );

            // Version
            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_TAGS,
        function (CustomTableManager $table) {
            $table->integer('tag_id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('tag_id');

            $table->string('name', 100)->unique();
            $table->boolean('sync')->default(0);

            // Version de la table
            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::CONTACT_TAGS,
        function (CustomTableManager $table) {
            // Colonnes (pas d'auto-increment ici)
            $table->integer('contact_id')->unsigned();
            $table->integer('tag_id')->unsigned();

            // Clé primaire composée
            $table->setPrimaryKey('contact_id, tag_id');

            // Index sur tag_id pour optimiser les recherches inverses
            $table->addIndex('tag_id');

            // Clés étrangères
            $table->addForeignKey('contact_id', Tables::MAILERPRESS_CONTACT, 'contact_id', 'CASCADE', 'RESTRICT');
            $table->addForeignKey('tag_id', Tables::MAILERPRESS_TAGS, 'tag_id', 'CASCADE', 'RESTRICT');

            // Version de la table
            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_LIST,
        function (CustomTableManager $table) {
            $table->integer('list_id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('list_id');

            $table->string('name', 255);
            $table->boolean('sync')->default(0);

            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_CONTACT_LIST,
        function (CustomTableManager $table) {
            $table->integer('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->integer('contact_id')->unsigned();
            $table->integer('list_id')->unsigned();

            $table->addColumn('added_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');

            $table->addIndex('contact_id, list_id', 'UNIQUE');

            $table->addIndex('list_id');

            $table->addForeignKey('contact_id', Tables::MAILERPRESS_CONTACT, 'contact_id', 'CASCADE', 'RESTRICT');
            $table->addForeignKey('list_id', Tables::MAILERPRESS_LIST, 'list_id', 'CASCADE', 'RESTRICT');

            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_CONTACT_BATCHES,
        function (CustomTableManager $table) {
            $table->integer('batch_id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('batch_id');

            $table->json('tags')->nullable();
            $table->json('lists')->nullable();

            $table->enum('subscription_status', ['subscribed', 'unsubscribed', 'pending'])
                ->default('pending');

            $table->integer('count')->unsigned()->default(0);
            $table->integer('processed_count')->unsigned()->default(0);

            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

            $table->enum('status', ['done', 'pending', 'failure'])->default('pending');

            $table->setVersion('0.0.1');
        }
    );

    $schema->create(
        Tables::MAILERPRESS_IMPORT_CONTACT_QUEUE,
        function (CustomTableManager $table) {
            $table->integer('id')->unsigned()->autoIncrement();
            $table->setPrimaryKey('id');

            $table->integer('batch_id')->unsigned();
            $table->string('email', 255);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();

            $table->addColumn('created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
            $table->addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

            $table->addIndex('batch_id');

            $table->addForeignKey('batch_id', Tables::MAILERPRESS_CONTACT_BATCHES, 'batch_id', 'CASCADE', 'RESTRICT');

            $table->setVersion('0.0.1');
        }
    );


};
