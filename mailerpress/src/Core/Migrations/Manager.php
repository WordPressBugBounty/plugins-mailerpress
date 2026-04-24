<?php

namespace MailerPress\Core\Migrations;

\defined('ABSPATH') || exit;

class Manager
{
    protected string $migrationPath;
    protected SchemaBuilder $schema;
    protected MigrationTracker $tracker;
    protected MigrationValidator $validator;

    /**
     * List of tables to drop on run.
     */
    protected array $tablesToDrop = [];

    /**
     * Lock option name to prevent concurrent migrations
     */
    protected string $lockOptionName = 'mailerpress_migrations_running';

    /**
     * Force execution mode - ignores tracking and validates against actual DB state
     */
    protected bool $forceMode = false;

    public function __construct(string $migrationPath, array $tablesToDrop = [], bool $forceMode = false)
    {
        $this->migrationPath = $migrationPath;
        $this->schema = new SchemaBuilder();
        $this->tablesToDrop = $tablesToDrop;
        $this->tracker = new MigrationTracker();
        $this->validator = new MigrationValidator();
        $this->forceMode = $forceMode;
    }

    /**
     * Run all pending migrations with error handling
     * Automatically detects changed files via file_hash comparison
     */
    public function run(): void
    {
        // Prevent concurrent execution
        if ($this->isLocked()) {
            return;
        }

        try {
            $this->acquireLock();

            // Ensure file_hash column exists (for existing installations)
            // Note: This is called once per migration run, and uses internal caching
            $this->tracker->forceAddFileHashColumn();

            // In force mode, skip handleExistingInstallations to force validation
            if (!$this->forceMode) {
                // For existing installations: if tracking table is empty but tables exist, 
                // mark old migrations as completed to avoid re-execution
                $this->handleExistingInstallations();
            }

            // Drop tables first if any
            if (!empty($this->tablesToDrop)) {
                $this->dropTables($this->tablesToDrop);
            }

            // Load and run migrations
            $files = glob($this->migrationPath . '/*.php');

            if ($files === false) {
                throw new \RuntimeException("Failed to read migration directory: {$this->migrationPath}");
            }

            sort($files); // consistent order

            $executedCount = 0;
            $failedCount = 0;

            foreach ($files as $file) {
                $migrationName = basename($file, '.php');
                // Use relative path from migration directory for tracking
                $relativePath = str_replace($this->migrationPath . '/', '', $file);
                // Normalize to use forward slashes for consistency
                $relativePath = str_replace('\\', '/', $relativePath);

                // In force mode, always validate against actual DB state
                if ($this->forceMode) {
                    // Validate if migration is actually needed by checking DB state
                    if (!$this->validator->shouldExecuteMigration($file, $relativePath)) {
                        // Still mark as completed in tracker if not already
                        if (!$this->tracker->isExecuted($relativePath, $file)) {
                            $this->tracker->startMigration($migrationName, $relativePath, $file);
                            $this->tracker->completeMigration($relativePath, 0, $file);
                        }
                        continue;
                    }
                } else {
                    // Normal mode: Skip if already executed successfully AND file hasn't changed
                    if ($this->tracker->isExecuted($relativePath, $file)) {
                        continue;
                    }
                }

                // Skip if currently running (shouldn't happen with lock, but safety check)
                if ($this->tracker->isRunning($relativePath)) {
                    continue;
                }

                $startTime = microtime(true);
                $migrationId = null;

                try {
                    $migrationId = $this->tracker->startMigration($migrationName, $relativePath, $file);

                    // Load migration file
                    if (!file_exists($file)) {
                        throw new \RuntimeException("Migration file not found: {$file}");
                    }

                    $migration = require $file;

                    if (!is_callable($migration)) {
                        throw new \RuntimeException("Migration file does not return a callable: {$file}");
                    }

                    // Execute migration callback
                    $migration($this->schema);

                    // Execute schema changes
                    $this->schema->migrate();

                    // Calculate execution time
                    $executionTime = microtime(true) - $startTime;

                    // Mark as completed (with file hash for change detection)
                    $this->tracker->completeMigration($relativePath, $executionTime, $file);
                    $executedCount++;

                    // Déclencher le hook après la migration pour permettre la synchronisation des données
                    do_action('mailerpress_migration_completed');

                    // Reset schema for next migration
                    $this->schema = new SchemaBuilder();
                } catch (\Throwable $e) {
                    $errorMessage = sprintf(
                        "Migration failed: %s in %s:%d - %s",
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        $e->getTraceAsString()
                    );

                    if ($migrationId !== null) {
                        $this->tracker->failMigration($relativePath, $errorMessage);
                    }

                    $failedCount++;

                    // Continue with next migration instead of stopping everything
                    // This allows partial success
                    continue;
                }
            }
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $this->releaseLock();
        }
    }

