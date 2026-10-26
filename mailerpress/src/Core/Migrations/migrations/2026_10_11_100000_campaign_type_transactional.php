<?php
/**
 * Add the "transactional" campaign type.
 *
 * Transactional emails are designed in the MailerPress editor and sent by the
 * integration that owns them (for example an opt-in lead magnet delivery).
 *
 * @package MailerPress
 */

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    global $wpdb;

    $campaignsTable = $wpdb->prefix . Tables::MAILERPRESS_CAMPAIGNS;
    $campaignsExists = $wpdb->get_var("SHOW TABLES LIKE '{$campaignsTable}'") === $campaignsTable;

    if ($campaignsExists) {
        $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
            $table->modifyColumn('campaign_type', "ENUM(
                'newsletter',
                'ab_test',
                'automated',
                'automation',
                'wp_email',
                'wc_email',
                'confirm_email',
                'transactional'
            ) NOT NULL DEFAULT 'newsletter'");

            $table->setVersion('2.2.0');
        });
    }
};
