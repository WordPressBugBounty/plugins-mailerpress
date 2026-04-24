<?php

declare(strict_types=1);

namespace MailerPress\Core\Esp;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Kernel;

class EspBase
{
    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function __construct()
    {
        try {
            $this->httpClient = Kernel::getContainer()->get($this->httpClient);
        } catch (DependencyException $e) {
        } catch (NotFoundException $e) {
        }
    }
}
