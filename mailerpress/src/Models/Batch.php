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
                    total_click AS campaign_total_click,
                    total_revenue AS campaign_total_revenue
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
                'campaign_total_revenue' => 0,
            ],
            (array)$baseStats,
            (array)$campaignStats
        );

        // 5️⃣ Format revenue using WooCommerce settings
        $revenue = (float)$stats['campaign_total_revenue'];
        $revenue = (float)$stats['campaign_total_revenue'];
        // Before using WooCommerce functions
        if (function_exists('WC')) {
            $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
            $formatted_number = number_format(
                $revenue,
                wc_get_price_decimals(),
                wc_get_price_decimal_separator(),
                wc_get_price_thousand_separator()
            );

            switch (get_option('woocommerce_currency_pos', 'left')) {
                case 'right':
                    $stats['campaign_total_revenue'] = $formatted_number . ' ' . $currency_symbol;
                    break;
                case 'left_space':
                    $stats['campaign_total_revenue'] = $currency_symbol . ' ' . $formatted_number;
                    break;
                case 'right_space':
                    $stats['campaign_total_revenue'] = $formatted_number . ' ' . $currency_symbol;
                    break;
                case 'left': // default
                default:
                    $stats['campaign_total_revenue'] = $currency_symbol . $formatted_number;
                    break;
            }
        } else {
            // Fallback if WooCommerce not installed
            $stats['campaign_total_revenue'] = number_format($revenue, 2);
        }
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
                SUM(click_count) AS total_click_count,
                SUM(revenue) AS total_revenue
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
        $revenuePerContact = $stats['total_revenue'] / $total;

        // Weighted score: openers = 70%, clicks = 30%
        $score = ($openRate * 0.7) + ($clickRate * 0.3);

        // Optional: boost if revenue is positive
        if ($revenuePerContact > 0) {
            $score += min($revenuePerContact / 100, 0.1); // small bonus
            $score = min($score, 1); // cap at 1
        }

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
