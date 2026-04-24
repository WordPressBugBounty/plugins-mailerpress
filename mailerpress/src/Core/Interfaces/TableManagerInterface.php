<?php

declare(strict_types=1);

namespace MailerPress\Core\Interfaces;

interface TableManagerInterface
{
    public function tableExists(): bool;

    public function createTable(): void;

    public function updateTable(string $currentVersion): void;

    public function createOrUpdateTable(): void;

    public function getDependentTables(): array;
}
