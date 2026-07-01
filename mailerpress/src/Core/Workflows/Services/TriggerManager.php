<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;

class TriggerManager
{
    private const GUEST_USER_ID_OFFSET = 2147483648;

    private AutomationRepository $automationRepo;
    private StepRepository $stepRepo;
    private AutomationJobRepository $jobRepo;
    private WorkflowExecutor $executor;
    private TriggerRateLimiter $rateLimiter;
    private array $registeredTriggers = [];
    private array $triggerDefinitions = [];

    public function __construct(
        ?WorkflowExecutor $executor = null,
        ?AutomationRepository $automationRepo = null,
        ?StepRepository $stepRepo = null,
        ?AutomationJobRepository $jobRepo = null
    ) {
        $this->automationRepo = $automationRepo ?? new AutomationRepository();
        $this->stepRepo = $stepRepo ?? new StepRepository();
        $this->jobRepo = $jobRepo ?? new AutomationJobRepository();
        $this->executor = $executor ?? new WorkflowExecutor();
        $this->rateLimiter = new TriggerRateLimiter();
    }

    /**
     * Register a trigger with optional definition (icon, label, description, etc.)
     *
     * @param string $key Unique trigger key
     * @param string $hookName WordPress hook name to listen to
     * @param callable|null $contextBuilder Function to build context from hook arguments
     * @param array|null $definition Optional definition array with icon, label, description, category
     */
    public function registerTrigger(string $key, string $hookName, ?callable $contextBuilder = null, ?array $definition = null): void
    {
        $this->registeredTriggers[$key] = [
            'hook' => $hookName,
            'context_builder' => $contextBuilder,
        ];

        // Store definition if provided
        if ($definition !== null) {
            // Merge definition with system fields, but preserve definition fields (icon, label, etc.)
            $this->triggerDefinitions[$key] = array_merge([
                'key' => $key,
                'hook' => $hookName,
                'type' => 'TRIGGER',
            ], $definition); // Put definition last to preserve icon, label, etc.
        }

        add_action($hookName, function (...$args) use ($key, $contextBuilder) {
            $this->handleTrigger($key, $args, $contextBuilder);
        }, 10, 10);
    }

    /**
     * Register an additional WordPress hook for an already-registered trigger.
     *
     * @param string $key Trigger key (must already be registered)
     * @param string $hookName Additional WordPress hook name to listen to
     * @param callable|null $contextBuilder Optional override context builder; falls back to the one from registerTrigger
     */
    public function registerAdditionalHook(string $key, string $hookName, ?callable $contextBuilder = null): void
    {
        $builder = $contextBuilder ?? ($this->registeredTriggers[$key]['context_builder'] ?? null);

        add_action($hookName, function (...$args) use ($key, $builder) {
            $this->handleTrigger($key, $args, $builder);
        }, 10, 10);
    }

    /**
     * Get trigger definition by key
     *
     * @param string $key Trigger key
     * @return array|null Definition array or null if not found
     */
    public function getTriggerDefinition(string $key): ?array
    {
        return $this->triggerDefinitions[$key] ?? null;
    }

    /**
     * Get all trigger definitions
     *
     * @return array Array of trigger definitions
     */
    public function getTriggerDefinitions(): array
    {
        return $this->triggerDefinitions;
    }

