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
        $listIds = $this->normalizeIds($this->lists, 'list_id');
        $tagIds = $this->normalizeIds($this->tags, 'tag_id');

        return $this->contactsModel->getContactsWithTagsAndLists(
            implode(',', $listIds),
            implode(',', $tagIds),
            true,
            $limit,
            $offset
        );
    }

    private function normalizeIds(array $items, string $primaryKey): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $value = $item[$primaryKey] ?? $item['id'] ?? $item['value'] ?? null;
            } elseif (is_object($item)) {
                $value = $item->{$primaryKey} ?? $item->id ?? $item->value ?? null;
            } else {
                $value = $item;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }
}
