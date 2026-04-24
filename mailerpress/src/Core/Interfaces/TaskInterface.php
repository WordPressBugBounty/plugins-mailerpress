<?php

declare(strict_types=1);

namespace MailerPress\Core\Interfaces;

\defined('ABSPATH') || exit;

interface TaskInterface
{
    public static function execute();

    public static function executeTask();
}
