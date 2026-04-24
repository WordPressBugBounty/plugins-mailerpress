<?php

namespace MailerPress\Core\Workflows\Repositories;

use MailerPress\Core\Enums\Tables;

class AutomationLogRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_LOG;
    }

    public function log(int $automationId, string $stepId, int $userId, string $status, array $data = []): bool
    {
        // Check if WAITING status is supported by the ENUM
        // If not, use COMPLETED as fallback and add a flag in data
        $actualStatus = $status;
        if ($status === 'WAITING') {
            $enumValues = $this->getStatusEnumValues();
            if (!in_array('WAITING', $enumValues, true)) {
                $actualStatus = 'COMPLETED';
                $data['_is_waiting_log'] = true;
            }
        }

        $result = $this->wpdb->insert(
            $this->table,
            [
                'automation_id' => $automationId,
                'step_id' => $stepId,
                'user_id' => $userId,
                'status' => $actualStatus,
                'data' => json_encode($data),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s']
        );

        if ($result === false) {
            return false;
        }

        $logId = $this->wpdb->insert_id;

        // Verify the log was actually inserted by querying it back
        if ($logId) {
            $verifyQuery = $this->wpdb->prepare(
                "SELECT id, status, step_id, user_id, LEFT(data, 300) as data_preview FROM {$this->table} WHERE id = %d",
                $logId
            );
            $verifyResult = $this->wpdb->get_row($verifyQuery, ARRAY_A);
        }

        return true;
    }

    /**
     * Get the ENUM values for the status column
     * 
     * @return array
     */
    private function getStatusEnumValues(): array
    {
        $query = "SHOW COLUMNS FROM {$this->table} WHERE Field = 'status'";
        $result = $this->wpdb->get_row($query, ARRAY_A);

        if (!$result || !isset($result['Type'])) {
            return ['PROCESSING', 'COMPLETED', 'EXITED']; // Default values
        }

        // Extract ENUM values from Type string like "ENUM('PROCESSING','COMPLETED','EXITED','WAITING')"
        preg_match("/ENUM\s*\((.+)\)/i", $result['Type'], $matches);
        if (!empty($matches[1])) {
            $values = array_map(function ($value) {
                return trim($value, " '\"");
            }, explode(',', $matches[1]));
            return $values;
        }

        return ['PROCESSING', 'COMPLETED', 'EXITED']; // Default values
    }

    /**
     * Get the context from log entries for a job
     * Tries to find context from PROCESSING logs first, then any log entry
     * 
     * @param int $automationId
     * @param int $userId
     * @return array|null Context data or null if not found
     */
    public function getTriggerContext(int $automationId, int $userId): ?array
    {
        // First try to get from PROCESSING logs (these contain the context passed to handlers)
        $query = $this->wpdb->prepare(
            "SELECT data FROM {$this->table} 
             WHERE automation_id = %d 
             AND user_id = %d 
             AND status = 'PROCESSING'
             ORDER BY created_at ASC
             LIMIT 1",
            $automationId,
            $userId
        );

        $result = $this->wpdb->get_var($query);

        if ($result) {
            $data = json_decode($result, true);
            if (is_array($data) && !empty($data)) {
                return $data;
            }
        }

        // Fallback: try any log entry
        $query = $this->wpdb->prepare(
            "SELECT data FROM {$this->table} 
             WHERE automation_id = %d 
             AND user_id = %d 
             ORDER BY created_at ASC
             LIMIT 1",
            $automationId,
            $userId
        );

        $result = $this->wpdb->get_var($query);

        if ($result) {
            $data = json_decode($result, true);
            if (is_array($data) && !empty($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Delete all logs for a specific job
     * 
     * @param int $jobId
     * @return int Number of logs deleted
     */
    public function deleteByJobId(int $jobId): int
    {
        // Get automation_id, user_id, and created_at from job
        global $wpdb;
        $jobTable = $wpdb->prefix . 'mailerpress_automations_jobs';
        $job = $wpdb->get_row(
            $wpdb->prepare("SELECT automation_id, user_id, created_at FROM {$jobTable} WHERE id = %d", $jobId),
            ARRAY_A
        );

        if (!$job) {
            return 0;
        }

        // Delete logs for this automation and user created at or after the job was created
        // This ensures we only delete logs for this specific job, not other jobs
        $query = $this->wpdb->prepare(
            "DELETE FROM {$this->table} 
             WHERE automation_id = %d 
             AND user_id = %d 
             AND created_at >= %s",
            $job['automation_id'],
            $job['user_id'],
            $job['created_at']
        );

        $result = $this->wpdb->query($query);

        return $result !== false ? $result : 0;
    }

    /**
     * Delete logs by cart_hash
     * 
     * @param int $automationId
     * @param int $userId
     * @param string $cartHash
     * @return int Number of logs deleted
     */
    public function deleteByCartHash(int $automationId, int $userId, string $cartHash): int
    {
        if (empty($cartHash)) {
            return 0;
        }

        // Find logs that contain this cart_hash
        $query = $this->wpdb->prepare(
            "SELECT id FROM {$this->table} 
             WHERE automation_id = %d 
             AND user_id = %d 
             AND data LIKE %s",
            $automationId,
            $userId,
            '%"cart_hash":"' . $this->wpdb->esc_like($cartHash) . '"%'
        );

        $logIds = $this->wpdb->get_col($query);

        if (empty($logIds)) {
            return 0;
        }

        // Delete logs
        $placeholders = implode(',', array_fill(0, count($logIds), '%d'));
        $query = $this->wpdb->prepare(
            "DELETE FROM {$this->table} WHERE id IN ({$placeholders})",
            ...$logIds
        );

        $result = $this->wpdb->query($query);

        return $result !== false ? $result : 0;
    }

    /**
     * Get the last log entry for a job
     * 
     * @param int $automationId
     * @param int $userId
     * @return array|null Log entry data or null if not found
     */
    public function getLastLogForJob(int $automationId, int $userId): ?array
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

        return $result ?: null;
    }

    /**
     * Get the last log entry that contains waiting metadata (COMPLETED status with waiting_for_field)
     * 
     * @param int $automationId
     * @param int $userId
     * @return array|null Log entry data or null if not found
     */
    public function getLastWaitingLogForJob(int $automationId, int $userId): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE automation_id = %d 
             AND user_id = %d 
             AND status = 'COMPLETED'
             AND data LIKE %s
             ORDER BY created_at DESC
             LIMIT 1",
            $automationId,
            $userId,
            '%"waiting_for_field"%'
        );

        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ?: null;
    }

    /**
     * Get all waiting log entries that match a specific event (field and campaign_id)
     * This is used when multiple conditions might be waiting for the same event
     * 
     * @param int $automationId
     * @param int $userId
     * @param string $eventType The event type (e.g., 'mp_email_opened')
     * @param int $campaignId The campaign ID
     * @return array Array of log entries, ordered by created_at DESC
     */
    public function getAllWaitingLogsForEvent(int $automationId, int $userId, string $eventType, int $campaignId): array
    {
        // Search for logs with WAITING status OR COMPLETED status with waiting_for_field
        // This handles cases where the ENUM doesn't support WAITING and logs are created as COMPLETED
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE automation_id = %d 
             AND user_id = %d 
             AND (status = 'WAITING' OR (status = 'COMPLETED' AND data LIKE %s))
             AND data LIKE %s
             AND data LIKE %s
             ORDER BY created_at DESC",
            $automationId,
            $userId,
            '%"waiting_for_field"%',
            '%"waiting_for_field":"' . $this->wpdb->esc_like($eventType) . '"%',
            '%"waiting_for_campaign_id":' . $campaignId . '%'
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);

        // Filter to ensure exact matches
        $filtered = [];
        foreach ($results as $result) {
            $data = json_decode($result['data'] ?? '{}', true);
            $isWaitingLog = ($result['status'] === 'WAITING') ||
                (isset($data['_is_waiting_log']) && $data['_is_waiting_log'] === true) ||
                (isset($data['waiting_for_field']) && !empty($data['waiting_for_field']));

            if (
                $isWaitingLog &&
                isset($data['waiting_for_field']) && $data['waiting_for_field'] === $eventType &&
                isset($data['waiting_for_campaign_id']) && (int)$data['waiting_for_campaign_id'] === $campaignId
            ) {
                $filtered[] = $result;
            }
        }
        return $filtered;
    }

    /**
     * Get all WAITING logs for a user, event type, and campaign
     * This includes logs even if the job is no longer in WAITING status
     * 
     * @param int $userId
     * @param string $eventType
     * @param int $campaignId
     * @return array
     */
    public function getAllWaitingLogsForUser(int $userId, string $eventType, int $campaignId): array
    {
        // First, try to get all WAITING logs for this user to debug
        $debugQuery = $this->wpdb->prepare(
            "SELECT id, status, step_id, created_at, LEFT(data, 200) as data_preview FROM {$this->table} 
             WHERE user_id = %d 
             AND status = 'WAITING'
             ORDER BY created_at DESC
             LIMIT 10",
            $userId
        );
        $debugResults = $this->wpdb->get_results($debugQuery, ARRAY_A);

        // Search for logs with WAITING status OR COMPLETED status with waiting_for_field
        // This handles cases where the ENUM doesn't support WAITING and logs are created as COMPLETED
        // Use a simpler approach: get all WAITING logs first, then filter in PHP
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE user_id = %d 
             AND status = 'WAITING'
             ORDER BY created_at DESC",
            $userId
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);

        // Also get COMPLETED logs that might have waiting_for_field (fallback case)
        $completedQuery = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE user_id = %d 
             AND status = 'COMPLETED'
             AND data LIKE %s
             ORDER BY created_at DESC",
            $userId,
            '%"waiting_for_field"%'
        );
        $completedResults = $this->wpdb->get_results($completedQuery, ARRAY_A);

        // Merge both results
        $allResults = array_merge($results, $completedResults);

        // Filter to ensure exact matches
        $filtered = [];
        foreach ($allResults as $result) {
            $data = json_decode($result['data'] ?? '{}', true);
            $isWaitingLog = ($result['status'] === 'WAITING') ||
                (isset($data['_is_waiting_log']) && $data['_is_waiting_log'] === true) ||
                (isset($data['waiting_for_field']) && !empty($data['waiting_for_field']));

            if (
                $isWaitingLog &&
                isset($data['waiting_for_field']) && $data['waiting_for_field'] === $eventType &&
                isset($data['waiting_for_campaign_id']) && (int)$data['waiting_for_campaign_id'] === $campaignId
            ) {
                $filtered[] = $result;
            }
        }

        return $filtered;
    }

    /**
     * Get the last log entry that contains email_sent_at for a specific campaign
     * This is used to verify that an email was opened AFTER it was sent
     * 
     * @param int $automationId
     * @param int $userId
     * @param int $campaignId
     * @param string|null $beforeTimestamp Optional: only get email sent before this timestamp
     * @param string|null $stepId Optional: only get email sent from this specific step
     * @return array|null Log entry data with email_sent_at or null if not found
     */
    public function getEmailSentLog(int $automationId, int $userId, int $campaignId, ?string $beforeTimestamp = null, ?string $stepId = null): ?array
    {
        // First, get all COMPLETED logs for this automation and user that might contain email_sent_at
        // We'll filter in PHP to be more reliable
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE automation_id = %d 
             AND user_id = %d 
             AND status = 'COMPLETED'
             AND data LIKE %s",
            $automationId,
            $userId,
            '%"email_sent_at"%'
        );

        // IMPORTANT: We want the email sent BEFORE the condition was evaluated
        // The condition is put in WAITING state when it fails, so the email MUST have been sent before
        // We use < (strict) to ensure we get the email sent BEFORE the condition was evaluated
        // This is crucial when multiple emails with the same campaign_id are sent
        if ($beforeTimestamp) {
            $query .= $this->wpdb->prepare(
                " AND created_at < %s",
                $beforeTimestamp
            );
        }

        // Order by created_at DESC to get the most recent email
        // This should be the email that the condition is waiting for
        $query .= " ORDER BY created_at DESC LIMIT 20";

        $results = $this->wpdb->get_results($query, ARRAY_A);

        // Find the log entry where campaign_id matches exactly
        // We want the MOST RECENT email sent at or before the condition was evaluated
        foreach ($results as $result) {
            $data = json_decode($result['data'] ?? '{}', true);
            $logStepId = $result['step_id'] ?? null; // step_id is in the table column, not in JSON data

            // Verify campaign_id matches
            if (isset($data['campaign_id']) && (int)$data['campaign_id'] === $campaignId && !empty($data['email_sent_at'])) {
                // If step_id is specified, verify it matches the column value (not data['step_id'])
                if ($stepId !== null) {
                    if ($logStepId === $stepId) {
                        return $data;
                    }
                    // Continue to next result if step_id doesn't match
                    continue;
                }
                return $data;
            }
        }
        return null;
    }
}
