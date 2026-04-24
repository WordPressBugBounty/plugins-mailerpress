<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    /**
     * --------------------------------------------------------------------------
     * Campaign Statistics Table
     * --------------------------------------------------------------------------
     * Stores aggregated stats per campaign (open rate, click rate, etc.)
     */
    $schema->create(Tables::MAILERPRESS_CAMPAIGN_STATS, function (CustomTableManager $table) {
        $table->bigInteger('id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('id');

        $table->bigInteger('campaign_id')->unsigned();
        $table->integer('total_sent')->default(0);
        $table->integer('total_open')->default(0);
        $table->integer('total_click')->default(0);
        $table->integer('total_unsubscribe')->default(0);
        $table->integer('total_bounce')->default(0);
        $table->decimal('total_revenue', 10, 2)->default(0);

        $table->dateTime('created_at')->nullable()->default('CURRENT_TIMESTAMP');
        $table->dateTime('updated_at')->nullable()->default('CURRENT_TIMESTAMP');

        $table->addIndex('campaign_id');
        $table->addForeignKey('campaign_id', Tables::MAILERPRESS_CAMPAIGNS, 'campaign_id', 'CASCADE', 'CASCADE');

        $table->setVersion('1.0.1');
    });

    /**
     * --------------------------------------------------------------------------
     * Contact Statistics Table
     * --------------------------------------------------------------------------
     * Tracks opens, clicks, and engagement classification for each contact/campaign.
     */
    $schema->create(Tables::MAILERPRESS_CONTACT_STATS, function (CustomTableManager $table) {
        $table->bigInteger('id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('id');

        $table->integer('contact_id')->unsigned();
        $table->bigInteger('campaign_id')->unsigned();

        $table->boolean('opened')->default(false);
        $table->boolean('clicked')->default(false);
        $table->integer('click_count')->default(0);
        $table->dateTime('last_click_at')->nullable();
        $table->decimal('revenue', 10, 2)->default(0);
        $table->enum('status', ['good', 'bad', 'neutral'])->default('neutral');

        $table->dateTime('created_at')->nullable()->default('CURRENT_TIMESTAMP');
        $table->dateTime('updated_at')->nullable()->default('CURRENT_TIMESTAMP');

        $table->addIndex(['contact_id', 'campaign_id']);
        $table->addForeignKey('contact_id', Tables::MAILERPRESS_CONTACT, 'contact_id', 'CASCADE', 'CASCADE');
        $table->addForeignKey('campaign_id', Tables::MAILERPRESS_CAMPAIGNS, 'campaign_id', 'CASCADE', 'CASCADE');

        $table->setVersion('1.0.1');
    });

    /**
     * --------------------------------------------------------------------------
     * Click Tracking Table
     * --------------------------------------------------------------------------
     * Records each individual link click event with timestamp and metadata.
     */
    $schema->create(Tables::MAILERPRESS_CLICK_TRACKING, function (CustomTableManager $table) {
        $table->bigInteger('id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('id');

        $table->integer('contact_id')->unsigned();
        $table->bigInteger('campaign_id')->unsigned();
        $table->text('url');
        $table->string('ip_address', 50)->nullable();
        $table->text('user_agent')->nullable();

        $table->dateTime('created_at')->nullable()->default('CURRENT_TIMESTAMP');

        $table->addIndex(['contact_id', 'campaign_id']);
        $table->addForeignKey('contact_id', Tables::MAILERPRESS_CONTACT, 'contact_id', 'CASCADE', 'CASCADE');
        $table->addForeignKey('campaign_id', Tables::MAILERPRESS_CAMPAIGNS, 'campaign_id', 'CASCADE', 'CASCADE');

        $table->setVersion('1.0.1');
    });

    $schema->create(Tables::MAILERPRESS_CUSTOM_FIELD_DEFINITIONS, function (CustomTableManager $table) {
        $table->bigInteger('id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('id');

        $table->string('field_key', 100)->unique(); // Unique programmatic key, e.g. "first_name"
        $table->string('label', 255);               // Display label for admins
        $table->string('type', 50);                 // text, number, date, select, checkbox, etc.
        $table->text('options')->nullable();        // JSON or serialized options for select/checkbox
        $table->boolean('required')->default(false);
        $table->boolean('is_editable')->default(true);

        $table->dateTime('created_at')->nullable()->default('CURRENT_TIMESTAMP');
        $table->dateTime('updated_at')->nullable()->default('CURRENT_TIMESTAMP');

        $table->setVersion('1.0.1');
    });

    $schema->create(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS, function (CustomTableManager $table) {
        $table->bigInteger('field_id')->unsigned()->autoIncrement();
        $table->setPrimaryKey('field_id');

        $table->integer('contact_id')->unsigned()->nullable();
        $table->string('field_key', 100);
        $table->text('field_value')->nullable();

        $table->dateTime('created_at')->nullable()->default('CURRENT_TIMESTAMP');
        $table->dateTime('updated_at')->nullable()->default('CURRENT_TIMESTAMP');

        $table->addIndex('contact_id');
        $table->addForeignKey('contact_id', Tables::MAILERPRESS_CONTACT, 'contact_id', 'CASCADE', 'CASCADE');

        $table->setVersion('1.0.1');
    });

    // Only modify contact table if it exists
    global $wpdb;
    $contactTable = $wpdb->prefix . Tables::MAILERPRESS_CONTACT;
    $contactExists = $wpdb->get_var("SHOW TABLES LIKE '{$contactTable}'") === $contactTable;

    if ($contactExists) {
        $schema->table(Tables::MAILERPRESS_CONTACT, function (CustomTableManager $table) {
            $table->enum('engagement_status', ['good', 'neutral', 'bad'])
                ->default('neutral')
                ->after('subscription_status');

            $table->setVersion('1.0.1'); // bump version to trigger update
        });
    }
};
