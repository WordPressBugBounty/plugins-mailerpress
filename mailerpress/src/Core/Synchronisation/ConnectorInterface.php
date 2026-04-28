<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation;

\defined('ABSPATH') || exit;

interface ConnectorInterface
{
    /**
     * Unique machine key for this connector (e.g. 'wordpress_users').
     * Must match the connector_key column in DB.
     */
    public function getKey(): string;

    /**
     * Human-readable label displayed in the UI.
     */
    public function getLabel(): string;

    /**
     * Short description for the connector card.
     */
    public function getDescription(): string;

    /**
     * Icon identifier or URL (e.g. 'wordpress' for the WP icon, or a full image URL).
     */
    public function getIcon(): string;

    /**
     * Whether this connector requires the Pro plugin.
     * Free connectors return false; Pro connectors return true.
     */
    public function isPro(): bool;

    /**
     * Supported sync modes for this connector.
     * 'manual' = on-demand only (Sync now button).
     * 'auto'   = real-time hooks (user_register, webhooks, etc.).
     *
     * Always includes 'manual'. Return ['manual', 'auto'] if real-time sync is supported.
     *
     * @return array<'manual'|'auto'>
     */
    public function getSyncModes(): array;

    /**
     * Default settings values for this connector.
     */
    public function getDefaultSettings(): array;

    /**
     * Whether the connector has been sufficiently configured to run a sync.
     * Used by the UI to enable/disable the "Sync now" button.
     *
     * @param array $settings Saved settings for this connector.
     */
    public function isConfigured(array $settings): bool;

    /**
     * Return the list of fields available from the source system for field mapping.
     *
     * Each entry: ['key' => 'source_key', 'label' => 'Human label', 'example' => 'optional']
     * Returns an empty array if fields cannot be retrieved (e.g. API key missing).
     *
     * @param array $settings Saved settings for this connector.
     * @return array<array{key: string, label: string, example?: string}>
     */
    public function getAvailableSourceFields(array $settings): array;

    /**
     * Run a full bulk synchronisation.
     *
     * @param array $settings Saved settings for this connector.
     * @return array{synced: int, skipped: int, errors: int}
     */
    public function sync(array $settings): array;

    /**
     * Register WordPress hooks for real-time sync (called on boot when connector is active).
     *
     * @param array $settings Saved settings for this connector.
     */
    public function registerHooks(array $settings): void;

    /**
     * Remove WordPress hooks (called when connector is deactivated).
     */
    public function unregisterHooks(): void;
}
