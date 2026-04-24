<?php

declare(strict_types=1);

namespace MailerPress\Core\EmailManager;

use WP_Error;

\defined('ABSPATH') || exit;

interface EmailServiceInterface
{
    public function connect(array $config): bool;

    public function sendEmail(array $emailData): bool|WP_Error;

    public function testConnection(): bool;

    public function config(): array;
}
