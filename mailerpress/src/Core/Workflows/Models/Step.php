<?php

namespace MailerPress\Core\Workflows\Models;

class Step
{
    private ?int $id = null;
    private ?int $automationId = null;
    private ?string $stepId = null;
    private ?string $type = null;
    private ?string $key = null;
    private ?array $settings = null;
    private ?string $nextStepId = null;
    private ?string $alternativeStepId = null;

    public function __construct(array $data = [])
    {
        $this->hydrate($data);
    }

    private function hydrate(array $data): void
    {
        if (isset($data['id'])) $this->id = (int) $data['id'];
        if (isset($data['automation_id'])) $this->automationId = (int) $data['automation_id'];
        if (isset($data['step_id'])) $this->stepId = $data['step_id'];
        if (isset($data['type'])) $this->type = $data['type'];
        if (isset($data['key'])) $this->key = $data['key'];
        if (isset($data['settings'])) {
            $this->settings = is_string($data['settings']) 
                ? json_decode($data['settings'], true) 
                : $data['settings'];
        }
        if (isset($data['next_step_id'])) $this->nextStepId = $data['next_step_id'];
        if (isset($data['alternative_step_id'])) $this->alternativeStepId = $data['alternative_step_id'];
    }

    public function getId(): ?int { return $this->id; }
    public function getAutomationId(): ?int { return $this->automationId; }
    public function getStepId(): ?string { return $this->stepId; }
    public function getType(): ?string { return $this->type; }
    public function getKey(): ?string { return $this->key; }
    public function getSettings(): ?array { return $this->settings; }
    public function getNextStepId(): ?string { return $this->nextStepId; }
    public function getAlternativeStepId(): ?string { return $this->alternativeStepId; }

    public function isTrigger(): bool { return $this->type === 'TRIGGER'; }
    public function isAction(): bool { return $this->type === 'ACTION'; }
    public function isDelay(): bool { return $this->type === 'DELAY'; }
    public function isCondition(): bool { return $this->type === 'CONDITION'; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automationId,
            'step_id' => $this->stepId,
            'type' => $this->type,
            'key' => $this->key,
            'settings' => $this->settings,
            'next_step_id' => $this->nextStepId,
            'alternative_step_id' => $this->alternativeStepId,
        ];
    }
}