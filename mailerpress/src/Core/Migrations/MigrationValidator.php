<?php

namespace MailerPress\Core\Migrations;

\defined('ABSPATH') || exit;

/**
 * Validates if migrations are actually needed by checking database state
 * This is a fallback system that doesn't rely solely on tracking
 */
class MigrationValidator
{
    /**
     * Check if a migration file should be executed based on actual database state
     *
     * @param string $migrationFile Full path to migration file
     * @param string $relativePath Relative path for tracking
     * @return bool True if migration should run
     */
    public function shouldExecuteMigration(string $migrationFile, string $relativePath): bool
    {
        global $wpdb;

        // Always execute if file doesn't exist (shouldn't happen, but safety check)
        if (!file_exists($migrationFile)) {
            return false;
        }

        // Load migration to analyze what it does
        try {
            $migration = require $migrationFile;
            if (!is_callable($migration)) {
                return true; // If invalid, assume it needs to run
            }

            // IMPORTANT: We must NOT call $migration($schema) directly because some migrations
            // use raw $wpdb->query() calls instead of SchemaBuilder. Calling the callback would
            // execute those SQL statements as a side effect (ALTER TABLE is DDL and cannot be
            // rolled back with SAVEPOINT in MySQL).
            //
            // Instead, we use static analysis: load the file content and check if it uses
            // SchemaBuilder methods ($schema->create or $schema->table). If it does, we can
            // safely call it for analysis. If not, we skip analysis and let the migration
            // handle its own idempotency.
            $fileContent = file_get_contents($migrationFile);
            $usesSchemaBuilder = preg_match('/\$schema\s*->\s*(create|table)\s*\(/', $fileContent);

            if (!$usesSchemaBuilder) {
                // Migration uses raw SQL — can't analyze safely, assume it needs to run.
                // The migration itself must be idempotent (use IF NOT EXISTS, etc.)
                return true;
            }

            // Safe to call: migration only uses SchemaBuilder (no raw SQL side effects)
            $schema = new SchemaBuilder();
            $migration($schema);

            // Check if all expected tables/columns exist
            $needsExecution = $this->validateSchemaState($schema);

            return $needsExecution;
        } catch (\Throwable $e) {
            // If we can't analyze, assume it needs to run (safer)
            return true;
        }
    }

    /**
     * Validate that all tables and columns expected by schema actually exist
     *
     * @param SchemaBuilder $schema
     * @return bool True if migration is needed (tables/columns missing)
     */
    protected function validateSchemaState(SchemaBuilder $schema): bool
    {
        global $wpdb;

        $reflection = new \ReflectionClass($schema);
        $property = $reflection->getProperty('operations');
        $operations = $property->getValue($schema);

        // If no operations, migration is not needed
        if (empty($operations)) {
            return false;
        }

        foreach ($operations as $op) {
            $manager = $op['manager'];
            $tableName = $manager->getTableName();

            if ($op['type'] === 'create') {
                // For create operations, check if table exists with correct structure
                if (!$this->tableExistsWithCorrectStructure($manager)) {
                    return true; // Migration needed
                }
            } elseif ($op['type'] === 'alter') {
                // For alter operations, check if changes are needed
                if ($this->tableNeedsAlteration($manager)) {
                    return true; // Migration needed
                }
            }
        }

        return false; // All tables exist and are correct
    }