    private function handleTrigger(string $triggerKey, array $args, ?callable $contextBuilder): void
    {
        // Special handling for birthday_check trigger - it has its own logic in BirthdayCheckTrigger::checkBirthdays()
        // We should NOT process it here to avoid bypassing the date validation
        if ($triggerKey === 'birthday_check') {
            return;
        }

        $automations = $this->automationRepo->findByStatus('ENABLED');

        foreach ($automations as $automation) {
            $trigger = $this->stepRepo->findTriggerByKey($automation->getId(), $triggerKey);

            if (!$trigger) {
                continue;
            }

            $context = $contextBuilder ? $contextBuilder(...$args) : [];

            if (empty($context)) {
                continue;
            }

            // For abandoned cart trigger, verify context has cart_hash
            if ($triggerKey === 'woocommerce_abandoned_cart') {
                if (empty($context['cart_hash'])) {
                    continue;
                }
            }

            // For SureCart triggers, verify context has at least customer_email
            if (strpos($triggerKey, 'surecart_') === 0) {
                if (empty($context['customer_email']) && empty($context['email']) && empty($context['user_email'])) {
                    continue;
                }
            }

            $userId = $this->resolveWorkflowUserId($context);

            // If create_contact is enabled in trigger settings, create/update the contact
            $triggerSettings = $trigger->getSettings() ?? [];
            if (!empty($triggerSettings['create_contact'])) {
                $createdContact = $this->createContactFromTrigger($triggerSettings, $context);
                if ($createdContact) {
                    $userId = (int) $createdContact->contact_id;
                    $context['contact_id'] = $userId;
                    $context['user_id'] = $userId;
                }
            }

            if (!$userId) {
                continue;
            }

            // For abandoned cart trigger, only create a job if this is a NEW cart (first time detected)
            // The contextBuilder sets 'is_new_cart' to true when a cart is first registered
            if ($triggerKey === 'woocommerce_abandoned_cart') {
                $isNewCart = $context['is_new_cart'] ?? false;

                if (!$isNewCart) {
                    // This is an update to an existing cart - don't create a new job
                    continue;
                }

                // New cart detected - check if job already exists (shouldn't happen, but safety check)
                $includeWaiting = true;
                $existingJob = $this->jobRepo->findActiveByAutomationAndUser(
                    $automation->getId(),
                    $userId,
                    $includeWaiting
                );

                if ($existingJob) {
                    // Job already exists - this shouldn't happen for a new cart, but skip anyway
                    continue;
                }
            } else {
                // For other triggers, use the standard job checking logic
                $includeWaiting = false;
                $existingJob = $this->jobRepo->findActiveByAutomationAndUser(
                    $automation->getId(),
                    $userId,
                    $includeWaiting
                );

                if ($existingJob) {
                    // Check if the job is stuck (older than 10 minutes)
                    $jobUpdatedAt = $existingJob->getUpdatedAt();
                    if ($jobUpdatedAt) {
                        $jobTime = new \DateTime($jobUpdatedAt);
                        $now = new \DateTime();
                        $diff = $now->diff($jobTime);
                        $minutesOld = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

                        if ($minutesOld > 10) {
                            $existingJob->setStatus('FAILED');
                            $this->jobRepo->update($existingJob);
                            // Continue to create new job below
                        } else {
                            continue;
                        }
                    } else {
                        // No updated_at, consider it stuck
                        $existingJob->setStatus('FAILED');
                        $this->jobRepo->update($existingJob);
                        // Continue to create new job below
                    }
                }
            }

            // If run_once_per_subscriber is enabled, also check for completed jobs
            // Check both user_id and contact_id to cover all cases:
            // 1. WordPress user only (no MailerPress contact) - check by user_id
            // 2. MailerPress contact without WordPress account - check by contact_id (which is also user_id)
            // 3. MailerPress contact with WordPress account - check by both user_id and contact_id
            if ($automation->isRunOncePerSubscriber()) {
                $completedJob = null;

                // Get contact_id from context if available (for MailerPress contacts)
                $contactId = $context['contact_id'] ?? null;

                // Check by user_id first (for WordPress users)
                $completedJob = $this->jobRepo->findCompletedByAutomationAndUser(
                    $automation->getId(),
                    $userId
                );

                // Also check by contact_id if available (for MailerPress contacts)
                // This will also check if the contact has a WordPress account and find jobs by that user_id
                if (!$completedJob && $contactId) {
                    $completedJob = $this->jobRepo->findCompletedByAutomationAndContact(
                        $automation->getId(),
                        $contactId
                    );
                }

                if ($completedJob) {
                    continue;
                }
            }

            $conditionsPass = $this->checkTriggerConditions($trigger, $userId, $context);

            if (!$conditionsPass) {
                do_action('mailerpress_workflow_trigger_skipped', $triggerKey, $automation->getId(), $userId, $context);
                continue;
            }

            $nextStepId = $trigger->getNextStepId();

            if (empty($nextStepId)) {
                continue;
            }

            if (!$this->rateLimiter->isAllowed($triggerKey, $userId)) {
                do_action('mailerpress_workflow_trigger_rate_limited', $triggerKey, $userId);
                continue;
            }

            $job = $this->jobRepo->create(
                $automation->getId(),
                $userId,
                $nextStepId
            );

            if ($job) {
                do_action('mailerpress_workflow_trigger_fired', $triggerKey, $job, $context);
                $this->executor->executeJob($job->getId(), $context);
            }
        }
    }

