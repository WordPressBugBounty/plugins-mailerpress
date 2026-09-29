<?php

declare(strict_types=1);

namespace MailerPress\Core\Migration;

\defined('ABSPATH') || exit;

use MailerPress\Core\Migration\Sources\MailPoetMigrationSource;
use MailerPress\Core\Migration\Sources\NewsletterMigrationSource;

class MigrationSourceRegistry
{
    /**
     * @var array<string, MigrationSourceInterface>
     */
    private array $sources = [];

    private bool $booted = false;

    public function __construct(
        private MailPoetMigrationSource $mailPoetSource,
        private NewsletterMigrationSource $newsletterSource
    )
    {
    }

    public function register(MigrationSourceInterface $source): void
    {
        $this->sources[$source->getKey()] = $source;
    }

    public function all(): array
    {
        $this->boot();

        return $this->sources;
    }

    public function get(string $key): ?MigrationSourceInterface
    {
        $this->boot();

        return $this->sources[$key] ?? null;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->register($this->mailPoetSource);
        $this->register($this->newsletterSource);

        do_action('mailerpress_register_migration_sources', $this);

        $this->booted = true;
    }
}
