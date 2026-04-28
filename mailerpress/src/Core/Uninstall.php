<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

class Uninstall
{
    /**
     * Static callback for register_uninstall_hook (must be serializable).
     */
    public static function handleUninstall(): void
    {
        $uninstall = new self();
        $uninstall->run();
        do_action('mailerpress_uninstall');
    }

    public function run(): void
    {
        delete_option('mailerpress_activated');
        delete_option('mailerpress_global_email_senders');
    }
}
