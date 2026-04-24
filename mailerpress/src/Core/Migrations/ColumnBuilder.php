<?php

namespace MailerPress\Core\Migrations;

\defined('ABSPATH') || exit;

class ColumnBuilder
{
    protected string $name;
    protected string $type;
    protected array $modifiers = [];
    protected ?string $default = null;
    protected bool $nullable = false;
    protected bool $autoIncrement = false;
    protected bool $unique = false;
    protected ?string $position = null;
    protected ?string $before = null;
    protected ?string $after = null;
    protected ?string $extra = null;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        // If type starts with a keyword and has parameters, uppercase only keyword
        if (preg_match('/^(\w+)(\(.*\))?$/i', $type, $matches)) {
            $keyword = strtoupper($matches[1]);
            $params = $matches[2] ?? '';
            $this->type = $keyword . $params;
        } else {
            $this->type = strtoupper($type);
        }
    }

    public function nullable(): static
    {
        $this->nullable = true;
        return $this;
    }

    public function notNull(): static
    {
        $this->nullable = false;
        return $this;
    }

    public function default(string|int|null $value): static
    {
        $rawConstants = ['CURRENT_TIMESTAMP', 'NULL', 'NOW()', 'CURRENT_DATE', 'CURRENT_TIME'];

        if (is_string($value) && in_array(strtoupper($value), $rawConstants, true)) {
            $this->default = strtoupper($value);
        } elseif (is_string($value)) {
            $this->default = "'$value'";
        } else {
            $this->default = $value;
        }

        return $this;
    }

    public function autoIncrement(): static
    {
        $this->autoIncrement = true;
        return $this;
    }

    public function unique(): static
    {
        $this->unique = true;
        return $this;
    }

    public function unsigned(): static
    {
        $this->modifiers[] = 'UNSIGNED';
        return $this;
    }

    // Ajoute dans la classe ColumnBuilder

    public function extra(string $extra): static
    {
        $this->extra = $extra;
        return $this;
    }

    public function getSQL(): string
    {
        $parts = [$this->type];

        if (!empty($this->modifiers)) {
            $parts[] = implode(' ', $this->modifiers);
        }

        if ($this->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        $parts[] = $this->nullable ? 'NULL' : 'NOT NULL';

        if ($this->default !== null) {
            $parts[] = "DEFAULT {$this->default}";
        }

        if ($this->extra !== null) {
            $parts[] = $this->extra;
        }

        return implode(' ', $parts);
    }


    public function getName(): string
    {
        return $this->name;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function after(string $column): static
    {
        $this->position = " AFTER `$column`";
        return $this;
    }

    public function before(string $column): static
    {
        $this->position = " BEFORE `$column`";
        return $this;
    }

    public function getPosition(): string
    {
        return $this->position ?? '';
    }


    public function getBefore(): ?string
    {
        return $this->before;
    }

    public function getAfter(): ?string
    {
        return $this->after;
    }
}
