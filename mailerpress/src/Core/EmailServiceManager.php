<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\EmailServiceInterface;

class EmailServiceManager
{
    private $services = [];

    public function addService(
        string $name,
        EmailServiceInterface $service
    ): void {
        $this->services[$name] = $service;
    }

    public function getServices(): array
    {
        return $this->services;
    }

    public function getServiceByName(string $name): ?EmailServiceInterface
    {
        if (isset($this->services[$name])) {
            return $this->services[$name];
        }

        return null;
    }
}
