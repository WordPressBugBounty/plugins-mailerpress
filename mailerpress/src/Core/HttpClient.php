<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

class HttpClient
{
    private \Symfony\Component\HttpClient\HttpClient $http_client;

    /**
     * @param \Symfony\Component\HttpClient\HttpClient $
     */
    public function __construct(\Symfony\Component\HttpClient\HttpClient $http_client)
    {
        $this->http_client = $http_client;
    }
}
