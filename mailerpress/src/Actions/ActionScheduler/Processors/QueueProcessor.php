<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\QueueManager;

class QueueProcessor
{
    #[Action('mailerpress_process_queue_worker')]
    public static function processQueue(): void
    {
        $queueManager = QueueManager::getInstance();
        $nextJob = $queueManager->getNextJob();
        if (null !== $nextJob) {
            $queueManager->processJob($nextJob);
        }
    }
}
