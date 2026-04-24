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

        $lists = $wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);

        return $lists ?: [];
    }
}