    /**
     * Check if table exists with correct structure
     */
    protected function tableExistsWithCorrectStructure(CustomTableManager $manager): bool
    {
        global $wpdb;

        $tableName = $manager->getTableName();

        // Check if table exists
        $tableExists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($tableName))
        ) === $tableName;

        if (!$tableExists) {
            return false;
        }

        // Get expected columns from manager
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('columnBuilders');
        $columnBuilders = $property->getValue($manager);

        // Get actual columns from database
        $actualColumns = $wpdb->get_col("SHOW COLUMNS FROM {$tableName}", 0);

        // Check if all expected columns exist (from columnBuilders)
        foreach ($columnBuilders as $builder) {
            $columnName = $builder->getName();
            if (!in_array($columnName, $actualColumns, true)) {
                return false;
            }
        }

        // Also check columns added via addColumn() / id() which populate $this->columns directly
        $columnsProperty = $reflection->getProperty('columns');
        $columnsProperty->setAccessible(true);
        $simpleColumns = $columnsProperty->getValue($manager);

        foreach (array_keys($simpleColumns) as $columnName) {
            if (!in_array($columnName, $actualColumns, true)) {
                return false;
            }
        }

        // Check version if set
        $versionProperty = $reflection->getProperty('version');
        $expectedVersion = $versionProperty->getValue($manager);

        if ($expectedVersion && $expectedVersion !== '2.0.2') {
            $versionOptionName = 'custom_table_' . sanitize_key(str_replace($wpdb->prefix, '', $tableName)) . '_version';
            $actualVersion = get_option($versionOptionName);

            if ($actualVersion !== $expectedVersion) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if table needs alteration
     */
    protected function tableNeedsAlteration(CustomTableManager $manager): bool
    {
        global $wpdb;

        $tableName = $manager->getTableName();

        // Check if table exists
        $tableExists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($tableName))
        ) === $tableName;

        if (!$tableExists) {
            return true; // Table doesn't exist, needs creation
        }

        // Check columns to drop
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('columnsToDrop');
        $columnsToDrop = $property->getValue($manager);

        if (!empty($columnsToDrop)) {
            $actualColumns = $wpdb->get_col("SHOW COLUMNS FROM {$tableName}", 0);
            foreach ($columnsToDrop as $column) {
                if (in_array($column, $actualColumns, true)) {
                    return true; // Column exists and should be dropped
                }
            }
        }

        // Check columns to add (from ColumnBuilder objects)
        $property = $reflection->getProperty('columnBuilders');
        $columnBuilders = $property->getValue($manager);

        if (!empty($columnBuilders)) {
            $actualColumns = $wpdb->get_col("SHOW COLUMNS FROM {$tableName}", 0);
            foreach ($columnBuilders as $builder) {
                $columnName = $builder->getName();
                if (!in_array($columnName, $actualColumns, true)) {
                    return true; // Column missing, needs to be added
                }
            }
        }

        // Check columns added via addColumn() which populate $this->columns directly
        $columnsProperty = $reflection->getProperty('columns');
        $columnsProperty->setAccessible(true);
        $simpleColumns = $columnsProperty->getValue($manager);

        if (!empty($simpleColumns)) {
            if (!isset($actualColumns)) {
                $actualColumns = $wpdb->get_col("SHOW COLUMNS FROM {$tableName}", 0);
            }
            foreach (array_keys($simpleColumns) as $columnName) {
                if (!in_array($columnName, $actualColumns, true)) {
                    return true; // Column missing, needs to be added
                }
            }
        }

        // Check indexes to add — pure-index migrations were previously invisible to this
        // validator, causing handleExistingInstallations() to mark them as completed without
        // running them on upgrades from older installs.
        $indexProperty = $reflection->getProperty('indexes');
        $indexes = $indexProperty->getValue($manager);

        if (!empty($indexes)) {
            $existingIndexes = $wpdb->get_results("SHOW INDEX FROM {$tableName}", ARRAY_A);
            $existingIndexedCols = [];
            foreach ($existingIndexes as $idx) {
                $existingIndexedCols[] = strtolower($idx['Column_name']);
            }

            foreach ($indexes as $clause) {
                preg_match_all('/`([^`]+)`/', $clause, $matches);
                $expectedCols = array_map('strtolower', $matches[1] ?? []);

                if (!empty($expectedCols)) {
                    // Check whether all columns of this index are already indexed together.
                    // We use the same multi-column logic as CustomTableManager::migrate().
                    $indexFound = false;
                    $existingByKey = [];
                    foreach ($existingIndexes as $idx) {
                        $key = $idx['Key_name'];
                        $existingByKey[$key][] = strtolower($idx['Column_name']);
                    }
                    foreach ($existingByKey as $keyCols) {
                        if (count(array_intersect($expectedCols, $keyCols)) === count($expectedCols)) {
                            $indexFound = true;
                            break;
                        }
                    }
                    if (!$indexFound) {
                        return true; // Index missing, migration needed
                    }
                }
            }
        }

        // Check foreign keys to drop
        $property = $reflection->getProperty('foreignKeysToDrop');
        $foreignKeysToDrop = $property->getValue($manager);

        if (!empty($foreignKeysToDrop)) {
            foreach ($foreignKeysToDrop as $column) {
                $fkName = "fk_{$tableName}_{$column}";
                $fkExists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                         WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = %s
                         AND CONSTRAINT_NAME = %s
                         AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                        $tableName,
                        $fkName
                    )
                );
                if ($fkExists > 0) {
                    return true; // Foreign key exists and should be dropped
                }
            }
        }

        // Check indexes to drop
        $property = $reflection->getProperty('indexesToDrop');
        $indexesToDrop = $property->getValue($manager);

        if (!empty($indexesToDrop)) {
            $existingIndexes = $wpdb->get_results("SHOW INDEX FROM {$tableName}", ARRAY_A);
            foreach ($indexesToDrop as $columnOrName) {
                foreach ($existingIndexes as $index) {
                    if ($index['Column_name'] === $columnOrName || $index['Key_name'] === $columnOrName) {
                        if ($index['Key_name'] !== 'PRIMARY') {
                            return true; // Index exists and should be dropped
                        }
                    }
                }
            }
        }

        return false;
    }
}
