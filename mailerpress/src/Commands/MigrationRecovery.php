<?php

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Core\Kernel;
use MailerPress\Core\Migrations\Manager;

\defined('ABSPATH') || exit;

/**
 * Emergency recovery commands for migrations
 */
class MigrationRecovery
{
    #[Command('mailerpress migrations:reset-failed')]
    public function resetFailed(array $args, array $assoc_args): void
    {
        $manager = new Manager(
            Kernel::$config['root'] . '/src/Core/Migrations/migrations',
            []
        );

        $count = $manager->resetFailed();

        if ($count > 0) {
            \WP_CLI::success("Reset {$count} failed migration(s). Lock released. You can now run migrations again.");
        } else {
            \WP_CLI::success("No failed migrations found. Lock released.");
        }
    }

    #[Command('mailerpress migrations:force-run')]
    public function forceRun(array $args, array $assoc_args): void
    {
        \WP_CLI::line('Running migrations in force mode (resetting failed migrations)...');

        $manager = new Manager(
            Kernel::$config['root'] . '/src/Core/Migrations/migrations',
            []
        );

        try {
            $manager->runForce();
            \WP_CLI::success('Migrations completed successfully!');
        } catch (\Throwable $e) {
            \WP_CLI::error("Migration error: " . $e->getMessage());
        }
    }

    #[Command('mailerpress migrations:status')]
    public function status(array $args, array $assoc_args): void
    {
        $manager = new Manager(
            Kernel::$config['root'] . '/src/Core/Migrations/migrations',
            []
        );

        $status = $manager->getStatus();

        \WP_CLI::line('Migration Status:');
        \WP_CLI::line('  Locked: ' . ($status['is_locked'] ? 'Yes' : 'No'));
        \WP_CLI::line('  Failed: ' . $status['failed_count']);
        \WP_CLI::line('  Running: ' . $status['running_count']);

        if (!empty($status['failed_migrations'])) {
            \WP_CLI::line('');
            \WP_CLI::line('Failed Migrations:');
            foreach ($status['failed_migrations'] as $migration) {
                \WP_CLI::line("  - {$migration['migration_name']}: {$migration['error_message']}");
            }
        }
    }

    #[Command('mailerpress migrations:unlock')]
    public function unlock(array $args, array $assoc_args): void
    {
        $manager = new Manager(
            Kernel::$config['root'] . '/src/Core/Migrations/migrations',
            []
        );

        $manager->forceReleaseLock();
        \WP_CLI::success('Migration lock released. Migrations can now run.');
    }

    #[Command('mailerpress migrations:force-reexecute')]
    public function forceReexecute(array $args, array $assoc_args): void
    {
        $migrationFile = $args[0] ?? null;

        if (!$migrationFile) {
            \WP_CLI::error('Migration file name is required. Example: 2025_01_15_143022_add_campaign_type');
        }

        $tracker = new \MailerPress\Core\Migrations\MigrationTracker();

        try {
            $tracker->forceReexecute($migrationFile);
            \WP_CLI::success("Migration {$migrationFile} will be re-executed on next run.");
            \WP_CLI::line('Note: Make sure you have corrected the migration file before running migrations again.');
        } catch (\Throwable $e) {
            \WP_CLI::error("Failed to force re-execution: " . $e->getMessage());
        }
    }

    #[Command('mailerpress migrations:add-file-hash-column')]
    public function addFileHashColumn(array $args, array $assoc_args): void
    {
        $tracker = new \MailerPress\Core\Migrations\MigrationTracker();

        try {
            $result = $tracker->forceAddFileHashColumn();
            if ($result) {
                \WP_CLI::success('file_hash column added successfully.');
            } else {
                \WP_CLI::error('Failed to add file_hash column. Check error logs for details.');
            }
        } catch (\Throwable $e) {
            \WP_CLI::error("Failed to add file_hash column: " . $e->getMessage());
        }
    }
}
