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

    public function __construct()
    {
        $this->automationRepo = new AutomationRepository();
        $this->stepRepo = new StepRepository();
        $this->jobRepo = new AutomationJobRepository();
        $this->executor = new WorkflowExecutor();
        // Pass the executor instance to ensure all services use the same instance
        $this->triggerManager = new TriggerManager($this->executor);
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

        // If run_once_per_subscriber is enabled, check for completed jobs
        if ($automation->isRunOncePerSubscriber()) {
            $completedJob = null;

            // Check by user_id first (for WordPress users)
            $completedJob = $this->jobRepo->findCompletedByAutomationAndUser($automationId, $userId);

            // Also check by contact_id if available (for MailerPress contacts)
            // This will also check if the contact has a WordPress account and find jobs by that user_id
            if (!$completedJob && $contactId) {
                $completedJob = $this->jobRepo->findCompletedByAutomationAndContact($automationId, $contactId);
            }

            if ($completedJob) {
                return null;
            }
        }

        $steps = $this->stepRepo->findByAutomationId($automationId);
        $triggerStep = array_filter($steps, fn($step) => $step->isTrigger());

        if (empty($triggerStep)) {
            return null;
        }

        $firstStep = reset($triggerStep);

        // Additional validation for birthday_check trigger
        // This ensures the date check is always performed, even if startWorkflow is called directly
        if ($firstStep->getKey() === 'birthday_check' && isset($context['birthday_date'])) {
            $birthdayDateStr = $context['birthday_date'];
            $todayDateTime = new \DateTime('now', function_exists('wp_timezone') ? wp_timezone() : null);

            try {
                // Parse the birthday date from context
                $birthdayDateTime = null;
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $birthdayDateStr, $matches)) {
                    $birthdayDateTime = \DateTime::createFromFormat('Y-m-d', $birthdayDateStr);
                } else {
                    $timestamp = strtotime($birthdayDateStr);
                    if ($timestamp !== false) {
                        $birthdayDateTime = new \DateTime();
                        $birthdayDateTime->setTimestamp($timestamp);
                    }
                }

                if ($birthdayDateTime) {
                    // Ensure both dates use the same timezone
                    $timezone = function_exists('wp_timezone') ? wp_timezone() : null;
                    if ($timezone) {
                        $birthdayDateTime->setTimezone($timezone);
                    }

                    // Compare only month and day (ignore year)
                    $birthdayMonth = (int) $birthdayDateTime->format('m');
                    $birthdayDay = (int) $birthdayDateTime->format('d');
                    $todayMonth = (int) $todayDateTime->format('m');
                    $todayDay = (int) $todayDateTime->format('d');

                    if ($birthdayMonth !== $todayMonth || $birthdayDay !== $todayDay) {
                        return null;
                    }
                }
            } catch (\Exception $e) {
                return null;
            }
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
