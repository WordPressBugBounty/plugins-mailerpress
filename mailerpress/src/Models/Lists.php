<?php

declare(strict_types=1);

namespace MailerPress\Models;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class Lists
{
    public static function getLists()
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_LIST);

        // Check if table exists before querying (cached in memory)
        $table_exists = Tables::exists($table_name);

        if (!$table_exists) {
            // Table doesn't exist yet, return empty array
            // This can happen during initial installation before migrations run
            return [];
        }

        $contact_list_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        $lists = $wpdb->get_results(
            "SELECT t.*, COUNT(c.contact_id) as contact_count
            FROM {$table_name} t
            LEFT JOIN {$contact_list_table} c ON c.list_id = t.list_id
            GROUP BY t.list_id",
            ARRAY_A
        );

        return $lists ?: [];
    }
}
