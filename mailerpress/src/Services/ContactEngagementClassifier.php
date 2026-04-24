<?php

namespace MailerPress\Services;

use wpdb;
use MailerPress\Core\Enums\Tables;

class ContactEngagementClassifier
{
    protected $wpdb;
    protected $batchSize = 500; // adjust for performance

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Schedule batch jobs for engagement recalculation.
     */
    public function queueRecalculationJobs(): void
    {
        $totalContacts = (int)$this->wpdb->get_var("
            SELECT COUNT(DISTINCT contact_id)
            FROM " . Tables::get(Tables::MAILERPRESS_CONTACT_STATS)
        );

        $pages = ceil($totalContacts / $this->batchSize);

        for ($page = 0; $page < $pages; $page++) {
            $offset = $page * $this->batchSize;
            as_enqueue_async_action('mailerpress_recalculate_engagement_batch', [$offset, $this->batchSize]);
        }
    }

    /**
     * Process a single batch of contacts.
     */
    public function processBatch(int $offset, int $limit): void
    {
        $contacts = $this->wpdb->get_col($this->wpdb->prepare("
            SELECT DISTINCT contact_id
            FROM " . Tables::get(Tables::MAILERPRESS_CONTACT_STATS) . "
            LIMIT %d OFFSET %d
        ", $limit, $offset));

        foreach ($contacts as $contactId) {
            $this->classifyContact((int)$contactId);
        }
    }

    public function classifyContact(int $contactId): string
    {
        $score = $this->calculateEngagementScore($contactId);

        if ($score >= 70) {
            $status = 'good';
        } elseif ($score >= 30) {
            $status = 'neutral';
        } else {
            $status = 'bad';
        }

        // ✅ Update the contact table, not contact_stats
        $this->wpdb->update(
            Tables::get(Tables::MAILERPRESS_CONTACT),
            [
                'engagement_status' => $status,
                'updated_at' => current_time('mysql')
            ],
            ['contact_id' => $contactId]
        );

        return $status;
    }


    public function calculateEngagementScore(int $contactId): float
    {
        $table = Tables::get(Tables::MAILERPRESS_CONTACT_STATS);

        $stats = $this->wpdb->get_results(
            $this->wpdb->prepare("
                SELECT opened, clicked, click_count
                FROM {$table}
                WHERE contact_id = %d
            ", $contactId)
        );

        if (empty($stats)) {
            return 0;
        }

        $total = count($stats);
        $opens = array_sum(array_column($stats, 'opened'));
        $clicks = array_sum(array_column($stats, 'clicked'));
        $clickCount = array_sum(array_column($stats, 'click_count'));

        $openRate = $opens / $total;
        $clickRate = $clicks / $total;
        $clickIntensity = min($clickCount / ($total * 3), 1);

        $score = ($openRate * 70) + ($clickRate * 25) + ($clickIntensity * 5);

        return round($score, 2);
    }
}
