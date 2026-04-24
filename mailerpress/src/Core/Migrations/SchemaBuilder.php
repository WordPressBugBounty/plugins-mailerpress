<?php

namespace MailerPress\Core\Migrations;

\defined('ABSPATH') || exit;

class SchemaBuilder
{
    protected array $tables = [];
    protected array $operations = [];

    public function create(string $tableName, callable $callback): static
    {
        // Always add the operation - let migrate() check if table exists at execution time
        // This allows tables to be created even if they were just dropped in the same migration run
        $manager = new CustomTableManager($tableName, true); // true = create operation
        $callback($manager);
        $this->operations[] = ['type' => 'create', 'manager' => $manager];

        return $this;
    }


    public function table(string $tableName, callable $callback): static
    {
        $manager = new CustomTableManager($tableName, false); // false = alter operation
        $callback($manager);
        $this->operations[] = ['type' => 'alter', 'manager' => $manager];
        return $this;
    }

    public function migrate(): void
    {
        foreach ($this->operations as $op) {
            try {
                $op['manager']->migrate();
            } catch (\Throwable $e) {
                $tableName = $op['manager']->getTableName();
                throw $e;
            }
        }
    }

    public function drop(): void
    {
        foreach ($this->operations as $op) {
            $op['manager']->drop();
        }
    }

    public function dryRun(): void
    {
        foreach ($this->operations as $op) {
            echo "Operation: {$op['type']} on {$op['manager']->getTableName()}\n";
            echo $op['manager']->generateSQLPreview() . "\n\n";
        }
    }

    public function loadMigrationsFrom(string $directory): static
    {
        foreach (glob($directory . '/*.php') as $file) {
            require_once $file;
        }
        return $this;
    }
}
