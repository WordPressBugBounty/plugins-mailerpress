<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Repositories\StepRepository;

/**
 * Read-only helper to walk the step graph of an automation.
 *
 * Goals need this to decide whether a contact may jump to a goal step: a jump
 * is only legitimate when the goal is still *ahead* of the contact's current
 * position. Pulling a contact backwards to a goal they already walked past
 * would replay part of the sequence.
 */
class WorkflowGraph
{
    private StepRepository $stepRepo;

    /** @var array<int, array<string, Step>> Steps indexed by automation then step_id */
    private array $cache = [];

    public function __construct(?StepRepository $stepRepo = null)
    {
        $this->stepRepo = $stepRepo ?? new StepRepository();
    }

    /**
     * Is $targetStepId reachable by following the flow from $fromStepId?
     *
     * $fromStepId itself counts as reachable: a contact parked *on* the goal has
     * already been offered it and must not be re-entered.
     */
    public function isReachable(int $automationId, ?string $fromStepId, string $targetStepId): bool
    {
        if (!$fromStepId) {
            return false;
        }

        if ($fromStepId === $targetStepId) {
            return true;
        }

        $steps = $this->getSteps($automationId);
        $visited = [];
        $queue = [$fromStepId];

        while (!empty($queue)) {
            $currentId = array_shift($queue);

            if (isset($visited[$currentId])) {
                continue;
            }
            $visited[$currentId] = true;

            if ($currentId === $targetStepId) {
                return true;
            }

            $step = $steps[$currentId] ?? null;
            if (!$step) {
                continue;
            }

            foreach ($this->getOutgoing($step, $automationId) as $nextId) {
                if (!isset($visited[$nextId])) {
                    $queue[] = $nextId;
                }
            }
        }

        return false;
    }

    /**
     * Step ids a step can hand over to (main path, alternative path, branches).
     *
     * @return string[]
     */
    private function getOutgoing(Step $step, int $automationId): array
    {
        $next = [];

        if ($step->getNextStepId()) {
            $next[] = $step->getNextStepId();
        }

        if ($step->getAlternativeStepId()) {
            $next[] = $step->getAlternativeStepId();
        }

        if ($step->isCondition() && $step->getId()) {
            foreach ($this->stepRepo->findBranchesByStepId((int) $step->getId()) as $branch) {
                if ($branch->getNextStepId()) {
                    $next[] = $branch->getNextStepId();
                }
            }
        }

        return $next;
    }

    /**
     * @return array<string, Step> Steps of an automation indexed by step_id
     */
    public function getSteps(int $automationId): array
    {
        if (isset($this->cache[$automationId])) {
            return $this->cache[$automationId];
        }

        $indexed = [];

        foreach ($this->stepRepo->findByAutomationId($automationId) as $step) {
            if ($step->getStepId()) {
                $indexed[$step->getStepId()] = $step;
            }
        }

        $this->cache[$automationId] = $indexed;

        return $indexed;
    }

    /**
     * Trigger step of an automation, if any.
     */
    public function getTriggerStep(int $automationId): ?Step
    {
        foreach ($this->getSteps($automationId) as $step) {
            if ($step->isTrigger()) {
                return $step;
            }
        }

        return null;
    }

    public function flushCache(?int $automationId = null): void
    {
        if (null === $automationId) {
            $this->cache = [];
            return;
        }

        unset($this->cache[$automationId]);
    }
}
