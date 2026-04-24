<?php

declare(strict_types=1);

namespace MailerPress\Actions\Setup;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\EmailManager\services\BrevoService;
use MailerPress\Core\EmailManager\services\MailgunService;
use MailerPress\Core\EmailManager\services\MailjetService;
use MailerPress\Core\EmailManager\services\PhpService;
use MailerPress\Core\EmailManager\services\SendGridService;
use MailerPress\Core\EmailManager\services\SmtpService;
use MailerPress\Core\Esp\Brevo\BrevoEsp;
use MailerPress\Core\Kernel;

class EspServices
{
    private EmailServiceManager $emailServiceManager;

    public function __construct(EmailServiceManager $emailServiceManager)
    {
        $this->emailServiceManager = $emailServiceManager;
    }

    /**
     * @throws NotFoundException
     * @throws DependencyException
     * @throws \Exception
     */
    #[Action('init')]
    public function registerService(): void
    {
        $this->emailServiceManager->registerService('php', Kernel::getContainer()->get(PhpService::class));
        $this->emailServiceManager->registerService('smtp', Kernel::getContainer()->get(SmtpService::class));
//        $this->emailServiceManager->registerService('brevo', Kernel::getContainer()->get(BrevoService::class));//Â
    }
}
