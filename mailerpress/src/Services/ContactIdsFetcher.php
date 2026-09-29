<?php

namespace MailerPress\Services;

use MailerPress\Core\Interfaces\ContactFetcherInterface;

class ContactIdsFetcher implements ContactFetcherInterface, \Countable
{
    private array $contactIds;

    public function __construct(array $contactIds)
    {
        $this->contactIds = array_values(array_unique(array_filter(array_map('absint', $contactIds))));
    }

    public function count(): int
    {
        return count($this->contactIds);
    }

    public function fetch(int $limit, int $offset): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_slice($this->contactIds, max(0, $offset), $limit);
    }
}
