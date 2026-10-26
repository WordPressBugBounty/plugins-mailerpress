<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;
use MailerPress\Core\Workflows\Models\Automation;
use MailerPress\Core\Workflows\Handlers\StepHandlerInterface;

class WorkflowManager
{
    private AutomationRepository $automationRepo;
    private StepRepository $stepRepo;
    private AutomationJobRepository $jobRepo;
    private WorkflowExecutor $executor;
    private TriggerManager $triggerManager;
    private ActionSchedulerManager $schedulerManager;

    public function __construct(
        ?AutomationRepository $automationRepo = null,
        ?StepRepository $stepRepo = null,
        ?AutomationJobRepository $jobRepo = null,
        ?WorkflowExecutor $executor = null
    ) {
        $this->automationRepo = $automationRepo ?? new AutomationRepository();
        $this->stepRepo = $stepRepo ?? new StepRepository();
        $this->jobRepo = $jobRepo ?? new AutomationJobRepository();
        $this->executor = $executor ?? new WorkflowExecutor($this->automationRepo, $this->stepRepo, $this->jobRepo);
        $this->triggerManager = new TriggerManager($this->executor, $this->automationRepo, $this->stepRepo, $this->jobRepo);
        $this->schedulerManager = new ActionSchedulerManager($this->executor);
    }

    public function getAutomation(int $id): ?Automation
    {
        return $this->automationRepo->find($id);
    }

    public function getActiveAutomations(): array
    {
        return $this->automationRepo->findByStatus('ENABLED');
    }

    public function getAutomationSteps(int $automationId): array
    {
        return $this->stepRepo->findByAutomationId($automationId);
    }

    public function startWorkflow(int $automationId, int $userId, array $context = []): ?AutomationJob
    {
        $automation = $this->automationRepo->find($automationId);

        if (!$automation || !$automation->isEnabled()) {
            return null;
        }

        // Get contact_id from context if available (for MailerPress contacts)
        $contactId = $context['contact_id'] ?? null;

        if ($automation->isRunOncePerSubscriber()) {
            $existingRun = $this->jobRepo->findAnyByAutomationAndUser($automationId, $userId);

            if (!$existingRun && $contactId) {
                $existingRun = $this->jobRepo->findAnyByAutomationAndContact($automationId, (int) $contactId);
            }

            if ($existingRun) {
                return null;
            }
        }

        // Check for active jobs - check both user_id and contact_id to cover all cases:
        // 1. WordPress user only (no MailerPress contact) - check by user_id
        // 2. MailerPress contact without WordPress account - check by contact_id (which is also user_id)
        // 3. MailerPress contact with WordPress account - check by both user_id and contact_id
        $existingJob = null;

        // First check by user_id (for WordPress users)
        $existingJob = $this->jobRepo->findActiveByAutomationAndUser($automationId, $userId);

        // Also check by contact_id if available (for MailerPress contacts)
        // This will also check if the contact has a WordPress account and find jobs by that user_id
        if (!$existingJob && $contactId) {
            $existingJob = $this->jobRepo->findActiveByAutomationAndContact($automationId, $contactId);
        }

        if ($existingJob) {
            return $existingJob;
        }

        $steps = $this->stepRepo->findByAutomationId($automationId);
        $triggerStep = array_filter($steps, fn($step) => $step->isTrigger());

        if (empty($triggerStep)) {
            return null;
        }

        $firstStep = reset($triggerStep);

        // Extensions can refuse the start, e.g. MailerPress Pro checks a birthday trigger is still due today.
        // Checked here so it applies even when startWorkflow is called directly.
        if (!apply_filters('mailerpress_workflow_can_start', true, $firstStep->getKey(), $context, $automationId, $userId)) {
            return null;
        }

        $job = $this->jobRepo->create(
            $automationId,
            $userId,
            $firstStep->getNextStepId()
        );

        if ($job) {
            $this->executor->executeJob($job->getId(), $context);
        }

        return $job;
    }

    public function stopWorkflow(int $jobId): bool
    {
        $job = $this->jobRepo->find($jobId);

        if (!$job) {
            return false;
        }

        $this->schedulerManager->cancelJobActions($jobId);

        $job->setStatus('CANCELLED');
        return $this->jobRepo->update($job);
    }

    public function registerStepHandler(StepHandlerInterface $handler): void
    {
        $this->executor->getHandlerRegistry()->register($handler);
    }

    public function registerTrigger(string $key, string $hookName, ?callable $contextBuilder = null): void
    {
        $this->triggerManager->registerTrigger($key, $hookName, $contextBuilder);
    }

    public function getTriggerManager(): TriggerManager
    {
        return $this->triggerManager;
    }

    public function getExecutor(): WorkflowExecutor
    {
        return $this->executor;
    }
}
