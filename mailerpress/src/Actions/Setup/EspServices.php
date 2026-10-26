<?php

declare(strict_types=1);

namespace MailerPress\Actions\Setup;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Api\Options;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\EmailManager\services\SmtpService;
use MailerPress\Core\EmailManager\services\WordPressService;
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
        // MailerPress Pro replaces this key with a service that calls wp_mail().
        $this->emailServiceManager->registerService('wordpress', Kernel::getContainer()->get(WordPressService::class));
        $this->emailServiceManager->registerService('smtp', Kernel::getContainer()->get(SmtpService::class));
    }

    /**
     * Never store the former "php" key, including from a settings import, and keep the saved credentials
     * submitted masked by the settings screens.
     *
     * @param mixed $value
     * @param mixed $oldValue
     * @return mixed
     */
    #[Filter('pre_update_option_mailerpress_email_services', acceptedArgs: 2)]
    public function normalizeConfigurations($value, $oldValue = null)
    {
        $value = Options::restoreEmailServiceSecrets($value, $oldValue);

        if (\is_array($value)) {
            return EmailServiceManager::normalizeConfigurations($value);
        }

        $decoded = \is_string($value) ? json_decode($value, true) : null;

        return \is_array($decoded) ? wp_json_encode(EmailServiceManager::normalizeConfigurations($decoded)) : $value;
    }
}
