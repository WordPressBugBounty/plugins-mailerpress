<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;

class TriggerManager
{
    private AutomationRepository $automationRepo;
    private StepRepository $stepRepo;
    private AutomationJobRepository $jobRepo;
    private WorkflowExecutor $executor;
    private array $registeredTriggers = [];
    private array $triggerDefinitions = [];

    public function __construct(?WorkflowExecutor $executor = null)
    {
        $this->automationRepo = new AutomationRepository();
        $this->stepRepo = new StepRepository();
        $this->jobRepo = new AutomationJobRepository();
        // Use provided executor or create a new one (for backward compatibility)
        $this->executor = $executor ?? new WorkflowExecutor();
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
            // Debug: Get all triggers for this automation to see what keys are stored
            $allSteps = $this->stepRepo->findByAutomationId($automation->getId());
            $triggerKeys = [];
            $allStepKeys = [];
            foreach ($allSteps as $step) {
                $allStepKeys[] = "type={$step->getType()}, key={$step->getKey()}";
                if ($step->isTrigger()) {
                    $triggerKeys[] = $step->getKey();
                }
            }

            $trigger = $this->stepRepo->findTriggerByKey($automation->getId(), $triggerKey);

            if (!$trigger) {
                continue;
            }

            $context = $contextBuilder ? $contextBuilder(...$args) : [];

            // For abandoned cart trigger, verify context has cart_hash
            if ($triggerKey === 'woocommerce_abandoned_cart') {
                if (empty($context['cart_hash'])) {
                    continue;
                }
            }

            $userId = $context['user_id'] ?? null;
            if (!$userId) {
                $userId = get_current_user_id();
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
                continue;
            }

            $nextStepId = $trigger->getNextStepId();

            if (empty($nextStepId)) {
                continue;
            }

            $job = $this->jobRepo->create(
                $automation->getId(),
                $userId,
                $nextStepId
            );

            if ($job) {
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

        $userId = $context['user_id'] ?? null;
        $email = $context['email'] ?? null;
        $contactId = $context['contact_id'] ?? null;

        if (!$userId) {
            // Try to get user_id from contact_id
            if ($contactId) {
                $userId = $contactId;
            } else {
                // Try to find contact by email
                if ($email) {
                    $contactsModel = new \MailerPress\Models\Contacts();
                    $contact = $contactsModel->getContactByEmail($email);
                    if ($contact) {
                        $userId = (int) $contact->contact_id;
                    }
                }
            }
        }

        if (!$userId) {
            $userId = get_current_user_id();
        }

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
            return;
        }

        $nextStepId = $trigger->getNextStepId();

        if (empty($nextStepId)) {
            return;
        }

        $job = $this->jobRepo->create($automationId, $userId, $nextStepId);

        if ($job) {
            $this->executor->executeJob($job->getId(), $context);
        }
    }

