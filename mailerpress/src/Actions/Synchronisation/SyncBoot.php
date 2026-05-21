<?php

declare(strict_types=1);

namespace MailerPress\Actions\Synchronisation;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Synchronisation\Connectors\WordPressUsersConnectorStub;
use MailerPress\Core\Synchronisation\SynchronisationManager;

class SyncBoot
{
    private SynchronisationManager $manager;

    public function __construct(SynchronisationManager $manager)
    {
        $this->manager = $manager;
    }

    #[Action('init', priority: 5)]
    public function boot(): void
    {
        // Register stub — replaced by real connector when Pro is active
        $this->manager->register(new WordPressUsersConnectorStub());

        // Extension point for PRO or third-party plugins
        do_action('mailerpress_register_sync_connectors', $this->manager);

        // Activate hooks for all connectors with status = 'active'
        $this->manager->bootActiveConnectors();
    }
}
