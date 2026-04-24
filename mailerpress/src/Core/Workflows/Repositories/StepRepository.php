<?php

namespace MailerPress\Core\Workflows\Repositories;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\StepBranch;

class StepRepository
{
    private \wpdb $wpdb;
    private string $table;
    private string $branchesTable;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEPS;
        $this->branchesTable = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEP_BRANCHES;
    }

    public function findByAutomationId(int $automationId): array
    {
        $query = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE automation_id = %d", $automationId);
        $results = $this->wpdb->get_results($query, ARRAY_A);
        
        return array_map(fn($data) => new Step($data), $results);
    }

    public function findByStepId(string $stepId): ?Step
    {
        $query = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE step_id = %s", $stepId);
        $result = $this->wpdb->get_row($query, ARRAY_A);
        
        return $result ? new Step($result) : null;
    }

    public function findTriggerByKey(int $automationId, string $key): ?Step
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE automation_id = %d 
             AND type = 'TRIGGER' 
             AND `key` = %s 
             LIMIT 1",
            $automationId,
            $key
        );
        $result = $this->wpdb->get_row($query, ARRAY_A);
        
        return $result ? new Step($result) : null;
    }

    public function findBranchesByStepId(int $stepId): array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->branchesTable} WHERE step_id = %d",
            $stepId
        );
        $results = $this->wpdb->get_results($query, ARRAY_A);
        
        return array_map(fn($data) => new StepBranch($data), $results);
    }

    public function create(array $data): int
    {
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        $this->wpdb->insert($this->table, [
            'automation_id' => $data['automation_id'],
            'step_id' => $data['step_id'],
            'type' => $data['type'],
            'key' => $data['key'],
            'settings' => isset($data['settings']) ? wp_json_encode($data['settings']) : null,
            'next_step_id' => $data['next_step_id'] ?? null,
            'alternative_step_id' => $data['alternative_step_id'] ?? null,
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at'],
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        return (int) $this->wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = current_time('mysql');

        $updateData = [];
        $format = [];

        if (isset($data['step_id'])) {
            $updateData['step_id'] = $data['step_id'];
            $format[] = '%s';
        }

        if (isset($data['type'])) {
            $updateData['type'] = $data['type'];
            $format[] = '%s';
        }

        if (isset($data['key'])) {
            $updateData['key'] = $data['key'];
            $format[] = '%s';
        }

        if (isset($data['settings'])) {
            $updateData['settings'] = wp_json_encode($data['settings']);
            $format[] = '%s';
        }

        if (isset($data['next_step_id'])) {
            $updateData['next_step_id'] = $data['next_step_id'];
            $format[] = '%s';
        }

        if (isset($data['alternative_step_id'])) {
            $updateData['alternative_step_id'] = $data['alternative_step_id'];
            $format[] = '%s';
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

    public function deleteByAutomationId(int $automationId): bool
    {
        $result = $this->wpdb->delete(
            $this->table,
            ['automation_id' => $automationId],
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