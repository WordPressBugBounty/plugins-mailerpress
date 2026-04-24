<?php

declare(strict_types=1);

namespace MailerPress\Core\Factories;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\EmailManager\EmailServiceInterface;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;

class EmailServiceFactory
{
    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    public static function getProvider(): ?EmailServiceInterface
    {
        return Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
    }
}
