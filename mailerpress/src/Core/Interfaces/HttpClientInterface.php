<?php

declare(strict_types=1);

namespace MailerPress\Core\Interfaces;

\defined('ABSPATH') || exit;

interface HttpClientInterface
{
    public function get(
        string $endpoint,
        array $data = []
    );

    public function post(
        string $endpoint,
        array $data = []
    );

    public function put();

    public function delete();
}