    /**
     * Handle custom trigger execution
     *
     * This method is called by CustomTrigger when a custom hook is fired.
     * It processes the trigger for a specific automation.
     *
     * @param string $triggerKey The trigger key (should be 'custom_trigger')
     * @param array $context The context data from the hook
     * @param int $automationId The automation ID
     * @param string $stepId The trigger step ID
     */
    public function handleCustomTrigger(string $triggerKey, array $context, int $automationId, string $stepId): void
    {
        $automation = $this->automationRepo->find($automationId);

        if (!$automation || $automation->getStatus() !== 'ENABLED') {
            return;
        }

        $trigger = $this->stepRepo->findByStepId($stepId);

        if (!$trigger || $trigger->getKey() !== $triggerKey) {
            return;
        }

        $userId = $this->resolveWorkflowUserId($context);

        if (!$userId) {
            return;
        }

        // Check for existing active jobs
        $includeWaiting = false;
        $existingJob = $this->jobRepo->findActiveByAutomationAndUser(
            $automationId,
            $userId,
            $includeWaiting
        );

        if ($existingJob) {
            // Check if the job is stuck (older than 10 minutes)
            $jobUpdatedAt = $existingJob->getUpdatedAt();
            if ($jobUpdatedAt) {
                $jobTime = new \DateTime($jobUpdatedAt);
                $now = new \DateTime();
                $diff = $now->diff($jobTime);
                $minutesOld = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

                if ($minutesOld > 10) {
                    $existingJob->setStatus('FAILED');
                    $this->jobRepo->update($existingJob);
                } else {
                    return;
                }
            } else {
                $existingJob->setStatus('FAILED');
                $this->jobRepo->update($existingJob);
            }
        }

        // Check run_once_per_subscriber
        if ($automation->isRunOncePerSubscriber()) {
            $contactId = $context['contact_id'] ?? null;
            $completedJob = $this->jobRepo->findCompletedByAutomationAndUser($automationId, $userId);

            if (!$completedJob && $contactId) {
                $completedJob = $this->jobRepo->findCompletedByAutomationAndContact($automationId, $contactId);
            }

            if ($completedJob) {
                return;
            }
        }

        // Check trigger conditions
        $conditionsPass = $this->checkTriggerConditions($trigger, $userId, $context);

        if (!$conditionsPass) {
            do_action('mailerpress_workflow_trigger_skipped', $triggerKey, $automationId, $userId, $context);
            return;
        }

        $nextStepId = $trigger->getNextStepId();

        if (empty($nextStepId)) {
            return;
        }

        if (!$this->rateLimiter->isAllowed($triggerKey, $userId)) {
            do_action('mailerpress_workflow_trigger_rate_limited', $triggerKey, $userId);
            return;
        }

        $job = $this->jobRepo->create($automationId, $userId, $nextStepId);

        if ($job) {
            do_action('mailerpress_workflow_trigger_fired', $triggerKey, $job, $context);
            $this->executor->executeJob($job->getId(), $context);
        }
    }

    private function resolveWorkflowUserId(array &$context): int
    {
        $userId = (int) ($context['user_id'] ?? 0);
        if ($userId > 0) {
            $context['user_id'] = $userId;
            return $userId;
        }

        $contactId = (int) ($context['contact_id'] ?? 0);
        if ($contactId > 0) {
            $context['user_id'] = $contactId;
            return $contactId;
        }

        $email = $this->getContextEmail($context);
        if ($email !== '') {
            $contactsModel = new \MailerPress\Models\Contacts();
            $contact = $contactsModel->getContactByEmail($email);

            if ($contact) {
                $contactId = (int) $contact->contact_id;
                $context['contact_id'] = $contactId;
                $context['user_id'] = $contactId;
                return $contactId;
            }

            $guestUserId = self::GUEST_USER_ID_OFFSET + abs(crc32(strtolower($email)));

            $context['user_id'] = $guestUserId;
            return $guestUserId;
        }

        $currentUserId = (int) get_current_user_id();
        if ($currentUserId > 0) {
            $context['user_id'] = $currentUserId;
            return $currentUserId;
        }

        return 0;
    }