    public function dryRun(): void
    {
        $files = glob($this->migrationPath . '/*.php');

        if ($files === false) {
            throw new \RuntimeException("Failed to read migration directory: {$this->migrationPath}");
        }

        sort($files);

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $migration = require $file;
            if (is_callable($migration)) {
                $migration($this->schema);
            }
        }

        $this->schema->dryRun();
    }

    /**
     * Drop specific tables immediately with error handling.
     */
    public function dropTables(array $tables): static
    {
        global $wpdb;

        if (empty($tables)) {
            return $this;
        }

        try {
            // Disable foreign key checks temporarily
            $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

            if (!empty($wpdb->last_error)) {
                throw new \RuntimeException("Failed to disable foreign key checks: {$wpdb->last_error}");
            }

            foreach ($tables as $table) {
                $fullTable = $wpdb->prefix . $table;

                // Check if table exists
                $tableExists = $wpdb->get_var(
                    $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($fullTable))
                );

                if ($tableExists) {
                    $wpdb->query("DROP TABLE IF EXISTS `{$fullTable}`");
                }
            }
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            // Always re-enable foreign key checks
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
        }

        return $this;
    }

    /**
     * Check if migrations are currently locked (running)
     */
    protected function isLocked(): bool
    {
        $lock = get_transient($this->lockOptionName);
        return $lock !== false;
    }

    /**
     * Acquire lock to prevent concurrent migrations
     */
    protected function acquireLock(): void
    {
        // Set lock for 5 minutes (migrations should complete much faster)
        set_transient($this->lockOptionName, time(), 300);
    }

    /**
     * Release migration lock
     */
    protected function releaseLock(): void
    {
        delete_transient($this->lockOptionName);
    }

    /**
     * Force release lock (for emergency recovery)
     */
    public function forceReleaseLock(): void
    {
        delete_transient($this->lockOptionName);
    }

    /**
     * Run migrations with force mode (ignores failed migrations and retries them)
     */
    public function runForce(): void
    {
        // Reset all failed migrations first
        $resetCount = $this->tracker->resetAllFailedMigrations();

        // Reset running migrations (in case of crash)
        $runningCount = $this->tracker->resetAllRunningMigrations();

        // Force release lock
        $this->forceReleaseLock();

        // Run migrations normally
        $this->run();
    }

    /**
     * Reset all failed migrations and release lock
     * Useful for emergency recovery
     */
    public function resetFailed(): int
    {
        $count = $this->tracker->resetAllFailedMigrations();
        $this->forceReleaseLock();
        return $count;
    }

    /**
     * Handle existing installations where migrations were run before tracking was introduced
     * If tracking table is empty but MailerPress tables exist, mark old migrations as completed
     */
    protected function handleExistingInstallations(): void
    {
        global $wpdb;

        // Check if tracking table has any completed migrations
        $hasCompletedMigrations = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tracker->getTableName()} WHERE status = 'completed'"
        );

        // If we have completed migrations, tracking is already working
        if ((int)$hasCompletedMigrations > 0) {
            return;
        }

        // Check if MailerPress tables exist (indicates old installation)
        $mailerpressTables = [
            $wpdb->prefix . 'mailerpress_contacts',
            $wpdb->prefix . 'mailerpress_campaigns',
            $wpdb->prefix . 'mailerpress_lists',
        ];

        $existingTables = 0;
        foreach ($mailerpressTables as $table) {
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table))) === $table) {
                $existingTables++;
            }
        }

        // If at least one MailerPress table exists, this is an old installation
        if ($existingTables > 0) {

            // Get all migration files
            $files = glob($this->migrationPath . '/*.php');
            if ($files === false) {
                return;
            }

            sort($files);

            // Mark migrations as completed without executing
            // This prevents re-execution of migrations that already ran
            foreach ($files as $file) {
                $migrationName = basename($file, '.php');
                $relativePath = str_replace($this->migrationPath . '/', '', $file);
                $relativePath = str_replace('\\', '/', $relativePath);

                // Check if already tracked
                if ($this->tracker->isExecuted($relativePath, $file)) {
                    continue;
                }

                // For existing installations, mark as completed without executing
                try {
                    $fileHash = file_exists($file) ? hash_file('sha256', $file) : null;

                    // Insert as completed (simulating that it already ran)
                    $wpdb->insert(
                        $this->tracker->getTableName(),
                        [
                            'migration_name' => $migrationName,
                            'migration_file' => $relativePath,
                            'status' => 'completed',
                            'completed_at' => current_time('mysql'),
                            'executed_at' => current_time('mysql'),
                            'file_hash' => $fileHash,
                        ],
                        ['%s', '%s', '%s', '%s', '%s', '%s']
                    );

                } catch (\Throwable $e) {}
            }
        }
    }

    /**
     * Get migration status summary
     */
    public function getStatus(): array
    {
        return [
            'is_locked' => $this->isLocked(),
            'failed_count' => $this->tracker->getFailedCount(),
            'running_count' => $this->tracker->getRunningCount(),
            'failed_migrations' => $this->tracker->getFailedMigrations(),
        ];
    }
}
