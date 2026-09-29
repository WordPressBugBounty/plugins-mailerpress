<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Migration\MigrationManager;

class ProcessMigrationChunk
{
    public function __construct(private MigrationManager $migrationManager)
    {
    }

    #[Action(MigrationManager::PROCESS_CHUNK_HOOK, priority: 10, acceptedArgs: 1)]
    public function processMigrationChunk(int $chunkId): void
    {
        $this->migrationManager->processChunk($chunkId);
    }
}
