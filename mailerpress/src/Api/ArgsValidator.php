<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

class ArgsValidator
{
    public static function validateId($param, $request, $key): bool
    {
        return is_numeric($param);
    }
}
