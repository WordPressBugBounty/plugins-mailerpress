<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Models\GoalSettings;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Workflows\Services\ActionSchedulerManager;
use MailerPress\Core\Workflows\Services\GoalManager;
use MailerPress\Core\Workflows\Services\GoalRegistry;

/**
 * Executes a GOAL step when the flow reaches it.
 *
 * Optional goal: registers the measurement and hands over to the next step
 * immediately.
 *
 * Essential goal: registers the measurement, parks the job in WAITING and
 * points `next_step_id` at the goal itself. Parking on the goal (rather than on
 * null) means the run's position stays truthful for the dashboard, a stray
 * resume simply re-parks instead of silently completing the run, and
 * GoalManager only has to flip the pointer to `next_step_id` when the event
 * finally happens.
 */
class GoalStepHandler implements StepHandlerInterface
{
    private AutomationJobRepository $jobRepo;
    private ?GoalManager $goalManager;

    public function __construct(?GoalManager $goalManager = null)
    {
        $this->jobRepo = new AutomationJobRepository();
        $this->goalManager = $goalManager;
    }

    /**
     * A goal step carries the goal event as its key, so support is decided by
     * the registry rather than by a fixed list.
     */
    public function supports(string $key): bool
    {
        return GoalRegistry::getInstance()->has($key);
    }

    /**
     * Goals are listed by their own endpoint, not among the actions.
     */
    public function getDefinition(): array
    {
        return [];
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        $settings = GoalSettings::fromStep($step);
        $manager = $this->getGoalManager();

        $goal = $manager->registerPendingGoal(
            $step,
            (int) $job->getId(),
            (int) $job->getUserId(),
            $settings,
            $context
        );

        if ($settings->isOptional()) {
            return StepResult::success($step->getNextStepId(), [
                'goal_registered' => true,
                'goal_mode' => 'optional',
                'goal_event' => $step->getKey(),
                'goal_id' => $goal ? $goal->getId() : null,
            ]);
        }

        // Essential: hold the contact here until the event happens (or expires).
        // Any continuation already scheduled for this run belongs to the path we
        // are leaving behind and would resume the job past the goal.
        ActionSchedulerManager::cancelActionsForJob((int) $job->getId());

        $job->setScheduledAt(null);
        $job->setNextStepId($step->getStepId());
        $job->setStatus('WAITING');
        $this->jobRepo->update($job);

        return StepResult::success($step->getStepId(), [
            'goal_registered' => true,
            'goal_mode' => 'essential',
            'goal_event' => $step->getKey(),
            'goal_id' => $goal ? $goal->getId() : null,
            'goal_expires_at' => $goal ? $goal->getExpiresAt() : null,
            'waiting_for_goal' => true,
        ]);
    }

    private function getGoalManager(): GoalManager
    {
        if (!$this->goalManager instanceof GoalManager) {
            $this->goalManager = GoalManager::instance();
        }

        return $this->goalManager;
    }
}
