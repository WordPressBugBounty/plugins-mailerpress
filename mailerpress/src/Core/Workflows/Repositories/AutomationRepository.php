<?php

namespace MailerPress\Core\Workflows\Repositories;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Models\Automation;

class AutomationRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS;
    }

    public function find(int $id): ?Automation
    {
        $query = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id);
        $result = $this->wpdb->get_row($query, ARRAY_A);

        return $result ? new Automation($result) : null;
    }

    public function findByStatus(string $status): array
    {
        $query = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE status = %s", $status);
        $results = $this->wpdb->get_results($query, ARRAY_A);

        return array_map(fn($data) => new Automation($data), $results);
    }

    /**
     * Find automations by trigger type (legacy support)
     * Note: trigger_type column doesn't exist anymore - triggers are in mailerpress_automations_steps
     * This method is kept for backward compatibility but should not be used
     * 
     * @deprecated Use StepRepository::findByTriggerKey() instead
     */
    public function findByTriggerType(string $triggerType): array
    {
        // This method should not be used - triggers are now in mailerpress_automations_steps
        // Return empty array to prevent errors
        return [];
    }

    public function findAll(): array
    {
        $query = "SELECT * FROM {$this->table}";
        $results = $this->wpdb->get_results($query, ARRAY_A);

        return array_map(fn($data) => new Automation($data), $results);
    }

    public function create(array $data): int
    {
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        if (!isset($data['author']) && is_user_logged_in()) {
            $data['author'] = get_current_user_id();
        }

        $this->wpdb->insert($this->table, [
            'name' => $data['name'] ?? null,
            'author' => $data['author'] ?? null,
            'status' => $data['status'] ?? 'DRAFT',
            'run_once_per_subscriber' => isset($data['run_once_per_subscriber']) ? (int) $data['run_once_per_subscriber'] : 0,
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at'],
        ], ['%s', '%d', '%s', '%d', '%s', '%s']);

        return (int) $this->wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = current_time('mysql');

        $updateData = [];
        $format = [];

        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
            $format[] = '%s';
        }

        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
            $format[] = '%s';
        }

        if (isset($data['author'])) {
            $updateData['author'] = $data['author'];
            $format[] = '%d';
        }

        if (isset($data['run_once_per_subscriber'])) {
            $updateData['run_once_per_subscriber'] = (int) $data['run_once_per_subscriber'];
            $format[] = '%d';
        }

        $updateData['updated_at'] = $data['updated_at'];
        $format[] = '%s';

        if (empty($updateData)) {
            return false;
        }

        $result = $this->wpdb->update(
            $this->table,
            $updateData,
            ['id' => $id],
            $format,
            ['%d']
        );

        return $result !== false;
    }

    public function delete(int $id): bool
    {
        $result = $this->wpdb->delete(
            $this->table,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }
}
