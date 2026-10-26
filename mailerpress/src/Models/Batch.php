<?php

declare(strict_types=1);

namespace MailerPress\Models;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class Batch
{
    public function getById($id, $withStats = false): null|array|object
    {
        global $wpdb;

        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mailerpress_email_batches WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        if ($withStats) {
            $stats = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT
            (SELECT SUM(emails_opened) FROM {$wpdb->prefix}mailerpress_email_queue WHERE batch_id = %d) AS total_opens,
            (SELECT sent_emails FROM {$wpdb->prefix}mailerpress_email_batches WHERE id = %d) AS sent_emails
         FROM {$wpdb->prefix}mailerpress_email_batches
         WHERE id = %d",
                    $id,
                    $id,
                    $id
                ),
                ARRAY_A
            );
        }

        if ($batch && !$withStats) {
            return $batch;
        }
        if ($batch && $withStats) {
            return array_merge(
                $batch,
                $stats
            );
        }

        return null;
    }

    public function getStatistics($batchId): null|array|object
    {
        global $wpdb;

        $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $campaignStatsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGN_STATS);

        // 1️⃣ Get the campaign_id for this batch
        $campaignId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT campaign_id FROM {$wpdb->prefix}mailerpress_email_batches WHERE id = %d",
                (int)$batchId
            )
        );

        // 2️⃣ Base stats from tracking table
        $baseStats = $wpdb->get_row(
            $wpdb->prepare(
                "
            SELECT
                COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL THEN contact_id END) AS total_opens,
                SUM(clicks) AS total_clicks,
                COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL THEN contact_id END) AS total_unsubscribes
            FROM
                {$trackingTable}
            WHERE
                batch_id = %d
            GROUP BY
                batch_id
            ",
                (int)$batchId
            ),
            ARRAY_A
        );

        // 3️⃣ Campaign stats if exists
        $campaignStats = [];
        if ($campaignId) {
            $campaignStats = $wpdb->get_row(
                $wpdb->prepare(
                    "
                SELECT
                    total_click AS campaign_total_click
                FROM
                    {$campaignStatsTable}
                WHERE
                    campaign_id = %d
                LIMIT 1
                ",
                    (int)$campaignId
                ),
                ARRAY_A
            ) ?: [];
        }

        // 4️⃣ Merge datasets
        $stats = array_merge(
            [
                'total_opens' => 0,
                'total_clicks' => 0,
                'total_unsubscribes' => 0,
                'campaign_total_click' => 0,
            ],
            (array)$baseStats,
            (array)$campaignStats
        );

        // 5️⃣ Metrics added by extensions
        $stats = apply_filters('mailerpress_campaign_statistics', $stats, (int) $campaignId);

        return (object)$stats;
    }

    public function classifyCampaignWeighted(int $campaignId): string
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CONTACT_STATS);

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "
            SELECT
                COUNT(*) AS total_contacts,
                SUM(opened) AS total_opens,
                SUM(clicked) AS total_clicks,
                SUM(click_count) AS total_click_count
            FROM {$table}
            WHERE campaign_id = %d
            ",
                $campaignId
            ),
            ARRAY_A
        );

        if (!$stats || $stats['total_contacts'] == 0) {
            return 'bad';
        }

        $total = (int)$stats['total_contacts'];
        $openRate = $stats['total_opens'] / $total;
        $clickRate = $stats['total_clicks'] / $total;

        // Weighted score: openers = 70%, clicks = 30%
        $score = ($openRate * 0.7) + ($clickRate * 0.3);

        // Classification thresholds
        if ($score >= 0.8) {
            return 'perfect';
        }
        if ($score >= 0.5) {
            return 'good';
        }
        if ($score >= 0.2) {
            return 'neutral';
        }

        return 'bad';
    }
}
