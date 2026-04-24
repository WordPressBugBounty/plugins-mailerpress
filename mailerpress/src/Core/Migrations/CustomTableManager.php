<?php

namespace MailerPress\Core\Migrations;

\defined('ABSPATH') || exit;

class CustomTableManager
{
    protected string $tableName;
    protected string $version = '1.5.5';
    protected string $versionOptionName;
    protected array $columns = [];
    protected array|string|null $primaryKey = null;
    protected array $indexes = [];
    protected array $foreignKeys = [];
    protected array $columnBuilders = [];
    protected array $columnsToDrop = [];
    protected array $foreignKeysToDrop = [];
    protected array $indexesToDrop = [];
    protected bool $isCreateOperation = false;


    public function __construct(string $tableName, bool $isCreateOperation = false)
    {
        global $wpdb;
        $this->tableName = $wpdb->prefix . $tableName;
        $this->versionOptionName = 'custom_table_' . sanitize_key($tableName) . '_version';
        $this->isCreateOperation = $isCreateOperation;
    }

    public function dropColumn(string $name): static
    {
        $this->columnsToDrop[] = $name;
        return $this;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function addColumn(string $name, string $definition): static
    {
        $this->columns[$name] = $definition;
        return $this;
    }

    public function setPrimaryKey(string|array $columns): static
    {
        if (is_string($columns)) {
            // Automatically detect comma-separated list and split
            $columns = array_map('trim', explode(',', $columns));
        }

        $this->primaryKey = $columns;
        return $this;
    }

    public function addIndex(string|array $columns, string $type = 'INDEX'): static
    {
        $type = strtoupper($type);

        // Normalize to array
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        $indexName = implode('_', $columns);
        $key = "{$type}_{$indexName}";

        // Build column list with prefix lengths for long VARCHAR columns
        // This avoids "Specified key was too long" error on MySQL with utf8mb4
        $quotedColumnsParts = [];
        foreach ($columns as $col) {
            $prefixLength = $this->getIndexPrefixLength($col);
            if ($prefixLength !== null) {
                $quotedColumnsParts[] = "`{$col}`({$prefixLength})";
            } else {
                $quotedColumnsParts[] = "`{$col}`";
            }
        }
        $quotedColumns = implode(', ', $quotedColumnsParts);

        if (!isset($this->indexes[$key])) {
            if ($type === 'UNIQUE') {
                $this->indexes[$key] = "CONSTRAINT `{$indexName}_unique` UNIQUE ({$quotedColumns})";
            } else {
                $this->indexes[$key] = "$type ({$quotedColumns})";
            }
        }

        return $this;
    }

    /**
     * Determine if a column needs a prefix length for indexing
     * Returns the prefix length if needed, null otherwise
     */
    protected function getIndexPrefixLength(string $columnName): ?int
    {
        // Check in columnBuilders
        foreach ($this->columnBuilders as $builder) {
            if ($builder->getName() === $columnName) {
                $sql = $builder->getSQL();
                // Check if it's a VARCHAR with length > 191
                if (preg_match('/VARCHAR\((\d+)\)/i', $sql, $matches)) {
                    $length = (int) $matches[1];
                    // utf8mb4 uses 4 bytes per char, max key length is 767-1000 bytes depending on MySQL version
                    // 191 * 4 = 764 bytes, which is safe for all MySQL versions
                    if ($length > 191) {
                        return 191;
                    }
                }
                // Check for TEXT types (which also need prefix)
                if (preg_match('/^(TEXT|MEDIUMTEXT|LONGTEXT)/i', $sql)) {
                    return 191;
                }
                break;
            }
        }

        // Check in columns array (for addColumn() calls)
        if (isset($this->columns[$columnName])) {
            $definition = $this->columns[$columnName];
            if (preg_match('/VARCHAR\((\d+)\)/i', $definition, $matches)) {
                $length = (int) $matches[1];
                if ($length > 191) {
                    return 191;
                }
            }
            if (preg_match('/^(TEXT|MEDIUMTEXT|LONGTEXT)/i', $definition)) {
                return 191;
            }
        }

        return null;
    }

    public function addForeignKey(
        string $column,
        string $foreignTable,
        string $foreignColumn,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT'
    ): static {
        $this->foreignKeys[$column] = [
            'foreignTable' => $foreignTable,
            'foreignColumn' => $foreignColumn,
            'onDelete' => strtoupper($onDelete),
            'onUpdate' => strtoupper($onUpdate),
        ];
        return $this;
    }

    public function column(string $name, string $type): ColumnBuilder
    {
        $builder = new ColumnBuilder($name, $type);
        $this->columnBuilders[] = $builder;
        return $builder;
    }

    public function id(string $name = 'id'): static
    {
        $this->addColumn($name, 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT');
        $this->setPrimaryKey($name);
        return $this;
    }

    public function string(string $name, int $length = 255): ColumnBuilder
    {
        return $this->column($name, "VARCHAR($length)");
    }

    public function text(string $name): ColumnBuilder
    {
        return $this->column($name, 'TEXT');
    }

    public function longText(string $name): ColumnBuilder
    {
        return $this->column($name, 'LONGTEXT');
    }

    public function boolean(string $name): ColumnBuilder
    {
        return $this->column($name, 'TINYINT(1)');
    }

    public function integer(string $name): ColumnBuilder
    {
        return $this->column($name, 'INT');
    }

    public function bigInteger(string $name): ColumnBuilder
    {
        return $this->column($name, 'BIGINT(20)');
    }

    public function unsignedBigInteger(string $name): ColumnBuilder
    {
        return $this->bigInteger($name)->unsigned();
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnBuilder
    {
        return $this->column($name, "DECIMAL($precision,$scale)");
    }

    public function float(string $name): ColumnBuilder
    {
        return $this->column($name, 'FLOAT');
    }

    public function json(string $name): ColumnBuilder
    {
        return $this->column($name, 'JSON');
    }

    public function enum(string $name, array $values): ColumnBuilder
    {
        $escaped = array_map(fn($v) => "'$v'", $values);
        return $this->column($name, 'enum(' . implode(',', $escaped) . ')');
    }

    public function datetime(string $name): ColumnBuilder
    {
        return $this->column($name, 'DATETIME');
    }

    public function createdAt(): static
    {
        $this->column('created_at', 'DATETIME')->notNull()->default('CURRENT_TIMESTAMP');
        return $this;
    }

    public function deletedAt(): static
    {
        $this->column('deleted_at', 'DATETIME')->nullable();
        return $this;
    }

    protected function tableExists(): bool
    {
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '{$this->tableName}'") === $this->tableName;
    }

    protected function getCurrentColumns(): array
    {
        global $wpdb;
        return $wpdb->get_col("SHOW COLUMNS FROM {$this->tableName}", 0);
    }

    protected function getCurrentIndexes(): array
    {
        global $wpdb;
        $results = $wpdb->get_results("SHOW INDEX FROM {$this->tableName}");
        $existing = [];
        foreach ($results as $index) {
            $key = strtoupper($index->Key_name);
            $col = strtolower($index->Column_name);
            $existing[$key][] = $col;
        }
        return $existing;
    }

    protected function createTable(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // If table exists but version doesn't match, drop it first to allow clean recreation
        // This handles cases where old table structure is incompatible with new structure
        $installedVersion = get_option($this->versionOptionName);
        if ($this->tableExists() && $installedVersion !== $this->version && $installedVersion !== false) {
            $wpdb->query("DROP TABLE IF EXISTS {$this->tableName}");
        }

        foreach ($this->columnBuilders as $builder) {
            $this->addColumn($builder->getName(), $builder->getSQL());
            if ($builder->isUnique()) {
                $this->addIndex($builder->getName(), 'UNIQUE');
            }
        }

        // Prevent creating empty tables
        if (empty($this->columns) && empty($this->columnBuilders)) {
            throw new \RuntimeException("Cannot create table {$this->tableName}: no columns defined");
        }

        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->tableName} (\n";
        foreach ($this->columns as $name => $definition) {
            $sql .= "  `$name` $definition,\n";
        }

        if ($this->primaryKey) {
            $columns = is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];
            $sql .= 'PRIMARY KEY (`' . implode('`,`', $columns) . '`),';
        }

        foreach ($this->indexes as $indexClause) {
            $sql .= "  $indexClause,\n";
        }

        foreach ($this->foreignKeys as $column => $fk) {
            $fkName = "fk_{$this->tableName}_$column";
            $foreignTable = $wpdb->prefix . $fk['foreignTable'];

            // Verify foreign table exists before creating foreign key
            $foreignTableExists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($foreignTable))
            );

            if (!$foreignTableExists) {
                continue;
            }

            // Verify foreign column exists
            $foreignCols = $wpdb->get_col("SHOW COLUMNS FROM {$foreignTable}", 0);
            if (!in_array($fk['foreignColumn'], $foreignCols, true)) {
                continue;
            }

            $sql .= "  CONSTRAINT `$fkName` FOREIGN KEY (`$column`) REFERENCES `{$foreignTable}` (`{$fk['foreignColumn']}`) ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']},\n";
        }

        $sql = rtrim($sql, ",\n") . "\n) $charsetCollate;";

        // Suppress errors temporarily to check manually
        $wpdb->suppress_errors(true);
        dbDelta($sql);
        $wpdb->suppress_errors(false);

        // Check for errors
        if (!empty($wpdb->last_error)) {
            $errorMsg = "Failed to create table {$this->tableName}: {$wpdb->last_error}";
            throw new \RuntimeException($errorMsg);
        }
    }

