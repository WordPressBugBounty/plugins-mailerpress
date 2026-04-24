<?php

declare(strict_types=1);

namespace MailerPress\Core\Abstract;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\TaskInterface;

abstract class AbstractTask implements TaskInterface
{
    protected $action;

    public static function execute(): void
    {
        add_action(self::$action, [__CLASS__, 'executeTask']);
    }
}
