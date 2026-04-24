<?php

namespace MailerPress\Core\Workflows\Models;

class AutomationJob
{
    private ?int $id = null;
    private ?int $automationId = null;
    private ?int $userId = null;
    private ?string $nextStepId = null;
    private string $status = 'ACTIVE';
    private ?string $scheduledAt = null;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;

    public function __construct(array $data = [])
    {
        $this->hydrate($data);
    }

    private function hydrate(array $data): void
    {
        if (isset($data['id'])) $this->id = (int) $data['id'];
        if (isset($data['automation_id'])) $this->automationId = (int) $data['automation_id'];
        if (isset($data['user_id'])) $this->userId = (int) $data['user_id'];
        if (isset($data['next_step_id'])) $this->nextStepId = $data['next_step_id'];
        if (isset($data['status'])) $this->status = $data['status'];
        if (isset($data['scheduled_at'])) $this->scheduledAt = $data['scheduled_at'];
        if (isset($data['created_at'])) $this->createdAt = $data['created_at'];
        if (isset($data['updated_at'])) $this->updatedAt = $data['updated_at'];
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getAutomationId(): ?int
    {
        return $this->automationId;
    }
    public function getUserId(): ?int
    {
        return $this->userId;
    }
    public function getNextStepId(): ?string
    {
        return $this->nextStepId;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getScheduledAt(): ?string
    {
        return $this->scheduledAt;
    }
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function setNextStepId(?string $nextStepId): self
    {
        $this->nextStepId = $nextStepId;
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setScheduledAt(?string $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }
    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }
    public function isWaiting(): bool
    {
        return $this->status === 'WAITING';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automationId,
            'user_id' => $this->userId,
            'next_step_id' => $this->nextStepId,
            'status' => $this->status,
            'scheduled_at' => $this->scheduledAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
