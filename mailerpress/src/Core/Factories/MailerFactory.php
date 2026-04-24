<?php

declare(strict_types=1);

namespace MailerPress\Core\Factories;

\defined('ABSPATH') || exit;

use MailerPress\Core\EmailManager\EmailServiceInterface;
use MailerPress\Core\EmailManager\EmailServiceManager;

class MailerFactory
{
    private EmailServiceManager $email_service_manager;

    public function __construct(EmailServiceManager $email_service_manager)
    {
        $this->email_service_manager = $email_service_manager;
    }

    public function createMailer(): ?EmailServiceInterface
    {
        try {
            return $this->email_service_manager->getActiveService();
        } catch (\Exception $e) {
            return null;
        }
    }
}
