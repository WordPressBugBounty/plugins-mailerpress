<?php

declare(strict_types=1);

namespace MailerPress\Core\Esp\Mailjet;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\EmailServiceInterface;

class MailjetEsp implements EmailServiceInterface
{
    public function send(): void
    {
        // TODO: Implement send() method.
    }

    public function getLists(): void
    {
        // TODO: Implement getLists() method.
    }

    public function getContacts(): void {}

    public function config(): array
    {
        return [
            'name' => 'MailJet',
            'image' => MAILERPRESS_ASSETS_DIR.'/img/mailjet.svg',
            'link' => 'https://www.mailjet.com/pricing/',
            'createAccountLink' => 'https://app.mailjet.com/signup?lang=en_US',
            'linkApiKey' => 'https://app.mailjet.com/account/api_keys',
            'description' => __('Mailjet is an email platform offering seamless transactional and marketing email sending, advanced personalization, real-time analytics, and API integration.', 'mailerpress'),
        ];
    }

    public function getSendersList($formatted = false): array
    {
        // TODO: Implement getSendersList() method.
    }
}
