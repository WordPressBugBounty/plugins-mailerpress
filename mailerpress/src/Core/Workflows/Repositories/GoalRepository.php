<?php

namespace MailerPress\Core\Workflows\Repositories;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Models\Goal;

class GoalRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_GOALS;
    }

    public function find(int $id): ?Goal
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $result ? new Goal($result) : null;
    }

    /**
     * Register a goal as pending for a run.
     *
     * The (step_id, job_id) unique index makes this idempotent: re-entering the
     * same goal step within the same job updates the existing row instead of
     * creating a duplicate.
     */
    public function createPending(array $data): ?Goal
    {
        $now = current_time('mysql');

        $row = [
            'automation_id' => (int) $data['automation_id'],
            'step_id' => (string) $data['step_id'],
            'job_id' => isset($data['job_id']) ? (int) $data['job_id'] : null,
            'user_id' => (int) $data['user_id'],
            'contact_id' => isset($data['contact_id']) ? (int) $data['contact_id'] : null,
            'goal_event' => (string) $data['goal_event'],
            'status' => Goal::STATUS_PENDING,
            'match_hash' => $data['match_hash'] ?? null,
            // UTC reference for durations, like expires_at. Never use created_at
            // for that: it is a TIMESTAMP, so MySQL rewrites it according to the
            // session timezone.
            'started_at' => gmdate('Y-m-d H:i:s'),
            'data' => isset($data['data']) ? wp_json_encode($data['data']) : null,
            'expires_at' => $data['expires_at'] ?? null,
            'achieved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $existing = $this->findPendingForJob((string) $data['step_id'], $row['job_id']);

        if ($existing) {
            $this->wpdb->update(
                $this->table,
                [
                    'status' => Goal::STATUS_PENDING,
                    'match_hash' => $row['match_hash'],
                    'data' => $row['data'],
                    'expires_at' => $row['expires_at'],
                    'achieved_at' => null,
                    'updated_at' => $now,
                ],
                ['id' => $existing->getId()],
                ['%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            return $this->find((int) $existing->getId());
        }

        $result = $this->wpdb->insert(
            $this->table,
            $row,
            ['%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (false === $result) {
            return null;
        }

        $row['id'] = $this->wpdb->insert_id;

        return new Goal($row);
    }

    /**
     * Find the goal row of a step for a given job, whatever its status.
     */
    public function findForJob(string $stepId, ?int $jobId): ?Goal
    {
        if (null === $jobId) {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE step_id = %s AND job_id IS NULL LIMIT 1",
                $stepId
            );
        } else {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE step_id = %s AND job_id = %d LIMIT 1",
                $stepId,
                $jobId
            );
        }

        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new Goal($result) : null;
    }

    public function findPendingForJob(string $stepId, ?int $jobId): ?Goal
    {
        $goal = $this->findForJob($stepId, $jobId);

        return $goal && $goal->isPending() ? $goal : null;
    }

    /**
     * Pending goals of a contact for a given goal event.
     *
     * @return Goal[]
     */
    public function findPendingByUserAndEvent(int $userId, string $goalEvent): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE user_id = %d
                 AND goal_event = %s
                 AND status = %s
                 ORDER BY created_at ASC",
                $userId,
                $goalEvent,
                Goal::STATUS_PENDING
            ),
            ARRAY_A
        );

        return array_map(fn($row) => new Goal($row), $results);
    }

    /**
     * Pending goals of a contact for one precise goal step.
     *
     * @return Goal[]
     */
    public function findPendingByUserAndStep(int $userId, string $stepId): array
    {
        return $this->findPendingByIdentitiesAndStep([$userId], $stepId);
    }

    /**
     * Pending goals for a step, looked up under every identity of the contact.
     *
     * A job may have been recorded under a WordPress user id while the event
     * only knows the contact id (or the other way around), so both columns are
     * matched against the whole identity set.
     *
     * @param int[] $identities
     *
     * @return Goal[]
     */
    public function findPendingByIdentitiesAndStep(array $identities, string $stepId): array
    {
        $identities = array_values(array_unique(array_filter(array_map('intval', $identities), fn($id) => $id > 0)));

        if (empty($identities)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($identities), '%d'));
        $params = array_merge([$stepId, Goal::STATUS_PENDING], $identities, $identities);

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE step_id = %s
                 AND status = %s
                 AND (user_id IN ({$placeholders}) OR contact_id IN ({$placeholders}))
                 ORDER BY created_at ASC",
                ...$params
            ),
            ARRAY_A
        );

        return array_map(fn($row) => new Goal($row), $results);
    }

    /**
     * Pending goals whose timeout has elapsed.
     *
     * `expires_at` holds an absolute instant written with gmdate(), so it must
     * be compared against UTC. Using current_time('mysql') here would expire
     * goals early by the site's UTC offset (two hours on a Europe/Paris site).
     *
     * @return Goal[]
     */
    public function findExpiredPending(int $limit = 100): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = %s
                 AND expires_at IS NOT NULL
                 AND expires_at <= %s
                 ORDER BY expires_at ASC
                 LIMIT %d",
                Goal::STATUS_PENDING,
                gmdate('Y-m-d H:i:s'),
                $limit
            ),
            ARRAY_A
        );

        return array_map(fn($row) => new Goal($row), $results);
    }

    /**
     * Pending goals whose timeout is still in the future.
     *
     * Used to re-arm expiry actions that went missing from the scheduler.
     *
     * @return Goal[]
     */
    public function findPendingWithFutureExpiry(int $limit = 200): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = %s
                 AND expires_at IS NOT NULL
                 AND expires_at > %s
                 AND job_id IS NOT NULL
                 ORDER BY expires_at ASC
                 LIMIT %d",
                Goal::STATUS_PENDING,
                gmdate('Y-m-d H:i:s'),
                $limit
            ),
            ARRAY_A
        );

        return array_map(fn($row) => new Goal($row), $results);
    }

    /**
     * Mark a goal as achieved. Returns false when the row was not pending
     * anymore, which makes the transition safe against concurrent events.
     */
    public function markAchieved(int $goalId, array $data = []): bool
    {
        $now = current_time('mysql');

        // Guard on the PENDING status so two concurrent events cannot both win.
        // achieved_at is UTC (like started_at and expires_at) so the duration
        // between them is meaningful whatever the site timezone.
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = %s, achieved_at = %s, updated_at = %s
                 WHERE id = %d AND status = %s",
                Goal::STATUS_ACHIEVED,
                gmdate('Y-m-d H:i:s'),
                $now,
                $goalId,
                Goal::STATUS_PENDING
            )
        );

        if (!$updated) {
            return false;
        }

        if (!empty($data)) {
            $this->wpdb->update(
                $this->table,
                ['data' => wp_json_encode($data)],
                ['id' => $goalId],
                ['%s'],
                ['%d']
            );
        }

        return true;
    }

    public function updateStatus(int $goalId, string $status): bool
    {
        return false !== $this->wpdb->update(
            $this->table,
            [
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $goalId],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function attachJob(int $goalId, int $jobId): bool
    {
        return false !== $this->wpdb->update(
            $this->table,
            [
                'job_id' => $jobId,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $goalId],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Mark every pending goal of a job as skipped (job cancelled, jumped over…).
     */
    public function skipPendingForJob(int $jobId, ?string $exceptStepId = null): int
    {
        if ($exceptStepId) {
            $query = $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = %s, updated_at = %s
                 WHERE job_id = %d AND status = %s AND step_id != %s",
                Goal::STATUS_SKIPPED,
                current_time('mysql'),
                $jobId,
                Goal::STATUS_PENDING,
                $exceptStepId
            );
        } else {
            $query = $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = %s, updated_at = %s
                 WHERE job_id = %d AND status = %s",
                Goal::STATUS_SKIPPED,
                current_time('mysql'),
                $jobId,
                Goal::STATUS_PENDING
            );
        }

        return (int) $this->wpdb->query($query);
    }

    /**
     * Aggregated stats per goal step for an automation.
     *
     * @return array<string, array{total:int, pending:int, achieved:int, expired:int, skipped:int, conversion_rate:float, median_seconds:?int}>
     */
    public function getStatsByAutomation(int $automationId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT step_id, status, COUNT(*) as total
                 FROM {$this->table}
                 WHERE automation_id = %d
                 GROUP BY step_id, status",
                $automationId
            ),
            ARRAY_A
        );

        $stats = [];

        foreach ($rows as $row) {
            $stepId = $row['step_id'];

            if (!isset($stats[$stepId])) {
                $stats[$stepId] = [
                    'total' => 0,
                    'pending' => 0,
                    'achieved' => 0,
                    'expired' => 0,
                    'skipped' => 0,
                    'conversion_rate' => 0.0,
                    'median_seconds' => null,
                ];
            }

            $count = (int) $row['total'];
            $stats[$stepId]['total'] += $count;
            $stats[$stepId][strtolower($row['status'])] = $count;
        }

        foreach ($stats as $stepId => $stat) {
            if ($stat['total'] > 0) {
                $stats[$stepId]['conversion_rate'] = round(($stat['achieved'] / $stat['total']) * 100, 2);
            }

            $stats[$stepId]['median_seconds'] = $this->getMedianTimeToAchieve($automationId, (string) $stepId);
        }

        return $stats;
    }

    /**
     * Median delay (in seconds) between entering a goal and achieving it.
     */
    public function getMedianTimeToAchieve(int $automationId, string $stepId): ?int
    {
        // Both bounds are UTC DATETIME columns. Rows predating started_at are
        // skipped rather than measured against created_at, whose TIMESTAMP
        // conversion would produce a duration off by the timezone offset.
        $durations = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, started_at, achieved_at) as duration
                 FROM {$this->table}
                 WHERE automation_id = %d
                 AND step_id = %s
                 AND status = %s
                 AND achieved_at IS NOT NULL
                 AND started_at IS NOT NULL
                 ORDER BY duration ASC",
                $automationId,
                $stepId,
                Goal::STATUS_ACHIEVED
            )
        );

        $durations = array_values(array_filter(array_map('intval', $durations), fn($d) => $d >= 0));
        $count = count($durations);

        if (0 === $count) {
            return null;
        }

        $middle = (int) floor($count / 2);

        if (0 === $count % 2) {
            return (int) round(($durations[$middle - 1] + $durations[$middle]) / 2);
        }

        return $durations[$middle];
    }

    public function deleteByAutomationId(int $automationId): int
    {
        return (int) $this->wpdb->delete($this->table, ['automation_id' => $automationId], ['%d']);
    }

    /**
     * Remove goal rows whose step no longer exists (workflow edited).
     */
    public function deleteOrphansByAutomationId(int $automationId, array $keptStepIds): int
    {
        if (empty($keptStepIds)) {
            return $this->deleteByAutomationId($automationId);
        }

        $placeholders = implode(', ', array_fill(0, count($keptStepIds), '%s'));
        $params = array_merge([$automationId], $keptStepIds);

        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE automation_id = %d
                 AND step_id NOT IN ({$placeholders})",
                ...$params
            )
        );
    }
}
