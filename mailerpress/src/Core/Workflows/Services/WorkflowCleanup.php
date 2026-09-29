<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Enums\Tables;
use MailerPress\Services\Logger;

class WorkflowCleanup
{
    private int $jobRetentionDays;
    private int $logRetentionDays;
    private int $batchSize;

    public function __construct(int $jobRetentionDays = 30, int $logRetentionDays = 90, int $batchSize = 500)
    {
        $this->jobRetentionDays = apply_filters('mailerpress_cleanup_job_retention_days', $jobRetentionDays);
        $this->logRetentionDays = apply_filters('mailerpress_cleanup_log_retention_days', $logRetentionDays);
        $this->batchSize = $batchSize;
    }

    public function cleanup(): array
    {
        $deletedJobs = $this->cleanupOldJobs();
        $deletedLogs = $this->cleanupOldLogs();
        $expiredGoals = $this->expireOverdueGoals();

        Logger::info('WorkflowCleanup: completed', [
            'deleted_jobs' => $deletedJobs,
            'deleted_logs' => $deletedLogs,
            'expired_goals' => $expiredGoals,
        ]);

        return ['jobs' => $deletedJobs, 'logs' => $deletedLogs, 'expired_goals' => $expiredGoals];
    }

    /**
     * Safety net for goals whose Action Scheduler expiry was lost (scheduler
     * flushed, site migrated…). Without this a run could wait forever.
     */
    private function expireOverdueGoals(): int
    {
        try {
            // Re-arm first: a goal whose expiry action went missing would
            // otherwise only ever expire through this daily sweep.
            GoalManager::instance()->ensureTimeoutsScheduled();

            return GoalManager::instance()->expireOverdueGoals();
        } catch (\Exception $e) {
            Logger::warning('WorkflowCleanup: failed to expire overdue goals', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function cleanupOldJobs(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_JOBS;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$this->jobRetentionDays} days"));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN ('COMPLETED', 'FAILED', 'CANCELLED') AND updated_at < %s LIMIT %d",
            $cutoff,
            $this->batchSize
        ));
    }

    private function cleanupOldLogs(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_LOG;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$this->logRetentionDays} days"));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
            $cutoff,
            $this->batchSize
        ));
    }
}
