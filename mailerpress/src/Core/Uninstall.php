<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

class Uninstall
{
    public function run(): void
    {
        delete_option('mailerpress_activated');
        delete_option('mailerpress_global_email_senders');
    }
}
