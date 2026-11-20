<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;
use MailerPress\Core\Workflows\Repositories\AutomationLogRepository;

/** Finish only the runs belonging to a cart that has left recovery. */
class CartWorkflowExit
{
    public static function finish(array $cart, string $status): void
    {
        $jobs = new AutomationJobRepository();
        $logs = new AutomationLogRepository();
        $reason = 'COMPLETED' === $status ? 'Cart recovered' : 'Cart emptied';
        foreach ($jobs->findLiveByCart((int) $cart['user_id'], $cart['cart_hash']) as $job) {
            if (!$jobs->completeLive((int) $job->getId())) {
                continue;
            }
            ActionSchedulerManager::cancelActionsForJob((int) $job->getId());
            $logs->log($job->getAutomationId(), $job->getNextStepId() ?? 'exit', $job->getUserId(), 'EXITED', [
                '_job_id' => $job->getId(), 'cart_hash' => $cart['cart_hash'], 'reason' => $reason,
            ]);
            $job->setStatus('COMPLETED')->setNextStepId(null)->setScheduledAt(null)->setLastError(null);
            do_action('mailerpress_workflow_job_completed', $job);
        }
        // Generic business events; extensions own their integrations (including Goals).
        do_action('COMPLETED' === $status ? 'mailerpress_cart_recovered' : 'mailerpress_cart_emptied', [
            'user_id' => (int) $cart['user_id'],
            'customer_email' => $cart['customer_email'],
            'cart_hash' => $cart['cart_hash'],
        ]);
    }
}
