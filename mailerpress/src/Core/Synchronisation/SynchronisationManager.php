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
            $wpdb->prepare("SELECT * FROM {$table} WHERE connector_key = %s", $connectorKey)
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

    /**
     * Save (UPSERT) a connector's settings and status.
     */
    public function saveConnector(string $key, array $settings, string $status = 'active'): bool
    {
        global $wpdb;

        $connector = $this->get($key);
        if (!$connector) {
            return false;
        }

        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);
        $existing = $this->getDbRecord($key);

        if ($existing) {
            $result = $wpdb->update(
                $table,
                [
                    'settings'   => wp_json_encode($settings),
                    'status'     => $status,
                    'label'      => $connector->getLabel(),
                    'updated_at' => current_time('mysql'),
                ],
                ['connector_key' => $key],
                ['%s', '%s', '%s', '%s'],
                ['%s']
            );
        } else {
            $result = $wpdb->insert(
                $table,
                [
                    'connector_key' => $key,
                    'label'         => $connector->getLabel(),
                    'settings'      => wp_json_encode($settings),
                    'status'        => $status,
                ],
                ['%s', '%s', '%s', '%s']
            );
        }

        return $result !== false;
    }

    /**
     * Update the last sync result in DB.
     */
    public function updateSyncResult(string $key, int $synced, ?string $error = null): void
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);

        $wpdb->update(
            $table,
            [
                'last_sync_at'    => current_time('mysql'),
                'last_sync_count' => $synced,
                'last_error'      => $error,
                'status'          => $error ? 'error' : 'active',
                'updated_at'      => current_time('mysql'),
            ],
            ['connector_key' => $key],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%s']
        );
    }

    /**
     * Boot all active connectors by registering their WordPress hooks.
     * Called once during plugin init.
     */
    public function bootActiveConnectors(): void
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);
        $activeRecords = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'active'"
        );

        foreach ($activeRecords as $record) {
            $key = $record->connector_key;
            $connector = $this->get($key);
            if (!$connector) {
                continue;
            }

            $settings = json_decode($record->settings ?? '{}', true) ?? [];
            $connector->registerHooks($settings);
        }
    }
}
