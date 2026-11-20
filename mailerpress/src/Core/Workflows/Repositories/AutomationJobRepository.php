<?php

namespace MailerPress\Core\Workflows\Repositories;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Models\AutomationJob;

class AutomationJobRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_JOBS;
    }

    public function find(int $id): ?AutomationJob
    {
        $query = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id);
        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new AutomationJob($result) : null;
    }

    /** Lock a run while executing a step so an external exit cannot be overwritten. */
    public function findForUpdate(int $id): ?AutomationJob
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id), ARRAY_A);
        return $row ? new AutomationJob($row) : null;
    }

    /** Atomically resume only a live run; stale scheduled callbacks cannot revive it. */
    public function resume(int $id, ?string $nextStepId): bool
    {
        $step = null === $nextStepId ? 'next_step_id' : $this->wpdb->prepare('%s', $nextStepId);
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET status = 'ACTIVE', scheduled_at = NULL, next_step_id = {$step}, updated_at = %s
             WHERE id = %d AND status IN ('ACTIVE', 'WAITING')",
            current_time('mysql'), $id
        ));
        $job = false !== $result ? $this->find($id) : null;
        return $job && $job->isActive();
    }

    /** Runs are linked through their own log id, never another run of the same contact. */
    public function findLiveByCart(int $userId, string $cartHash): array
    {
        $logs = $this->wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_LOG;
        $steps = $this->wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEPS;
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$this->table} j INNER JOIN {$logs} l
             ON j.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(l.data, '$._job_id')) AS UNSIGNED)
             WHERE j.status IN ('ACTIVE', 'PROCESSING', 'WAITING', 'PAUSED')
             AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.data, '$.cart_user_id')), JSON_UNQUOTE(JSON_EXTRACT(l.data, '$.user_id'))) = %s
             AND JSON_UNQUOTE(JSON_EXTRACT(l.data, '$.cart_hash')) = %s
             AND EXISTS (SELECT 1 FROM {$steps} s WHERE s.automation_id = j.automation_id
                         AND s.type = 'TRIGGER' AND s.`key` = 'woocommerce_abandoned_cart')",
            (string) $userId, $cartHash
        ), ARRAY_A);
        return array_map(static fn($row) => new AutomationJob($row), $rows);
    }

    public function completeLive(int $id): bool
    {
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET status = 'COMPLETED', next_step_id = NULL,
             scheduled_at = NULL, last_error = NULL, updated_at = %s
             WHERE id = %d AND status IN ('ACTIVE', 'PROCESSING', 'WAITING', 'PAUSED')",
            current_time('mysql'), $id
        ));
    }

    public function failLive(int $id, string $error): bool
    {
        return 1 === $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET status = 'FAILED', last_error = %s, updated_at = %s
             WHERE id = %d AND status IN ('ACTIVE', 'PROCESSING', 'WAITING')",
            $error, current_time('mysql'), $id
        ));
    }

    public function create(int $automationId, int $userId, ?string $nextStepId = null, array $context = []): ?AutomationJob
    {
        // Entry policies also apply to callers that create a run directly.
        if (!apply_filters('mailerpress_workflow_job_can_create', true, $automationId, $userId, $context)) {
            return null;
        }

        $data = [
            'automation_id' => $automationId,
            'user_id' => $userId,
            'next_step_id' => $nextStepId,
            'status' => 'ACTIVE',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $result = $this->wpdb->insert($this->table, $data);

        if ($result === false) {
          return null;
        }

        if ($result) {
            $data['id'] = $this->wpdb->insert_id;
            $job = new AutomationJob($data);
            do_action('mailerpress_workflow_job_created', $job, $context);
            return $job;
        }

        return null;
    }

    public function update(AutomationJob $job): bool
    {
        return $this->wpdb->update(
            $this->table,
            [
                'next_step_id' => $job->getNextStepId(),
                'status' => $job->getStatus(),
                'retry_count' => $job->getRetryCount(),
                'max_retries' => $job->getMaxRetries(),
                'last_error' => $job->getLastError(),
                'scheduled_at' => $job->getScheduledAt(),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $job->getId()],
            ['%s', '%s', '%d', '%d', '%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    public function findActiveByAutomationAndUser(int $automationId, int $userId, bool $includeWaiting = false): ?AutomationJob
    {
        if ($includeWaiting) {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE automation_id = %d
                 AND user_id = %d
                 AND status IN ('ACTIVE', 'PROCESSING', 'WAITING')
                 ORDER BY created_at DESC
                 LIMIT 1",
                $automationId,
                $userId
            );
        } else {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE automation_id = %d
                 AND user_id = %d
                 AND status IN ('ACTIVE', 'PROCESSING')
                 ORDER BY created_at DESC
                 LIMIT 1",
                $automationId,
                $userId
            );
        }

        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new AutomationJob($result) : null;
    }

    public function findCompletedByAutomationAndUser(int $automationId, int $userId): ?AutomationJob
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE automation_id = %d
             AND user_id = %d
             AND status = 'COMPLETED'
             ORDER BY updated_at DESC
             LIMIT 1",
            $automationId,
            $userId
        );
        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new AutomationJob($result) : null;
    }

    public function findAnyByAutomationAndUser(int $automationId, int $userId): ?AutomationJob
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE automation_id = %d
             AND user_id = %d
             ORDER BY created_at DESC
             LIMIT 1",
            $automationId,
            $userId
        );
        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new AutomationJob($result) : null;
    }

    /**
     * Find completed job by automation and contact_id (via logs or user_id)
     * This works for both WordPress users and MailerPress contacts
     *
     * @param int $automationId
     * @param int $contactId
     * @return AutomationJob|null
     */
    public function findCompletedByAutomationAndContact(int $automationId, int $contactId): ?AutomationJob
    {
        $logTable = $this->wpdb->prefix . 'mailerpress_automations_log';
        $contactsTable = $this->wpdb->prefix . 'mailerpress_contact';

        // First try by user_id (contact_id might be used as user_id for non-WordPress users)
        $job = $this->findCompletedByAutomationAndUser($automationId, $contactId);
        if ($job) {
            return $job;
        }

        // Get contact email to find associated WordPress user
        $contact = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT email FROM {$contactsTable} WHERE contact_id = %d",
                $contactId
            ),
            ARRAY_A
        );

        if ($contact && !empty($contact['email'])) {
            // Try to find WordPress user by email
            $user = get_user_by('email', $contact['email']);
            if ($user) {
                // Check if there's a job for this WordPress user
                $job = $this->findCompletedByAutomationAndUser($automationId, $user->ID);
                if ($job) {
                    return $job;
                }
            }
        }

        // If not found, check logs for contact_id in context data
        $query = $this->wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$this->table} j
             INNER JOIN {$logTable} l
             ON j.automation_id = l.automation_id AND j.user_id = l.user_id
             WHERE j.automation_id = %d
             AND j.status = 'COMPLETED'
             AND JSON_UNQUOTE(JSON_EXTRACT(l.data, '$.contact_id')) = %s
             ORDER BY j.updated_at DESC
             LIMIT 1",
            $automationId,
            (string) $contactId
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new AutomationJob($result) : null;
    }

    /**
     * Find any job by automation and contact_id.
     *
     * This is used for run_once_per_subscriber, where any previously started
     * job means the subscriber has already entered the automation.
     */
    public function findAnyByAutomationAndContact(int $automationId, int $contactId): ?AutomationJob
    {
        $logTable = $this->wpdb->prefix . 'mailerpress_automations_log';
        $contactsTable = $this->wpdb->prefix . 'mailerpress_contact';

        $job = $this->findAnyByAutomationAndUser($automationId, $contactId);
        if ($job) {
            return $job;
        }

        $contact = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT email FROM {$contactsTable} WHERE contact_id = %d",
                $contactId
            ),
            ARRAY_A
        );

        if ($contact && !empty($contact['email'])) {
            $user = get_user_by('email', $contact['email']);
            if ($user) {
                $job = $this->findAnyByAutomationAndUser($automationId, $user->ID);
                if ($job) {
                    return $job;
                }
            }
        }

        $query = $this->wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$this->table} j
             INNER JOIN {$logTable} l
             ON j.automation_id = l.automation_id AND j.user_id = l.user_id
             WHERE j.automation_id = %d
             AND JSON_UNQUOTE(JSON_EXTRACT(l.data, '$.contact_id')) = %s
             ORDER BY j.created_at DESC
             LIMIT 1",
            $automationId,
            (string) $contactId
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new AutomationJob($result) : null;
    }

    /**
     * Find active job by automation and contact_id (via logs or user_id)
     * This works for both WordPress users and MailerPress contacts
     *
     * @param int $automationId
     * @param int $contactId
     * @param bool $includeWaiting
     * @return AutomationJob|null
     */
    public function findActiveByAutomationAndContact(int $automationId, int $contactId, bool $includeWaiting = false): ?AutomationJob
    {
        $logTable = $this->wpdb->prefix . 'mailerpress_automations_log';
        $contactsTable = $this->wpdb->prefix . 'mailerpress_contact';

        // First try by user_id (contact_id might be used as user_id for non-WordPress users)
        $job = $this->findActiveByAutomationAndUser($automationId, $contactId, $includeWaiting);
        if ($job) {
            return $job;
        }

        // Get contact email to find associated WordPress user
        $contact = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT email FROM {$contactsTable} WHERE contact_id = %d",
                $contactId
            ),
            ARRAY_A
        );

        if ($contact && !empty($contact['email'])) {
            // Try to find WordPress user by email
            $user = get_user_by('email', $contact['email']);
            if ($user) {
                // Check if there's an active job for this WordPress user
                $job = $this->findActiveByAutomationAndUser($automationId, $user->ID, $includeWaiting);
                if ($job) {
                    return $job;
                }
            }
        }

        // If not found, check logs for contact_id in context
        $statuses = $includeWaiting
            ? "('ACTIVE', 'PROCESSING', 'WAITING')"
            : "('ACTIVE', 'PROCESSING')";

        $query = $this->wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$this->table} j
             INNER JOIN {$logTable} l
             ON j.automation_id = l.automation_id AND j.user_id = l.user_id
             WHERE j.automation_id = %d
             AND j.status IN {$statuses}
             AND JSON_UNQUOTE(JSON_EXTRACT(l.data, '$.contact_id')) = %s
             ORDER BY j.created_at DESC
             LIMIT 1",
            $automationId,
            (string) $contactId
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new AutomationJob($result) : null;
    }

    /**
     * Find all waiting jobs for a specific user
     *
     * @param int $userId
     * @return AutomationJob[]
     */
    public function findWaitingByUser(int $userId): array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
             AND status = 'WAITING'
             ORDER BY updated_at DESC",
            $userId
        );
        $results = $this->wpdb->get_results($query, ARRAY_A);

        return array_map(fn($row) => new AutomationJob($row), $results);
    }

    /**
     * Find waiting jobs by user and campaign (for email opened/clicked conditions)
     *
     * @param int $userId
     * @param int $campaignId
     * @return AutomationJob[]
     */
    public function findWaitingByUserAndCampaign(int $userId, int $campaignId): array
    {
        // We'll need to check logs to find jobs waiting for this specific campaign
        // This is a simplified version - in practice, you might want to store waiting_for in the job itself
        $query = $this->wpdb->prepare(
            "SELECT j.* FROM {$this->table} j
             INNER JOIN {$this->wpdb->prefix}mailerpress_automations_log l
             ON j.automation_id = l.automation_id AND j.user_id = l.user_id
             WHERE j.user_id = %d
             AND j.status = 'WAITING'
             AND l.data LIKE %s
             ORDER BY j.updated_at DESC",
            $userId,
            '%"waiting_for_campaign_id":' . $campaignId . '%'
        );
        $results = $this->wpdb->get_results($query, ARRAY_A);

        return array_map(fn($row) => new AutomationJob($row), $results);
    }

    /**
     * Find all active jobs for a specific automation and user
     *
     * @param int $automationId
     * @param int $userId
     * @return AutomationJob[]
     */
    public function findAllActiveByAutomationAndUser(int $automationId, int $userId): array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE automation_id = %d
             AND user_id = %d
             AND status IN ('ACTIVE', 'PROCESSING', 'WAITING')
             ORDER BY created_at DESC",
            $automationId,
            $userId
        );
        $results = $this->wpdb->get_results($query, ARRAY_A);

        return array_map(fn($row) => new AutomationJob($row), $results);
    }

    /**
     * Cancel all active jobs for a specific automation and user
     *
     * @param int $automationId
     * @param int $userId
     * @return int Number of jobs cancelled
     */
    public function cancelAllActiveByAutomationAndUser(int $automationId, int $userId): int
    {
        $jobs = $this->findAllActiveByAutomationAndUser($automationId, $userId);
        $cancelled = 0;

        foreach ($jobs as $job) {
            $job->setStatus('CANCELLED');
            if ($this->update($job)) {
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * Find and cancel jobs by cart_hash stored in logs
     *
     * @param int $automationId
     * @param int $userId
     * @param string $cartHash
     * @return int Number of jobs cancelled
     */
    public function cancelJobsByCartHash(int $automationId, int $userId, string $cartHash): int
    {
        if (empty($cartHash)) {
            return 0;
        }

        $logTable = $this->wpdb->prefix . 'mailerpress_automations_log';

        // Find jobs that have this cart_hash in their log context
        $query = $this->wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$this->table} j
             INNER JOIN {$logTable} l
             ON j.automation_id = l.automation_id AND j.user_id = l.user_id
             WHERE j.automation_id = %d
             AND j.user_id = %d
             AND j.status IN ('ACTIVE', 'PROCESSING', 'WAITING')
             AND l.data LIKE %s
             ORDER BY j.created_at DESC",
            $automationId,
            $userId,
            '%"cart_hash":"' . $this->wpdb->esc_like($cartHash) . '"%'
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);
        $jobs = array_map(fn($row) => new AutomationJob($row), $results);

        $cancelled = 0;
        foreach ($jobs as $job) {
            $job->setStatus('CANCELLED');
            if ($this->update($job)) {
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * Delete a job completely (and its logs)
     *
     * @param int $jobId
     * @return bool
     */
    public function delete(int $jobId): bool
    {
        // First delete associated logs
        $logRepo = new AutomationLogRepository();
        $logRepo->deleteByJobId($jobId);

        // Then delete the job
        $result = $this->wpdb->delete(
            $this->table,
            ['id' => $jobId],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete all jobs for a specific automation and user (and their logs)
     *
     * @param int $automationId
     * @param int $userId
     * @return int Number of jobs deleted
     */
    public function deleteAllByAutomationAndUser(int $automationId, int $userId): int
    {
        $jobs = $this->findAllActiveByAutomationAndUser($automationId, $userId);
        $deleted = 0;

        foreach ($jobs as $job) {
            if ($this->delete($job->getId())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete jobs by cart_hash (and their logs)
     *
     * @param int $automationId
     * @param int $userId
     * @param string $cartHash
     * @return int Number of jobs deleted
     */
    public function deleteJobsByCartHash(int $automationId, int $userId, string $cartHash): int
    {
        if (empty($cartHash)) {
            return 0;
        }

        $logTable = $this->wpdb->prefix . 'mailerpress_automations_log';

        // Find jobs that have this cart_hash in their log context
        $query = $this->wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$this->table} j
             INNER JOIN {$logTable} l
             ON j.automation_id = l.automation_id AND j.user_id = l.user_id
             WHERE j.automation_id = %d
             AND j.user_id = %d
             AND j.status IN ('ACTIVE', 'PROCESSING', 'WAITING')
             AND l.data LIKE %s
             ORDER BY j.created_at DESC",
            $automationId,
            $userId,
            '%"cart_hash":"' . $this->wpdb->esc_like($cartHash) . '"%'
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);
        $jobs = array_map(fn($row) => new AutomationJob($row), $results);

        $deleted = 0;
        foreach ($jobs as $job) {
            if ($this->delete($job->getId())) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
