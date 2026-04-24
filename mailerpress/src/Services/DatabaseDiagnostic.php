<?php

declare(strict_types=1);

namespace MailerPress\Services;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\Manager;
use MailerPress\Core\Migrations\MigrationTracker;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Services\DatabaseRepairLogger;

\defined('ABSPATH') || exit;

/**
 * Service for database diagnosis
 * Analyzes the state of the database and detects problems
 */
class DatabaseDiagnostic
{
    protected string $migrationPath;
    protected MigrationTracker $tracker;

    public function __construct()
    {
        $this->migrationPath = Kernel::$config['root'] . '/src/Core/Migrations/migrations';
        $this->tracker = new MigrationTracker();
    }

    /**
     * Performs a complete diagnosis of the database
     */
    public function diagnose(): array
    {
        global $wpdb;

        $issues = [];
        $tables = [];
        $migrationStatus = [];

        // 1. Check the status of migrations
        $migrationStatus = $this->checkMigrationStatus();

        // 2. Analyze all expected tables
        $expectedTables = $this->getExpectedTables();

        foreach ($expectedTables as $tableName) {
            $fullTableName = Tables::get($tableName);
            $tableInfo = $this->analyzeTable($fullTableName, $tableName);

            if (!empty($tableInfo['issues'])) {
                $issues = array_merge($issues, $tableInfo['issues']);
            }

            $tables[$tableName] = [
                'exists' => $tableInfo['exists'],
                'issues' => $tableInfo['issues'],
                'columns' => $tableInfo['columns'],
                'expected_columns' => $tableInfo['expected_columns'] ?? [],
                'missing_columns' => $tableInfo['missing_columns'] ?? [],
                'indexes' => $tableInfo['indexes'],
                'expected_indexes' => $tableInfo['expected_indexes'] ?? [],
                'missing_indexes' => $tableInfo['missing_indexes'] ?? [],
                'foreign_keys' => $tableInfo['foreign_keys'],
                'expected_foreign_keys' => $tableInfo['expected_foreign_keys'] ?? [],
                'missing_foreign_keys' => $tableInfo['missing_foreign_keys'] ?? [],
            ];
        }

        // 3. Check the tracking table of migrations
        $trackerTable = $this->tracker->getTableName();
        $trackerExists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($trackerTable))
        ) === $trackerTable;

        if (!$trackerExists) {
            $issues[] = [
                'type' => 'critical',
                'table' => 'mailerpress_migrations',
                'issue' => 'missing_table',
                'message' => __('The migrations tracking table is missing', 'mailerpress'),
            ];
        }

        // 4. Check the failed migrations
        $failedMigrations = $this->tracker->getFailedMigrations();
        if (!empty($failedMigrations)) {
            foreach ($failedMigrations as $migration) {
                $issues[] = [
                    'type' => 'error',
                    'table' => 'migrations',
                    'issue' => 'failed_migration',
                    'message' => sprintf(
                        __('Migration failed: %s - %s', 'mailerpress'),
                        $migration['migration_name'],
                        $migration['error_message'] ?? __('Unknown error', 'mailerpress')
                    ),
                    'migration_file' => $migration['migration_file'],
                    'error_message' => $migration['error_message'],
                ];
            }
        }

        // 5. Check if the migrations are locked
        $manager = new Manager($this->migrationPath, []);
        $status = $manager->getStatus();
        if ($status['is_locked']) {
            $issues[] = [
                'type' => 'warning',
                'table' => 'migrations',
                'issue' => 'locked',
                'message' => __('Migrations are currently locked', 'mailerpress'),
            ];
        }

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'tables' => $tables,
            'migration_status' => $migrationStatus,
            'summary' => [
                'total_tables' => count($expectedTables),
                'existing_tables' => count(array_filter($tables, fn($t) => $t['exists'])),
                'missing_tables' => count(array_filter($tables, fn($t) => !$t['exists'])),
                'total_issues' => count($issues),
                'critical_issues' => count(array_filter($issues, fn($i) => $i['type'] === 'critical')),
                'errors' => count(array_filter($issues, fn($i) => $i['type'] === 'error')),
                'warnings' => count(array_filter($issues, fn($i) => $i['type'] === 'warning')),
            ],
        ];
    }

    /**
     * Analyze a specific table and compare with the expected structure
     */
    protected function analyzeTable(string $fullTableName, string $tableName): array
    {
        global $wpdb;

        $result = [
            'exists' => false,
            'issues' => [],
            'columns' => [],
            'expected_columns' => [],
            'missing_columns' => [],
            'indexes' => [],
            'expected_indexes' => [],
            'missing_indexes' => [],
            'foreign_keys' => [],
            'expected_foreign_keys' => [],
            'missing_foreign_keys' => [],
        ];

        // Check if the table exists
        $tableExists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($fullTableName))
        ) === $fullTableName;

        $result['exists'] = $tableExists;

        if (!$tableExists) {
            $result['issues'][] = [
                'type' => 'critical',
                'table' => $tableName,
                'issue' => 'missing_table',
                'message' => sprintf(__('Table %s is missing', 'mailerpress'), $tableName),
            ];
            return $result;
        }

        // Get the expected structure from the migrations
        $expectedStructure = $this->getExpectedTableStructure($tableName);

        DatabaseRepairLogger::info(__("Expected structure for {$tableName}", 'mailerpress'), [
            'expected_columns_count' => count($expectedStructure['columns'] ?? []),
            'expected_indexes_count' => count($expectedStructure['indexes'] ?? []),
            'expected_foreign_keys_count' => count($expectedStructure['foreign_keys'] ?? []),
        ]);

        // Analyze the current columns
        try {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$fullTableName}", ARRAY_A);
            $actualColumns = array_map(fn($col) => $col['Field'], $columns);
            $result['columns'] = $actualColumns;

            // Compare with the expected columns
            if (!empty($expectedStructure['columns'])) {
                $result['expected_columns'] = $expectedStructure['columns'];
                $missingColumns = array_diff($expectedStructure['columns'], $actualColumns);
                $result['missing_columns'] = array_values($missingColumns);

                DatabaseRepairLogger::info(__("Column comparison for {$tableName}", 'mailerpress'), [
                    'expected' => count($expectedStructure['columns']),
                    'actual' => count($actualColumns),
                    'missing' => count($result['missing_columns']),
                    'missing_list' => $result['missing_columns'],
                ]);

                foreach ($missingColumns as $missingCol) {
                    $result['issues'][] = [
                        'type' => 'critical',
                        'table' => $tableName,
                        'issue' => 'missing_column',
                        'message' => sprintf(__('Missing column in %s: %s', 'mailerpress'), $tableName, $missingCol),
                        'column' => $missingCol,
                    ];
                }

                // Detect extra columns (exist in DB but not in migrations)
                // Exclude 'id' as it may be added via addColumn() and is always legitimate
                $extraColumns = array_diff($actualColumns, $expectedStructure['columns']);
                $result['extra_columns'] = array_values($extraColumns);

                if (!empty($extraColumns)) {
                    DatabaseRepairLogger::warning(__("Extra columns detected in {$tableName}", 'mailerpress'), [
                        'extra_columns' => array_values($extraColumns),
                        'expected' => $expectedStructure['columns'],
                        'actual' => $actualColumns,
                    ]);

                    foreach ($extraColumns as $extraCol) {
                        $result['issues'][] = [
                            'type' => 'warning',
                            'table' => $tableName,
                            'issue' => 'extra_column',
                            'message' => sprintf(__('Extra column in %s: %s (not defined in any migration)', 'mailerpress'), $tableName, $extraCol),
                            'column' => $extraCol,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $result['issues'][] = [
                'type' => 'error',
                'table' => $tableName,
                'issue' => 'column_check_failed',
                'message' => sprintf(__('Unable to check columns: %s', 'mailerpress'), $e->getMessage()),
            ];
        }

        // Analyze the current indexes
        try {
            $indexes = $wpdb->get_results("SHOW INDEX FROM {$fullTableName}", ARRAY_A);
            $indexGroups = [];
            foreach ($indexes as $index) {
                $keyName = $index['Key_name'];
                if ($keyName === 'PRIMARY') {
                    continue; // Ignore the primary key
                }
                if (!isset($indexGroups[$keyName])) {
                    $indexGroups[$keyName] = [];
                }
                $indexGroups[$keyName][] = strtolower($index['Column_name']);
            }
            $result['indexes'] = $indexGroups;

            // Compare with the expected indexes
            if (!empty($expectedStructure['indexes'])) {
                $result['expected_indexes'] = $expectedStructure['indexes'];
                $actualIndexKeys = array_keys($indexGroups);

                DatabaseRepairLogger::info(__("Index comparison for {$tableName}", 'mailerpress'), [
                    'expected' => count($expectedStructure['indexes']),
                    'actual' => count($indexGroups),
                    'expected_details' => $expectedStructure['indexes'],
                    'actual_details' => $indexGroups,
                ]);

                foreach ($expectedStructure['indexes'] as $expectedIndex) {
                    $indexFound = false;
                    $expectedCols = is_array($expectedIndex['columns'])
                        ? array_map('strtolower', $expectedIndex['columns'])
                        : [strtolower($expectedIndex['columns'])];

                    // Sort the expected columns for comparison
                    $expectedColsSorted = $expectedCols;
                    sort($expectedColsSorted);

                    // Check if an index exists with the same columns (same order or different order)
                    foreach ($indexGroups as $indexName => $indexCols) {
                        $normalizedCols = array_map('strtolower', $indexCols);
                        $normalizedColsSorted = $normalizedCols;
                        sort($normalizedColsSorted);

                        // Compare the exact order first (more strict)
                        $exactMatch = (
                            count($normalizedCols) === count($expectedCols) &&
                            $normalizedCols === $expectedCols
                        );

                        // If no exact match, compare the sorted columns (different order but same columns)
                        $sortedMatch = (
                            count($normalizedColsSorted) === count($expectedColsSorted) &&
                            $normalizedColsSorted === $expectedColsSorted
                        );

                        if ($exactMatch || $sortedMatch) {
                            $indexFound = true;
                            DatabaseRepairLogger::info(__("Index found: {$indexName} corresponds to the expected index", 'mailerpress'), [
                                'table' => $tableName,
                                'existing_index' => $indexName,
                                'existing_columns' => $indexCols,
                                'expected_columns' => $expectedIndex['columns'],
                                'exact_match' => $exactMatch,
                                'sorted_match' => $sortedMatch,
                            ]);
                            break;
                        }
                    }

                    if (!$indexFound) {
                        $result['missing_indexes'][] = $expectedIndex;
                        $indexDesc = is_array($expectedIndex['columns'])
                            ? implode(', ', $expectedIndex['columns'])
                            : $expectedIndex['columns'];
                        $result['issues'][] = [
                            'type' => 'error',
                            'table' => $tableName,
                            'issue' => 'missing_index',
                            'message' => sprintf(
                                __('Missing index in %s: %s (%s)', 'mailerpress'),
                                $tableName,
                                $expectedIndex['type'],
                                $indexDesc
                            ),
                            'index' => $expectedIndex,
                        ];
                        DatabaseRepairLogger::warning(__("Missing index detected for {$tableName}", 'mailerpress'), [
                            'index' => $expectedIndex,
                            'expected_columns' => $expectedCols,
                            'existing_indexes' => $indexGroups,
                            'total_missing' => count($result['missing_indexes']),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $result['issues'][] = [
                'type' => 'warning',
                'table' => $tableName,
                'issue' => 'index_check_failed',
                'message' => sprintf(__('Unable to check indexes: %s', 'mailerpress'), $e->getMessage()),
            ];
        }

        // Analyze the current foreign keys
        try {
            $foreignKeys = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = %s
                     AND REFERENCED_TABLE_NAME IS NOT NULL",
                    $fullTableName
                ),
                ARRAY_A
            );
            $result['foreign_keys'] = $foreignKeys;

            // Compare with the expected foreign keys
            $result['expected_foreign_keys'] = $expectedStructure['foreign_keys'] ?? [];
            $actualFKs = [];
            foreach ($foreignKeys as $fk) {
                $actualFKs[] = [
                    'column' => strtolower($fk['COLUMN_NAME']),
                    'referenced_table' => str_replace($wpdb->prefix, '', $fk['REFERENCED_TABLE_NAME']),
                    'referenced_column' => strtolower($fk['REFERENCED_COLUMN_NAME']),
                    'constraint_name' => $fk['CONSTRAINT_NAME'],
                ];
            }

            // Check for missing foreign keys (expected but not in DB)
            if (!empty($expectedStructure['foreign_keys'])) {
                foreach ($expectedStructure['foreign_keys'] as $expectedFK) {
                    $fkFound = false;
                    foreach ($actualFKs as $actualFK) {
                        if (
                            $actualFK['column'] === strtolower($expectedFK['column']) &&
                            $actualFK['referenced_table'] === $expectedFK['referenced_table'] &&
                            $actualFK['referenced_column'] === strtolower($expectedFK['referenced_column'])
                        ) {
                            $fkFound = true;
                            break;
                        }
                    }

                    if (!$fkFound) {
                        $result['missing_foreign_keys'][] = $expectedFK;
                        $result['issues'][] = [
                            'type' => 'error',
                            'table' => $tableName,
                            'issue' => 'missing_foreign_key',
                            'message' => sprintf(
                                __('Missing foreign key in %s: %s -> %s.%s', 'mailerpress'),
                                $tableName,
                                $expectedFK['column'],
                                $expectedFK['referenced_table'],
                                $expectedFK['referenced_column']
                            ),
                            'foreign_key' => $expectedFK,
                        ];
                    }
                }
            }

            // Check for extra foreign keys (in DB but not expected — should have been dropped by a migration)
            $expectedFKsNormalized = array_map(function ($fk) {
                return strtolower($fk['column']) . '|' . strtolower($fk['referenced_table']) . '|' . strtolower($fk['referenced_column']);
            }, $expectedStructure['foreign_keys'] ?? []);

            // Also detect duplicate foreign keys (same column/ref but different constraint names)
            $seenFKs = [];

            foreach ($actualFKs as $actualFK) {
                $actualKey = $actualFK['column'] . '|' . $actualFK['referenced_table'] . '|' . $actualFK['referenced_column'];

                if (!in_array($actualKey, $expectedFKsNormalized, true)) {
                    // FK not expected at all — flag as extra
                    $result['extra_foreign_keys'][] = $actualFK;
                    $result['issues'][] = [
                        'type' => 'warning',
                        'table' => $tableName,
                        'issue' => 'extra_foreign_key',
                        'message' => sprintf(
                            __('Extra foreign key in %s: %s -> %s.%s (should have been dropped by migration)', 'mailerpress'),
                            $tableName,
                            $actualFK['column'],
                            $actualFK['referenced_table'],
                            $actualFK['referenced_column']
                        ),
                        'foreign_key' => $actualFK,
                    ];

                    DatabaseRepairLogger::warning(__("Extra foreign key detected in {$tableName}", 'mailerpress'), [
                        'constraint_name' => $actualFK['constraint_name'],
                        'column' => $actualFK['column'],
                        'referenced_table' => $actualFK['referenced_table'],
                        'referenced_column' => $actualFK['referenced_column'],
                    ]);
                } elseif (isset($seenFKs[$actualKey])) {
                    // FK is expected but we already saw one with the same signature — this is a duplicate
                    $result['extra_foreign_keys'][] = $actualFK;
                    $result['issues'][] = [
                        'type' => 'warning',
                        'table' => $tableName,
                        'issue' => 'extra_foreign_key',
                        'message' => sprintf(
                            __('Duplicate foreign key in %s: %s -> %s.%s (constraint %s is a duplicate of %s)', 'mailerpress'),
                            $tableName,
                            $actualFK['column'],
                            $actualFK['referenced_table'],
                            $actualFK['referenced_column'],
                            $actualFK['constraint_name'],
                            $seenFKs[$actualKey]
                        ),
                        'foreign_key' => $actualFK,
                    ];

                    DatabaseRepairLogger::warning(__("Duplicate foreign key detected in {$tableName}", 'mailerpress'), [
                        'constraint_name' => $actualFK['constraint_name'],
                        'duplicate_of' => $seenFKs[$actualKey],
                        'column' => $actualFK['column'],
                        'referenced_table' => $actualFK['referenced_table'],
                        'referenced_column' => $actualFK['referenced_column'],
                    ]);
                }

                // Track the first constraint seen for each FK signature
                if (!isset($seenFKs[$actualKey])) {
                    $seenFKs[$actualKey] = $actualFK['constraint_name'];
                }
            }
        } catch (\Throwable $e) {
            // Ignore the foreign key errors (may not be supported)
        }

        return $result;
    }

    /**
     * Get the expected structure of a table from the migrations
     * Aggregate all modifications from all migrations
     */
    protected function getExpectedTableStructure(string $tableName): array
    {
        global $wpdb;
        $fullTableName = Tables::get($tableName);

        $structure = [
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
        ];

        $files = glob($this->migrationPath . '/*.php');
        if ($files === false) {
            return $structure;
        }

        sort($files);

        // Use keys to avoid duplicates
        $columnsMap = [];
        $indexesMap = [];
        $foreignKeysMap = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            try {
                $schema = new SchemaBuilder();
                $migration = require $file;

                if (!is_callable($migration)) {
                    continue;
                }

                $migration($schema);

                // Use reflection to access the operations
                $reflection = new \ReflectionClass($schema);
                $operationsProperty = $reflection->getProperty('operations');
                $operationsProperty->setAccessible(true);
                $operations = $operationsProperty->getValue($schema);

                foreach ($operations as $op) {
                    $manager = $op['manager'];
                    if ($manager->getTableName() !== $fullTableName) {
                        continue;
                    }

                    // Extract the columns
                    $managerReflection = new \ReflectionClass($manager);

                    // Columns from columnBuilders
                    $columnBuildersProperty = $managerReflection->getProperty('columnBuilders');
                    $columnBuildersProperty->setAccessible(true);
                    $columnBuilders = $columnBuildersProperty->getValue($manager);

                    foreach ($columnBuilders as $builder) {
                        $columnName = $builder->getName();
                        $columnsMap[$columnName] = true;
                    }

                    // Columns from columns (directly added via addColumn)
                    $columnsProperty = $managerReflection->getProperty('columns');
                    $columnsProperty->setAccessible(true);
                    $columns = $columnsProperty->getValue($manager);

                    foreach (array_keys($columns) as $columnName) {
                        $columnsMap[$columnName] = true;
                    }

                    // Handle the columns to drop (do not include them in the expected structure)
                    $columnsToDropProperty = $managerReflection->getProperty('columnsToDrop');
                    $columnsToDropProperty->setAccessible(true);
                    $columnsToDrop = $columnsToDropProperty->getValue($manager);

                    foreach ($columnsToDrop as $columnToDrop) {
                        unset($columnsMap[$columnToDrop]);
                    }

                    // Extract the indexes
                    $indexesProperty = $managerReflection->getProperty('indexes');
                    $indexesProperty->setAccessible(true);
                    $indexes = $indexesProperty->getValue($manager);

                    foreach ($indexes as $indexClause) {
                        // Parse the index to extract the columns
                        // Possible format: 
                        // - "INDEX (`col1`, `col2`)"
                        // - "UNIQUE (`col1`, `col2`)"  
                        // - "CONSTRAINT `name_unique` UNIQUE (`col1`, `col2`)"

                        $indexType = 'INDEX';
                        if (stripos($indexClause, 'UNIQUE') !== false) {
                            $indexType = 'UNIQUE';
                        }

                        // Extract only the columns between parentheses after UNIQUE or INDEX
                        // Pattern: search UNIQUE or INDEX followed by (possibly a constraint name) then parentheses with the columns
                        if (preg_match('/\((?:`[^`]+`(?:,\s*`[^`]+`)*)\)/', $indexClause, $parenMatch)) {
                            // Extract the columns from the parentheses
                            preg_match_all('/`([^`]+)`/', $parenMatch[0], $matches);
                            $cols = $matches[1] ?? [];
                        } else {
                            // Fallback: extract all columns between backticks
                            preg_match_all('/`([^`]+)`/', $indexClause, $matches);
                            $cols = $matches[1] ?? [];

                            // If it's a UNIQUE index with CONSTRAINT, the first element is the constraint name
                            // We remove it if the name contains "_unique" or if it's the only different element
                            if ($indexType === 'UNIQUE' && stripos($indexClause, 'CONSTRAINT') !== false && count($cols) > 1) {
                                // The first element is probably the constraint name, we remove it
                                $firstCol = $cols[0];
                                if (stripos($firstCol, '_unique') !== false || stripos($firstCol, 'unique') !== false) {
                                    array_shift($cols);
                                }
                            }
                        }

                        // Deduplicate and clean the columns
                        $cols = array_unique(array_map('trim', $cols));
                        $cols = array_values(array_filter($cols, function ($col) {
                            // Ignore names that resemble constraint names
                            return stripos($col, '_unique') === false &&
                                stripos($col, 'unique') === false &&
                                $col !== 'PRIMARY';
                        }));

                        if (!empty($cols)) {
                            // Create a unique key for the index (sort the columns to avoid duplicate order)
                            $colsSorted = $cols;
                            sort($colsSorted);
                            $indexKey = $indexType . '_' . implode('_', $colsSorted);

                            // Do not add if an identical index already exists (same columns, same type)
                            if (!isset($indexesMap[$indexKey])) {
                                $indexesMap[$indexKey] = [
                                    'type' => $indexType,
                                    'columns' => $cols, // Keep the original order
                                ];
                            } else {
                                // If an identical index already exists, keep the one with the original order the most logical
                                DatabaseRepairLogger::info(__("Duplicate index detected (ignored)", 'mailerpress'), [
                                    'table' => $tableName,
                                    'index_key' => $indexKey,
                                    'columns' => $cols,
                                    'existing' => $indexesMap[$indexKey],
                                ]);
                            }
                        }
                    }

                    // Handle the indexes to drop
                    $indexesToDropProperty = $managerReflection->getProperty('indexesToDrop');
                    $indexesToDropProperty->setAccessible(true);
                    $indexesToDrop = $indexesToDropProperty->getValue($manager);

                    foreach ($indexesToDrop as $indexToDrop) {
                        // Remove all indexes that contain this column
                        foreach (array_keys($indexesMap) as $key) {
                            if (stripos($key, $indexToDrop) !== false) {
                                unset($indexesMap[$key]);
                            }
                        }
                    }

                    // Extract the foreign keys
                    $foreignKeysProperty = $managerReflection->getProperty('foreignKeys');
                    $foreignKeysProperty->setAccessible(true);
                    $foreignKeys = $foreignKeysProperty->getValue($manager);

                    foreach ($foreignKeys as $column => $fk) {
                        $fkKey = $column . '_' . $fk['foreignTable'] . '_' . $fk['foreignColumn'];
                        $foreignKeysMap[$fkKey] = [
                            'column' => $column,
                            'referenced_table' => $fk['foreignTable'],
                            'referenced_column' => $fk['foreignColumn'],
                        ];
                    }

                    // Handle the foreign keys to drop
                    $foreignKeysToDropProperty = $managerReflection->getProperty('foreignKeysToDrop');
                    $foreignKeysToDropProperty->setAccessible(true);
                    $foreignKeysToDrop = $foreignKeysToDropProperty->getValue($manager);

                    foreach ($foreignKeysToDrop as $fkToDrop) {
                        // Remove all FKs that use this column
                        foreach (array_keys($foreignKeysMap) as $key) {
                            if (strpos($key, $fkToDrop . '_') === 0) {
                                unset($foreignKeysMap[$key]);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ignore the errors during the analysis of migrations
                continue;
            }
        }

        // Convert the maps to arrays
        $structure['columns'] = array_keys($columnsMap);
        $structure['indexes'] = array_values($indexesMap);
        $structure['foreign_keys'] = array_values($foreignKeysMap);

        DatabaseRepairLogger::info(__("Expected final structure for {$tableName}", 'mailerpress'), [
            'columns_count' => count($structure['columns']),
            'indexes_count' => count($structure['indexes']),
            'foreign_keys_count' => count($structure['foreign_keys']),
            'indexes' => $structure['indexes'],
        ]);

        return $structure;
    }

    /**
     * Get the list of all expected tables from the migrations
     */
    protected function getExpectedTables(): array
    {
        // Use the Tables constants to get all expected tables
        $reflection = new \ReflectionClass(Tables::class);
        $constants = $reflection->getConstants();

        // Tables obsolete to exclude from the diagnosis
        $obsoleteTables = [
            'mailerpress_provider_accounts',
            'mailerpress_provider_contacts',
            'mailerpress_provider_lists',
        ];

        $tables = [];
        foreach ($constants as $name => $value) {
            // Filter only the table constants (which start with MAILERPRESS_)
            if (strpos($name, 'MAILERPRESS_') === 0 || strpos($name, 'CONTACT_') === 0) {
                // Exclude the obsolete tables
                if (!in_array($value, $obsoleteTables, true)) {
                    $tables[] = $value;
                }
            }
        }

        // Add the migrations table
        $tables[] = 'mailerpress_migrations';

        return array_unique($tables);
    }

    /**
     * Check the status of the migrations
     */
    protected function checkMigrationStatus(): array
    {
        global $wpdb;

        $trackerTable = $this->tracker->getTableName();
        $trackerExists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($trackerTable))
        ) === $trackerTable;

        if (!$trackerExists) {
            return [
                'tracker_exists' => false,
                'total_migrations' => 0,
                'completed' => 0,
                'failed' => 0,
                'pending' => 0,
                'running' => 0,
            ];
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$trackerTable}");
        $completed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$trackerTable} WHERE status = 'completed'");
        $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$trackerTable} WHERE status = 'failed'");
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$trackerTable} WHERE status = 'pending'");
        $running = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$trackerTable} WHERE status = 'running'");

        return [
            'tracker_exists' => true,
            'total_migrations' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'pending' => $pending,
            'running' => $running,
        ];
    }

    /**
     * Repair the database by executing the missing migrations
     */
    public function repair(): array
    {
        DatabaseRepairLogger::init();
        DatabaseRepairLogger::info(__('Starting database repair', 'mailerpress'));

        $results = [
            'success' => false,
            'message' => '',
            'actions_taken' => [],
            'errors' => [],
            'warnings' => [],
            'fixed_issues' => [],
        ];

        try {
            global $wpdb;

            // 0. First, check if the migration table exists - if not, create it from scratch
            $migrationTableName = $wpdb->prefix . 'mailerpress_migrations';
            $migrationTableExists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($migrationTableName))
            ) === $migrationTableName;

            if (!$migrationTableExists) {
                DatabaseRepairLogger::info(__('Migration table does not exist - creating from scratch', 'mailerpress'));

                // Create the migration table manually using raw SQL
                // Note: Use prefix key length (191) for indexed VARCHAR columns to support utf8mb4
                // This avoids "Specified key was too long" error on MySQL with utf8mb4
                $charsetCollate = $wpdb->get_charset_collate();
                $sql = "CREATE TABLE IF NOT EXISTS {$migrationTableName} (
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
                ) {$charsetCollate};";

                $wpdb->query($sql);

                if ($wpdb->last_error) {
                    DatabaseRepairLogger::error(__('Failed to create migration table', 'mailerpress'), [
                        'error' => $wpdb->last_error,
                        'sql' => $sql,
                    ]);
                    throw new \RuntimeException(__('Failed to create migration table: ', 'mailerpress') . $wpdb->last_error);
                }

                $results['actions_taken'][] = __('Migration tracking table created from scratch', 'mailerpress');
                DatabaseRepairLogger::info(__('Migration table created successfully', 'mailerpress'));
            }

            // 1. Release the migrations lock if necessary
            // Create the manager in force mode to force execution
            $manager = new Manager($this->migrationPath, [], true); // true = force mode
            $status = $manager->getStatus();

            DatabaseRepairLogger::info(__('Initial migration status', 'mailerpress'), ['status' => $status]);

            if ($status['is_locked']) {
                $manager->forceReleaseLock();
                $results['actions_taken'][] = __('Migration lock released', 'mailerpress');
                DatabaseRepairLogger::info(__('Migration lock released', 'mailerpress'));
            }

            // 2. Reset the failed migrations
            if ($status['failed_count'] > 0) {
                $resetCount = $manager->resetFailed();
                $results['actions_taken'][] = sprintf(
                    __('%d migration(s) failed reset(s)', 'mailerpress'),
                    $resetCount
                );
                DatabaseRepairLogger::info("{$resetCount} migration(s) failed reset(s)");
            }

            // 3. Reset the running migrations (in case of crash)
            if ($status['running_count'] > 0) {
                $runningCount = $this->tracker->resetAllRunningMigrations();
                $results['actions_taken'][] = sprintf(
                    __('%d migration(s) in progress reset(s)', 'mailerpress'),
                    $runningCount
                );
                DatabaseRepairLogger::info("{$runningCount} migration(s) in progress reset(s)");
            }

            // 4. Execute the migrations in force mode
            DatabaseRepairLogger::info(__('Execution of migrations in force mode', 'mailerpress'));
            $manager->runForce();
            $results['actions_taken'][] = __('Migrations executed in force mode', 'mailerpress');
            DatabaseRepairLogger::info(__('Migrations executed in force mode', 'mailerpress'));

            // 5. Repair the structural issues detected (missing indexes, columns, foreign keys)
            DatabaseRepairLogger::info(__('Check and repair the structural issues', 'mailerpress'));

            // Get the current diagnostic to see the problems
            $currentDiagnostic = $this->diagnose();
            DatabaseRepairLogger::info(__('Diagnostic before repair', 'mailerpress'), [
                'total_issues' => $currentDiagnostic['summary']['total_issues'],
                'missing_tables' => $currentDiagnostic['summary']['missing_tables'],
                'issues_details' => array_map(function ($issue) {
                    return [
                        'type' => $issue['type'],
                        'table' => $issue['table'],
                        'issue' => $issue['issue'],
                    ];
                }, array_slice($currentDiagnostic['issues'], 0, 10)), // First 10 problems
            ]);

            // Use the diagnostic data to repair
            $structuralRepairs = $this->repairStructuralIssues($currentDiagnostic);
            DatabaseRepairLogger::info(__('Results of structural repair', 'mailerpress'), [
                'fixed_count' => count($structuralRepairs['fixed']),
                'warnings_count' => count($structuralRepairs['warnings']),
            ]);

            $results['fixed_issues'] = $structuralRepairs['fixed'];
            $results['warnings'] = array_merge($results['warnings'], $structuralRepairs['warnings']);

            if (!empty($structuralRepairs['fixed'])) {
                DatabaseRepairLogger::info(__('Structural issues repaired', 'mailerpress'), [
                    'count' => count($structuralRepairs['fixed']),
                    'details' => $structuralRepairs['fixed']
                ]);
            } else {
                DatabaseRepairLogger::info(__('No structural issues to repair', 'mailerpress'), [
                    'total_issues_detected' => $currentDiagnostic['summary']['total_issues'],
                ]);
            }

            $results['success'] = true;
            $results['message'] = __('Database successfully repaired', 'mailerpress');
            DatabaseRepairLogger::info(__('Database repair completed successfully', 'mailerpress'));
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $errorTrace = $e->getTraceAsString();

            DatabaseRepairLogger::exception($e, [
                'repair_step' => 'general',
            ]);

            $results['errors'][] = $errorMessage;
            $results['errors'][] = __('Trace:', 'mailerpress') . "\n" . $errorTrace;

            $results['message'] = sprintf(
                __('Error during database repair: %s', 'mailerpress'),
                $errorMessage
            );
        }

        return $results;
    }

    /**
     * Repair the structural issues detected (missing columns, indexes, foreign keys)
     */
    protected function repairStructuralIssues(?array $diagnostic = null): array
    {
        global $wpdb;

        $fixed = [];
        $warnings = [];

        DatabaseRepairLogger::info(__('Starting structural issues repair', 'mailerpress'));

        // Get the current diagnostic if not provided
        if ($diagnostic === null) {
            $diagnostic = $this->diagnose();
        }

        DatabaseRepairLogger::info(__('Diagnostic obtained', 'mailerpress'), [
            'tables_count' => count($diagnostic['tables']),
            'issues_count' => count($diagnostic['issues']),
            'issues_by_type' => [
                'missing_column' => count(array_filter($diagnostic['issues'], fn($i) => $i['issue'] === 'missing_column')),
                'missing_index' => count(array_filter($diagnostic['issues'], fn($i) => $i['issue'] === 'missing_index')),
                'missing_foreign_key' => count(array_filter($diagnostic['issues'], fn($i) => $i['issue'] === 'missing_foreign_key')),
                'extra_foreign_key' => count(array_filter($diagnostic['issues'], fn($i) => $i['issue'] === 'extra_foreign_key')),
            ],
        ]);

        // Group the problems by table to facilitate the treatment
        $issuesByTable = [];
        foreach ($diagnostic['issues'] as $issue) {
            if (!isset($issuesByTable[$issue['table']])) {
                $issuesByTable[$issue['table']] = [];
            }
            $issuesByTable[$issue['table']][] = $issue;
        }

        DatabaseRepairLogger::info(__('Problems grouped by table', 'mailerpress'), [
            'tables_with_issues' => array_keys($issuesByTable),
            'issues_per_table' => array_map('count', $issuesByTable),
        ]);

        // Sort issues within each table: drop extra FKs first (before structural changes), then add columns, drop extras, then indexes, then foreign keys
        $issueOrder = ['extra_foreign_key' => 0, 'missing_column' => 1, 'extra_column' => 2, 'missing_index' => 3, 'missing_foreign_key' => 4];
        foreach ($issuesByTable as &$tableIssues) {
            usort($tableIssues, static function ($a, $b) use ($issueOrder) {
                return ($issueOrder[$a['issue']] ?? 99) <=> ($issueOrder[$b['issue']] ?? 99);
            });
        }
        unset($tableIssues);

        // Treat the problems from the issues directly
        foreach ($issuesByTable as $tableName => $tableIssues) {
            $tableInfo = $diagnostic['tables'][$tableName] ?? null;
            if (!$tableInfo || !$tableInfo['exists']) {
                DatabaseRepairLogger::info(__("Table {$tableName} does not exist, will be created by migrations", 'mailerpress'));
                continue;
            }

            $fullTableName = Tables::get($tableName);
            DatabaseRepairLogger::info(__("Processing issues for {$tableName}", 'mailerpress'), [
                'issues_count' => count($tableIssues),
            ]);

            foreach ($tableIssues as $issue) {
                try {
                    if ($issue['issue'] === 'extra_foreign_key' && isset($issue['foreign_key'])) {
                        // Drop a foreign key that should have been removed by a migration
                        $fk = $issue['foreign_key'];
                        $constraintName = $fk['constraint_name'];

                        DatabaseRepairLogger::info(__("Dropping extra foreign key: {$constraintName}", 'mailerpress'), [
                            'table' => $tableName,
                            'column' => $fk['column'],
                            'referenced_table' => $fk['referenced_table'],
                            'referenced_column' => $fk['referenced_column'],
                        ]);

                        $sql = "ALTER TABLE {$fullTableName} DROP FOREIGN KEY `{$constraintName}`";

                        $wpdb->last_error = '';
                        $result = $wpdb->query($sql);
                        $lastError = $wpdb->last_error;

                        if ($result !== false && empty($lastError)) {
                            $fixed[] = [
                                'type' => 'drop_foreign_key',
                                'table' => $tableName,
                                'constraint_name' => $constraintName,
                                'column' => $fk['column'],
                                'referenced_table' => $fk['referenced_table'],
                            ];
                            DatabaseRepairLogger::info(__("Foreign key dropped successfully: {$constraintName}", 'mailerpress'));
                        } else {
                            DatabaseRepairLogger::error(__("Failed to drop foreign key {$constraintName}", 'mailerpress'), [
                                'table' => $tableName,
                                'error' => $lastError,
                                'sql' => $sql,
                            ]);
                            $warnings[] = sprintf(
                                __('Failed to drop extra foreign key %s: %s', 'mailerpress'),
                                $constraintName,
                                $lastError ?: __('Unknown error', 'mailerpress')
                            );
                        }
                    } elseif ($issue['issue'] === 'missing_index' && isset($issue['index'])) {
                        // Repair a missing index
                        $index = $issue['index'];
                        $columns = is_array($index['columns']) ? $index['columns'] : [$index['columns']];
                        $columnsStr = implode('`, `', $columns);

                        $indexType = $index['type'] === 'UNIQUE' ? 'UNIQUE INDEX' : 'INDEX';
                        $indexName = 'idx_' . $tableName . '_' . implode('_', $columns);

                        // Check if all columns exist
                        $currentCols = $wpdb->get_col("SHOW COLUMNS FROM {$fullTableName}", 0);
                        $missingCols = [];
                        foreach ($columns as $col) {
                            if (!in_array($col, $currentCols, true)) {
                                $missingCols[] = $col;
                            }
                        }

                        if (!empty($missingCols)) {
                            $missingColsStr = implode(', ', $missingCols);
                            DatabaseRepairLogger::warning(__("Unable to create index {$indexName}: missing columns", 'mailerpress'), [
                                'table' => $tableName,
                                'index' => $indexName,
                                'expected_columns' => $columns,
                                'missing_columns' => $missingCols,
                                'existing_columns' => $currentCols,
                            ]);
                            $warnings[] = sprintf(
                                __('Missing index: %s (missing columns: %s)', 'mailerpress'),
                                $indexName,
                                $missingColsStr
                            );
                            continue;
                        }

                        // Check if the index already exists (with a potentially different name)
                        $existingIndexes = $wpdb->get_results("SHOW INDEX FROM {$fullTableName}", ARRAY_A);
                        $indexAlreadyExists = false;
                        $normalizedExpectedCols = array_map('strtolower', $columns);

                        foreach ($existingIndexes as $existingIndex) {
                            if ($existingIndex['Key_name'] === 'PRIMARY') {
                                continue;
                            }

                            // Check if an index with the same columns already exists
                            $existingCols = [];
                            foreach ($existingIndexes as $idx) {
                                if ($idx['Key_name'] === $existingIndex['Key_name']) {
                                    $existingCols[] = strtolower($idx['Column_name']);
                                }
                            }

                            if (
                                count($existingCols) === count($normalizedExpectedCols) &&
                                empty(array_diff($normalizedExpectedCols, $existingCols))
                            ) {
                                $indexAlreadyExists = true;
                                DatabaseRepairLogger::info(__("Index already exists with a different name: {$existingIndex['Key_name']}", 'mailerpress'), [
                                    'table' => $tableName,
                                    'existing_name' => $existingIndex['Key_name'],
                                    'expected_name' => $indexName,
                                    'columns' => $columns,
                                ]);
                                break;
                            }
                        }

                        if ($indexAlreadyExists) {
                            DatabaseRepairLogger::info(__("Index already present (different name), ignored: {$indexName}", 'mailerpress'));
                            continue;
                        }

                        // Create the index
                        $sql = "ALTER TABLE {$fullTableName} ADD {$indexType} `{$indexName}` (`{$columnsStr}`)";
                        DatabaseRepairLogger::info(__("Attempt to create index: {$indexName}", 'mailerpress'), [
                            'table' => $tableName,
                            'sql' => $sql,
                            'columns' => $columns,
                        ]);

                        // Reset the previous errors
                        $wpdb->last_error = '';
                        $result = $wpdb->query($sql);
                        $lastError = $wpdb->last_error;
                        $lastQuery = $wpdb->last_query;

                        if ($result !== false && empty($lastError)) {
                            // Check if the index has been created
                            $verifyIndexes = $wpdb->get_results("SHOW INDEX FROM {$fullTableName} WHERE Key_name = '{$indexName}'", ARRAY_A);
                            if (!empty($verifyIndexes)) {
                                $fixed[] = [
                                    'type' => 'index',
                                    'table' => $tableName,
                                    'name' => $indexName,
                                    'columns' => $columns,
                                ];
                                DatabaseRepairLogger::info(__("Index created successfully: {$indexName}", 'mailerpress'), [
                                    'table' => $tableName,
                                    'index' => $indexName,
                                ]);
                            } else {
                                DatabaseRepairLogger::warning(__("Index created but not found during verification: {$indexName}", 'mailerpress'), [
                                    'table' => $tableName,
                                    'sql' => $sql,
                                ]);
                                $warnings[] = sprintf(
                                    __('Index created but not verified: %s', 'mailerpress'),
                                    $indexName
                                );
                            }
                        } else {
                            // Check if the error is due to an index already existing
                            $errorLower = strtolower($lastError);
                            if (
                                strpos($errorLower, 'duplicate key name') !== false ||
                                strpos($errorLower, 'already exists') !== false ||
                                strpos($errorLower, 'duplicate') !== false
                            ) {
                                DatabaseRepairLogger::info(__("Index already exists (detected by SQL error): {$indexName}", 'mailerpress'), [
                                    'table' => $tableName,
                                    'error' => $lastError,
                                ]);
                                // Do not add a warning, the index already exists
                            } else {
                                DatabaseRepairLogger::error(__("Error creating index {$indexName}", 'mailerpress'), [
                                    'table' => $tableName,
                                    'error' => $lastError,
                                    'sql' => $sql,
                                    'result' => $result,
                                    'last_query' => $lastQuery,
                                ]);
                                $warnings[] = sprintf(
                                    __('Error creating index %s: %s', 'mailerpress'),
                                    $indexName,
                                    $lastError ?: __('Unknown error', 'mailerpress')
                                );
                            }
                        }
                    } elseif ($issue['issue'] === 'missing_foreign_key' && isset($issue['foreign_key'])) {
                        // Repair a missing foreign key
                        $fk = $issue['foreign_key'];
                        $fkName = "fk_{$tableName}_{$fk['column']}";
                        $referencedTable = Tables::get($fk['referenced_table']);

                        // Check if the referenced table exists
                        $refTableExists = $wpdb->get_var(
                            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($referencedTable))
                        ) === $referencedTable;

                        if (!$refTableExists) {
                            DatabaseRepairLogger::warning(__("Unable to create foreign key {$fkName}: referenced table does not exist", 'mailerpress'), [
                                'table' => $tableName,
                                'fk' => $fkName,
                                'referenced_table' => $fk['referenced_table'],
                            ]);
                            $warnings[] = sprintf(
                                __('Missing foreign key: %s (referenced table does not exist)', 'mailerpress'),
                                $fkName
                            );
                            continue;
                        }

                        // Step 1: Clean up orphan records before creating FK
                        // This is the most common reason FK creation fails
                        $orphanCount = $this->cleanOrphanRecords(
                            $fullTableName,
                            $fk['column'],
                            $referencedTable,
                            $fk['referenced_column']
                        );

                        if ($orphanCount > 0) {
                            DatabaseRepairLogger::info(__("Cleaned {$orphanCount} orphan records before creating FK {$fkName}", 'mailerpress'), [
                                'table' => $tableName,
                                'orphans_cleaned' => $orphanCount,
                            ]);
                            $fixed[] = [
                                'type' => 'orphan_cleanup',
                                'table' => $tableName,
                                'column' => $fk['column'],
                                'count' => $orphanCount,
                            ];
                        }

                        // Step 2: Ensure index exists on FK column (required for FK creation)
                        $indexExists = $this->columnHasIndex($fullTableName, $fk['column']);
                        if (!$indexExists) {
                            $indexName = "idx_{$tableName}_{$fk['column']}";
                            $indexSql = "ALTER TABLE {$fullTableName} ADD INDEX `{$indexName}` (`{$fk['column']}`)";
                            $wpdb->query($indexSql);
                            if (empty($wpdb->last_error)) {
                                DatabaseRepairLogger::info(__("Created index for FK column: {$indexName}", 'mailerpress'));
                            }
                        }

                        // Step 3: Check if FK already exists (by different name)
                        $existingFK = $this->foreignKeyExists($fullTableName, $fk['column'], $referencedTable, $fk['referenced_column']);
                        if ($existingFK) {
                            DatabaseRepairLogger::info(__("Foreign key already exists with different name: {$existingFK}", 'mailerpress'), [
                                'table' => $tableName,
                                'existing_name' => $existingFK,
                            ]);
                            continue;
                        }

                        // Step 4: Check if tables are using InnoDB (required for FK)
                        $tableEngine = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                                $fullTableName
                            )
                        );
                        $refTableEngine = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                                $referencedTable
                            )
                        );

                        if (strtoupper($tableEngine) !== 'INNODB' || strtoupper($refTableEngine) !== 'INNODB') {
                            DatabaseRepairLogger::warning(__("Cannot create FK {$fkName}: tables must use InnoDB engine", 'mailerpress'), [
                                'table' => $tableName,
                                'table_engine' => $tableEngine,
                                'ref_table' => $referencedTable,
                                'ref_table_engine' => $refTableEngine,
                            ]);

                            // Try to convert tables to InnoDB
                            if (strtoupper($tableEngine) !== 'INNODB') {
                                $wpdb->query("ALTER TABLE {$fullTableName} ENGINE=InnoDB");
                                DatabaseRepairLogger::info(__("Converted {$fullTableName} to InnoDB", 'mailerpress'));
                            }
                            if (strtoupper($refTableEngine) !== 'INNODB') {
                                $wpdb->query("ALTER TABLE {$referencedTable} ENGINE=InnoDB");
                                DatabaseRepairLogger::info(__("Converted {$referencedTable} to InnoDB", 'mailerpress'));
                            }
                        }

                        // Step 5: Create the foreign key
                        $sql = "ALTER TABLE {$fullTableName} 
                                ADD CONSTRAINT `{$fkName}` 
                                FOREIGN KEY (`{$fk['column']}`) 
                                REFERENCES `{$referencedTable}` (`{$fk['referenced_column']}`) 
                                ON DELETE CASCADE ON UPDATE CASCADE";

                        DatabaseRepairLogger::info(__("Attempt to create foreign key: {$fkName}", 'mailerpress'), [
                            'table' => $tableName,
                            'sql' => $sql,
                        ]);

                        // Disable FK checks temporarily to allow creation
                        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

                        $wpdb->last_error = '';
                        $wpdb->suppress_errors(false);
                        $result = $wpdb->query($sql);
                        $lastError = $wpdb->last_error;

                        // Re-enable FK checks
                        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

                        DatabaseRepairLogger::info(__("Query result for {$fkName}", 'mailerpress'), [
                            'result' => $result,
                            'last_error' => $lastError,
                            'affected_rows' => $wpdb->rows_affected,
                        ]);

                        // Step 6: VERIFY the FK was actually created
                        $fkVerify = $this->foreignKeyExists($fullTableName, $fk['column'], $referencedTable, $fk['referenced_column']);

                        if ($fkVerify) {
                            $fixed[] = [
                                'type' => 'foreign_key',
                                'table' => $tableName,
                                'name' => $fkVerify,
                                'column' => $fk['column'],
                                'referenced_table' => $fk['referenced_table'],
                                'referenced_column' => $fk['referenced_column'],
                            ];
                            DatabaseRepairLogger::info(__("Foreign key verified and created successfully: {$fkVerify}", 'mailerpress'));
                        } else {
                            // FK was not created - find out why
                            $errorMsg = $lastError ?: __('Unknown error - FK not created', 'mailerpress');

                            // Check for common issues
                            if (empty($lastError)) {
                                // No error but FK doesn't exist - check for data type mismatch
                                $colType = $wpdb->get_var("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$fullTableName}' AND COLUMN_NAME = '{$fk['column']}'");
                                $refColType = $wpdb->get_var("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$referencedTable}' AND COLUMN_NAME = '{$fk['referenced_column']}'");

                                if ($colType !== $refColType) {
                                    $errorMsg = sprintf(
                                        __('Column type mismatch: %s (%s) vs %s (%s)', 'mailerpress'),
                                        $fk['column'],
                                        $colType,
                                        $fk['referenced_column'],
                                        $refColType
                                    );
                                }
                            }

                            DatabaseRepairLogger::error(__("Failed to create foreign key {$fkName}", 'mailerpress'), [
                                'table' => $tableName,
                                'error' => $errorMsg,
                                'sql' => $sql,
                                'result' => $result,
                                'last_error' => $lastError,
                            ]);
                            $warnings[] = sprintf(
                                __('Error creating foreign key %s: %s', 'mailerpress'),
                                $fkName,
                                $errorMsg
                            );
                        }
                    } elseif ($issue['issue'] === 'missing_column' && isset($issue['column'])) {
                        $columnName = $issue['column'];

                        // Check column doesn't already exist
                        $currentCols = $wpdb->get_col("SHOW COLUMNS FROM {$fullTableName}", 0);
                        if (in_array($columnName, $currentCols, true)) {
                            DatabaseRepairLogger::info(__("Column {$columnName} already exists in {$tableName}, skipping", 'mailerpress'));
                            continue;
                        }

                        // Look up the column definition from all migrations
                        $columnDef = $this->getColumnDefinitionFromMigrations($tableName, $columnName);

                        if ($columnDef === null) {
                            DatabaseRepairLogger::warning(__("Cannot find column definition for {$tableName}.{$columnName} in any migration", 'mailerpress'), [
                                'table' => $tableName,
                                'column' => $columnName,
                            ]);
                            $warnings[] = sprintf(
                                __('Missing column: %s.%s (definition not found in migrations)', 'mailerpress'),
                                $tableName,
                                $columnName
                            );
                            continue;
                        }

                        $isAutoIncrement = stripos($columnDef, 'AUTO_INCREMENT') !== false;

                        if ($isAutoIncrement) {
                            // AUTO_INCREMENT column (typically 'id') — needs special PK handling
                            $existingPK = $wpdb->get_var(
                                $wpdb->prepare(
                                    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                                     WHERE TABLE_SCHEMA = DATABASE()
                                     AND TABLE_NAME = %s
                                     AND CONSTRAINT_TYPE = 'PRIMARY KEY'",
                                    $fullTableName
                                )
                            );

                            if ($existingPK > 0) {
                                // Drop child FKs that reference this table's PK first
                                $childFKs = $wpdb->get_results(
                                    "SELECT kcu.TABLE_NAME AS child_table, kcu.CONSTRAINT_NAME AS fk_name
                                     FROM information_schema.KEY_COLUMN_USAGE kcu
                                     JOIN information_schema.TABLE_CONSTRAINTS tc
                                       ON tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                                      AND tc.TABLE_NAME = kcu.TABLE_NAME
                                      AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                                     WHERE kcu.TABLE_SCHEMA = DATABASE()
                                       AND kcu.REFERENCED_TABLE_NAME = '{$fullTableName}'
                                       AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'",
                                    ARRAY_A
                                );

                                if (!empty($childFKs)) {
                                    DatabaseRepairLogger::info(__("Dropping child foreign keys that reference {$tableName} before PK change", 'mailerpress'), [
                                        'child_fks' => $childFKs,
                                    ]);
                                    foreach ($childFKs as $childFK) {
                                        $dropSql = "ALTER TABLE `{$childFK['child_table']}` DROP FOREIGN KEY `{$childFK['fk_name']}`";
                                        $wpdb->query($dropSql);
                                        DatabaseRepairLogger::info(__("Dropped FK {$childFK['fk_name']} from {$childFK['child_table']}", 'mailerpress'));
                                    }
                                }

                                // Get existing PK columns to remove AUTO_INCREMENT before dropping PK
                                $pkColumns = $wpdb->get_results(
                                    "SELECT c.COLUMN_NAME, c.COLUMN_TYPE, c.EXTRA
                                     FROM information_schema.KEY_COLUMN_USAGE kcu
                                     JOIN information_schema.COLUMNS c
                                       ON c.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                                      AND c.TABLE_NAME = kcu.TABLE_NAME
                                      AND c.COLUMN_NAME = kcu.COLUMN_NAME
                                     WHERE kcu.TABLE_SCHEMA = DATABASE()
                                       AND kcu.TABLE_NAME = '{$fullTableName}'
                                       AND kcu.CONSTRAINT_NAME = 'PRIMARY'",
                                    ARRAY_A
                                );

                                $alterParts = [];
                                foreach ($pkColumns as $pkCol) {
                                    if (stripos($pkCol['EXTRA'], 'auto_increment') !== false) {
                                        $alterParts[] = "MODIFY COLUMN `{$pkCol['COLUMN_NAME']}` {$pkCol['COLUMN_TYPE']} NOT NULL";
                                    }
                                }
                                $alterParts[] = 'DROP PRIMARY KEY';
                                $alterParts[] = "ADD COLUMN `{$columnName}` {$columnDef} FIRST";
                                $alterParts[] = "ADD PRIMARY KEY (`{$columnName}`)";

                                $sql = "ALTER TABLE {$fullTableName} " . implode(', ', $alterParts);
                            } else {
                                $sql = "ALTER TABLE {$fullTableName} ADD COLUMN `{$columnName}` {$columnDef} FIRST, ADD PRIMARY KEY (`{$columnName}`)";
                            }
                        } else {
                            // Regular column — simple ALTER TABLE ADD COLUMN
                            $sql = "ALTER TABLE {$fullTableName} ADD COLUMN `{$columnName}` {$columnDef}";
                        }

                        DatabaseRepairLogger::info(__("Attempting to add column {$columnName} to {$tableName}", 'mailerpress'), [
                            'table' => $tableName,
                            'column' => $columnName,
                            'sql' => $sql,
                        ]);

                        $wpdb->last_error = '';
                        $result = $wpdb->query($sql);
                        $lastError = $wpdb->last_error;

                        if ($result !== false && empty($lastError)) {
                            $newCols = $wpdb->get_col("SHOW COLUMNS FROM {$fullTableName}", 0);
                            if (in_array($columnName, $newCols, true)) {
                                $fixed[] = [
                                    'type' => 'column',
                                    'table' => $tableName,
                                    'column' => $columnName,
                                ];
                                DatabaseRepairLogger::info(__("Column {$columnName} added successfully to {$tableName}", 'mailerpress'));
                            } else {
                                $warnings[] = sprintf(
                                    __('Column %s added to %s but not found during verification', 'mailerpress'),
                                    $columnName,
                                    $tableName
                                );
                            }
                        } else {
                            DatabaseRepairLogger::error(__("Failed to add column {$columnName} to {$tableName}", 'mailerpress'), [
                                'table' => $tableName,
                                'column' => $columnName,
                                'error' => $lastError,
                                'sql' => $sql,
                            ]);
                            $warnings[] = sprintf(
                                __('Error adding column %s to %s: %s', 'mailerpress'),
                                $columnName,
                                $tableName,
                                $lastError ?: __('Unknown error', 'mailerpress')
                            );
                        }
                    } elseif ($issue['issue'] === 'extra_column' && isset($issue['column'])) {
                        // Drop a column that exists in DB but not in any migration
                        $columnName = $issue['column'];

                        // Safety: never drop 'id' column
                        if ($columnName === 'id') {
                            DatabaseRepairLogger::info(__("Skipping drop of 'id' column in {$tableName} (safety check)", 'mailerpress'));
                            continue;
                        }

                        // Check column still exists
                        $currentCols = $wpdb->get_col("SHOW COLUMNS FROM {$fullTableName}", 0);
                        if (!in_array($columnName, $currentCols, true)) {
                            DatabaseRepairLogger::info(__("Extra column {$columnName} already gone from {$tableName}, skipping", 'mailerpress'));
                            continue;
                        }

                        // Step 1: Drop any foreign keys ON this column (from this table)
                        $fksOnColumn = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT CONSTRAINT_NAME
                                 FROM information_schema.KEY_COLUMN_USAGE
                                 WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = %s
                                 AND COLUMN_NAME = %s
                                 AND REFERENCED_TABLE_NAME IS NOT NULL",
                                $fullTableName,
                                $columnName
                            ),
                            ARRAY_A
                        );

                        foreach ($fksOnColumn as $fk) {
                            $dropFkSql = "ALTER TABLE {$fullTableName} DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`";
                            $wpdb->query($dropFkSql);
                            DatabaseRepairLogger::info(__("Dropped FK {$fk['CONSTRAINT_NAME']} on column {$columnName} before dropping column", 'mailerpress'));
                        }

                        // Step 2: Drop any foreign keys from OTHER tables that reference this column
                        $childFks = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT kcu.TABLE_NAME AS child_table, kcu.CONSTRAINT_NAME AS fk_name
                                 FROM information_schema.KEY_COLUMN_USAGE kcu
                                 JOIN information_schema.TABLE_CONSTRAINTS tc
                                   ON tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                                  AND tc.TABLE_NAME = kcu.TABLE_NAME
                                  AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                                 WHERE kcu.TABLE_SCHEMA = DATABASE()
                                   AND kcu.REFERENCED_TABLE_NAME = %s
                                   AND kcu.REFERENCED_COLUMN_NAME = %s
                                   AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'",
                                $fullTableName,
                                $columnName
                            ),
                            ARRAY_A
                        );

                        foreach ($childFks as $childFk) {
                            $dropFkSql = "ALTER TABLE `{$childFk['child_table']}` DROP FOREIGN KEY `{$childFk['fk_name']}`";
                            $wpdb->query($dropFkSql);
                            DatabaseRepairLogger::info(__("Dropped child FK {$childFk['fk_name']} from {$childFk['child_table']} referencing {$columnName}", 'mailerpress'));
                        }

                        // Step 3: Drop any indexes on this column (except PRIMARY)
                        $indexesOnColumn = $wpdb->get_results(
                            "SHOW INDEX FROM {$fullTableName} WHERE Column_name = '{$columnName}'",
                            ARRAY_A
                        );
                        $droppedIndexes = [];
                        foreach ($indexesOnColumn as $idx) {
                            if ($idx['Key_name'] !== 'PRIMARY' && !in_array($idx['Key_name'], $droppedIndexes, true)) {
                                $dropIdxSql = "ALTER TABLE {$fullTableName} DROP INDEX `{$idx['Key_name']}`";
                                $wpdb->query($dropIdxSql);
                                $droppedIndexes[] = $idx['Key_name'];
                                DatabaseRepairLogger::info(__("Dropped index {$idx['Key_name']} on column {$columnName} before dropping column", 'mailerpress'));
                            }
                        }

                        // Step 4: Drop the column
                        $sql = "ALTER TABLE {$fullTableName} DROP COLUMN `{$columnName}`";
                        DatabaseRepairLogger::info(__("Attempting to drop extra column {$columnName} from {$tableName}", 'mailerpress'), [
                            'table' => $tableName,
                            'column' => $columnName,
                            'sql' => $sql,
                        ]);

                        $wpdb->last_error = '';
                        $result = $wpdb->query($sql);
                        $lastError = $wpdb->last_error;

                        if ($result !== false && empty($lastError)) {
                            $newCols = $wpdb->get_col("SHOW COLUMNS FROM {$fullTableName}", 0);
                            if (!in_array($columnName, $newCols, true)) {
                                $fixed[] = [
                                    'type' => 'dropped_column',
                                    'table' => $tableName,
                                    'column' => $columnName,
                                ];
                                DatabaseRepairLogger::info(__("Extra column {$columnName} dropped successfully from {$tableName}", 'mailerpress'));
                            } else {
                                $warnings[] = sprintf(
                                    __('Column %s drop executed on %s but column still exists', 'mailerpress'),
                                    $columnName,
                                    $tableName
                                );
                            }
                        } else {
                            DatabaseRepairLogger::error(__("Failed to drop extra column {$columnName} from {$tableName}", 'mailerpress'), [
                                'table' => $tableName,
                                'column' => $columnName,
                                'error' => $lastError,
                                'sql' => $sql,
                            ]);
                            $warnings[] = sprintf(
                                __('Error dropping column %s from %s: %s', 'mailerpress'),
                                $columnName,
                                $tableName,
                                $lastError ?: __('Unknown error', 'mailerpress')
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    DatabaseRepairLogger::exception($e, [
                        'table' => $tableName,
                        'issue' => $issue,
                    ]);
                    $warnings[] = sprintf(
                        __('Error during repair: %s', 'mailerpress'),
                        $e->getMessage()
                    );
                }
            }
        }

        DatabaseRepairLogger::info(__('Summary of structural repair', 'mailerpress'), [
            'total_fixed' => count($fixed),
            'total_warnings' => count($warnings),
            'fixed_details' => $fixed,
        ]);

        return [
            'fixed' => $fixed,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get the SQL definition of a column by replaying all migrations.
     * Returns the full SQL type definition (e.g. "BIGINT(20) UNSIGNED NULL") or null if not found.
     */
    protected function getColumnDefinitionFromMigrations(string $tableName, string $columnName): ?string
    {
        global $wpdb;
        $fullTableName = Tables::get($tableName);

        $files = glob($this->migrationPath . '/*.php');
        if ($files === false) {
            return null;
        }

        sort($files);

        $lastDefinition = null;
        $wasDropped = false;

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            try {
                $schema = new SchemaBuilder();
                $migration = require $file;

                if (!is_callable($migration)) {
                    continue;
                }

                $migration($schema);

                $reflection = new \ReflectionClass($schema);
                $operationsProperty = $reflection->getProperty('operations');
                $operationsProperty->setAccessible(true);
                $operations = $operationsProperty->getValue($schema);

                foreach ($operations as $op) {
                    $manager = $op['manager'];
                    if ($manager->getTableName() !== $fullTableName) {
                        continue;
                    }

                    $managerReflection = new \ReflectionClass($manager);

                    // Check if this migration drops the column
                    $columnsToDropProperty = $managerReflection->getProperty('columnsToDrop');
                    $columnsToDropProperty->setAccessible(true);
                    $columnsToDrop = $columnsToDropProperty->getValue($manager);

                    if (in_array($columnName, $columnsToDrop, true)) {
                        $lastDefinition = null;
                        $wasDropped = true;
                    }

                    // Check columnBuilders (most columns)
                    $columnBuildersProperty = $managerReflection->getProperty('columnBuilders');
                    $columnBuildersProperty->setAccessible(true);
                    $columnBuilders = $columnBuildersProperty->getValue($manager);

                    foreach ($columnBuilders as $builder) {
                        if ($builder->getName() === $columnName) {
                            $lastDefinition = $builder->getSQL();
                            $wasDropped = false;
                        }
                    }

                    // Check columns (addColumn / id())
                    $columnsProperty = $managerReflection->getProperty('columns');
                    $columnsProperty->setAccessible(true);
                    $columns = $columnsProperty->getValue($manager);

                    if (isset($columns[$columnName])) {
                        $lastDefinition = $columns[$columnName];
                        $wasDropped = false;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $lastDefinition;
    }

    /**
     * Clean orphan records that reference non-existent parent records
     * This is necessary before creating foreign keys
     *
     * @return int Number of orphan records cleaned
     */
    protected function cleanOrphanRecords(
        string $tableName,
        string $column,
        string $referencedTable,
        string $referencedColumn
    ): int {
        global $wpdb;

        // First, check if there are orphan records
        $orphanCountSql = "SELECT COUNT(*) FROM {$tableName} t
                          WHERE t.`{$column}` IS NOT NULL 
                          AND t.`{$column}` != 0
                          AND NOT EXISTS (
                              SELECT 1 FROM {$referencedTable} r 
                              WHERE r.`{$referencedColumn}` = t.`{$column}`
                          )";

        $orphanCount = (int) $wpdb->get_var($orphanCountSql);

        if ($orphanCount === 0) {
            return 0;
        }

        DatabaseRepairLogger::info(__("Found {$orphanCount} orphan records", 'mailerpress'), [
            'table' => $tableName,
            'column' => $column,
            'referenced_table' => $referencedTable,
            'referenced_column' => $referencedColumn,
        ]);

        // Option 1: Set orphan values to NULL if column is nullable
        $columnInfo = $wpdb->get_row("SHOW COLUMNS FROM {$tableName} WHERE Field = '{$column}'");
        $isNullable = ($columnInfo && strtoupper($columnInfo->Null) === 'YES');

        if ($isNullable) {
            // Set to NULL
            $updateSql = "UPDATE {$tableName} t
                         SET t.`{$column}` = NULL
                         WHERE t.`{$column}` IS NOT NULL 
                         AND t.`{$column}` != 0
                         AND NOT EXISTS (
                             SELECT 1 FROM {$referencedTable} r 
                             WHERE r.`{$referencedColumn}` = t.`{$column}`
                         )";
            $wpdb->query($updateSql);

            DatabaseRepairLogger::info(__("Set {$orphanCount} orphan values to NULL", 'mailerpress'), [
                'table' => $tableName,
                'column' => $column,
            ]);
        } else {
            // Delete orphan records if column is NOT NULL
            $deleteSql = "DELETE t FROM {$tableName} t
                         WHERE t.`{$column}` IS NOT NULL 
                         AND t.`{$column}` != 0
                         AND NOT EXISTS (
                             SELECT 1 FROM {$referencedTable} r 
                             WHERE r.`{$referencedColumn}` = t.`{$column}`
                         )";
            $wpdb->query($deleteSql);

            DatabaseRepairLogger::info(__("Deleted {$orphanCount} orphan records", 'mailerpress'), [
                'table' => $tableName,
                'column' => $column,
            ]);
        }

        return $orphanCount;
    }

    /**
     * Check if a column has an index
     */
    protected function columnHasIndex(string $tableName, string $column): bool
    {
        global $wpdb;

        $indexes = $wpdb->get_results("SHOW INDEX FROM {$tableName} WHERE Column_name = '{$column}'", ARRAY_A);

        return !empty($indexes);
    }

    /**
     * Check if a foreign key with the same structure already exists
     * Returns the FK name if found, null otherwise
     */
    protected function foreignKeyExists(
        string $tableName,
        string $column,
        string $referencedTable,
        string $referencedColumn
    ): ?string {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = %s 
                AND REFERENCED_TABLE_NAME = %s 
                AND REFERENCED_COLUMN_NAME = %s",
                $tableName,
                $column,
                $referencedTable,
                $referencedColumn
            )
        );

        return $result ? $result->CONSTRAINT_NAME : null;
    }
}
