<?php

declare(strict_types=1);

namespace MailerPress\Core\Esp\Smtp;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\EmailServiceInterface;

class SmtpEsp implements EmailServiceInterface
{
    public function send(): void
    {
        // TODO: Implement send() method.
    }

    /**
     * @return array
     *
     * @example [
     *      ['id' => 1, 'name': 'ListName1'],
     *      ['id' => 2, 'name': 'ListName2'],
     *      ['id' => 3, 'name': 'ListName3'],
     * ]
     */
    public function getLists() {}

    public function getContacts(): void
    {
        // TODO: Implement getContacts() method.
    }

    public function config(): array
    {
        return [
            'name' => 'External service (SMTP)',
            'link' => 'https://www.brevo.com/fr/pricing/',
            'createAccountLink' => 'https://onboarding.brevo.com/account/register',
            'linkApiKey' => 'https://app.brevo.com/settings/keys/api',
            'description' => __('The SMTP method allows you to fine-tune a sending method by configuring a dedicated SMTP server.', 'mailerpress'),
            'recommended' => false,
        ];
    }

    public function getSendersList($formatted = false): ?array
    {
        return null;
    }
}
