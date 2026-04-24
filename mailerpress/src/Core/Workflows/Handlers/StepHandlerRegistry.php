<?php

namespace MailerPress\Core\Workflows\Handlers;

class StepHandlerRegistry
{
    private array $handlers = [];

    public function register(StepHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function getHandler(string $key): ?StepHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($key)) {
                return $handler;
            }
        }
        return null;
    }

    public function getHandlers(): array
    {
        return $this->handlers;
    }
}