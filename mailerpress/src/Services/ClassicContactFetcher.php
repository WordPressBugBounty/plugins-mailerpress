<?php

namespace MailerPress\Services;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Interfaces\ContactFetcherInterface;
use MailerPress\Models\Contacts;
use MailerPress\Core\Kernel;

class ClassicContactFetcher implements ContactFetcherInterface
{
    private array $lists;
    private array $tags;
    private Contacts $contactsModel;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function __construct(array $lists, array $tags)
    {
        $this->lists = $lists;
        $this->tags = $tags;
        $this->contactsModel = Kernel::getContainer()->get(Contacts::class);
    }

    public function fetch(int $limit, int $offset): array
    {
        return $this->contactsModel->getContactsWithTagsAndLists(
            implode(',', array_column($this->lists, 'list_id')),
            implode(',', array_column($this->tags, 'tag_id')),
            true,
            $limit,
            $offset
        );
    }
}
