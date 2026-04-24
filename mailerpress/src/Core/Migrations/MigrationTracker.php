<?php

namespace MailerPress\Core\Migrations;

\defined('ABSPATH') || exit;

/**
 * Tracks executed migrations to prevent duplicate execution and enable rollback
 */
class MigrationTracker
{
    protected string $tableName;

    /**
     * Cache for column existence checks (per request)
     */
    protected static ?bool $fileHashColumnExists = null;
    protected static ?bool $tableExistsCache = null;

    public function __construct()
    {
        global $wpdb;
        $this->tableName = $wpdb->prefix . 'mailerpress_migrations';
        $this->ensureTableExists();
    }

    /**
     * Get the table name (for use in Manager)
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Create the migrations tracking table if it doesn't exist
     */
    protected function ensureTableExists(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();

        // Note: Use prefix key length (191) for indexed VARCHAR columns to support utf8mb4
        // This avoids "Specified key was too long" error on MySQL with utf8mb4
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_name VARCHAR(255) NOT NULL,
            migration_file VARCHAR(500) NOT NULL,
            status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
            error_message TEXT NULL,
            executed_at DATETIME NULL,
            completed_at DATETIME NULL,
            execution_time DECIMAL(10,3) NULL,
            file_hash VARCHAR(64) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY migration_file (migration_file(191)),
            KEY status (status),
            KEY migration_name (migration_name(191))
        ) $charsetCollate;";

        dbDelta($sql);


