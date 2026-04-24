<?php

declare(strict_types=1);

namespace MailerPress\Core\Interfaces;

\defined('ABSPATH') || exit;

interface EmailServiceInterface
{
    public function getSendersList($formatted = false): ?array;

    public function config(): array;

    public function send();

    public function getLists();

    public function getContacts();
}
