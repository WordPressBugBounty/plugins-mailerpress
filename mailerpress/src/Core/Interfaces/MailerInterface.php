<?php

declare(strict_types=1);

namespace MailerPress\Core\Interfaces;

\defined('ABSPATH') || exit;

interface MailerInterface
{
    public function sendEmail($to, $subject, $body, $headers): bool;
}
