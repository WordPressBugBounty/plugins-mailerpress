<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Services\InactiveContactManager;

return function (SchemaBuilder $schema) {
    global $wpdb;

    $tableExists = static function (string $table) use ($wpdb): bool {
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    };

    $columnExists = static function (string $table, string $column) use ($wpdb, $tableExists): bool {
        if (!$tableExists($table)) {
            return false;
        }

        return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
    };

    $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
    if (
        !$tableExists($contactTable)
        || !$columnExists($contactTable, 'last_subscribed_at')
        || !$columnExists($contactTable, 'email_count')
        || !$columnExists($contactTable, 'last_sending_at')
        || !$columnExists($contactTable, 'last_open_at')
        || !$columnExists($contactTable, 'last_click_at')
        || !$columnExists($contactTable, 'last_engagement_at')
    ) {
        return;
    }

    if ($columnExists($contactTable, 'created_at')) {
        $wpdb->query(
            "UPDATE `{$contactTable}`
             SET `last_subscribed_at` = `created_at`
             WHERE `last_subscribed_at` IS NULL
               AND `created_at` IS NOT NULL"
        );
    }

    $contactStatsTable = Tables::get(Tables::MAILERPRESS_CONTACT_STATS);
    if (
        $tableExists($contactStatsTable)
        && $columnExists($contactStatsTable, 'contact_id')
        && $columnExists($contactStatsTable, 'created_at')
    ) {
        $wpdb->query(
            "UPDATE `{$contactTable}` c
             INNER JOIN (
                 SELECT `contact_id`, COUNT(*) AS sent_count, MAX(`created_at`) AS last_sent_at
                 FROM `{$contactStatsTable}`
                 WHERE `contact_id` > 0
                 GROUP BY `contact_id`
             ) stats ON stats.contact_id = c.contact_id
             SET c.email_count = GREATEST(COALESCE(c.email_count, 0), stats.sent_count),
                 c.last_sending_at = IF(
                    c.last_sending_at IS NULL OR c.last_sending_at < stats.last_sent_at,
                    stats.last_sent_at,
                    c.last_sending_at
                 )"
        );
    }

    $emailTrackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
    $openSources = [];

    if (
        $tableExists($emailTrackingTable)
        && $columnExists($emailTrackingTable, 'contact_id')
        && $columnExists($emailTrackingTable, 'opened_at')
    ) {
        $openSources[] = "SELECT `contact_id`, MAX(`opened_at`) AS opened_at
                          FROM `{$emailTrackingTable}`
                          WHERE `contact_id` > 0 AND `opened_at` IS NOT NULL
                          GROUP BY `contact_id`";
    }

    if (
        $tableExists($contactStatsTable)
        && $columnExists($contactStatsTable, 'contact_id')
        && $columnExists($contactStatsTable, 'opened')
        && $columnExists($contactStatsTable, 'updated_at')
    ) {
        $openSources[] = "SELECT `contact_id`, MAX(`updated_at`) AS opened_at
                          FROM `{$contactStatsTable}`
                          WHERE `contact_id` > 0 AND `opened` > 0 AND `updated_at` IS NOT NULL
                          GROUP BY `contact_id`";
    }

    if (!empty($openSources)) {
        $wpdb->query(
            "UPDATE `{$contactTable}` c
             INNER JOIN (
                 SELECT contact_id, MAX(opened_at) AS opened_at
                 FROM (" . implode(' UNION ALL ', $openSources) . ") open_events
                 GROUP BY contact_id
             ) opens ON opens.contact_id = c.contact_id
             SET c.last_open_at = IF(c.last_open_at IS NULL OR c.last_open_at < opens.opened_at, opens.opened_at, c.last_open_at)"
        );
    }

    $clickTrackingTable = Tables::get(Tables::MAILERPRESS_CLICK_TRACKING);
    $clickSources = [];

    if (
        $tableExists($clickTrackingTable)
        && $columnExists($clickTrackingTable, 'contact_id')
        && $columnExists($clickTrackingTable, 'created_at')
    ) {
        $clickSources[] = "SELECT `contact_id`, MAX(`created_at`) AS clicked_at
                           FROM `{$clickTrackingTable}`
                           WHERE `contact_id` > 0 AND `created_at` IS NOT NULL
                           GROUP BY `contact_id`";
    }

    if (
        $tableExists($contactStatsTable)
        && $columnExists($contactStatsTable, 'contact_id')
        && $columnExists($contactStatsTable, 'last_click_at')
    ) {
        $clickSources[] = "SELECT `contact_id`, MAX(`last_click_at`) AS clicked_at
                           FROM `{$contactStatsTable}`
                           WHERE `contact_id` > 0 AND `last_click_at` IS NOT NULL
                           GROUP BY `contact_id`";
    }

    if (!empty($clickSources)) {
        $wpdb->query(
            "UPDATE `{$contactTable}` c
             INNER JOIN (
                 SELECT contact_id, MAX(clicked_at) AS clicked_at
                 FROM (" . implode(' UNION ALL ', $clickSources) . ") click_events
                 GROUP BY contact_id
             ) clicks ON clicks.contact_id = c.contact_id
             SET c.last_click_at = IF(c.last_click_at IS NULL OR c.last_click_at < clicks.clicked_at, clicks.clicked_at, c.last_click_at)"
        );
    }

    $wpdb->query(
        "UPDATE `{$contactTable}`
         SET `last_engagement_at` = IF(
            `last_engagement_at` IS NULL
                OR `last_engagement_at` < GREATEST(
                    COALESCE(`last_open_at`, '1000-01-01 00:00:00'),
                    COALESCE(`last_click_at`, '1000-01-01 00:00:00')
                ),
            GREATEST(
                COALESCE(`last_open_at`, '1000-01-01 00:00:00'),
                COALESCE(`last_click_at`, '1000-01-01 00:00:00')
            ),
            `last_engagement_at`
         )
         WHERE `last_open_at` IS NOT NULL
            OR `last_click_at` IS NOT NULL"
    );

    add_option(InactiveContactManager::OPTION_NAME, wp_json_encode([
        'enabled' => false,
        'inactive_period_unit' => 'years',
        'inactive_period_value' => 1,
        'inactive_after_days' => 365,
        'batch_size' => 1000,
    ]), '', false);
};
