<?php

namespace MailerPress\Models;

use MailerPress\Core\Enums\Tables;

class Campaigns
{
    /**
     * Get a campaign by ID from wp_mailerpress_campaigns
     *
     * @param int $campaignId
     * @return object|null
     */
    public function find($campaignId): ?object
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $sql = $wpdb->prepare("SELECT * FROM $table WHERE campaign_id = %d LIMIT 1", $campaignId);

        return $wpdb->get_row($sql);
    }
}