        // Add file_hash column if it doesn't exist (for existing installations)
        $this->ensureFileHashColumnExists();
    }

    /**
     * Ensure file_hash column exists (for existing installations)
     */
    protected function ensureFileHashColumnExists(): void
    {
        // Use cached value if available
        if (self::$fileHashColumnExists !== null) {
            return;
        }

        global $wpdb;

        // First, check if table exists (use cache)
        if (self::$tableExistsCache === null) {
            self::$tableExistsCache = (bool) $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($this->tableName))
            );
        }

        if (!self::$tableExistsCache) {
            // Table doesn't exist yet, dbDelta will create it with file_hash column
            self::$fileHashColumnExists = false;
            return;
        }

        // Check if column exists using SHOW COLUMNS (cache the result)
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->tableName}");
        $hasFileHashColumn = in_array('file_hash', $columns, true);
        self::$fileHashColumnExists = $hasFileHashColumn;

        if (!$hasFileHashColumn) {
            // Add the column
            $result = $wpdb->query("ALTER TABLE {$this->tableName} ADD COLUMN file_hash VARCHAR(64) NULL AFTER execution_time");

            if ($result === false || !empty($wpdb->last_error)) {
            } else {
                self::$fileHashColumnExists = true; // Update cache after successful addition
            }
        }
    }

    /**
     * Force add file_hash column (for manual recovery)
     */
    public function forceAddFileHashColumn(): bool
    {
        // Use cached value if available
        if (self::$fileHashColumnExists === true) {
            return true; // Already exists
        }

        global $wpdb;

        // Check if table exists (use cache)
        if (self::$tableExistsCache === null) {
            self::$tableExistsCache = (bool) $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($this->tableName))
            );
        }

        if (!self::$tableExistsCache) {
            return false;
        }

        // Check if column already exists (use cache or check once)
        if (self::$fileHashColumnExists === null) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->tableName}");
            self::$fileHashColumnExists = in_array('file_hash', $columns, true);
        }

        if (self::$fileHashColumnExists) {
            return true;
        }

        // Add the column
        $result = $wpdb->query("ALTER TABLE {$this->tableName} ADD COLUMN file_hash VARCHAR(64) NULL AFTER execution_time");

        if ($result === false || !empty($wpdb->last_error)) {
            return false;
        }

        // Update cache after successful addition
        self::$fileHashColumnExists = true;
        return true;
    }

    /**
     * Check if a migration has already been executed successfully
     * Also checks if the file has changed (by comparing hash)
     */
    public function isExecuted(string $migrationFile, ?string $fullPath = null): bool
    {
        global $wpdb;

        // Check if table exists first (use cache)
        if (self::$tableExistsCache === null) {
            self::$tableExistsCache = (bool) $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($this->tableName))
            );
        }

        if (!self::$tableExistsCache) {
            return false; // Table doesn't exist, migration not executed
        }

        // Use cached column check if available
        if (self::$fileHashColumnExists === null) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->tableName}");
            self::$fileHashColumnExists = in_array('file_hash', $columns, true);
        }

        $hasFileHashColumn = self::$fileHashColumnExists;

        if ($hasFileHashColumn) {
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT status, file_hash FROM {$this->tableName} WHERE migration_file = %s",
                    $migrationFile
                )
            );
        } else {
            // Fallback for old installations without file_hash column
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT status FROM {$this->tableName} WHERE migration_file = %s",
                    $migrationFile
                )
            );
        }

        if (!$result || $result->status !== 'completed') {
            return false;
        }

        // If file path provided and file_hash column exists, check if file has changed
        if ($hasFileHashColumn && $fullPath && file_exists($fullPath)) {
            $currentHash = $this->getFileHash($fullPath);
            $storedHash = $result->file_hash ?? null;

            // If file has changed, it needs to be re-executed
            if ($storedHash && $currentHash !== $storedHash) {
                return false; // File changed, needs re-execution
            }

            // If no hash stored yet, calculate and store it (for existing completed migrations)
            if (!$storedHash && $currentHash) {
                $wpdb->update(
                    $this->tableName,
                    ['file_hash' => $currentHash],
                    ['migration_file' => $migrationFile],
                    ['%s'],
                    ['%s']
                );
            }
        }

        return true;
    }

    /**
     * Get file hash for change detection
     */
    protected function getFileHash(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return '';
        }

        return hash_file('sha256', $filePath);
    }

    /**
     * Check if a migration is currently running (to prevent concurrent execution)
     */
    public function isRunning(string $migrationFile): bool
    {
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tableName} WHERE migration_file = %s AND status = 'running'",
                $migrationFile
            )
        );

        return (int)$result > 0;
    }

    /**
     * Mark a migration as started
     */
    public function startMigration(string $migrationName, string $migrationFile, ?string $fullPath = null): int
    {
        global $wpdb;

        $fileHash = $fullPath ? $this->getFileHash($fullPath) : null;

        // Use cached column check
        if (self::$fileHashColumnExists === null) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->tableName}");
            self::$fileHashColumnExists = in_array('file_hash', $columns, true);
        }
        $hasFileHashColumn = self::$fileHashColumnExists;

        // Check if already exists
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->tableName} WHERE migration_file = %s",
                $migrationFile
            )
        );

        if ($existing) {
            // Update existing record (reset if file changed)
            $updateData = [
                'migration_name' => $migrationName,
                'status' => 'running',
                'executed_at' => current_time('mysql'),
                'error_message' => null,
            ];
            $updateFormat = ['%s', '%s', '%s', '%s'];

            if ($hasFileHashColumn && $fileHash !== null) {
                $updateData['file_hash'] = $fileHash;
                $updateFormat[] = '%s';
            }

            $wpdb->update(
                $this->tableName,
                $updateData,
                ['id' => $existing],
                $updateFormat,
                ['%d']
            );
            return (int)$existing;
        } else {
            // Insert new record
            $insertData = [
                'migration_name' => $migrationName,
                'migration_file' => $migrationFile,
                'status' => 'running',
                'executed_at' => current_time('mysql'),
            ];
            $insertFormat = ['%s', '%s', '%s', '%s'];

            if ($hasFileHashColumn && $fileHash !== null) {
                $insertData['file_hash'] = $fileHash;
                $insertFormat[] = '%s';
            }

            $wpdb->insert(
                $this->tableName,
                $insertData,
                $insertFormat
            );

            if (!empty($wpdb->last_error)) {
                throw new \RuntimeException("Failed to track migration start: {$wpdb->last_error}");
            }

            return (int)$wpdb->insert_id;
        }
    }

    /**
     * Mark a migration as completed
     */
    public function completeMigration(string $migrationFile, float $executionTime, ?string $fullPath = null): void
    {
        global $wpdb;

        $fileHash = $fullPath ? $this->getFileHash($fullPath) : null;

        // Use cached column check
        if (self::$fileHashColumnExists === null) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->tableName}");
            self::$fileHashColumnExists = in_array('file_hash', $columns, true);
        }
        $hasFileHashColumn = self::$fileHashColumnExists;

        $updateData = [
            'status' => 'completed',
            'completed_at' => current_time('mysql'),
            'execution_time' => $executionTime,
            'error_message' => null,
        ];
        $updateFormat = ['%s', '%s', '%f', '%s'];

        if ($hasFileHashColumn && $fileHash !== null) {
            $updateData['file_hash'] = $fileHash;
            $updateFormat[] = '%s';
        }

        $wpdb->update(
            $this->tableName,
            $updateData,
            ['migration_file' => $migrationFile],
            $updateFormat,
            ['%s']
        );
    }

    /**
     * Mark a migration as failed
     */
    public function failMigration(string $migrationFile, string $errorMessage): void
    {
        global $wpdb;

        $wpdb->update(
            $this->tableName,
            [
                'status' => 'failed',
                'error_message' => $errorMessage,
            ],
            ['migration_file' => $migrationFile],
            ['%s', '%s'],
            ['%s']
        );
    }

    /**
     * Get all failed migrations
     */
    public function getFailedMigrations(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$this->tableName} WHERE status = 'failed' ORDER BY executed_at DESC",
            ARRAY_A
        );
    }

    /**
     * Get migration history
     */
    public function getHistory(int $limit = 50): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->tableName} ORDER BY executed_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Reset a failed migration to allow retry
     */
    public function resetMigration(string $migrationFile): void
    {
        global $wpdb;

        $wpdb->update(
            $this->tableName,
            [
                'status' => 'pending',
                'error_message' => null,
                'executed_at' => null,
                'completed_at' => null,
                'execution_time' => null,
                'file_hash' => null, // Reset hash to force re-check
            ],
            ['migration_file' => $migrationFile],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );
    }

    /**
     * Force re-execution of a migration (even if completed)
     * Useful when migration file has been corrected
     */
    public function forceReexecute(string $migrationFile): void
    {
        global $wpdb;

        $wpdb->update(
            $this->tableName,
            [
                'status' => 'pending',
                'error_message' => null,
                'executed_at' => null,
                'completed_at' => null,
                'execution_time' => null,
                'file_hash' => null, // Reset hash to force re-check
            ],
            ['migration_file' => $migrationFile],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );
    }

    /**
     * Reset all failed migrations to allow retry
     */
    public function resetAllFailedMigrations(): int
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->tableName,
            [
                'status' => 'pending',
                'error_message' => null,
                'executed_at' => null,
                'completed_at' => null,
                'execution_time' => null,
                'file_hash' => null, // Reset hash to force re-check
            ],
            ['status' => 'failed'],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );

        return (int)$result;
    }

    /**
     * Reset all running migrations (in case of crash)
     */
    public function resetAllRunningMigrations(): int
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->tableName,
            [
                'status' => 'pending',
                'error_message' => null,
            ],
            ['status' => 'running'],
            ['%s', '%s'],
            ['%s']
        );

        return (int)$result;
    }

    /**
     * Get count of failed migrations
     */
    public function getFailedCount(): int
    {
        global $wpdb;

        $result = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE status = 'failed'"
        );

        return (int)$result;
    }

    /**
     * Get count of running migrations
     */
    public function getRunningCount(): int
    {
        global $wpdb;

        $result = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE status = 'running'"
        );

        return (int)$result;
    }
}
