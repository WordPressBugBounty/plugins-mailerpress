<?php

namespace MailerPress\Core\Workflows\Models;

/**
 * A goal (benchmark) record: the state of one goal step for one workflow run.
 *
 * PENDING  - the contact reached the goal step (or is eligible for it) and the
 *            event has not happened yet.
 * ACHIEVED - the event happened and was attributed to this run.
 * EXPIRED  - the goal had a timeout that elapsed before the event happened.
 * SKIPPED  - the run left the goal behind without resolving it (e.g. the job was
 *            cancelled, or the contact jumped past it).
 */
class Goal
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACHIEVED = 'ACHIEVED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_SKIPPED = 'SKIPPED';

    public const MODE_ESSENTIAL = 'essential';
    public const MODE_OPTIONAL = 'optional';

    private ?int $id = null;
    private ?int $automationId = null;
    private ?string $stepId = null;
    private ?int $jobId = null;
    private ?int $userId = null;
    private ?int $contactId = null;
    private ?string $goalEvent = null;
    private string $status = self::STATUS_PENDING;
    private ?string $matchHash = null;
    private ?array $data = null;
    private ?string $expiresAt = null;
    private ?string $achievedAt = null;
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
        if (isset($data['step_id'])) $this->stepId = $data['step_id'];
        if (isset($data['job_id'])) $this->jobId = null === $data['job_id'] ? null : (int) $data['job_id'];
        if (isset($data['user_id'])) $this->userId = (int) $data['user_id'];
        if (isset($data['contact_id'])) $this->contactId = null === $data['contact_id'] ? null : (int) $data['contact_id'];
        if (isset($data['goal_event'])) $this->goalEvent = $data['goal_event'];
        if (isset($data['status'])) $this->status = $data['status'];
        if (isset($data['match_hash'])) $this->matchHash = $data['match_hash'];
        if (isset($data['data'])) {
            $this->data = is_string($data['data'])
                ? json_decode($data['data'], true)
                : $data['data'];
        }
        if (isset($data['expires_at'])) $this->expiresAt = $data['expires_at'];
        if (isset($data['achieved_at'])) $this->achievedAt = $data['achieved_at'];
        if (isset($data['created_at'])) $this->createdAt = $data['created_at'];
        if (isset($data['updated_at'])) $this->updatedAt = $data['updated_at'];
    }

    public function getId(): ?int { return $this->id; }
    public function getAutomationId(): ?int { return $this->automationId; }
    public function getStepId(): ?string { return $this->stepId; }
    public function getJobId(): ?int { return $this->jobId; }
    public function getUserId(): ?int { return $this->userId; }
    public function getContactId(): ?int { return $this->contactId; }
    public function getGoalEvent(): ?string { return $this->goalEvent; }
    public function getStatus(): string { return $this->status; }
    public function getMatchHash(): ?string { return $this->matchHash; }
    public function getData(): ?array { return $this->data; }
    public function getExpiresAt(): ?string { return $this->expiresAt; }
    public function getAchievedAt(): ?string { return $this->achievedAt; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }

    public function setStatus(string $status): void { $this->status = $status; }
    public function setJobId(?int $jobId): void { $this->jobId = $jobId; }
    public function setData(?array $data): void { $this->data = $data; }
    public function setAchievedAt(?string $achievedAt): void { $this->achievedAt = $achievedAt; }
    public function setExpiresAt(?string $expiresAt): void { $this->expiresAt = $expiresAt; }

    public function isPending(): bool { return self::STATUS_PENDING === $this->status; }
    public function isAchieved(): bool { return self::STATUS_ACHIEVED === $this->status; }

    public function hasExpired(): bool
    {
        if (!$this->expiresAt) {
            return false;
        }

        // `expires_at` is always written with gmdate(), so it must be parsed as
        // UTC explicitly. Relying on PHP's default timezone would break as soon
        // as anything calls date_default_timezone_set(): WordPress sets it to
        // UTC, but nothing prevents a plugin from changing it mid-request.
        $timestamp = strtotime($this->expiresAt . ' UTC');

        return false !== $timestamp && $timestamp <= time();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automationId,
            'step_id' => $this->stepId,
            'job_id' => $this->jobId,
            'user_id' => $this->userId,
            'contact_id' => $this->contactId,
            'goal_event' => $this->goalEvent,
            'status' => $this->status,
            'match_hash' => $this->matchHash,
            'data' => $this->data,
            'expires_at' => $this->expiresAt,
            'achieved_at' => $this->achievedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
