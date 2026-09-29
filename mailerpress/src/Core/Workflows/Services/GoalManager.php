<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Workflows\Models\Goal;
use MailerPress\Core\Workflows\Models\GoalSettings;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;
use MailerPress\Core\Workflows\Repositories\AutomationLogRepository;
use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Core\Workflows\Repositories\GoalRepository;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Services\Logger;

/**
 * Runtime side of goals (benchmarks).
 *
 * Responsibilities:
 *  - promote triggers flagged `can_be_goal` into goal events, and register the
 *    goal-only events (link clicked, tag removed…);
 *  - subscribe once to every underlying WordPress hook;
 *  - on event: resolve pending goals (advance the parked run), then optionally
 *    let new contacts in (entry) or pull running contacts forward (jump);
 *  - expire goals whose timeout elapsed.
 */
class GoalManager
{
    private AutomationRepository $automationRepo;
    private StepRepository $stepRepo;
    private AutomationJobRepository $jobRepo;
    private AutomationLogRepository $logRepo;
    private GoalRepository $goalRepo;
    private WorkflowExecutor $executor;
    private WorkflowUserResolver $userResolver;
    private WorkflowGraph $graph;
    private GoalRegistry $registry;

    private bool $booted = false;

    /** @var array<string, bool> Hooks already subscribed, to avoid double firing */
    private array $subscribedHooks = [];

    private static ?GoalManager $instance = null;

    /** Guards against goal events cascading into one another indefinitely. */
    private const MAX_EVENT_DEPTH = 5;
    private static int $eventDepth = 0;

    public function __construct(
        ?WorkflowExecutor $executor = null,
        ?AutomationRepository $automationRepo = null,
        ?StepRepository $stepRepo = null,
        ?AutomationJobRepository $jobRepo = null
    ) {
        $this->automationRepo = $automationRepo ?? new AutomationRepository();
        $this->stepRepo = $stepRepo ?? new StepRepository();
        $this->jobRepo = $jobRepo ?? new AutomationJobRepository();
        $this->logRepo = new AutomationLogRepository();
        $this->goalRepo = new GoalRepository();
        $this->executor = $executor ?? new WorkflowExecutor();
        $this->userResolver = new WorkflowUserResolver();
        $this->graph = new WorkflowGraph($this->stepRepo);
        $this->registry = GoalRegistry::getInstance();
    }

    /**
     * Shared instance, set by WorkflowManager at boot so that step handlers
     * reuse the booted manager instead of rebuilding the object graph.
     */
    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function setInstance(self $manager): void
    {
        self::$instance = $manager;
    }

    public function getRegistry(): GoalRegistry
    {
        return $this->registry;
    }

    /**
     * Build the goal catalogue and subscribe to the underlying hooks.
     *
     * Must run after triggers are registered: promoted goals reuse the trigger
     * hooks and context builders as-is, which is what keeps the resolved
     * `user_id` identical between the trigger and the goal path.
     */
    public function boot(TriggerManager $triggerManager): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->promoteTriggers($triggerManager);

        do_action('mailerpress_register_workflow_goals', $this);

        $this->subscribeHooks();