    protected function getExistingForeignKeys(): array
    {
        global $wpdb;
        $results = $wpdb->get_results("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = '{$this->tableName}' AND CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL");
        return array_map(fn($row) => $row->CONSTRAINT_NAME, $results);
    }

    public function migrate(): bool
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installedVersion = get_option($this->versionOptionName);
        $tableExists = $this->tableExists();

        // Skip if version matches and table exists
        if ($installedVersion === $this->version && $tableExists) {
            return false;
        }

        // Skip if installed version is already newer than this migration's version.
        // A later migration has already upgraded this table — re-running an older
        // migration would undo changes (e.g. re-adding dropped foreign keys).
        if ($tableExists && $installedVersion !== false && version_compare($installedVersion, $this->version, '>')) {
            return false;
        }

        try {
            foreach ($this->columnBuilders as $builder) {
                $this->addColumn($builder->getName(), $builder->getSQL());
                if ($builder->isUnique()) {
                    $this->addIndex($builder->getName(), 'UNIQUE');
                }
            }

            $charsetCollate = $wpdb->get_charset_collate();

            if (!$tableExists) {
                // Only create table if this is a create operation
                // For alter operations (table()), skip if table doesn't exist
                if ($this->isCreateOperation) {
                    $this->createTable();
                } else {
                    // Table doesn't exist and this is an alter operation - skip silently
                    // The table will be created by a later migration that uses create()
                    return false;
                }
            } else {
                $currentCols = $this->getCurrentColumns();
                $currentIndexes = $this->getCurrentIndexes();
                $existingConstraints = $this->getExistingForeignKeys();
                $alterParts = [];

                // Add new columns
                foreach ($this->columnBuilders as $builder) {
                    $name = $builder->getName();

                    if (!in_array($name, $currentCols, true)) {
                        $sql = "ADD COLUMN `$name` {$builder->getSQL()}";

                        if ($builder->getBefore()) {
                            $sql .= " BEFORE `{$builder->getBefore()}`";
                        } elseif ($builder->getAfter()) {
                            $sql .= " AFTER `{$builder->getAfter()}`";
                        }

                        $alterParts[] = $sql;
                    }
                }

                // Add new indexes
                foreach ($this->indexes as $key => $clause) {
                    // Extract column names from index clause
                    preg_match_all('/`([^`]+)`/', $clause, $matches);
                    $cols = $matches[1] ?? [];

                    if (!empty($cols)) {
                        // First, verify all columns exist before creating index
                        $allColumnsExist = true;
                        foreach ($cols as $col) {
                            if (!in_array($col, $currentCols, true)) {
                                $allColumnsExist = false;
                                break;
                            }
                        }

                        if (!$allColumnsExist) {
                            continue; // Skip this index if columns don't exist
                        }

                        // Check if index already exists by checking all columns
                        $indexExists = false;
                        foreach ($cols as $col) {
                            $indexKey = strtoupper($col);
                            if (isset($currentIndexes[$indexKey])) {
                                // Check if all columns match
                                $existingCols = $currentIndexes[$indexKey];
                                if (is_array($existingCols) && count(array_intersect($cols, $existingCols)) === count($cols)) {
                                    $indexExists = true;
                                    break;
                                }
                            }
                        }

                        if (!$indexExists) {
                            $alterParts[] = "ADD $clause";
                        }
                    }
                }

                // Add new foreign keys
                foreach ($this->foreignKeys as $column => $fk) {
                    $fkName = "fk_{$this->tableName}_$column";
                    if (!in_array($fkName, $existingConstraints, true)) {
                        $foreignTable = $wpdb->prefix . $fk['foreignTable'];

                        // Verify foreign table exists
                        $foreignTableExists = $wpdb->get_var(
                            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($foreignTable))
                        );

                        if (!$foreignTableExists) {
                            continue;
                        }

                        // Verify foreign column exists
                        $foreignCols = $wpdb->get_col("SHOW COLUMNS FROM {$foreignTable}", 0);
                        if (!in_array($fk['foreignColumn'], $foreignCols, true)) {
                            continue;
                        }

                        $alterParts[] = "ADD CONSTRAINT `$fkName` FOREIGN KEY (`$column`) REFERENCES `{$foreignTable}` (`{$fk['foreignColumn']}`) ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']}";
                    }
                }

                // Drop foreign keys
                foreach ($this->foreignKeysToDrop as $column) {
                    $fkName = "fk_{$this->tableName}_$column";
                    $existingFKs = $this->getExistingForeignKeys();
                    if (in_array($fkName, $existingFKs, true)) {
                        $alterParts[] = "DROP FOREIGN KEY `$fkName`";
                    }
                }

                // Drop indexes
                foreach ($this->indexesToDrop as $columnOrName) {
                    // Get actual index names from database
                    $indexes = $wpdb->get_results("SHOW INDEX FROM {$this->tableName}");
                    $indexNamesToDrop = [];

                    foreach ($indexes as $index) {
                        // Match by column name or index name
                        if ($index->Column_name === $columnOrName || $index->Key_name === $columnOrName) {
                            if ($index->Key_name !== 'PRIMARY') { // Don't drop primary key
                                $indexNamesToDrop[$index->Key_name] = true;
                            }
                        }
                    }

                    // Add to alter parts
                    foreach ($indexNamesToDrop as $indexName => $_) {
                        $alterParts[] = "DROP INDEX `$indexName`";
                    }
                }

                // Drop columns
                foreach ($this->columnsToDrop as $columnName) {
                    if (in_array($columnName, $currentCols, true)) {
                        $alterParts[] = "DROP COLUMN `$columnName`";
                    }
                }

                // Execute ALTER TABLE if there are changes
                if (!empty($alterParts)) {
                    $alterSQL = "ALTER TABLE {$this->tableName} " . implode(", ", $alterParts);

                    $result = $wpdb->query($alterSQL);

                    if ($result === false && !empty($wpdb->last_error)) {
                        $errorMsg = "Failed to alter table {$this->tableName}: {$wpdb->last_error}";
                        throw new \RuntimeException($errorMsg);
                    }
                }
            }