    private function checkTriggerConditions($trigger, int $userId, array $context): bool
    {
        $settings = $trigger->getSettings() ?? [];
        $triggerKey = $trigger->getKey();

        // Check specific trigger settings
        switch ($triggerKey) {
            case 'woocommerce_order_status_changed':
                $orderStatus = $settings['order_status'] ?? null;
                if ($orderStatus && isset($context['order_status'])) {
                    if ($context['order_status'] !== $orderStatus) {
                        return false;
                    }
                }
                break;

            case 'mailerpress_contact_optin':
                // Check subscription status filter
                $subscriptionStatus = $settings['subscription_status'] ?? null;
                if ($subscriptionStatus && isset($context['subscription_status'])) {
                    if ($context['subscription_status'] !== $subscriptionStatus) {
                        return false;
                    }
                }

                // Check lists filter
                $requiredLists = $settings['lists'] ?? null;
                if ($requiredLists && !empty($requiredLists)) {
                    $contactLists = $context['lists'] ?? [];
                    if (empty($contactLists)) {
                        // Contact has no lists but lists are required
                        return false;
                    }

                    // Convert to arrays if needed
                    if (!is_array($requiredLists)) {
                        $requiredLists = [$requiredLists];
                    }
                    if (!is_array($contactLists)) {
                        $contactLists = [$contactLists];
                    }

                    // Convert to integers for comparison
                    $requiredLists = array_map('intval', $requiredLists);
                    $contactLists = array_map('intval', $contactLists);

                    // Check if contact has at least one of the required lists
                    $hasRequiredList = !empty(array_intersect($requiredLists, $contactLists));
                    if (!$hasRequiredList) {
                        return false;
                    }
                }

                // Check tags filter
                $requiredTags = $settings['tags'] ?? null;
                if ($requiredTags && !empty($requiredTags)) {
                    $contactTags = $context['tags'] ?? [];
                    if (empty($contactTags)) {
                        // Contact has no tags but tags are required
                        return false;
                    }

                    // Convert to arrays if needed
                    if (!is_array($requiredTags)) {
                        $requiredTags = [$requiredTags];
                    }
                    if (!is_array($contactTags)) {
                        $contactTags = [$contactTags];
                    }

                    // Convert to integers for comparison
                    $requiredTags = array_map('intval', $requiredTags);
                    $contactTags = array_map('intval', $contactTags);

                    // Check if contact has at least one of the required tags
                    $hasRequiredTag = !empty(array_intersect($requiredTags, $contactTags));
                    if (!$hasRequiredTag) {
                        return false;
                    }
                }
                break;

            case 'post_published':
                // Check post type filter
                $postType = $settings['post_type'] ?? null;
                if ($postType && isset($context['post_type'])) {
                    if ($context['post_type'] !== $postType) {
                        return false;
                    }
                }

                // Check post category filter
                $categoryId = $settings['post_category'] ?? null;
                if ($categoryId && isset($context['post_categories'])) {
                    $categoryId = (int) $categoryId;
                    if (!in_array($categoryId, $context['post_categories'], true)) {
                        return false;
                    }
                }

                // Check post meta filters
                $metaKey = $settings['post_meta_key'] ?? null;
                $metaValue = $settings['post_meta_value'] ?? null;
                if ($metaKey && isset($context['post_meta'])) {
                    $postMeta = $context['post_meta'];
                    if (!isset($postMeta[$metaKey])) {
                        return false;
                    }
                    if ($metaValue !== null && $metaValue !== '') {
                        $actualValue = $postMeta[$metaKey];
                        // Handle array values
                        if (is_array($actualValue)) {
                            $actualValue = $actualValue[0] ?? '';
                        }
                        if ($actualValue != $metaValue) {
                            return false;
                        }
                    }
                }
                break;

            case 'user_role_changed':
                $roleFilter = $settings['role'] ?? null;
                if ($roleFilter && isset($context['new_role'])) {
                    if ($context['new_role'] !== $roleFilter) {
                        return false;
                    }
                }
                break;

            case 'user_meta_updated':
                $metaKeyFilter = $settings['meta_key'] ?? null;
                if ($metaKeyFilter && isset($context['meta_key'])) {
                    if ($context['meta_key'] !== $metaKeyFilter) {
                        return false;
                    }
                }
                break;

            case 'comment_posted':
                $postIdFilter = $settings['post_id'] ?? null;
                if ($postIdFilter && isset($context['post_id'])) {
                    // Support both single ID and array of IDs
                    $filterIds = is_array($postIdFilter)
                        ? array_map('intval', $postIdFilter)
                        : [(int) $postIdFilter];
                    if (!in_array((int) $context['post_id'], $filterIds, true)) {
                        return false;
                    }
                }
                break;

            case 'contact_subscribed':
            case 'user_login':
            case 'profile_updated':
                $roleFilter = $settings['user_role'] ?? null;
                if ($roleFilter && isset($context['user_role'])) {
                    if ($context['user_role'] !== $roleFilter) {
                        return false;
                    }
                }
                break;

            case 'woocommerce_abandoned_cart':
                // Check minimum cart value filter
                $minimumCartValue = $settings['minimum_cart_value'] ?? null;
                if ($minimumCartValue && isset($context['cart_total'])) {
                    $cartTotal = (float) $context['cart_total'];
                    $minimumValue = (float) $minimumCartValue;
                    if ($cartTotal < $minimumValue) {
                        return false;
                    }
                }

                // Check if email is required
                $requireEmail = $settings['require_email'] ?? false;
                if ($requireEmail && empty($context['customer_email'])) {
                    return false;
                }
                break;

            case 'tag_added':
                // Check if a specific tag filter is configured
                $requiredTagId = $settings['tag_id'] ?? null;
                if ($requiredTagId && isset($context['tag_id'])) {
                    // Convert both to integers for comparison
                    $requiredTagId = (int) $requiredTagId;
                    $contextTagId = (int) $context['tag_id'];
                    if ($contextTagId !== $requiredTagId) {
                        return false;
                    }
                }
                break;

            case 'woocommerce_subscription_status_changed':
                // Check if a specific subscription status filter is configured
                $subscriptionStatus = $settings['subscription_status'] ?? null;
                if ($subscriptionStatus && isset($context['subscription_status'])) {
                    if ($context['subscription_status'] !== $subscriptionStatus) {
                        return false;
                    }
                }
                break;

            case 'contact_custom_field_updated':
                // Check if a specific custom field filter is configured
                $requiredFieldKey = $settings['field_key'] ?? null;
                if ($requiredFieldKey && isset($context['field_key'])) {
                    if ($context['field_key'] !== $requiredFieldKey) {
                        return false;
                    }
                }
                break;

            case 'webhook_received':
                // Check if a specific webhook_id filter is configured
                $requiredWebhookId = $settings['webhook_id'] ?? null;
                if ($requiredWebhookId && isset($context['webhook_id'])) {
                    if ($context['webhook_id'] !== $requiredWebhookId) {
                        return false;
                    }
                }
                break;

            case 'woocommerce_product_purchased':
                // Check if specific products, categories, or tags are configured
                $requiredProducts = $settings['products'] ?? null;
                $requiredCategories = $settings['product_categories'] ?? null;
                $requiredTags = $settings['product_tags'] ?? null;

                // If no filters are configured, allow all products
                if (empty($requiredProducts) && empty($requiredCategories) && empty($requiredTags)) {
                    break;
                }

                // Get purchased products from context
                $purchasedProducts = $context['purchased_products'] ?? [];
                $orderItems = $context['order_items'] ?? [];

                if (empty($purchasedProducts) && empty($orderItems)) {
                    return false;
                }

                // Collect all product IDs from the order
                $orderProductIds = [];
                foreach ($purchasedProducts as $product) {
                    $orderProductIds[] = (int) ($product['product_id'] ?? 0);
                }
                foreach ($orderItems as $item) {
                    $productId = (int) ($item['product_id'] ?? 0);
                    if ($productId && !in_array($productId, $orderProductIds, true)) {
                        $orderProductIds[] = $productId;
                    }
                }

                $orderProductIds = array_filter($orderProductIds);
                if (empty($orderProductIds)) {
                    return false;
                }

                $matchesFilter = false;

                // Check if any product matches the required products
                if (!empty($requiredProducts)) {
                    $requiredProductIds = is_array($requiredProducts)
                        ? array_map('intval', $requiredProducts)
                        : [intval($requiredProducts)];

                    foreach ($orderProductIds as $productId) {
                        if (in_array($productId, $requiredProductIds, true)) {
                            $matchesFilter = true;
                            break;
                        }
                    }
                }

                // Check if any product matches the required categories
                if (!$matchesFilter && !empty($requiredCategories)) {
                    $requiredCategoryIds = is_array($requiredCategories)
                        ? array_map('intval', $requiredCategories)
                        : [intval($requiredCategories)];

                    foreach ($orderProductIds as $productId) {
                        $productCategories = wp_get_post_terms($productId, 'product_cat', ['fields' => 'ids']);
                        if (!is_wp_error($productCategories)) {
                            foreach ($productCategories as $categoryId) {
                                if (in_array($categoryId, $requiredCategoryIds, true)) {
                                    $matchesFilter = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                // Check if any product matches the required tags
                if (!$matchesFilter && !empty($requiredTags)) {
                    $requiredTagIds = is_array($requiredTags)
                        ? array_map('intval', $requiredTags)
                        : [intval($requiredTags)];

                    foreach ($orderProductIds as $productId) {
                        $productTags = wp_get_post_terms($productId, 'product_tag', ['fields' => 'ids']);
                        if (!is_wp_error($productTags)) {
                            foreach ($productTags as $tagId) {
                                if (in_array($tagId, $requiredTagIds, true)) {
                                    $matchesFilter = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                // If filters are configured but no match found, reject
                if ((!empty($requiredProducts) || !empty($requiredCategories) || !empty($requiredTags)) && !$matchesFilter) {
                    return false;
                }
                break;
        }

        // Check general conditions (if any)
        $conditions = $settings['conditions'] ?? null;

        // No conditions means always pass (trigger should proceed)
        if ($conditions === null || $conditions === false || $conditions === '') {
            return true;
        }

        // Empty array or empty condition structure means no condition (always pass)
        if (is_array($conditions)) {
            // Check if it's truly empty (no rules)
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
                    'user_role' => $user && !empty($user->roles) ? $user->roles[0] : '',
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
            ]
        );

        $this->registerTrigger(
            'user_login',
            'wp_login',
            function ($userLogin, $user) {
                return [
                    'user_id' => $user->ID,
                    'user_login' => $userLogin,
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
            ]
        );

        $this->registerTrigger(
            'profile_updated',
            'profile_update',
            function ($userId, $oldUserData) {
                $user = get_userdata($userId);
                return [
                    'user_id' => $userId,
                    'old_user_data' => $oldUserData,
                    'user_role' => $user && !empty($user->roles) ? $user->roles[0] : '',
                ];
            },
            [
                'label' => __('Profile Updated', 'mailerpress'),
                'description' => __('Triggered when a user updates their WordPress profile. Allows you to react to user information changes and synchronize data.', 'mailerpress'),
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
                return [
                    'user_id' => $commentData['user_id'] ?? get_current_user_id(),
                    'comment_id' => $commentId,
                    'comment_approved' => $commentApproved,
                    'post_id' => $comment ? $comment->comment_post_ID : null,
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

        $this->registerTrigger(
            'post_published',
            'publish_post',
            function ($postId, $post) {
                $postObj = get_post($postId);
                if (!$postObj) {
                    return [];
                }

                // Get post categories
                $categories = wp_get_post_categories($postId, ['fields' => 'ids']);

                // Get post meta
                $postMeta = get_post_meta($postId);
                $metaFlat = [];
                foreach ($postMeta as $key => $values) {
                    $metaFlat[$key] = is_array($values) && count($values) === 1 ? $values[0] : $values;
                }

                return [
                    'user_id' => $postObj->post_author,
                    'post_id' => $postId,
                    'post_type' => $postObj->post_type,
                    'post_categories' => $categories,
                    'post_meta' => $metaFlat,
                ];
            },
            [
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
            ]
        );
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