        add_action(ActionSchedulerManager::HOOK_GOAL_TIMEOUT, [$this, 'handleTimeoutAction'], 10, 1);
    }

    /**
     * Expose the registry to third parties without leaking the singleton.
     */
    public function registerGoal(string $key, array $definition, array $hooks = []): void
    {
        $this->registry->register($key, $definition, $hooks);
    }

    /**
     * Trigger keys exposed as goals, with the wording adjusted from "cause" to
     * "outcome". Third-party triggers can opt in instead by setting
     * `can_be_goal => true` on their definition.
     *
     * Deliberately excluded: scanner-driven triggers (birthday_check,
     * woocommerce_customer_inactive) and woocommerce_abandoned_cart, whose
     * events describe a state scan rather than an action the contact took.
     *
     * @return array<string, array{label?: string, description?: string}>
     */
    public static function getPromotableTriggerGoals(): array
    {
        $goals = [
            'tag_added' => [
                'label' => __('Tag applied', 'mailerpress'),
                'description' => __('Achieved when the selected tag is applied to the contact.', 'mailerpress'),
            ],
            'list_added' => [
                'label' => __('List applied', 'mailerpress'),
                'description' => __('Achieved when the contact is added to the selected list.', 'mailerpress'),
            ],
            'mailerpress_contact_optin' => [
                'label' => __('Opt-in confirmed', 'mailerpress'),
                'description' => __('Achieved when the contact confirms their subscription.', 'mailerpress'),
            ],
            'contact_custom_field_updated' => [
                'label' => __('Custom field updated', 'mailerpress'),
                'description' => __('Achieved when the selected custom field is updated on the contact.', 'mailerpress'),
            ],
            'contact_subscribed' => [
                'label' => __('User registered', 'mailerpress'),
                'description' => __('Achieved when the contact registers an account on your site.', 'mailerpress'),
            ],
            'user_login' => [
                'label' => __('User logged in', 'mailerpress'),
                'description' => __('Achieved the next time the contact logs into your site.', 'mailerpress'),
            ],
            'comment_posted' => [
                'label' => __('Comment posted', 'mailerpress'),
                'description' => __('Achieved when the contact posts a comment.', 'mailerpress'),
            ],
            'woocommerce_order_status_changed' => [
                'label' => __('Order received (WooCommerce)', 'mailerpress'),
                'description' => __('Achieved when the contact places an order reaching the selected status.', 'mailerpress'),
            ],
            'woocommerce_product_purchased' => [
                'label' => __('Product purchased (WooCommerce)', 'mailerpress'),
                'description' => __('Achieved when the contact buys one of the selected products.', 'mailerpress'),
            ],
            'woocommerce_customer_first_order' => [
                'label' => __('First order placed (WooCommerce)', 'mailerpress'),
                'description' => __('Achieved when the contact places their very first order.', 'mailerpress'),
            ],
            'woocommerce_subscription_started' => [
                'label' => __('Subscription started (WooCommerce)', 'mailerpress'),
                'description' => __('Achieved when the contact starts a subscription.', 'mailerpress'),
            ],
            'woocommerce_subscription_renewed' => [
                'label' => __('Subscription renewed (WooCommerce)', 'mailerpress'),
                'description' => __('Achieved when the contact renews their subscription.', 'mailerpress'),
            ],
            'surecart_order_created' => [
                'label' => __('Order received (SureCart)', 'mailerpress'),
                'description' => __('Achieved when the contact places a SureCart order.', 'mailerpress'),
            ],
            'fluentcart_order_created' => [
                'label' => __('Order received (FluentCart)', 'mailerpress'),
                'description' => __('Achieved when the contact places a FluentCart order.', 'mailerpress'),
            ],
            'fluentcart_order_paid' => [
                'label' => __('Order paid (FluentCart)', 'mailerpress'),
                'description' => __('Achieved when the contact pays a FluentCart order.', 'mailerpress'),
            ],
            'fluentcart_subscription_activated' => [
                'label' => __('Subscription activated (FluentCart)', 'mailerpress'),
                'description' => __('Achieved when the contact activates a subscription.', 'mailerpress'),
            ],
            'webhook_received' => [
                'label' => __('Webhook received', 'mailerpress'),
                'description' => __('Achieved when your webhook endpoint receives a payload for this contact.', 'mailerpress'),
            ],
            'custom_trigger' => [
                'label' => __('Custom hook fired', 'mailerpress'),
                'description' => __('Achieved when the configured WordPress hook fires for this contact.', 'mailerpress'),
            ],
        ];

        return apply_filters('mailerpress_workflow_promotable_trigger_goals', $goals);
    }

    /**
     * Turn every promotable trigger into a goal event.
     *
     * Promoted goals reuse the trigger hook and context builder verbatim, which
     * is what keeps the resolved contact identity consistent between the two
     * paths.
     */
    private function promoteTriggers(TriggerManager $triggerManager): void
    {
        $definitions = $triggerManager->getTriggerDefinitions();
        $registered = $triggerManager->getRegisteredTriggers();
        $promotable = self::getPromotableTriggerGoals();

        foreach ($definitions as $key => $definition) {
            $override = $promotable[$key] ?? null;

            if (null === $override && empty($definition['can_be_goal'])) {
                continue;
            }

            if (is_array($override)) {
                if (!empty($override['label'])) {
                    $definition['goal_label'] = $override['label'];
                }
                if (!empty($override['description'])) {
                    $definition['goal_description'] = $override['description'];
                }
            }

            $hooks = [];
            $trigger = $registered[$key] ?? null;

            if ($trigger && !empty($trigger['hook'])) {
                $hooks[] = [
                    'hook' => $trigger['hook'],
                    'context_builder' => $trigger['context_builder'] ?? null,
                ];
            }

            foreach ($trigger['additional_hooks'] ?? [] as $additional) {
                if (empty($additional['hook'])) {
                    continue;
                }

                $hooks[] = [
                    'hook' => $additional['hook'],
                    'context_builder' => $additional['context_builder'] ?? ($trigger['context_builder'] ?? null),
                ];
            }

            if (empty($hooks)) {
                continue;
            }

            $goalDefinition = $definition;
            unset($goalDefinition['can_be_goal'], $goalDefinition['hook']);

            // A goal label reads better as an outcome than as a cause.
            if (!empty($definition['goal_label'])) {
                $goalDefinition['label'] = $definition['goal_label'];
                unset($goalDefinition['goal_label']);
            }

            if (!empty($definition['goal_description'])) {
                $goalDefinition['description'] = $definition['goal_description'];
                unset($goalDefinition['goal_description']);
            }

            $this->registry->register($key, $goalDefinition, $hooks);
        }
    }

    /**
     * Subscribe to each distinct hook once, dispatching to every goal key that
     * listens to it.
     */
    private function subscribeHooks(): void
    {
        foreach ($this->registry->getDefinitions() as $key => $definition) {
            foreach ($this->registry->getHooks($key) as $index => $hook) {
                $hookName = $hook['hook'];
                $signature = $key . '|' . $hookName . '|' . $index;

                if (isset($this->subscribedHooks[$signature])) {
                    continue;
                }
                $this->subscribedHooks[$signature] = true;

                $contextBuilder = $hook['context_builder'];

                add_action($hookName, function (...$args) use ($key, $contextBuilder) {
                    $this->handleEvent($key, $args, $contextBuilder);
                }, 20, 10);
            }
        }
    }

    /**
     * A goal event fired: resolve pending goals, then handle entries/jumps.
     */
    public function handleEvent(string $goalKey, array $args, ?callable $contextBuilder): void
    {
        // Resolving a goal can run a workflow, which can fire another goal event
        // (e.g. "automation completed"). Bound the nesting so two automations
        // feeding each other cannot recurse indefinitely.
        if (self::$eventDepth >= self::MAX_EVENT_DEPTH) {
            Logger::warning('GoalManager: goal event nesting limit reached', [
                'goal_key' => $goalKey,
                'depth' => self::$eventDepth,
            ]);

            return;
        }

        $steps = $this->stepRepo->findStepsByTypeAndKey('GOAL', $goalKey, 'ENABLED');

        if (empty($steps)) {
            return;
        }

        $context = $contextBuilder ? $contextBuilder(...$args) : [];

        if (empty($context)) {
            return;
        }

        $userId = $this->userResolver->resolve($context);

        if (!$userId) {
            return;
        }

        $context['goal_event'] = $goalKey;

        // The same person can be recorded under several ids depending on which
        // trigger created the run; resolve them all once for this event.
        $identities = $this->userResolver->resolveIdentities($context, $userId);

        ++self::$eventDepth;

        try {
            foreach ($steps as $step) {
                try {
                    $this->processStepForEvent($step, $goalKey, $userId, $identities, $context);
                } catch (\Exception $e) {
                    Logger::error('GoalManager: failed to process goal event', [
                        'goal_key' => $goalKey,
                        'step_id' => $step->getStepId(),
                        'automation_id' => $step->getAutomationId(),
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            --self::$eventDepth;
        }
    }

    /**
     * @param int[] $identities Every id the contact may be recorded under
     */
    private function processStepForEvent(Step $step, string $goalKey, int $userId, array $identities, array $context): void
    {
        $settings = GoalSettings::fromStep($step);

        if (!$this->matchesFilters($step, $goalKey, $settings, $userId, $context)) {
            return;
        }

        $pending = $this->goalRepo->findPendingByIdentitiesAndStep($identities, (string) $step->getStepId());
        $resolved = 0;

        foreach ($pending as $goal) {
            if ($this->resolvePendingGoal($goal, $step, $settings, $context)) {
                ++$resolved;
            }
        }

        if ($resolved > 0) {
            return;
        }

        if (!$settings->allowsEntry()) {
            return;
        }

        $this->handleEntry($step, $settings, $goalKey, $userId, $identities, $context);
    }

    /**
     * Check the event-specific filters, then the generic condition builder.
     */
    private function matchesFilters(Step $step, string $goalKey, GoalSettings $settings, int $userId, array $context): bool
    {
        $filters = $settings->getEventFilters();

        $checker = new TriggerConditionChecker();
        if (!$checker->check($goalKey, $filters, $userId, $context)) {
            return false;
        }

        $conditions = $filters['conditions'] ?? null;

        if (null === $conditions || false === $conditions || '' === $conditions) {
            return true;
        }

        if (is_array($conditions)) {
            $rules = $conditions['rules'] ?? [];
            if (empty($rules)) {
                return true;
            }
        }

        return (new ConditionEvaluator())->evaluate($conditions, $userId, $context);
    }

    /**
     * Mark a pending goal as achieved and, for essential goals, resume the run.
     */
    private function resolvePendingGoal(Goal $goal, Step $step, GoalSettings $settings, array $context): bool
    {
        // markAchieved() is guarded on the PENDING status: losing this race means
        // another event already credited the goal, so there is nothing to do.
        if (!$this->goalRepo->markAchieved((int) $goal->getId(), $this->buildGoalData($context))) {
            return false;
        }

        do_action('mailerpress_workflow_goal_achieved', $goal, $step, $context);

        $this->logRepo->log(
            (int) $step->getAutomationId(),
            (string) $step->getStepId(),
            (int) $goal->getUserId(),
            'COMPLETED',
            [
                'goal_achieved' => true,
                'goal_event' => $goal->getGoalEvent(),
                'goal_mode' => $settings->getMode(),
                'job_id' => $goal->getJobId(),
            ]
        );

        $jobId = $goal->getJobId();

        if (!$jobId) {
            return true;
        }

        // An optional goal never held the run back: it already moved on.
        if ($settings->isOptional()) {
            return true;
        }

        $job = $this->jobRepo->find((int) $jobId);

        if (!$job) {
            return true;
        }

        if (in_array($job->getStatus(), ['COMPLETED', 'CANCELLED', 'FAILED'], true)) {
            return true;
        }

        ActionSchedulerManager::cancelActionsForJob((int) $jobId, ActionSchedulerManager::HOOK_GOAL_TIMEOUT);
        ActionSchedulerManager::cancelActionsForJob((int) $jobId);

        $job->setScheduledAt(null);
        $job->setNextStepId($step->getNextStepId());
        $job->setStatus('ACTIVE');
        $this->jobRepo->update($job);

        if (!$step->getNextStepId()) {
            // Nothing after the goal: the run is simply done.
            $job->setStatus('COMPLETED');
            $this->jobRepo->update($job);

            return true;
        }

        $this->executor->executeJob((int) $jobId, $context);

        return true;
    }

    /**
     * Contact entry: let a contact in at the goal step, or pull a running
     * contact forward to it.
     */
    private function handleEntry(Step $step, GoalSettings $settings, string $goalKey, int $userId, array $identities, array $context): void
    {
        $automationId = (int) $step->getAutomationId();
        $automation = $this->automationRepo->find($automationId);

        if (!$automation || 'ENABLED' !== $automation->getStatus()) {
            return;
        }

        $nextStepId = $step->getNextStepId();

        if (!$nextStepId) {
            // An entry point with nothing behind it would create an empty run.
            return;
        }

        $existingJob = $this->findLiveJob($automationId, $identities);

        if ($existingJob) {
            $this->jumpForward($existingJob, $step, $goalKey, $userId, $context);

            return;
        }

        if ($automation->isRunOncePerSubscriber() && !$settings->ignoresRunOnce()) {
            if ($this->hasAlreadyRun($automationId, $identities, $context)) {
                return;
            }
        }

        // A goal already credited for this contact must not re-open a run.
        if ($this->hasAchievedWithoutJob($identities, (string) $step->getStepId())) {
            return;
        }

        $job = $this->jobRepo->create($automationId, $userId, $nextStepId);

        if (!$job) {
            return;
        }

        $goal = $this->goalRepo->createPending([
            'automation_id' => $automationId,
            'step_id' => $step->getStepId(),
            'job_id' => $job->getId(),
            'user_id' => $userId,
            'contact_id' => $context['contact_id'] ?? null,
            'goal_event' => $goalKey,
            'data' => ['entered_via_goal' => true],
        ]);

        if ($goal) {
            $this->goalRepo->markAchieved((int) $goal->getId(), $this->buildGoalData($context) + ['entered_via_goal' => true]);
        }

        do_action('mailerpress_workflow_goal_entered', $step, $job, $context);

        $this->logRepo->log(
            $automationId,
            (string) $step->getStepId(),
            $userId,
            'COMPLETED',
            [
                'goal_entry' => true,
                'goal_event' => $goalKey,
                'job_id' => $job->getId(),
            ]
        );

        $this->executor->executeJob((int) $job->getId(), $context);
    }

    /**
     * Move a live run forward to the goal step.
     *
     * Only ever forward: if the goal is not reachable from the contact's current
     * position they already passed it, and replaying the sequence would resend
     * emails they have had.
     */
    private function jumpForward($job, Step $step, string $goalKey, int $userId, array $context): void
    {
        $jobId = (int) $job->getId();
        $automationId = (int) $step->getAutomationId();

        $existingGoal = $this->goalRepo->findForJob((string) $step->getStepId(), $jobId);

        if ($existingGoal && !$existingGoal->isPending()) {
            // Already achieved (or deliberately skipped) for this run.
            return;
        }

        if (!$this->graph->isReachable($automationId, $job->getNextStepId(), (string) $step->getStepId())) {
            return;
        }

        // Parked exactly on this goal: the pending-goal path owns that case.
        if ($job->getNextStepId() === $step->getStepId()) {
            return;
        }

        $goal = $existingGoal ?: $this->goalRepo->createPending([
            'automation_id' => $automationId,
            'step_id' => $step->getStepId(),
            'job_id' => $jobId,
            'user_id' => $userId,
            'contact_id' => $context['contact_id'] ?? null,
            'goal_event' => $goalKey,
            'data' => ['jumped_from' => $job->getNextStepId()],
        ]);

        if (!$goal) {
            return;
        }

        if (!$this->goalRepo->markAchieved((int) $goal->getId(), $this->buildGoalData($context) + ['jumped_from' => $job->getNextStepId()])) {
            return;
        }

        // Drop everything the old position had scheduled, otherwise a pending
        // delay would later resume the run on the path we just left.
        ActionSchedulerManager::cancelActionsForJob($jobId);
        ActionSchedulerManager::cancelActionsForJob($jobId, ActionSchedulerManager::HOOK_GOAL_TIMEOUT);
        $this->goalRepo->skipPendingForJob($jobId, (string) $step->getStepId());

        $job->setScheduledAt(null);
        $job->setNextStepId($step->getNextStepId());
        $job->setStatus('ACTIVE');
        $this->jobRepo->update($job);

        do_action('mailerpress_workflow_goal_jumped', $step, $job, $context);

        $this->logRepo->log(
            $automationId,
            (string) $step->getStepId(),
            $userId,
            'COMPLETED',
            [
                'goal_jump' => true,
                'goal_event' => $goalKey,
                'job_id' => $jobId,
            ]
        );

        $this->executor->executeJob($jobId, $context);
    }

    /**
     * Live (ACTIVE, PROCESSING or WAITING) run of this automation for any of the
     * contact's identities.
     *
     * @param int[] $identities
     */
    private function findLiveJob(int $automationId, array $identities)
    {
        foreach ($identities as $identity) {
            $job = $this->jobRepo->findActiveByAutomationAndUser($automationId, (int) $identity, true);

            if ($job) {
                return $job;
            }
        }

        return null;
    }

    /**
     * @param int[] $identities
     */
    private function hasAlreadyRun(int $automationId, array $identities, array $context): bool
    {
        foreach ($identities as $identity) {
            if ($this->jobRepo->findAnyByAutomationAndUser($automationId, (int) $identity)) {
                return true;
            }
        }

        $contactId = absint($context['contact_id'] ?? 0);

        if (!$contactId) {
            return false;
        }

        return (bool) $this->jobRepo->findAnyByAutomationAndContact($automationId, $contactId);
    }

    /**
     * Has this contact already been credited for this goal step outside of any
     * run? Guards against an event firing repeatedly for the same contact.
     *
     * @param int[] $identities
     */
    private function hasAchievedWithoutJob(array $identities, string $stepId): bool
    {
        $goal = $this->goalRepo->findForJob($stepId, null);

        if (!$goal || !$goal->isAchieved()) {
            return false;
        }

        return in_array((int) $goal->getUserId(), array_map('intval', $identities), true);
    }

    /**
     * Register a goal as pending for a run. Called by GoalStepHandler when the
     * flow reaches a goal step.
     */
    public function registerPendingGoal(Step $step, int $jobId, int $userId, GoalSettings $settings, array $context): ?Goal
    {
        $timeoutSeconds = $settings->isEssential() ? $settings->getTimeoutSeconds() : null;
        $expiresAt = null;

        if ($timeoutSeconds) {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $timeoutSeconds);
        }

        $existing = $this->goalRepo->findForJob((string) $step->getStepId(), $jobId);

        $goal = $this->goalRepo->createPending([
            'automation_id' => (int) $step->getAutomationId(),
            'step_id' => $step->getStepId(),
            'job_id' => $jobId,
            'user_id' => $userId,
            'contact_id' => $context['contact_id'] ?? null,
            'goal_event' => (string) $step->getKey(),
            'expires_at' => $expiresAt,
            'data' => ['mode' => $settings->getMode()],
        ]);

        // Only schedule a timeout the first time the run reaches the goal, so a
        // re-entry does not stack duplicate expiry actions.
        if ($goal && $timeoutSeconds && (!$existing || !$existing->getExpiresAt())) {
            ActionSchedulerManager::scheduleGoalTimeout(
                time() + $timeoutSeconds,
                $jobId,
                (string) $step->getStepId()
            );
        }

        return $goal;
    }

    /**
     * Action Scheduler callback: a goal timeout elapsed.
     *
     * @param mixed $args
     */
    public function handleTimeoutAction($args): void
    {
        $args = ActionSchedulerManager::normalizeArgs($args);

        $jobId = (int) ($args['job_id'] ?? 0);
        $stepId = (string) ($args['step_id'] ?? '');

        if (!$jobId || '' === $stepId) {
            return;
        }

        $this->expireGoal($stepId, $jobId);
    }

    /**
     * Expire a pending goal and route the run to the "not achieved" branch.
     */
    public function expireGoal(string $stepId, int $jobId): bool
    {
        $goal = $this->goalRepo->findForJob($stepId, $jobId);

        // Only a goal still waiting can expire. This is the guard that matters:
        // achieving a goal cancels its timeout action, and an already resolved
        // row must never be re-routed.
        //
        // Deliberately NOT checking expires_at here: deciding *when* to fire is
        // the scheduler's job. Gating on the deadline made running the action
        // by hand (Tools > Scheduled Actions > Run) a no-op that silently
        // consumed the action, leaving the run waiting forever.
        if (!$goal || !$goal->isPending()) {
            return false;
        }

        $this->goalRepo->updateStatus((int) $goal->getId(), Goal::STATUS_EXPIRED);

        $step = $this->stepRepo->findByStepId($stepId);

        if (!$step) {
            return false;
        }

        do_action('mailerpress_workflow_goal_expired', $goal, $step);

        $job = $this->jobRepo->find($jobId);

        if (!$job || in_array($job->getStatus(), ['COMPLETED', 'CANCELLED', 'FAILED'], true)) {
            return true;
        }

        $alternativeStepId = $step->getAlternativeStepId();

        $this->logRepo->log(
            (int) $step->getAutomationId(),
            $stepId,
            (int) $job->getUserId(),
            'COMPLETED',
            [
                'goal_expired' => true,
                'goal_event' => $goal->getGoalEvent(),
                'next_step_id' => $alternativeStepId,
                'job_id' => $jobId,
            ]
        );

        $job->setScheduledAt(null);
        $job->setNextStepId($alternativeStepId);

        if (!$alternativeStepId) {
            $job->setStatus('COMPLETED');
            $this->jobRepo->update($job);

            return true;
        }

        $job->setStatus('ACTIVE');
        $this->jobRepo->update($job);

        $context = $this->logRepo->getTriggerContext((int) $step->getAutomationId(), (int) $job->getUserId(), $jobId) ?: [];

        $this->executor->executeJob($jobId, $context);

        return true;
    }

    /**
     * Safety net: expire goals whose scheduled action was lost.
     *
     * @return int Number of goals expired
     */
    public function expireOverdueGoals(int $limit = 100): int
    {
        $expired = 0;

        foreach ($this->goalRepo->findExpiredPending($limit) as $goal) {
            $jobId = $goal->getJobId();

            if (!$jobId) {
                $this->goalRepo->updateStatus((int) $goal->getId(), Goal::STATUS_EXPIRED);
                ++$expired;
                continue;
            }

            if ($this->expireGoal((string) $goal->getStepId(), (int) $jobId)) {
                ++$expired;
            }
        }

        return $expired;
    }

    /**
     * Re-arm expiry actions that are missing from the scheduler.
     *
     * A pending goal carries its deadline in `expires_at`, but the action that
     * fires it lives in Action Scheduler and can go missing: queue flushed,
     * site migrated, or the action never created because the scheduler was not
     * loaded when the goal was registered. Without this, such a goal would only
     * expire on the next cleanup sweep — up to a day late.
     *
     * @return int Number of expiry actions re-scheduled
     */
    public function ensureTimeoutsScheduled(int $limit = 200): int
    {
        if (!function_exists('as_has_scheduled_action')) {
            return 0;
        }

        $rescheduled = 0;

        foreach ($this->goalRepo->findPendingWithFutureExpiry($limit) as $goal) {
            $jobId = (int) $goal->getJobId();
            $stepId = (string) $goal->getStepId();

            if (!$jobId || '' === $stepId) {
                continue;
            }

            $args = [ActionSchedulerManager::buildJobArgs($jobId, null, ['step_id' => $stepId])];

            if (as_has_scheduled_action(ActionSchedulerManager::HOOK_GOAL_TIMEOUT, $args, ActionSchedulerManager::GROUP)) {
                continue;
            }

            $timestamp = strtotime((string) $goal->getExpiresAt() . ' UTC');

            if (false === $timestamp) {
                continue;
            }

            ActionSchedulerManager::scheduleGoalTimeout($timestamp, $jobId, $stepId);
            ++$rescheduled;
        }

        if ($rescheduled > 0) {
            Logger::info('GoalManager: re-armed missing goal timeouts', [
                'rescheduled' => $rescheduled,
            ]);
        }

        return $rescheduled;
    }

    /**
     * Keep only the event payload worth storing on the goal row.
     */
    private function buildGoalData(array $context): array
    {
        $keep = [
            'goal_event',
            'campaign_id',
            'order_id',
            'order_total',
            'tag_id',
            'list_id',
            'product_id',
            'link_url',
            'field_key',
            'contact_id',
            'user_id',
        ];

        $data = [];

        foreach ($keep as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $data[$key] = $context[$key];
            }
        }

        return $data;
    }
}
