<?php

namespace MailerPress\Core\Workflows\Models;

class StepBranch
{
    private ?int $id = null;
    private ?int $stepId = null;
    private ?array $condition = null;
    private ?string $nextStepId = null;

    public function __construct(array $data = [])
    {
        $this->hydrate($data);
    }

    private function hydrate(array $data): void
    {
        if (isset($data['id'])) $this->id = (int) $data['id'];
        if (isset($data['step_id'])) $this->stepId = (int) $data['step_id'];
        if (isset($data['condition'])) {
            $this->condition = is_string($data['condition']) 
                ? json_decode($data['condition'], true) 
                : $data['condition'];
        }
        if (isset($data['next_step_id'])) $this->nextStepId = $data['next_step_id'];
    }

    public function getId(): ?int { return $this->id; }
    public function getStepId(): ?int { return $this->stepId; }
    public function getCondition(): ?array { return $this->condition; }
    public function getNextStepId(): ?string { return $this->nextStepId; }
}