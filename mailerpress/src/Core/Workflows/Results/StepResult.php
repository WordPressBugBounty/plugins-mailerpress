<?php

namespace MailerPress\Core\Workflows\Results;

class StepResult
{
    private bool $success;
    private ?string $nextStepId;
    private array $data;
    private ?string $error;
    private bool $retryable;
    private ?string $missingFeature = null;

    public function __construct(
        bool $success,
        ?string $nextStepId = null,
        array $data = [],
        ?string $error = null,
        bool $retryable = true
    ) {
        $this->success = $success;
        $this->nextStepId = $nextStepId;
        $this->data = $data;
        $this->error = $error;
        $this->retryable = $retryable;
    }

    public static function success(?string $nextStepId = null, array $data = []): self
    {
        return new self(true, $nextStepId, $data);
    }

    public static function failed(string $error, bool $retryable = true): self
    {
        return new self(false, null, [], $error, $retryable);
    }

    /**
     * Pauses the contact on the step because its settings need a feature an extension provides (e.g. a
     * MailerPress Pro option while Pro is inactive). The contact resumes once the feature is available.
     */
    public static function paused(string $feature): self
    {
        $result = new self(true);
        $result->missingFeature = $feature;

        return $result;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getNextStepId(): ?string { return $this->nextStepId; }
    public function getData(): array { return $this->data; }
    public function getError(): ?string { return $this->error; }
    public function isRetryable(): bool { return $this->retryable; }
    public function getMissingFeature(): ?string { return $this->missingFeature; }
}
