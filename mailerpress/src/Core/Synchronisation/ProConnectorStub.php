<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation;

\defined('ABSPATH') || exit;

/**
 * Base class for Pro connector stubs registered in the Free plugin.
 *
 * These stubs ensure Pro connectors always appear in the UI even when the Pro
 * plugin is inactive. They expose metadata only — sync/hooks are no-ops.
 * When the Pro plugin is active, it replaces the stub with the real connector
 * via the 'mailerpress_register_sync_connectors' hook (same key → overwrite).
 */
abstract class ProConnectorStub implements ConnectorInterface
{
    final public function isPro(): bool
    {
        return true;
    }

    final public function getSyncModes(): array
    {
        return ['manual'];
    }

    final public function getDefaultSettings(): array
    {
        return [];
    }

    final public function isConfigured(array $settings): bool
    {
        return false;
    }

    final public function getAvailableSourceFields(array $settings): array
    {
        return [];
    }

    final public function sync(array $settings): array
    {
        return ['synced' => 0, 'skipped' => 0, 'errors' => 0];
    }

    final public function registerHooks(array $settings): void
    {
        // Stubs never register hooks
    }

    final public function unregisterHooks(): void
    {
        // Nothing to remove
    }
}
