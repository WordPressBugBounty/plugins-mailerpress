<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

use MailerPress\Core\Attributes\Action;
use MailerPress\Services\ContactEngagementClassifier;

\defined('ABSPATH') || exit;

class AccessTokenGenerator
{

    #[Action('mailerpress_recalculate_engagement')]
    public function calculateEngagement()
    {
        global $wpdb;
        $classifier = new ContactEngagementClassifier($wpdb);
        $classifier->queueRecalculationJobs();
    }

    #[Action('mailerpress_recalculate_engagement_batch', priority: 10, acceptedArgs: 2)]
    public function calculateEngagementBatch($offset, $limit)
    {
        global $wpdb;
        (new ContactEngagementClassifier($wpdb))->processBatch((int)$offset, (int)$limit);
    }
}