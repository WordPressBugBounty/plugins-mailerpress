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

    public function create(int $automationId, int $userId, ?string $nextStepId = null): ?AutomationJob
    {
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
            return new AutomationJob($data);
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
                'scheduled_at' => $job->getScheduledAt(),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $job->getId()],
            ['%s', '%s', '%s', '%s'],
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
        $escapedPattern = $this->wpdb->esc_like('"contact_id":' . $contactId);

        $query = $this->wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$this->table} j
             INNER JOIN {$logTable} l 
             ON j.automation_id = l.automation_id AND j.user_id = l.user_id
             WHERE j.automation_id = %d
             AND j.status = 'COMPLETED'
             AND l.data LIKE %s
             ORDER BY j.updated_at DESC
             LIMIT 1",
            $automationId,
            '%' . $escapedPattern . '%'
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

        $escapedPattern = $this->wpdb->esc_like('"contact_id":' . $contactId);

        $query = $this->wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$this->table} j
             INNER JOIN {$logTable} l 
             ON j.automation_id = l.automation_id AND j.user_id = l.user_id
             WHERE j.automation_id = %d
             AND j.status IN {$statuses}
             AND l.data LIKE %s
             ORDER BY j.created_at DESC
             LIMIT 1",
            $automationId,
            '%' . $escapedPattern . '%'
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
