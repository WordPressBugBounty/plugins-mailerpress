<?php

declare(strict_types=1);

namespace MailerPress\Core\Esp\SendGrid;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\EmailServiceInterface;

class SendGridEsp implements EmailServiceInterface
{
    public function send(): void
    {
        // TODO: Implement send() method.
    }

    public function getLists(): void
    {
        // TODO: Implement getLists() method.
    }

    public function getContacts(): void
    {
        // TODO: Implement getContacts() method.
    }

    public function config(): array
    {
        return [
            'name' => 'Sendgrid',
            'link' => 'https://sendgrid.com/pricing',
            'createAccountLink' => 'https://signup.sendgrid.com/',
            'description' => __(
                'SendGrid is a cloud-based email platform offering reliable delivery, automation, analytics, and API integration for transactional and marketing emails.',
                'mailerpress'
            ),
            'linkApiKey' => '',
            'recommended' => true,
        ];
    }

    public function getSendersList($formatted = false): array
    {
        // TODO: Implement getSendersList() method.
    }
}