    private function getContextEmail(array $context): string
    {
        $email = $context['customer_email']
            ?? $context['email']
            ?? $context['user_email']
            ?? $context['billing_email']
            ?? ($context['billing_address']['email'] ?? '');

        $email = sanitize_email((string) $email);

        return is_email($email) ? $email : '';
    }

    /**
     * Create or update a MailerPress contact from trigger settings and context.
     *
     * @param array $triggerSettings Trigger step settings (with create_contact, contact_email, etc.)
     * @param array $context Workflow context data
     * @return object|null The contact object, or null on failure
     */
    private function createContactFromTrigger(array $triggerSettings, array $context): ?object
    {
        $emailTemplate = $triggerSettings['contact_email'] ?? '';
        $email = $this->replacePlaceholders($emailTemplate, $context);

        if (empty($email)) {
            return null;
        }

        $email = sanitize_email($email);
        if (!is_email($email)) {
            return null;
        }

        $firstName = sanitize_text_field($this->replacePlaceholders($triggerSettings['contact_first_name'] ?? '', $context));
        $lastName = sanitize_text_field($this->replacePlaceholders($triggerSettings['contact_last_name'] ?? '', $context));
        $subscriptionStatus = $triggerSettings['contact_subscription_status'] ?? 'subscribed';
        $updateExisting = $triggerSettings['contact_update_existing'] ?? true;

        $customFields = [];
        if (!empty($triggerSettings['contact_custom_fields']) && is_array($triggerSettings['contact_custom_fields'])) {
            $customFields = $triggerSettings['contact_custom_fields'];
        } elseif (!empty($triggerSettings['custom_fields']) && is_array($triggerSettings['custom_fields'])) {
            $customFields = $triggerSettings['custom_fields'];
        }

        foreach ($triggerSettings as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'contact_custom_field_') || $value === '' || $value === null) {
                continue;
            }

