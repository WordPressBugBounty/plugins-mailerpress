<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class SynchronisationManager
{
    /** @var ConnectorInterface[] */
    private array $connectors = [];

    /**
     * Register a connector.
     */
    public function register(ConnectorInterface $connector): void
    {
        $this->connectors[$connector->getKey()] = $connector;
    }

    /**
     * Return all registered connectors.
     *
     * @return ConnectorInterface[]
     */
    public function getAll(): array
    {
        return $this->connectors;
    }

    /**
     * Return a connector by key.
     */
    public function get(string $key): ?ConnectorInterface
    {
        return $this->connectors[$key] ?? null;
    }

    /**
     * Read the DB record for a connector.
     */
    public function getDbRecord(string $connectorKey): ?object
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE connector_key = %s ORDER BY id LIMIT 1", $connectorKey)
        );
    }

    /**
     * Return all connectors merged with their DB status, for the UI listing.
     */
    public function getAllWithStatus(): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);
        $rawRows = $wpdb->get_results("SELECT * FROM {$table}");

        // Re-index by connector_key (OBJECT_K indexes by first column = id, not connector_key)
        $rows = [];
        foreach ($rawRows as $row) {
            $rows[$row->connector_key] = $row;
        }

        $result = [];

        foreach ($this->connectors as $key => $connector) {
            $record = $rows[$key] ?? null;

            $settings = $record?->settings
                ? (json_decode($record->settings, true) ?? $connector->getDefaultSettings())
                : $connector->getDefaultSettings();

            $result[] = [
                'key'             => $key,
                'label'           => $connector->getLabel(),
                'description'     => $connector->getDescription(),
                'icon'            => $connector->getIcon(),
                'is_pro'          => $connector->isPro(),
                'sync_modes'      => $connector->getSyncModes(),
                'is_configured'   => $connector->isConfigured($settings),
                'status'          => $record?->status ?? 'inactive',
                'last_sync_at'    => $record?->last_sync_at ?? null,
                'last_sync_count' => (int) ($record?->last_sync_count ?? 0),
                'last_error'      => $record?->last_error ?? null,
                'settings'        => $settings,
            ];
        }

        return $result;
    }

    /** Compatibility for older Pro releases; never update multiple configurations by connector key. */
    public function saveConnector(string $key, array $settings, string $status = 'active'): bool
    {
        global $wpdb;
        $records = $this->getConfigurations($key);
        if (!$this->get($key) || count($records) > 1) {
            return false;
        }
        $data = ['connector_key' => $key, 'settings' => wp_json_encode($settings), 'status' => $status];
        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);
        if ($records) {
            return $wpdb->update($table, $data, ['id' => $records[0]->id]) !== false;
        }
        $data['label'] = $this->get($key)->getLabel();
        return $wpdb->insert($table, $data) !== false;
    }

    public function updateSyncResult(string $key, int $synced, ?string $error = null): void
    {
        global $wpdb;
        $records = $this->getConfigurations($key);
        if (count($records) === 1) {
            $wpdb->update(Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS), [
                'last_sync_at' => current_time('mysql'), 'last_sync_count' => $synced, 'last_error' => $error,
            ], ['id' => $records[0]->id]);
        }
    }

    public function getConfigurations(string $key = 'wordpress_users'): array
    {
        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE connector_key = %s ORDER BY id", $key)) ?: [];
    }

    public function getConfiguration(int $id): ?object
    {
        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND connector_key = 'wordpress_users'", $id));
    }

    public function formatConfiguration(object $record): array
    {
        $connector = $this->get($record->connector_key);
        $settings = json_decode($record->settings ?? '{}', true) ?: [];
        $settings = array_replace($connector?->getDefaultSettings() ?? [], $settings);
        if (empty($settings['list_ids']) && !empty($settings['list_id'])) {
            $settings['list_ids'] = [(int) $settings['list_id']];
        }
        // The old update listener synchronized every profile edit, not just email changes.
        if (!array_key_exists('sync_on_profile', json_decode($record->settings ?? '{}', true) ?: [])) {
            $settings['sync_on_profile'] = !empty($settings['sync_on_update']);
        }
        $settings['list_ids'] = array_map('intval', $settings['list_ids'] ?? []);
        $settings['tag_ids'] = array_map('intval', $settings['tag_ids'] ?? []);
        $run = json_decode($record->run_state ?? 'null', true);
        if ($run) {
            unset($run['settings'], $run['token']);
        }
        return [
            'id' => (int) $record->id,
            'label' => $record->label,
            'status' => $record->status === 'active' ? 'active' : 'inactive',
            'settings' => $settings,
            'is_configured' => $connector?->isConfigured($settings) ?? false,
            'last_sync_at' => $record->last_sync_at,
            'last_sync_count' => (int) $record->last_sync_count,
            'last_error' => $record->last_error,
            'run' => $run,
        ];
    }

    public function saveConfiguration(?int $id, string $label, array $settings, string $status): int|false
    {
        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);
        $data = ['label' => $label, 'settings' => wp_json_encode($settings), 'status' => $status, 'updated_at' => current_time('mysql')];
        if ($id) {
            return $wpdb->update($table, $data, ['id' => $id]) !== false ? $id : false;
        }
        $data['connector_key'] = 'wordpress_users';
        return $wpdb->insert($table, $data) !== false ? (int) $wpdb->insert_id : false;
    }

    public function deleteConfiguration(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete(Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS), ['id' => $id], ['%d']) !== false;
    }

    public function saveRun(int $id, array $run): void
    {
        global $wpdb;
        $data = ['run_state' => wp_json_encode($run)];
        if (in_array($run['status'], ['completed', 'failed'], true)) {
            $data['last_sync_at'] = current_time('mysql');
            $data['last_sync_count'] = $run['synced'];
            $data['last_error'] = $run['message'] ?: null;
        }
        if ($wpdb->update(Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS), $data, ['id' => $id]) === false) {
            throw new \RuntimeException(__('Could not save synchronization progress.', 'mailerpress'));
        }
    }

    public function bootActiveConnectors(): void
    {
        foreach ($this->connectors as $key => $connector) {
            // Keep the settings contract for older Pro releases with a single configuration.
            $settings = [];
            foreach ($this->getConfigurations($key) as $record) {
                if ($record->status === 'active') {
                    $settings = json_decode($record->settings ?? '{}', true) ?: [];
                    break;
                }
            }
            $connector->registerHooks($settings);
        }
    }
}
