<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;

return function (SchemaBuilder $schema) {
    global $wpdb;
    $syncs = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);
    $contacts = Tables::get(Tables::MAILERPRESS_CONTACT);

    // Preserve ownership of contacts created by the original single configuration.
    $legacyId = (int) $wpdb->get_var("SELECT MIN(id) FROM {$syncs} WHERE connector_key = 'wordpress_users'");
    if ($legacyId) {
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$contacts} SET opt_in_details = JSON_SET(opt_in_details, '$.sync_ids', JSON_ARRAY(%d))
             WHERE opt_in_source = 'wp_sync' AND JSON_VALID(opt_in_details)
             AND JSON_EXTRACT(opt_in_details, '$.wp_user_id') IS NOT NULL
             AND JSON_EXTRACT(opt_in_details, '$.sync_ids') IS NULL",
            $legacyId
        ));
        if ($result === false) {
            throw new \RuntimeException('Could not preserve WordPress synchronization contact ownership.');
        }
    }
};