            $fieldKey = substr($key, strlen('contact_custom_field_'));
            if ($fieldKey !== '') {
                $customFields[$fieldKey] = $this->replacePlaceholders((string) $value, $context);
            }
        }

        $result = (new \MailerPress\Services\ContactUpsertService())->upsert([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'subscription_status' => $subscriptionStatus,
            'lists' => $triggerSettings['contact_lists'] ?? [],
            'custom_fields' => $customFields,
            'update_existing' => $updateExisting,
            'assign_default_list' => false,
            'opt_in_source' => 'workflow',
        ]);

        if (empty($result['success'])) {
            return null;
        }

        $contactId = (int) ($result['contact_id'] ?? 0);
        return $contactId > 0 ? (new \MailerPress\Models\Contacts())->get($contactId) : null;

        global $wpdb;
        $contactTable = \MailerPress\Core\Enums\Tables::get(\MailerPress\Core\Enums\Tables::MAILERPRESS_CONTACT);
        $contactsModel = new \MailerPress\Models\Contacts();

        $existingContact = $contactsModel->getContactByEmail($email);

        if ($existingContact) {
            if ($updateExisting) {
                $updateData = ['updated_at' => current_time('mysql')];
                $updateFormat = ['%s'];

                if (!empty($firstName)) {
                    $updateData['first_name'] = $firstName;
                    $updateFormat[] = '%s';
                }
                if (!empty($lastName)) {
                    $updateData['last_name'] = $lastName;
                    $updateFormat[] = '%s';
                }

                $wpdb->update($contactTable, $updateData, ['contact_id' => $existingContact->contact_id], $updateFormat, ['%d']);
            }

            $this->addContactToLists((int) $existingContact->contact_id, $triggerSettings);
            return $existingContact;
        }

        // Create new contact
        $result = $wpdb->insert($contactTable, [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'subscription_status' => $subscriptionStatus,
            'opt_in_source' => 'workflow',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'unsubscribe_token' => wp_generate_uuid4(),
            'access_token' => bin2hex(random_bytes(32)),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        if ($result === false) {
            return null;
        }

        $contactId = (int) $wpdb->insert_id;
        \do_action('mailerpress_contact_created', $contactId);

        $this->addContactToLists($contactId, $triggerSettings);

        return $contactsModel->get($contactId);
    }

    /**
     * Add contact to lists specified in trigger settings.
     */
    private function addContactToLists(int $contactId, array $triggerSettings): void
    {
        $lists = $triggerSettings['contact_lists'] ?? [];
        if (empty($lists)) {
            return;
        }

        if (is_string($lists)) {
            $decoded = json_decode($lists, true);
            $lists = is_array($decoded) ? $decoded : array_map('trim', explode(',', $lists));
        }

        global $wpdb;
        $listsTable = \MailerPress\Core\Enums\Tables::get(\MailerPress\Core\Enums\Tables::MAILERPRESS_CONTACT_LIST);

        foreach ($lists as $item) {
            $listId = is_array($item) ? (int) ($item['value'] ?? $item['id'] ?? 0) : (int) $item;
            if ($listId <= 0) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$listsTable} WHERE contact_id = %d AND list_id = %d",
                $contactId,
                $listId
            ));

            if (!$exists) {
                $wpdb->insert($listsTable, [
                    'contact_id' => $contactId,
                    'list_id' => $listId,
                ], ['%d', '%d']);
                \do_action('mailerpress_contact_list_added', $contactId, $listId);
            }
        }
    }

    /**
     * Replace {{placeholder}} patterns with values from context.
     */
    private function replacePlaceholders(string $template, array $context): string
    {
        if (empty($template)) {
            return '';
        }

        return preg_replace_callback('/\{\{(\w+(?:\.\w+)*)\}\}/', function ($matches) use ($context) {
            $key = $matches[1];

            // Support dot notation
            if (str_contains($key, '.')) {
                $parts = explode('.', $key);
                $value = $context;
                foreach ($parts as $part) {
                    if (is_array($value) && isset($value[$part])) {
                        $value = $value[$part];
                    } else {
                        return '';
                    }
                }
                return is_scalar($value) ? (string) $value : '';
            }

            if (isset($context[$key]) && is_scalar($context[$key])) {
                return (string) $context[$key];
            }

            return '';
        }, $template);
    }

    private function checkTriggerConditions($trigger, int $userId, array $context): bool
    {
        $settings = $trigger->getSettings() ?? [];

        // Check trigger-specific conditions
        $checker = new TriggerConditionChecker();
        if (!$checker->check($trigger->getKey(), $settings, $userId, $context)) {
            return false;
        }

        // Check general conditions (if any)
        $conditions = $settings['conditions'] ?? null;

        if ($conditions === null || $conditions === false || $conditions === '') {
            return true;
        }

        if (is_array($conditions)) {
            $rules = $conditions['rules'] ?? [];
            if (empty($rules) || (is_array($rules) && count($rules) === 0)) {
                return true;
            }
        }

        $evaluator = new ConditionEvaluator();
        return $evaluator->evaluate($conditions, $userId, $context);
    }

    public function registerDefaultTriggers(): void
    {
        $this->registerTrigger(
            'contact_subscribed',
            'user_register',
            function ($userId) {
                $user = get_userdata($userId);
                return [
                    'user_id' => $userId,
                    'user_email' => $user ? $user->user_email : '',
                    'user_login' => $user ? $user->user_login : '',
                    'display_name' => $user ? $user->display_name : '',
                    'first_name' => $user ? get_user_meta($userId, 'first_name', true) : '',
                    'last_name' => $user ? get_user_meta($userId, 'last_name', true) : '',
                    'user_role' => $user && !empty($user->roles) ? $user->roles[0] : '',
                    'user_registered' => $user ? $user->user_registered : '',
                ];
            },
            [
                'label' => __('User Registered', 'mailerpress'),
                'description' => __('Triggered when a new user registers on your WordPress site. Perfect for sending welcome emails or setting up onboarding workflows.', 'mailerpress'),
                'icon' => 'wordpress',
                'category' => 'user',
                'settings_schema' => [
                    [
                        'key' => 'user_role',
                        'label' => __('User Role', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'options' => $this->getRolesOptions(),
                        'help' => __('Only trigger for users with specific role (leave empty for all roles)', 'mailerpress'),
                    ],
                ],
                'output_fields' => [
                    ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'user'],
                    ['key' => 'user_email', 'label' => __('User Email', 'mailerpress'), 'type' => 'email', 'group' => 'user'],
                    ['key' => 'user_login', 'label' => __('Username', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'display_name', 'label' => __('Display Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'first_name', 'label' => __('First Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'last_name', 'label' => __('Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'user_role', 'label' => __('User Role', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'user_registered', 'label' => __('Registration Date', 'mailerpress'), 'type' => 'date', 'group' => 'user'],
                ],
            ]
        );

        $this->registerTrigger(
            'user_login',
            'wp_login',
            function ($userLogin, $user) {
                return [
                    'user_id' => $user->ID,
                    'user_login' => $userLogin,
                    'user_email' => $user->user_email,
                    'display_name' => $user->display_name,
                    'first_name' => get_user_meta($user->ID, 'first_name', true),
                    'last_name' => get_user_meta($user->ID, 'last_name', true),
                    'user_role' => !empty($user->roles) ? $user->roles[0] : '',
                ];
            },
            [
                'label' => __('User Login', 'mailerpress'),
                'description' => __('Triggered when a user logs into your site. Useful for sending security notifications or personalized messages after login.', 'mailerpress'),
                'icon' => 'wordpress',
                'category' => 'user',
                'settings_schema' => [
                    [
                        'key' => 'user_role',
                        'label' => __('User Role', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'options' => $this->getRolesOptions(),
                        'help' => __('Only trigger for users with specific role (leave empty for all roles)', 'mailerpress'),
                    ],
                ],
                'output_fields' => [
                    ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'user'],
                    ['key' => 'user_email', 'label' => __('User Email', 'mailerpress'), 'type' => 'email', 'group' => 'user'],
                    ['key' => 'user_login', 'label' => __('Username', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'display_name', 'label' => __('Display Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'first_name', 'label' => __('First Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'last_name', 'label' => __('Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'user_role', 'label' => __('User Role', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                ],
            ]
        );

        $this->registerTrigger(
            'profile_updated',
            'profile_update',
            function ($userId, $oldUserData) {
                $user = get_userdata($userId);
                return [
                    'user_id' => $userId,
                    'user_email' => $user ? $user->user_email : '',
                    'display_name' => $user ? $user->display_name : '',
                    'first_name' => $user ? get_user_meta($userId, 'first_name', true) : '',
                    'last_name' => $user ? get_user_meta($userId, 'last_name', true) : '',
                    'old_user_data' => $oldUserData,
                    'user_role' => $user && !empty($user->roles) ? $user->roles[0] : '',
                ];
            },
            [
                'label' => __('Profile Updated', 'mailerpress'),
                'description' => __('Triggered when a user updates their WordPress profile. Allows you to react to user information changes and synchronize data.', 'mailerpress'),
                'icon' => 'wordpress',
                'category' => 'user',
                'output_fields' => [
                    ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'user'],
                    ['key' => 'user_email', 'label' => __('User Email', 'mailerpress'), 'type' => 'email', 'group' => 'user'],
                    ['key' => 'display_name', 'label' => __('Display Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'first_name', 'label' => __('First Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'last_name', 'label' => __('Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                    ['key' => 'user_role', 'label' => __('User Role', 'mailerpress'), 'type' => 'string', 'group' => 'user'],
                ],
                'settings_schema' => [
                    [
                        'key' => 'user_role',
                        'label' => __('User Role', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'options' => $this->getRolesOptions(),
                        'help' => __('Only trigger for users with specific role (leave empty for all roles)', 'mailerpress'),
                    ],
                ],
            ]
        );

        $this->registerTrigger(
            'user_role_changed',
            'set_user_role',
            function ($userId, $role, $oldRoles) {
                return [
                    'user_id' => $userId,
                    'new_role' => $role,
                    'old_roles' => $oldRoles,
                ];
            },
            [
                'label' => __('User Role Changed', 'mailerpress'),
                'description' => __('Triggered when a user\'s role changes (e.g., from "Subscriber" to "Editor"). Ideal for automating actions based on permissions and access level changes.', 'mailerpress'),
                'icon' => 'wordpress',
                'category' => 'user',
                'settings_schema' => [
                    [
                        'key' => 'role',
                        'label' => __('Role', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'options' => $this->getRolesOptions(),
                        'help' => __('Only trigger when changed to specific role', 'mailerpress'),
                    ],
                ],
            ]
        );

        $this->registerTrigger(
            'user_meta_updated',
            'updated_user_meta',
            function ($metaId, $userId, $metaKey, $metaValue) {
                return [
                    'user_id' => $userId,
                    'meta_key' => $metaKey,
                    'meta_value' => $metaValue,
                ];
            },
            [
                'label' => __('User Meta Updated', 'mailerpress'),
                'description' => __('Triggered when a user metadata field is updated (e.g., phone number, address, etc.). Allows you to react to changes in personal data.', 'mailerpress'),
                'icon' => 'wordpress',
                'category' => 'user',
                'settings_schema' => [
                    [
                        'key' => 'meta_key',
                        'label' => __('Meta Key', 'mailerpress'),
                        'type' => 'text',
                        'required' => false,
                        'help' => __('Only trigger for specific meta key (e.g., billing_phone)', 'mailerpress'),
                    ],
                ],
            ]
        );

        $this->registerTrigger(
            'comment_posted',
            'comment_post',
            function ($commentId, $commentApproved, $commentData) {
                $comment = get_comment($commentId);
                $postId = $comment ? $comment->comment_post_ID : null;
                $post = $postId ? \get_post($postId) : null;

                return [
                    'user_id' => $commentData['user_id'] ?? get_current_user_id(),
                    'comment_id' => $commentId,
                    'comment_approved' => $commentApproved,
                    'comment_author' => $comment ? $comment->comment_author : '',
                    'comment_author_email' => $comment ? $comment->comment_author_email : '',
                    'comment_content' => $comment ? $comment->comment_content : '',
                    'comment_date' => $comment ? \date_i18n(\get_option('date_format'), \strtotime($comment->comment_date)) : '',
                    'post_id' => $postId,
                    'post_title' => $post ? $post->post_title : '',
                    'post_url' => $postId ? \get_permalink($postId) : '',
                ];
            },
            [
                'label' => __('Comment Posted', 'mailerpress'),
                'description' => __('Triggered when a comment is posted on your site. Perfect for sending notifications to post authors or engaging with your community.', 'mailerpress'),
                'icon' => 'wordpress',
                'category' => 'content',
                'settings_schema' => [
                    [
                        'key' => 'post_id',
                        'label' => __('Post', 'mailerpress'),
                        'type' => 'post_search',
                        'required' => false,
                        'post_type' => 'post',
                        'help' => __('Only trigger for comments on specific posts (leave empty for all posts)', 'mailerpress'),
                        'placeholder' => __('Search and select posts...', 'mailerpress'),
                    ],
                ],
            ]
        );

        // Use transition_post_status instead of publish_post to avoid double-firing
        // This hook only fires once when status transitions to 'publish'
        \add_action('transition_post_status', function ($newStatus, $oldStatus, $post) {
            // Only trigger when transitioning TO 'publish' status
            // Skip if already was 'publish' (to avoid triggering on updates)
            if ($newStatus !== 'publish' || $oldStatus === 'publish') {
                return;
            }

            // Skip auto-saves and revisions
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            // Skip revisions
            if (\wp_is_post_revision($post)) {
                return;
            }

            $postId = is_object($post) ? $post->ID : $post;
            $postObj = \get_post($postId);
            if (!$postObj) {
                return;
            }

            // Get post categories
            $categories = \wp_get_post_categories($postId, ['fields' => 'ids']);

            // Get post meta
            $postMeta = \get_post_meta($postId);
            $metaFlat = [];
            foreach ($postMeta as $key => $values) {
                $metaFlat[$key] = is_array($values) && count($values) === 1 ? $values[0] : $values;
            }

            // Get post excerpt (or generate from content)
            $excerpt = $postObj->post_excerpt;
            if (empty($excerpt)) {
                $excerpt = \wp_trim_words(\wp_strip_all_tags($postObj->post_content), 55, '...');
            }

            // Get post URL
            $postUrl = \get_permalink($postId);

            // Get featured image URL
            $thumbnailUrl = '';
            $thumbnailId = \get_post_thumbnail_id($postId);
            if ($thumbnailId) {
                $thumbnailUrl = \wp_get_attachment_image_url($thumbnailId, 'large')
                    ?: \wp_get_attachment_image_url($thumbnailId, 'full');
            }

            $context = [
                'user_id' => $postObj->post_author,
                'post_id' => $postId,
                'post_type' => $postObj->post_type,
                'post_title' => $postObj->post_title,
                'post_excerpt' => $excerpt,
                'post_url' => $postUrl ?: '',
                'post_thumbnail_url' => $thumbnailUrl ?: '',
                'post_categories' => $categories,
                'post_meta' => $metaFlat,
            ];

            // Manually trigger the workflow execution
            $this->handleTrigger('post_published', [$postId, $postObj], function () use ($context) {
                return $context;
            });
        }, 10, 3);

        // Also register the trigger definition for the UI
        $this->triggerDefinitions['post_published'] = [
            'key' => 'post_published',
            'hook' => 'transition_post_status',
            'type' => 'TRIGGER',
            'label' => __('Post Published', 'mailerpress'),
            'description' => __('Triggered when a post or content is published on your site. Ideal for sending automatic newsletters or notifying subscribers about new content.', 'mailerpress'),
            'icon' => 'wordpress',
            'category' => 'content',
            'settings_schema' => [
                [
                    'key' => 'post_type',
                    'label' => __('Post Type', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => $this->getPostTypesOptions(),
                    'help' => __('Only trigger for specific post types', 'mailerpress'),
                ],
                [
                    'key' => 'post_category',
                    'label' => __('Post Category', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => $this->getCategoriesOptions(),
                    'help' => __('Only trigger when post is in specific category', 'mailerpress'),
                ],
                [
                    'key' => 'post_meta_key',
                    'label' => __('Post Meta Key', 'mailerpress'),
                    'type' => 'text',
                    'required' => false,
                    'help' => __('Optional: Filter by post meta key (e.g., custom_field)', 'mailerpress'),
                ],
                [
                    'key' => 'post_meta_value',
                    'label' => __('Post Meta Value', 'mailerpress'),
                    'type' => 'text',
                    'required' => false,
                    'help' => __('Optional: Filter by post meta value (requires meta key)', 'mailerpress'),
                ],
            ],
            'output_fields' => [
                ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'user'],
                ['key' => 'post_id', 'label' => __('Post ID', 'mailerpress'), 'type' => 'number', 'group' => 'content'],
                ['key' => 'post_type', 'label' => __('Post Type', 'mailerpress'), 'type' => 'string', 'group' => 'content'],
                ['key' => 'post_title', 'label' => __('Post Title', 'mailerpress'), 'type' => 'string', 'group' => 'content'],
                ['key' => 'post_excerpt', 'label' => __('Post Excerpt', 'mailerpress'), 'type' => 'string', 'group' => 'content'],
                ['key' => 'post_url', 'label' => __('Post URL', 'mailerpress'), 'type' => 'string', 'group' => 'content'],
                ['key' => 'post_thumbnail_url', 'label' => __('Featured Image URL', 'mailerpress'), 'type' => 'string', 'group' => 'content'],
            ],
        ];
    }

    public function getRegisteredTriggers(): array
    {
        return $this->registeredTriggers;
    }

    /**
     * Get post types as options array
     */
    private function getPostTypesOptions(): array
    {
        $postTypes = get_post_types(['public' => true, 'show_in_rest' => true], 'objects');
        $options = [
            ['label' => __('All Post Types', 'mailerpress'), 'value' => ''],
        ];

        foreach ($postTypes as $postType) {
            $options[] = [
                'label' => $postType->label,
                'value' => $postType->name,
            ];
        }

        return $options;
    }

    /**
     * Get categories as options array
     */
    private function getCategoriesOptions(): array
    {
        $categories = get_categories(['hide_empty' => false]);
        $options = [
            ['label' => __('All Categories', 'mailerpress'), 'value' => ''],
        ];

        foreach ($categories as $category) {
            $options[] = [
                'label' => $category->name,
                'value' => (string) $category->term_id,
            ];
        }

        return $options;
    }

    /**
     * Get user roles as options array
     */
    private function getRolesOptions(): array
    {
        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new \WP_Roles();
        }

        $options = [
            ['label' => __('All Roles', 'mailerpress'), 'value' => ''],
        ];

        foreach ($wp_roles->get_names() as $roleKey => $roleName) {
            $options[] = [
                'label' => $roleName,
                'value' => $roleKey,
            ];
        }

        return $options;
    }
}