            // Update version only if migration succeeded
            update_option($this->versionOptionName, $this->version, false);
            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function modifyColumn(string $columnName, string $definition): static
    {
        global $wpdb;

        // First verify table exists
        if (!$this->tableExists()) {
            // If table doesn't exist, skip modification silently
            // This allows migrations to work even if tables haven't been created yet
            // The table will be created with the correct structure in a later migration
            return $this;
        }

        // Verify column exists before modifying
        $currentCols = $this->getCurrentColumns();
        if (!in_array($columnName, $currentCols, true)) {
            // Column doesn't exist - skip modification
            // This can happen if the table structure is different than expected
            return $this;
        }

        $result = $wpdb->query("
            ALTER TABLE {$this->tableName}
            MODIFY COLUMN `$columnName` $definition
        ");

        if ($result === false && !empty($wpdb->last_error)) {
            $errorMsg = "Failed to modify column {$columnName} in table {$this->tableName}: {$wpdb->last_error}";
            throw new \RuntimeException($errorMsg);
        }

        return $this;
    }


    public function drop(): void
    {
        global $wpdb;

        $result = $wpdb->query("DROP TABLE IF EXISTS {$this->tableName}");

        if ($result === false && !empty($wpdb->last_error)) {
            $errorMsg = "Failed to drop table {$this->tableName}: {$wpdb->last_error}";
            throw new \RuntimeException($errorMsg);
        }

        delete_option($this->versionOptionName);
    }

    public function generateSQLPreview(): string
    {
        global $wpdb;
        $sql = "CREATE TABLE {$this->tableName} (\n";

        foreach ($this->columnBuilders as $builder) {
            $this->addColumn($builder->getName(), $builder->getSQL());
            if ($builder->isUnique()) {
                $this->addIndex($builder->getName(), 'UNIQUE');
            }
        }

        foreach ($this->columns as $name => $definition) {
            $sql .= "  `$name` $definition,\n";
        }

        if ($this->primaryKey) {
            $columns = is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];
            $quoted = implode('`, `', $columns);
            $sql .= "  PRIMARY KEY (`$quoted`),\n";
        }

        foreach ($this->indexes as $indexClause) {
            $sql .= "  $indexClause,\n";
        }

        foreach ($this->foreignKeys as $column => $fk) {
            $fkName = "fk_{$this->tableName}_$column";
            $sql .= "  CONSTRAINT `$fkName` FOREIGN KEY (`$column`) REFERENCES `{$wpdb->prefix}{$fk['foreignTable']}` (`{$fk['foreignColumn']}`) ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']},\n";
        }

        $sql = rtrim($sql, ",\n") . "\n)";
        return $sql;
    }

    public function dropForeign(string $column): static
    {
        // Store the operation to execute during migrate()
        $this->foreignKeysToDrop[] = $column;

        // Also remove from internal foreignKeys array to prevent adding it
        unset($this->foreignKeys[$column]);

        return $this;
    }

    public function dropIndex(string $columnOrName): static
    {
        // Store the operation to execute during migrate()
        $this->indexesToDrop[] = $columnOrName;

        // Remove from internal indexes array to prevent adding it
        foreach ($this->indexes as $key => $clause) {
            if (str_contains($clause, "`$columnOrName`")) {
                unset($this->indexes[$key]);
            }
        }

        return $this;
    }
}
