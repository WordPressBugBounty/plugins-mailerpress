<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;

interface StepHandlerInterface
{
    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult;
    public function supports(string $key): bool;
}