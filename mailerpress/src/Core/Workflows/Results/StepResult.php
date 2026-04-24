<?php

namespace MailerPress\Core\Workflows\Results;

class StepResult
{
    private bool $success;
    private ?string $nextStepId;
    private array $data;
    private ?string $error;

    public function __construct(
        bool $success,
        ?string $nextStepId = null,
        array $data = [],
        ?string $error = null
    ) {
        $this->success = $success;
        $this->nextStepId = $nextStepId;
        $this->data = $data;
        $this->error = $error;
    }

    public static function success(?string $nextStepId = null, array $data = []): self
    {
        return new self(true, $nextStepId, $data);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, [], $error);
    }

    public function isSuccess(): bool { return $this->success; }
    public function getNextStepId(): ?string { return $this->nextStepId; }
    public function getData(): array { return $this->data; }
    public function getError(): ?string { return $this->error; }
}