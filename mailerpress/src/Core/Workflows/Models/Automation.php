<?php

namespace MailerPress\Core\Workflows\Models;

class Automation
{
    private ?int $id = null;
    private ?string $name = null;
    private ?int $author = null;
    private string $status = 'DRAFT';
    private bool $runOncePerSubscriber = false;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;

    public function __construct(array $data = [])
    {
        $this->hydrate($data);
    }

    private function hydrate(array $data): void
    {
        if (isset($data['id'])) $this->id = (int) $data['id'];
        if (isset($data['name'])) $this->name = $data['name'];
        if (isset($data['author'])) $this->author = (int) $data['author'];
        if (isset($data['status'])) $this->status = $data['status'];
        if (isset($data['run_once_per_subscriber'])) {
            $this->runOncePerSubscriber = (bool) $data['run_once_per_subscriber'];
        }
        if (isset($data['created_at'])) $this->createdAt = $data['created_at'];
        if (isset($data['updated_at'])) $this->updatedAt = $data['updated_at'];
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getName(): ?string
    {
        return $this->name;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function isEnabled(): bool
    {
        return $this->status === 'ENABLED';
    }
    public function getRunOncePerSubscriber(): bool
    {
        return $this->runOncePerSubscriber;
    }
    public function isRunOncePerSubscriber(): bool
    {
        return $this->runOncePerSubscriber;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'author' => $this->author,
            'status' => $this->status,
            'run_once_per_subscriber' => $this->runOncePerSubscriber,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
