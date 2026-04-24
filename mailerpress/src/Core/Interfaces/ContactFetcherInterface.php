<?php

namespace MailerPress\Core\Interfaces;

interface ContactFetcherInterface
{
    /**
     * Fetch contacts in chunks
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function fetch(int $limit, int $offset): array;
}
