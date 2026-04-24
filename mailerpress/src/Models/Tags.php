<?php

declare(strict_types=1);

namespace MailerPress\Models;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class Tags
{
    /**
     * @return mixed
     */
    public static function getAll()
    {
        global $wpdb;
        
        $table_name = Tables::get(Tables::MAILERPRESS_TAGS);
        
        // Check if table exists before querying (cached in memory)
        $table_exists = Tables::exists($table_name);
        
        if (!$table_exists) {
            // Table doesn't exist yet, return empty array
            // This can happen during initial installation before migrations run
            return [];
        }
        
        $tags = $wpdb->get_results("SELECT tag_id, name FROM {$table_name}");

        return $tags ?: [];
    }
}
