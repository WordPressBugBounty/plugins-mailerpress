<?php

namespace MailerPress\Api;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\WorkflowSystem;
use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Api\Permissions;
use MailerPress\Models\Contacts;
use MailerPress\Models\CustomFields;

class Workflows
{
    #[Endpoint(
        'workflows/triggers',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations'],
    )]
    public function getTriggers(): \WP_REST_Response
    {
        $manager = WorkflowSystem::getInstance()->getManager();
        $triggers = $manager->getTriggerManager()->getRegisteredTriggers();
        $triggerDefinitions = $manager->getTriggerManager()->getTriggerDefinitions();

        $result = [];

        // First, add all triggers that have definitions (preferred)
        foreach ($triggerDefinitions as $key => $definition) {
            $result[] = $definition;
        }

        // Then, add triggers that are registered but don't have definitions yet
        foreach ($triggers as $key => $def) {
            // Skip if we already added this trigger from definitions
            if (isset($triggerDefinitions[$key])) {
                continue;
            }

            // Fallback for triggers without complete definitions
            // Generate a basic definition from the registered trigger data
            $result[] = [
                'key' => $key,
                'hook' => $def['hook'] ?? '',
                'type' => 'TRIGGER',
                'label' => ucwords(str_replace('_', ' ', $key)),
                'description' => '',
                'icon' => 'admin-generic',
                'category' => 'other',
            ];
        }

        return new \WP_REST_Response($result, 200);
    }

    #[Endpoint(
        'workflows/actions',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations'],
    )]
    public function getActions(): \WP_REST_Response
    {
        $registry = WorkflowSystem::getInstance()->getManager()
            ->getExecutor()->getHandlerRegistry();

        $handlers = $registry->getHandlers();

        $knownKeys = ['send_email', 'send_mail', 'add_tag', 'delay', 'wait', 'condition', 'if'];
        $actions = [];

        foreach ($handlers as $handler) {
            // If handler exposes a definition method, prefer it
            if (method_exists($handler, 'getDefinition')) {
                $def = $handler->getDefinition();
                if ($def) {
                    $actions[] = $def;
                    continue;
                }
            }

            $supported = [];
            foreach ($knownKeys as $key) {
                if ($handler->supports($key)) {
                    $supported[] = $key;
                }
            }

            if (!empty($supported)) {
                $actions[] = [
                    'keys' => $supported,
                ];
            }
        }

        return new \WP_REST_Response($actions, 200);
    }

    #[Endpoint(
        'workflows/conditions',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations'],
    )]
    public function getConditions(): \WP_REST_Response
    {
        $operators = [
            '==',
            '!=',
            '>',
            '<',
            '>=',
            '<=',
            'contains',
            'not_contains',
            'starts_with',
            'ends_with',
            'in',
            'not_in',
            'empty',
            'not_empty',
        ];

        $fields = [
            ['key' => 'user_email', 'label' => 'User Email', 'type' => 'string', 'category' => 'user'],
            ['key' => 'user_login', 'label' => 'User Login', 'type' => 'string', 'category' => 'user'],
            ['key' => 'user_role', 'label' => 'User Role', 'type' => 'string', 'category' => 'user'],
            ['key' => 'user.meta:ANY', 'label' => 'User Meta (key)', 'type' => 'any', 'category' => 'user'],
            ['key' => 'mp_subscription_status', 'label' => 'Subscription Status', 'type' => 'string', 'category' => 'mailerpress', 'valueType' => 'select', 'valueOptions' => [
                ['label' => 'Subscribed', 'value' => 'subscribed'],
                ['label' => 'Unsubscribed', 'value' => 'unsubscribed'],
                ['label' => 'Pending', 'value' => 'pending'],
            ]],
            ['key' => 'mp_has_tag', 'label' => __('Tag', 'mailerpress'), 'type' => 'array|bool', 'category' => 'mailerpress', 'valueType' => 'token', 'description' => 'Check if contact has specific tags', 'operators' => ['==', '!=', 'in', 'not_in']],
            ['key' => 'mp_in_list', 'label' => __('List', 'mailerpress'), 'type' => 'array|bool', 'category' => 'mailerpress', 'valueType' => 'token', 'description' => 'Check if contact is in specific lists', 'operators' => ['==', '!=', 'in', 'not_in']],
            ['key' => 'mp_email_opened', 'label' => __('Email Opened', 'mailerpress'), 'type' => 'boolean', 'category' => 'mailerpress', 'valueType' => 'select', 'description' => 'Check if a contact has opened a specific email campaign'],
            ['key' => 'mp_email_clicked', 'label' => __('Email Clicked', 'mailerpress'), 'type' => 'boolean', 'category' => 'mailerpress', 'valueType' => 'select', 'description' => 'Check if a contact has clicked a link in a specific email campaign'],
        ];

        // Add MailerPress custom fields
        $customFieldsModel = new CustomFields();
        $customFields = $customFieldsModel->all();
        foreach ($customFields as $customField) {
            $fieldType = match ($customField->type) {
                'number' => 'number',
                'date' => 'date',
                'checkbox' => 'boolean',
                default => 'string',
            };

            $fieldConfig = [
                'key' => 'mp_custom_field:' . $customField->field_key,
                'label' => $customField->label . ' (Custom Field)',
                'type' => $fieldType,
                'category' => 'mailerpress',
                'description' => __('MailerPress custom field', 'mailerpress'),
            ];

            // Add value options for select fields
            if ($customField->type === 'select' && !empty($customField->options)) {
                $options = is_array($customField->options) ? $customField->options : [];
                $fieldConfig['valueType'] = 'select';
                $fieldConfig['valueOptions'] = array_map(function ($option) {
                    return ['label' => $option, 'value' => $option];
                }, $options);
            } elseif ($customField->type === 'date') {
                $fieldConfig['valueType'] = 'date';
            } elseif ($customField->type === 'number') {
                $fieldConfig['valueType'] = 'number';
            } elseif ($customField->type === 'checkbox') {
                $fieldConfig['valueType'] = 'select';
                $fieldConfig['valueOptions'] = [
                    ['label' => __('Yes', 'mailerpress'), 'value' => '1'],
                    ['label' => __('No', 'mailerpress'), 'value' => '0'],
                ];
            }

            $fields[] = $fieldConfig;
        }

        if (function_exists('wc_get_customer_total_spent')) {
            $fields = array_merge($fields, [
                ['key' => 'wc_total_spent', 'label' => 'Total Spent', 'type' => 'number', 'category' => 'woocommerce', 'valueType' => 'number', 'description' => 'Total amount spent by the customer'],
                ['key' => 'wc_order_count', 'label' => 'Order Count', 'type' => 'number', 'category' => 'woocommerce', 'valueType' => 'number', 'description' => 'Number of orders placed by the customer'],
                ['key' => 'wc_last_order_status', 'label' => 'Last Order Status', 'type' => 'string', 'category' => 'woocommerce', 'valueType' => 'select'],
                ['key' => 'wc_has_purchased_product', 'label' => 'Has Purchased Product', 'type' => 'array|bool', 'category' => 'woocommerce', 'valueType' => 'token', 'description' => 'Check if customer has purchased specific products'],
                ['key' => 'wc_purchased_in_category', 'label' => 'Purchased in Category', 'type' => 'boolean', 'category' => 'woocommerce', 'valueType' => 'select', 'description' => 'Check if customer has purchased a product in a specific category'],
                ['key' => 'wc_order_created', 'label' => 'Order Created', 'type' => 'boolean', 'category' => 'woocommerce', 'valueType' => 'select', 'description' => 'Check if an order_id exists in the workflow context (useful for abandoned cart recovery)'],
                ['key' => 'wc_has_reviewed_order', 'label' => 'Has Reviewed Order', 'type' => 'boolean', 'category' => 'woocommerce', 'valueType' => 'select', 'description' => 'Check if customer has left a product review for products in the current order'],
                ['key' => 'order_total', 'label' => 'Order Total', 'type' => 'number', 'category' => 'woocommerce', 'valueType' => 'number', 'description' => 'Total amount of the current order from workflow context'],
            ]);
        }

        if (function_exists('apply_filters')) {
            $fields = apply_filters('mailerpress/condition/available_fields', $fields);
        }

        return new \WP_REST_Response([
            'operators' => $operators,
            'fields' => $fields,
        ], 200);
    }

    #[Endpoint(
        'workflows/woocommerce/categories',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getWooCommerceCategories(): \WP_REST_Response
    {
        if (!function_exists('get_terms')) {
            return new \WP_REST_Response([], 200);
        }

        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($categories)) {
            return new \WP_REST_Response([], 200);
        }

        $formatted = array_map(function ($category) {
            return [
                'label' => $category->name,
                'value' => (string) $category->term_id,
            ];
        }, $categories);

        return new \WP_REST_Response($formatted, 200);
    }

    #[Endpoint(
        'workflows/woocommerce/products',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getWooCommerceProducts(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!function_exists('wc_get_products')) {
            return new \WP_REST_Response([], 200);
        }

        $search = $request->get_param('search') ?? '';
        $per_page = (int) ($request->get_param('per_page') ?? 50);

        $args = [
            'limit' => $per_page,
            'status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if (!empty($search)) {
            $args['search'] = sanitize_text_field($search);
        }

        $products = wc_get_products($args);

        if (is_wp_error($products)) {
            return new \WP_REST_Response([], 200);
        }

        $formatted = array_map(function ($product) {
            return [
                'label' => $product->get_name() . ' (#' . $product->get_id() . ')',
                'value' => (string) $product->get_id(),
            ];
        }, $products);

        return new \WP_REST_Response($formatted, 200);
    }

    #[Endpoint(
        'workflows/woocommerce/product-tags',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getWooCommerceProductTags(): \WP_REST_Response
    {
        if (!function_exists('get_terms')) {
            return new \WP_REST_Response([], 200);
        }

        $tags = get_terms([
            'taxonomy' => 'product_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($tags)) {
            return new \WP_REST_Response([], 200);
        }

        $formatted = array_map(function ($tag) {
            return [
                'label' => $tag->name,
                'value' => (string) $tag->term_id,
            ];
        }, $tags);

        return new \WP_REST_Response($formatted, 200);
    }

    #[Endpoint(
        'workflows',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function listWorkflows(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        // Get pagination params - use get_param consistently
        $search = $request->get_param('search');
        $per_page = (int) ($request->get_param('perPages') ?? 20);
        $page = (int) ($request->get_param('paged') ?? 1);
        $offset = ($page - 1) * $per_page;

        // Get status filter
        $status = $request->get_param('status');

        // Handle legacy trigger_type parameter
        // The trigger_type column doesn't exist in the automations table
        // Triggers are now stored in mailerpress_automations_steps with type='TRIGGER' and key='trigger_key'
        $triggerType = $request->get_param('trigger_type');

        // Get sorting params
        $allowed_orderby = ['name', 'id', 'created_at', 'updated_at'];
        $allowed_order = ['ASC', 'DESC'];
        $orderby_param = $request->get_param('orderby');
        $order_param = strtoupper($request->get_param('order') ?? 'DESC');
        $orderby = in_array($orderby_param, $allowed_orderby, true) ? $orderby_param : 'updated_at';
        $order = in_array($order_param, $allowed_order, true) ? $order_param : 'DESC';

        $table = $wpdb->prefix . 'mailerpress_automations';
        $stepsTable = $wpdb->prefix . 'mailerpress_automations_steps';

        // Select only needed columns for better performance
        $selectColumns = 'a.id, a.name, a.status, a.author, a.run_once_per_subscriber, a.created_at, a.updated_at';

        // Build WHERE clause
        $where = [];
        $params = [];
        $joins = [];

        if (!empty($search)) {
            $where[] = 'a.name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!empty($status)) {
            $where[] = 'a.status = %s';
            $params[] = $status;
        }

        // Use INNER JOIN instead of EXISTS for better performance when filtering by trigger_type
        if (!empty($triggerType)) {
            $joins[] = "INNER JOIN {$stepsTable} s ON s.automation_id = a.id AND s.type = 'TRIGGER' AND s.key = %s";
            $params[] = $triggerType;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $joinClause = !empty($joins) ? implode(' ', $joins) : '';
        // Use esc_sql() in addition to whitelist validation for extra security
        $orderBy = sprintf('ORDER BY a.%s %s', esc_sql($orderby), esc_sql($order));

        // Build base query parts
        $fromClause = "FROM {$table} a";
        $queryBase = "{$fromClause} {$joinClause} {$whereClause}";

        // Get total count - use same structure as main query for consistency
        $countQuery = "SELECT COUNT(DISTINCT a.id) {$queryBase}";
        $total_count = (int) $wpdb->get_var($wpdb->prepare($countQuery, $params));

        $total_pages = ceil($total_count / $per_page);

        // Fetch workflows - select only needed columns
        // Use DISTINCT to avoid duplicates when JOIN is used (e.g., with trigger_type filter)
        $query_params = $params;
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $query = "SELECT DISTINCT {$selectColumns} {$queryBase} {$orderBy} LIMIT %d OFFSET %d";
        $results = $wpdb->get_results($wpdb->prepare($query, $query_params), ARRAY_A);

        // Get all automation IDs to fetch A/B test info and stats
        $allAutomationIds = array_map(function ($row) {
            return (int) $row['id'];
        }, $results);

        // Fetch A/B test information for all automations
        $abTestMap = [];
        if (!empty($allAutomationIds)) {
            $abTestTable = $wpdb->prefix . 'mailerpress_ab_tests';
            $idsString = implode(',', array_map('intval', $allAutomationIds));

            // Get A/B test info: count, status, winner
            $abTestQuery = "SELECT 
                    automation_id,
                    COUNT(*) as total_tests,
                    SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_tests,
                    SUM(CASE WHEN status = 'completed' AND winner IS NOT NULL THEN 1 ELSE 0 END) as completed_tests,
                    MAX(CASE WHEN status = 'completed' AND winner IS NOT NULL THEN winner ELSE NULL END) as latest_winner,
                    MAX(CASE WHEN status = 'completed' AND winner IS NOT NULL THEN completed_at ELSE NULL END) as latest_completed_at
                 FROM {$abTestTable}
                 WHERE automation_id IN ({$idsString})
                 GROUP BY automation_id";

            $abTestResults = $wpdb->get_results($abTestQuery, ARRAY_A);

            foreach ($abTestResults as $abTest) {
                $abTestMap[(int) $abTest['automation_id']] = [
                    'has_ab_tests' => true,
                    'total_tests' => (int) $abTest['total_tests'],
                    'running_tests' => (int) $abTest['running_tests'],
                    'completed_tests' => (int) $abTest['completed_tests'],
                    'latest_winner' => $abTest['latest_winner'],
                    'latest_completed_at' => $abTest['latest_completed_at'],
                ];
            }
        }

        // Get enabled automation IDs to fetch stats
        $enabledAutomationIds = [];
        foreach ($results as $row) {
            if ($row['status'] === 'ENABLED') {
                $enabledAutomationIds[] = (int) $row['id'];
            }
        }

        // Fetch stats for enabled automations in batch
        $statsMap = [];
        if (!empty($enabledAutomationIds)) {
            $jobsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_JOBS);
            $ids = array_map('intval', $enabledAutomationIds);
            $idsString = implode(',', $ids);

            // Get aggregated stats for all enabled automations
            // IDs are already sanitized with intval, so safe to use directly
            $statsQuery = "SELECT 
                    automation_id,
                    COUNT(*) as total_jobs,
                    SUM(CASE WHEN status IN ('ACTIVE', 'PROCESSING', 'WAITING') THEN 1 ELSE 0 END) as active_jobs,
                    SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_jobs,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_jobs,
                    COUNT(DISTINCT user_id) as unique_users,
                    MAX(created_at) as last_execution
                 FROM {$jobsTable}
                 WHERE automation_id IN ({$idsString})
                 GROUP BY automation_id";

            $statsResults = $wpdb->get_results($statsQuery, ARRAY_A);

            foreach ($statsResults as $stat) {
                $statsMap[(int) $stat['automation_id']] = [
                    'total_jobs' => (int) $stat['total_jobs'],
                    'active_jobs' => (int) $stat['active_jobs'],
                    'completed_jobs' => (int) $stat['completed_jobs'],
                    'failed_jobs' => (int) $stat['failed_jobs'],
                    'unique_users' => (int) $stat['unique_users'],
                    'last_execution' => $stat['last_execution'],
                ];
            }
        }

        // Format results
        $posts = array_map(function ($row) use ($statsMap, $abTestMap) {
            $automationId = (int) $row['id'];
            $result = [
                'id' => $automationId,
                'automation_id' => $automationId,
                'name' => $row['name'],
                'status' => $row['status'],
                'author' => (int) $row['author'],
                'run_once_per_subscriber' => isset($row['run_once_per_subscriber']) ? (bool) $row['run_once_per_subscriber'] : false,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'canEdit' => true,
            ];

            // Add stats only for ENABLED workflows
            if ($row['status'] === 'ENABLED' && isset($statsMap[$automationId])) {
                $result['stats'] = $statsMap[$automationId];
            } else {
                $result['stats'] = null;
            }

            // Add A/B test information
            if (isset($abTestMap[$automationId])) {
                $result['ab_tests'] = $abTestMap[$automationId];
            } else {
                $result['ab_tests'] = [
                    'has_ab_tests' => false,
                    'total_tests' => 0,
                    'running_tests' => 0,
                    'completed_tests' => 0,
                    'latest_winner' => null,
                    'latest_completed_at' => null,
                ];
            }

            return $result;
        }, $results);

        return new \WP_REST_Response([
            'posts' => $posts,
            'pages' => $total_pages,
            'count' => $total_count,
        ], 200);
    }

    #[Endpoint(
        'workflows',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function createWorkflow(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $automationRepo = new AutomationRepository();
        $stepRepo = new StepRepository();

        $body = $request->get_json_params();

        if (!isset($body['automation']) || !isset($body['nodes'])) {
            return new \WP_Error(
                'invalid_data',
                'Missing automation or nodes data',
                ['status' => 400]
            );
        }

        // Check if template requires Pro version
        $isTemplate = isset($body['template_id']);
        $templateRequiresPro = false;

        if ($isTemplate) {
            $templateId = $body['template_id'];
            $templateRequiresPro = $this->templateRequiresPro($templateId);

            if ($templateRequiresPro && !$this->isProActive()) {
                return new \WP_Error(
                    'pro_required',
                    __('This template requires MailerPress Pro. Please upgrade to use this template.', 'mailerpress'),
                    ['status' => 403]
                );
            }
        }

        $automationData = $body['automation'];
        $nodes = $body['nodes'];

        // For non-template workflows, require Pro to create (even empty workflows)
        // Templates are allowed because they're pre-approved configurations
        if (!$isTemplate && !$this->isProActive()) {
            return new \WP_Error(
                'pro_required',
                __('Creating workflows requires MailerPress Pro. Please upgrade to continue, or start with a free template.', 'mailerpress'),
                ['status' => 403]
            );
        }

        // Count non-trigger nodes (steps that require Pro)
        $nonTriggerNodes = array_filter($nodes, function ($node) {
            return ($node['type'] ?? '') !== 'TRIGGER';
        });

        // If workflow has steps (not just a trigger), require Pro
        // Exception: Free templates are allowed to have steps (they're already validated above)
        if (count($nonTriggerNodes) > 0 && !$this->isProActive() && !$isTemplate) {
            return new \WP_Error(
                'pro_required',
                __('Adding steps to workflows requires MailerPress Pro. Please upgrade to continue.', 'mailerpress'),
                ['status' => 403]
            );
        }

        // Create automation
        $automationId = $automationRepo->create([
            'name' => $automationData['name'] ?? 'New Workflow',
            'status' => $automationData['status'] ?? 'DRAFT',
            'run_once_per_subscriber' => $automationData['run_once_per_subscriber'] ?? false,
        ]);

        if (!$automationId) {
            return new \WP_Error(
                'create_failed',
                'Failed to create automation',
                ['status' => 500]
            );
        }

        // Create steps
        $createdNodes = [];
        foreach ($nodes as $node) {
            // Extract position from node or settings
            $position = $node['position'] ?? ['x' => 0, 'y' => 0];
            $settings = $node['settings'] ?? [];

            // Store position in settings for persistence
            $settings['_position'] = $position;

            $stepId = $stepRepo->create([
                'automation_id' => $automationId,
                'step_id' => $node['step_id'] ?? $node['id'],
                'type' => $node['type'],
                'key' => $node['key'],
                'settings' => $settings,
                'next_step_id' => $node['next_step_id'] ?? null,
                'alternative_step_id' => $node['alternative_step_id'] ?? null,
            ]);

            $createdNodes[] = [
                'id' => $node['id'] ?? $node['step_id'],
                'step_id' => $node['step_id'] ?? $node['id'],
                'type' => $node['type'],
                'key' => $node['key'],
                'settings' => $settings,
                'next_step_id' => $node['next_step_id'] ?? null,
                'alternative_step_id' => $node['alternative_step_id'] ?? null,
                'position' => $position,
            ];
        }

        // Get created automation
        $automation = $automationRepo->find($automationId);

        return new \WP_REST_Response([
            'automation' => $automation ? $automation->toArray() : null,
            'nodes' => $createdNodes,
        ], 201);
    }

    /**
     * Check if a template requires Pro version
     * 
     * @param string $templateId Template ID
     * @return bool True if template requires Pro
     */
    private function templateRequiresPro(string $templateId): bool
    {
        // Templates that require Pro version
        $proTemplates = [
            'cart-abandonment',
            'post-purchase-followup',
            'email-sequence',
            'tag-and-email',
        ];

        return in_array($templateId, $proTemplates, true);
    }

    /**
     * Check if MailerPress Pro is active
     * 
     * @return bool True if Pro is active
     */
    private function isProActive(): bool
    {
        return function_exists('is_plugin_active')
            && is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    #[Endpoint(
        'workflows/ab-tests/(?P<automationId>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getABTestResults(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $automationId = (int) $request->get_param('automationId');

        if (!$automationId) {
            return new \WP_Error('invalid_automation', 'Automation ID is required', ['status' => 400]);
        }

        $testTable = $wpdb->prefix . 'mailerpress_ab_tests';
        $participantsTable = $wpdb->prefix . 'mailerpress_ab_test_participants';

        // Récupérer tous les tests A/B pour cette automation
        $tests = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$testTable} 
            WHERE automation_id = %d 
            ORDER BY created_at DESC",
            $automationId
        ), ARRAY_A);

        $results = [];

        foreach ($tests as $test) {
            $testId = (int) $test['id'];

            // Récupérer les statistiques des participants
            $stats = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    test_group,
                    COUNT(*) as total,
                    SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
                    SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked
                FROM {$participantsTable}
                WHERE test_id = %d
                GROUP BY test_group",
                $testId
            ), ARRAY_A);

            $metrics = [
                'A' => [
                    'total' => 0,
                    'opened' => 0,
                    'clicked' => 0,
                    'open_rate' => 0,
                    'click_rate' => 0,
                    'conversion_rate' => 0,
                ],
                'B' => [
                    'total' => 0,
                    'opened' => 0,
                    'clicked' => 0,
                    'open_rate' => 0,
                    'click_rate' => 0,
                    'conversion_rate' => 0,
                ],
            ];

            foreach ($stats as $stat) {
                $group = $stat['test_group'];
                $total = (int) $stat['total'];
                $opened = (int) $stat['opened'];
                $clicked = (int) $stat['clicked'];

                $metrics[$group]['total'] = $total;
                $metrics[$group]['opened'] = $opened;
                $metrics[$group]['clicked'] = $clicked;
                $metrics[$group]['open_rate'] = $total > 0 ? round(($opened / $total) * 100, 2) : 0;
                $metrics[$group]['click_rate'] = $total > 0 ? round(($clicked / $total) * 100, 2) : 0;
                $metrics[$group]['conversion_rate'] = $opened > 0 ? round(($clicked / $opened) * 100, 2) : 0;
            }

            // Récupérer les sujets
            $versionASubject = $test['version_a_subject'] ?? '';
            $versionBSubject = $test['version_b_subject'] ?? '';

            // Si les sujets sont vides, essayer de récupérer depuis les campagnes
            if (empty($versionASubject)) {
                $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
                $campaignA = $wpdb->get_var($wpdb->prepare(
                    "SELECT subject FROM {$campaignsTable} WHERE campaign_id = %d",
                    (int) $test['version_a_template_id']
                ));
                $versionASubject = $campaignA ?: '';
            }

            if (empty($versionBSubject)) {
                $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
                $campaignB = $wpdb->get_var($wpdb->prepare(
                    "SELECT subject FROM {$campaignsTable} WHERE campaign_id = %d",
                    (int) $test['version_b_template_id']
                ));
                $versionBSubject = $campaignB ?: '';
            }

            $results[] = [
                'id' => $testId,
                'test_name' => $test['test_name'] ?? '',
                'step_id' => $test['step_id'] ?? '',
                'status' => $test['status'] ?? 'running',
                'winner' => $test['winner'] ?? null,
                'winner_metric' => $test['winner_metric'] ? (float) $test['winner_metric'] : null,
                'winning_criteria' => $test['winning_criteria'] ?? 'open_rate',
                'created_at' => $test['created_at'] ?? '',
                'completed_at' => $test['completed_at'] ?? null,
                'version_a' => [
                    'template_id' => (int) $test['version_a_template_id'],
                    'subject' => $versionASubject,
                    'metrics' => $metrics['A'],
                ],
                'version_b' => [
                    'template_id' => (int) $test['version_b_template_id'],
                    'subject' => $versionBSubject,
                    'metrics' => $metrics['B'],
                ],
            ];
        }

        return new \WP_REST_Response($results, 200);
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function updateWorkflow(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $automationRepo = new AutomationRepository();
        $stepRepo = new StepRepository();

        $automationId = (int) $request->get_param('id');
        $body = $request->get_json_params();

        if (!isset($body['automation']) || !isset($body['nodes'])) {
            return new \WP_Error(
                'invalid_data',
                'Missing automation or nodes data',
                ['status' => 400]
            );
        }

        // Check if automation exists
        $existingAutomation = $automationRepo->find($automationId);
        if (!$existingAutomation) {
            return new \WP_Error(
                'not_found',
                'Automation not found',
                ['status' => 404]
            );
        }

        $automationData = $body['automation'];
        $nodes = $body['nodes'];

        // Count non-trigger nodes (steps that require Pro)
        $nonTriggerNodes = array_filter($nodes, function ($node) {
            return ($node['type'] ?? '') !== 'TRIGGER';
        });

        // If workflow has steps (not just a trigger), require Pro
        if (count($nonTriggerNodes) > 0 && !$this->isProActive()) {
            return new \WP_Error(
                'pro_required',
                __('Adding steps to workflows requires MailerPress Pro. Please upgrade to continue.', 'mailerpress'),
                ['status' => 403]
            );
        }

        // Update automation
        $automationRepo->update($automationId, [
            'name' => $automationData['name'] ?? null,
            'status' => $automationData['status'] ?? null,
            'run_once_per_subscriber' => $automationData['run_once_per_subscriber'] ?? null,
        ]);

        // Get existing steps before deletion to identify deleted send_email nodes
        $existingSteps = $stepRepo->findByAutomationId($automationId);
        
        // Get step IDs from new nodes to compare
        $newStepIds = array_map(function ($node) {
            return $node['step_id'] ?? $node['id'];
        }, $nodes);
        
        // Find deleted send_email steps
        $deletedStepIds = [];
        foreach ($existingSteps as $existingStep) {
            $stepId = $existingStep->getStepId();
            
            // Check if this step is being deleted (not in new nodes)
            if (!in_array($stepId, $newStepIds, true)) {
                // Check if it's a send_email action
                if ($existingStep->getKey() === 'send_email' || $existingStep->getKey() === 'send_mail') {
                    $deletedStepIds[] = $stepId;
                }
            }
        }
        
        // Delete associated campaigns if any
        if (!empty($deletedStepIds)) {
            global $wpdb;
            $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
            $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
            
            // Build placeholders for step_id IN clause
            $stepPlaceholders = implode(',', array_fill(0, count($deletedStepIds), '%s'));
            
            // Find campaigns linked to deleted step_ids
            // Only delete campaigns of type 'automation' to avoid deleting regular campaigns
            $automationCampaigns = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT campaign_id FROM {$campaignsTable} 
                     WHERE step_id IN ({$stepPlaceholders}) 
                     AND campaign_type = 'automation'",
                    ...$deletedStepIds
                )
            );
            
            if (!empty($automationCampaigns)) {
                // Delete batches first
                $batchPlaceholders = implode(',', array_fill(0, count($automationCampaigns), '%d'));
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$batchTable} WHERE campaign_id IN ({$batchPlaceholders})",
                        ...$automationCampaigns
                    )
                );
                
                // Delete campaigns
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$campaignsTable} WHERE campaign_id IN ({$batchPlaceholders})",
                        ...$automationCampaigns
                    )
                );
            }
        }

        // Delete existing steps
        $stepRepo->deleteByAutomationId($automationId);

        // Create new steps
        $createdNodes = [];
        foreach ($nodes as $node) {
            // Extract position from node or settings
            $position = $node['position'] ?? ['x' => 0, 'y' => 0];
            $settings = $node['settings'] ?? [];

            // Store position in settings for persistence
            $settings['_position'] = $position;

            $stepId = $stepRepo->create([
                'automation_id' => $automationId,
                'step_id' => $node['step_id'] ?? $node['id'],
                'type' => $node['type'],
                'key' => $node['key'],
                'settings' => $settings,
                'next_step_id' => $node['next_step_id'] ?? null,
                'alternative_step_id' => $node['alternative_step_id'] ?? null,
            ]);

            $createdNodes[] = [
                'id' => $node['id'] ?? $node['step_id'],
                'step_id' => $node['step_id'] ?? $node['id'],
                'type' => $node['type'],
                'key' => $node['key'],
                'settings' => $settings,
                'next_step_id' => $node['next_step_id'] ?? null,
                'alternative_step_id' => $node['alternative_step_id'] ?? null,
                'position' => $position,
            ];
        }

        // Get updated automation
        $automation = $automationRepo->find($automationId);

        // Trigger hook for custom triggers to re-register hooks
        do_action('mailerpress_workflow_updated', $automationId);

        return new \WP_REST_Response([
            'automation' => $automation ? $automation->toArray() : null,
            'nodes' => $createdNodes,
        ], 200);
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getWorkflow(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $automationRepo = new AutomationRepository();
        $stepRepo = new StepRepository();

        $automationId = (int) $request->get_param('id');

        // Get automation
        $automation = $automationRepo->find($automationId);
        if (!$automation) {
            return new \WP_Error(
                'not_found',
                'Automation not found',
                ['status' => 404]
            );
        }

        // Get steps
        $steps = $stepRepo->findByAutomationId($automationId);
        $nodes = array_map(function ($step) {
            $settings = $step->getSettings() ?? [];

            // Extract position from settings if available
            $position = $settings['_position'] ?? ['x' => 0, 'y' => 0];

            // Remove _position from settings to keep it clean for the frontend
            unset($settings['_position']);

            return [
                'id' => $step->getStepId(),
                'step_id' => $step->getStepId(),
                'type' => $step->getType(),
                'key' => $step->getKey(),
                'settings' => $settings,
                'next_step_id' => $step->getNextStepId(),
                'alternative_step_id' => $step->getAlternativeStepId(),
                'position' => $position,
            ];
        }, $steps);

        return new \WP_REST_Response([
            'automation' => $automation->toArray(),
            'nodes' => $nodes,
        ], 200);
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function deleteWorkflow(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $automationRepo = new AutomationRepository();
        $stepRepo = new StepRepository();

        $automationId = (int) $request->get_param('id');

        // Check if automation exists
        $existingAutomation = $automationRepo->find($automationId);
        if (!$existingAutomation) {
            return new \WP_Error(
                'not_found',
                'Automation not found',
                ['status' => 404]
            );
        }

        // Delete all steps first
        $stepRepo->deleteByAutomationId($automationId);

        // Delete AB tests related to this automation
        $this->deleteABTestsByAutomationId($automationId);

        // Delete automation
        $deleted = $automationRepo->delete($automationId);

        if (!$deleted) {
            return new \WP_Error(
                'delete_failed',
                'Failed to delete automation',
                ['status' => 500]
            );
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Workflow deleted successfully', 'mailerpress'),
        ], 200);
    }

    #[Endpoint(
        'workflows/all',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function deleteAll(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $automationRepo = new AutomationRepository();
        $stepRepo = new StepRepository();

        // Get all automation IDs
        $automations = $automationRepo->findAll();

        if (empty($automations)) {
            return new \WP_REST_Response([
                'success' => true,
                'message' => __('No workflows to delete.', 'mailerpress'),
                'deleted' => 0,
            ], 200);
        }

        $deletedCount = 0;
        $errors = [];

        // Delete all steps first, then automations
        foreach ($automations as $automation) {
            try {
                $automationId = $automation->getId();

                if (!$automationId) {
                    continue;
                }

                // Delete all steps for this automation
                $stepRepo->deleteByAutomationId($automationId);

                // Delete AB tests related to this automation
                $this->deleteABTestsByAutomationId($automationId);

                // Delete automation
                $deleted = $automationRepo->delete($automationId);

                if ($deleted) {
                    $deletedCount++;
                } else {
                    $errors[] = sprintf(__('Failed to delete workflow ID: %d', 'mailerpress'), $automationId);
                }
            } catch (\Exception $e) {
                $automationId = $automation->getId();
                $errors[] = sprintf(__('Error deleting workflow ID %d: %s', 'mailerpress'), $automationId, $e->getMessage());
            }
        }

        if (!empty($errors)) {
            return new \WP_Error(
                'partial_delete_failed',
                __('Some workflows could not be deleted.', 'mailerpress'),
                [
                    'status' => 500,
                    'errors' => $errors,
                    'deleted_count' => $deletedCount,
                ]
            );
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => sprintf(__('All workflows (%d) have been deleted successfully.', 'mailerpress'), $deletedCount),
            'deleted' => $deletedCount,
        ], 200);
    }

    #[Endpoint(
        'workflows/status',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function updateStatus(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $automationRepo = new AutomationRepository();

        $ids = $request->get_param('ids');
        $status = sanitize_text_field($request->get_param('status'));

        // Validate status
        $allowed_statuses = ['DRAFT', 'ENABLED', 'DISABLED'];
        if (empty($status) || !in_array($status, $allowed_statuses, true)) {
            return new \WP_Error(
                'invalid_status',
                __('Invalid or empty workflow status. Allowed values: DRAFT, ENABLED, DISABLED', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Handle "all" case
        if ($ids === 'all' || $ids === null) {
            $automations = $automationRepo->findAll();

            if (empty($automations)) {
                return new \WP_REST_Response([
                    'success' => true,
                    'message' => __('No workflows to update.', 'mailerpress'),
                    'updated' => 0,
                ], 200);
            }

            $updatedCount = 0;
            foreach ($automations as $automation) {
                $automationId = $automation->getId();
                if ($automationId) {
                    $updated = $automationRepo->update($automationId, ['status' => $status]);
                    if ($updated) {
                        $updatedCount++;
                        // Trigger hook for custom triggers to re-register hooks
                        do_action('mailerpress_workflow_status_changed', $automationId, $status);
                    }
                }
            }

            return new \WP_REST_Response([
                'success' => true,
                'message' => sprintf(__('All workflows (%d) status updated successfully.', 'mailerpress'), $updatedCount),
                'updated' => $updatedCount,
                'new_status' => $status,
            ], 200);
        }

        // Handle specific IDs
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            return new \WP_Error(
                'missing_ids',
                __('No workflow ID(s) provided.', 'mailerpress'),
                ['status' => 400]
            );
        }

        $updatedCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            $automation = $automationRepo->find($id);

            if (!$automation) {
                $errors[] = sprintf(__('Workflow ID %d not found.', 'mailerpress'), $id);
                continue;
            }

            $updated = $automationRepo->update($id, ['status' => $status]);

            if ($updated) {
                $updatedCount++;
                // Trigger hook for custom triggers to re-register hooks
                do_action('mailerpress_workflow_status_changed', $id, $status);
            } else {
                $errors[] = sprintf(__('Failed to update workflow ID: %d', 'mailerpress'), $id);
            }
        }

        if (!empty($errors) && $updatedCount === 0) {
            return new \WP_Error(
                'update_failed',
                __('Failed to update workflows.', 'mailerpress'),
                [
                    'status' => 500,
                    'errors' => $errors,
                ]
            );
        }

        $message = $updatedCount > 0
            ? sprintf(__('%d workflow(s) status updated successfully.', 'mailerpress'), $updatedCount)
            : __('No workflows were updated.', 'mailerpress');

        return new \WP_REST_Response([
            'success' => true,
            'message' => $message,
            'updated' => $updatedCount,
            'new_status' => $status,
            'errors' => $errors,
        ], 200);
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)/stats',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getWorkflowStats(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $automationId = (int) $request->get_param('id');

        // Check if automation exists
        $automationRepo = new AutomationRepository();
        $automation = $automationRepo->find($automationId);
        if (!$automation) {
            return new \WP_Error(
                'not_found',
                'Automation not found',
                ['status' => 404]
            );
        }

        $jobsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_JOBS);
        $logsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_LOG);

        // Stats des jobs par statut
        $jobsStats = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count 
             FROM {$jobsTable} 
             WHERE automation_id = %d 
             GROUP BY status",
            $automationId
        ), ARRAY_A);

        $jobsByStatus = [];
        $totalJobs = 0;
        foreach ($jobsStats as $stat) {
            $jobsByStatus[$stat['status']] = (int) $stat['count'];
            $totalJobs += (int) $stat['count'];
        }

        // Stats des logs par statut
        $logsStats = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count 
             FROM {$logsTable} 
             WHERE automation_id = %d 
             GROUP BY status",
            $automationId
        ), ARRAY_A);

        $logsByStatus = [];
        $totalLogs = 0;
        foreach ($logsStats as $stat) {
            $logsByStatus[$stat['status']] = (int) $stat['count'];
            $totalLogs += (int) $stat['count'];
        }

        // Nombre d'utilisateurs uniques
        $uniqueUsers = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) 
             FROM {$jobsTable} 
             WHERE automation_id = %d",
            $automationId
        ));

        // Dernière exécution (dernier job créé)
        $lastExecution = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(created_at) 
             FROM {$jobsTable} 
             WHERE automation_id = %d",
            $automationId
        ));

        // Nombre de jobs actifs (en cours)
        $activeJobs = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$jobsTable} 
             WHERE automation_id = %d 
             AND status IN ('ACTIVE', 'PROCESSING', 'WAITING')",
            $automationId
        ));

        // Nombre de jobs complétés
        $completedJobs = (int) ($jobsByStatus['COMPLETED'] ?? 0);

        // Nombre de jobs échoués
        $failedJobs = (int) ($jobsByStatus['FAILED'] ?? 0);

        return new \WP_REST_Response([
            'total_jobs' => $totalJobs,
            'active_jobs' => $activeJobs,
            'completed_jobs' => $completedJobs,
            'failed_jobs' => $failedJobs,
            'jobs_by_status' => $jobsByStatus,
            'total_logs' => $totalLogs,
            'logs_by_status' => $logsByStatus,
            'unique_users' => $uniqueUsers,
            'last_execution' => $lastExecution,
        ], 200);
    }

    #[Endpoint(
        'workflows/dashboard',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getWorkflowDashboard(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $intervalParam = $request->get_param('interval');
        $isToday = ($intervalParam === 'today');
        $interval = $isToday ? 1 : ((int) $intervalParam ?: 30);

        $automationsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS);
        $jobsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_JOBS);
        $logsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_LOG);

        // Statistiques globales
        $totalWorkflows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$automationsTable}");
        $enabledWorkflows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$automationsTable} WHERE status = 'ENABLED'");
        $draftWorkflows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$automationsTable} WHERE status = 'DRAFT'");

        // Statistiques des jobs (toutes périodes)
        $totalJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable}");
        $activeJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status IN ('ACTIVE', 'PROCESSING', 'WAITING')");
        $completedJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'COMPLETED'");
        $failedJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'FAILED'");
        $uniqueUsers = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$jobsTable}");

        // Construire la condition WHERE selon la période
        if ($isToday) {
            $dateCondition = "DATE(created_at) = CURDATE()";
        } else {
            $dateCondition = $wpdb->prepare("created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $interval);
        }

        // Statistiques sur la période sélectionnée
        $intervalJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE {$dateCondition}");
        $intervalCompleted = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'COMPLETED' AND {$dateCondition}");
        $intervalFailed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'FAILED' AND {$dateCondition}");
        $intervalUniqueUsers = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$jobsTable} WHERE {$dateCondition}");

        // Calcul des taux
        $successRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 2) : 0;
        $failureRate = $totalJobs > 0 ? round(($failedJobs / $totalJobs) * 100, 2) : 0;
        $intervalSuccessRate = $intervalJobs > 0 ? round(($intervalCompleted / $intervalJobs) * 100, 2) : 0;

        // Top workflows par nombre de jobs complétés
        if ($isToday) {
            $topWorkflowsQuery = "SELECT 
                a.id,
                a.name,
                a.status,
                COUNT(j.id) as total_jobs,
                SUM(CASE WHEN j.status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN j.status = 'FAILED' THEN 1 ELSE 0 END) as failed_jobs,
                COUNT(DISTINCT j.user_id) as unique_users,
                MAX(j.created_at) as last_execution
            FROM {$automationsTable} a
            LEFT JOIN {$jobsTable} j ON a.id = j.automation_id 
                AND DATE(j.created_at) = CURDATE()
            GROUP BY a.id, a.name, a.status
            HAVING total_jobs > 0
            ORDER BY completed_jobs DESC, total_jobs DESC
            LIMIT 10";
        } else {
            $topWorkflowsQuery = $wpdb->prepare(
                "SELECT 
                    a.id,
                    a.name,
                    a.status,
                    COUNT(j.id) as total_jobs,
                    SUM(CASE WHEN j.status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_jobs,
                    SUM(CASE WHEN j.status = 'FAILED' THEN 1 ELSE 0 END) as failed_jobs,
                    COUNT(DISTINCT j.user_id) as unique_users,
                    MAX(j.created_at) as last_execution
                FROM {$automationsTable} a
                LEFT JOIN {$jobsTable} j ON a.id = j.automation_id 
                    AND j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY a.id, a.name, a.status
                HAVING total_jobs > 0
                ORDER BY completed_jobs DESC, total_jobs DESC
                LIMIT 10",
                $interval
            );
        }
        $topWorkflows = $wpdb->get_results($topWorkflowsQuery, ARRAY_A);

        // Évolution des jobs par jour sur la période
        if ($isToday) {
            $jobsByDayQuery = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed
            FROM {$jobsTable}
            WHERE DATE(created_at) = CURDATE()
            GROUP BY DATE(created_at)
            ORDER BY date ASC";
        } else {
            $jobsByDayQuery = $wpdb->prepare(
                "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed
                FROM {$jobsTable}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC",
                $interval
            );
        }
        $jobsByDay = $wpdb->get_results($jobsByDayQuery, ARRAY_A);

        // Distribution des statuts de jobs
        $jobsByStatus = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$jobsTable} 
             GROUP BY status",
            ARRAY_A
        );

        $statusDistribution = [];
        foreach ($jobsByStatus as $stat) {
            $statusDistribution[$stat['status']] = (int) $stat['count'];
        }

        // Métriques de revenus WooCommerce (si WooCommerce est actif)
        $woocommerceActive = function_exists('wc_get_order');
        $revenueStats = null;

        if ($woocommerceActive) {
            // Extraire les revenus depuis les logs PROCESSING uniquement (contexte initial du trigger)
            // On groupe par order_id pour éviter de compter plusieurs fois la même commande
            // On utilise une sous-requête pour ne prendre que le premier log PROCESSING par job
            $revenueQuery = $wpdb->prepare(
                "SELECT 
                    SUM(order_revenue) as total_revenue,
                    COUNT(DISTINCT order_id) as total_orders,
                    SUM(CASE WHEN j.status = 'COMPLETED' THEN order_revenue ELSE 0 END) as completed_revenue,
                    COUNT(DISTINCT CASE WHEN j.status = 'COMPLETED' THEN order_id ELSE NULL END) as completed_orders
                FROM (
                    SELECT DISTINCT
                        l.automation_id,
                        l.user_id,
                        CAST(JSON_EXTRACT(l.data, '$.order_total') AS DECIMAL(10,2)) as order_revenue,
                        CAST(JSON_EXTRACT(l.data, '$.order_id') AS UNSIGNED) as order_id,
                        MIN(l.created_at) as first_log_date
                    FROM {$logsTable} l
                    WHERE l.status = 'PROCESSING'
                        AND l.data LIKE '%%\"order_total\"%%'
                        AND l.data LIKE '%%\"order_id\"%%'
                        AND l.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                        AND JSON_EXTRACT(l.data, '$.order_total') IS NOT NULL
                        AND JSON_EXTRACT(l.data, '$.order_total') > 0
                        AND JSON_EXTRACT(l.data, '$.order_id') IS NOT NULL
                        AND JSON_EXTRACT(l.data, '$.order_id') > 0
                    GROUP BY l.automation_id, l.user_id, JSON_EXTRACT(l.data, '$.order_id')
                ) as unique_orders
                INNER JOIN {$jobsTable} j ON unique_orders.automation_id = j.automation_id 
                    AND unique_orders.user_id = j.user_id
                    AND j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $interval,
                $interval
            );

            $revenueData = $wpdb->get_row($revenueQuery, ARRAY_A);

            // Revenus globaux (toutes périodes) - même logique
            $globalRevenueQuery = "SELECT 
                SUM(order_revenue) as total_revenue,
                COUNT(DISTINCT order_id) as total_orders
            FROM (
                SELECT DISTINCT
                    CAST(JSON_EXTRACT(data, '$.order_total') AS DECIMAL(10,2)) as order_revenue,
                    CAST(JSON_EXTRACT(data, '$.order_id') AS UNSIGNED) as order_id
                FROM {$logsTable}
                WHERE status = 'PROCESSING'
                    AND data LIKE '%%\"order_total\"%%'
                    AND data LIKE '%%\"order_id\"%%'
                    AND JSON_EXTRACT(data, '$.order_total') IS NOT NULL
                    AND JSON_EXTRACT(data, '$.order_total') > 0
                    AND JSON_EXTRACT(data, '$.order_id') IS NOT NULL
                    AND JSON_EXTRACT(data, '$.order_id') > 0
                GROUP BY JSON_EXTRACT(data, '$.order_id')
            ) as unique_orders";

            $globalRevenueData = $wpdb->get_row($globalRevenueQuery, ARRAY_A);

            // Revenus par jour - grouper par order_id pour éviter les doublons
            $revenueByDay = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    DATE(first_log_date) as date,
                    SUM(order_revenue) as revenue,
                    COUNT(DISTINCT order_id) as orders
                FROM (
                    SELECT DISTINCT
                        CAST(JSON_EXTRACT(l.data, '$.order_total') AS DECIMAL(10,2)) as order_revenue,
                        CAST(JSON_EXTRACT(l.data, '$.order_id') AS UNSIGNED) as order_id,
                        MIN(l.created_at) as first_log_date
                    FROM {$logsTable} l
                    WHERE l.status = 'PROCESSING'
                        AND l.data LIKE '%%\"order_total\"%%'
                        AND l.data LIKE '%%\"order_id\"%%'
                        AND l.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                        AND JSON_EXTRACT(l.data, '$.order_total') IS NOT NULL
                        AND JSON_EXTRACT(l.data, '$.order_total') > 0
                        AND JSON_EXTRACT(l.data, '$.order_id') IS NOT NULL
                        AND JSON_EXTRACT(l.data, '$.order_id') > 0
                    GROUP BY JSON_EXTRACT(l.data, '$.order_id')
                ) as unique_orders
                GROUP BY DATE(first_log_date)
                ORDER BY date ASC",
                $interval
            ), ARRAY_A);

            // Top workflows par revenus générés - grouper par order_id
            $topWorkflowsByRevenue = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    a.id,
                    a.name,
                    SUM(order_revenue) as revenue,
                    COUNT(DISTINCT order_id) as orders
                FROM (
                    SELECT DISTINCT
                        l.automation_id,
                        CAST(JSON_EXTRACT(l.data, '$.order_total') AS DECIMAL(10,2)) as order_revenue,
                        CAST(JSON_EXTRACT(l.data, '$.order_id') AS UNSIGNED) as order_id
                    FROM {$logsTable} l
                    WHERE l.status = 'PROCESSING'
                        AND l.data LIKE '%%\"order_total\"%%'
                        AND l.data LIKE '%%\"order_id\"%%'
                        AND l.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                        AND JSON_EXTRACT(l.data, '$.order_total') IS NOT NULL
                        AND JSON_EXTRACT(l.data, '$.order_total') > 0
                        AND JSON_EXTRACT(l.data, '$.order_id') IS NOT NULL
                        AND JSON_EXTRACT(l.data, '$.order_id') > 0
                    GROUP BY l.automation_id, JSON_EXTRACT(l.data, '$.order_id')
                ) as unique_orders
                INNER JOIN {$automationsTable} a ON unique_orders.automation_id = a.id
                GROUP BY a.id, a.name
                HAVING revenue > 0
                ORDER BY revenue DESC
                LIMIT 5",
                $interval
            ), ARRAY_A);

            $revenueStats = [
                'enabled' => true,
                'period' => [
                    'total_revenue' => (float) ($revenueData['total_revenue'] ?? 0),
                    'total_orders' => (int) ($revenueData['total_orders'] ?? 0),
                    'completed_revenue' => (float) ($revenueData['completed_revenue'] ?? 0),
                    'completed_orders' => (int) ($revenueData['completed_orders'] ?? 0),
                ],
                'global' => [
                    'total_revenue' => (float) ($globalRevenueData['total_revenue'] ?? 0),
                    'total_orders' => (int) ($globalRevenueData['total_orders'] ?? 0),
                ],
                'revenue_by_day' => array_map(function ($day) {
                    return [
                        'date' => $day['date'],
                        'revenue' => (float) ($day['revenue'] ?? 0),
                        'orders' => (int) ($day['orders'] ?? 0),
                    ];
                }, $revenueByDay),
                'top_workflows_by_revenue' => array_map(function ($wf) {
                    return [
                        'id' => (int) $wf['id'],
                        'name' => $wf['name'],
                        'revenue' => (float) ($wf['revenue'] ?? 0),
                        'orders' => (int) ($wf['orders'] ?? 0),
                    ];
                }, $topWorkflowsByRevenue),
            ];
        }

        return new \WP_REST_Response([
            'overview' => [
                'total_workflows' => $totalWorkflows,
                'enabled_workflows' => $enabledWorkflows,
                'draft_workflows' => $draftWorkflows,
                'total_jobs' => $totalJobs,
                'active_jobs' => $activeJobs,
                'completed_jobs' => $completedJobs,
                'failed_jobs' => $failedJobs,
                'unique_users' => $uniqueUsers,
                'success_rate' => $successRate,
                'failure_rate' => $failureRate,
            ],
            'period_stats' => [
                'interval_days' => $interval,
                'total_jobs' => $intervalJobs,
                'completed_jobs' => $intervalCompleted,
                'failed_jobs' => $intervalFailed,
                'unique_users' => $intervalUniqueUsers,
                'success_rate' => $intervalSuccessRate,
            ],
            'top_workflows' => array_map(function ($wf) {
                return [
                    'id' => (int) $wf['id'],
                    'name' => $wf['name'],
                    'status' => $wf['status'],
                    'total_jobs' => (int) $wf['total_jobs'],
                    'completed_jobs' => (int) $wf['completed_jobs'],
                    'failed_jobs' => (int) $wf['failed_jobs'],
                    'unique_users' => (int) $wf['unique_users'],
                    'last_execution' => $wf['last_execution'],
                ];
            }, $topWorkflows),
            'jobs_by_day' => array_map(function ($day) {
                return [
                    'date' => $day['date'],
                    'total' => (int) $day['total'],
                    'completed' => (int) $day['completed'],
                    'failed' => (int) $day['failed'],
                ];
            }, $jobsByDay),
            'status_distribution' => $statusDistribution,
            'woocommerce_revenue' => $revenueStats,
        ], 200);
    }

    #[Endpoint(
        'workflows/dashboard/contacts',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getWorkflowDashboardContacts(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $intervalParam = $request->get_param('interval');
        $isToday = ($intervalParam === 'today');
        $interval = $isToday ? 1 : ((int) $intervalParam ?: 30);

        // Pagination
        $per_page = (int) ($request->get_param('perPages') ?? 20);
        $page = (int) ($request->get_param('paged') ?? 1);
        $offset = ($page - 1) * $per_page;
        $search = $request->get_param('search');

        $automationsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS);
        $jobsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_JOBS);

        // Récupérer les détails des contacts avec leurs workflows et interactions
        if ($isToday) {
            $contactsQuery = "SELECT 
                j.user_id,
                j.automation_id,
                a.name as workflow_name,
                j.id as job_id,
                j.status as job_status,
                j.created_at as job_created_at,
                j.updated_at as job_updated_at
            FROM {$jobsTable} j
            INNER JOIN {$automationsTable} a ON j.automation_id = a.id
            WHERE DATE(j.created_at) = CURDATE()
            ORDER BY j.user_id, j.created_at DESC";
        } else {
            $contactsQuery = $wpdb->prepare(
                "SELECT 
                    j.user_id,
                    j.automation_id,
                    a.name as workflow_name,
                    j.id as job_id,
                    j.status as job_status,
                    j.created_at as job_created_at,
                    j.updated_at as job_updated_at
                FROM {$jobsTable} j
                INNER JOIN {$automationsTable} a ON j.automation_id = a.id
                WHERE j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                ORDER BY j.user_id, j.created_at DESC",
                $interval
            );
        }

        $jobsData = $wpdb->get_results($contactsQuery, ARRAY_A);

        // Debug: vérifier si des jobs sont trouvés
        if (empty($jobsData)) {
            // Essayer sans filtre de date pour voir s'il y a des jobs
            $testQuery = "SELECT COUNT(*) as total FROM {$jobsTable}";
            $wpdb->get_var($testQuery);
        }

        // Grouper par user_id et enrichir avec les informations
        $contactsMap = [];
        $logsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_LOG);

        foreach ($jobsData as $jobData) {
            $userId = (int) $jobData['user_id'];

            if (!isset($contactsMap[$userId])) {
                $email = '';
                $name = '';
                $shouldInclude = false;

                // Essayer d'abord avec get_userdata si user_id > 0
                if ($userId > 0) {
                    $user = get_userdata($userId);
                    if ($user && $user->user_email) {
                        // C'est un utilisateur WordPress valide → inclure
                        $email = $user->user_email;
                        $name = trim($user->display_name ?: ($user->first_name . ' ' . $user->last_name)) ?: $email;
                        $shouldInclude = true;
                    } else {
                        // Peut-être un contact MailerPress
                        $contactsModel = new Contacts;
                        $contact = $contactsModel->get($userId);
                        if ($contact) {
                            // Le contact MailerPress existe → inclure
                            $email = $contact->email ?? '';
                            $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $email;
                            $shouldInclude = true;
                        }
                        // Si le contact MailerPress n'existe plus, ne pas l'inclure
                    }
                }

                // Si pas d'email trouvé et que ce n'est pas un utilisateur WordPress ou un contact MailerPress,
                // chercher dans les logs (pour les contacts externes ou historiques)
                if (!$shouldInclude && empty($email)) {
                    $logQuery = $wpdb->prepare(
                        "SELECT data FROM {$logsTable} 
                         WHERE user_id = %d 
                         AND (data LIKE '%%\"customer_email\"%%' OR data LIKE '%%\"email\"%%')
                         ORDER BY created_at DESC
                         LIMIT 1",
                        $userId
                    );
                    $logData = $wpdb->get_var($logQuery);

                    if ($logData) {
                        $logContext = json_decode($logData, true);
                        if (isset($logContext['customer_email'])) {
                            $email = $logContext['customer_email'];
                            $name = trim(($logContext['customer_first_name'] ?? '') . ' ' . ($logContext['customer_last_name'] ?? '')) ?: $email;
                            $shouldInclude = !empty($email);
                        } elseif (isset($logContext['email'])) {
                            $email = $logContext['email'];
                            $name = $email;
                            $shouldInclude = !empty($email);
                        }
                    }
                }

                // Ne créer l'entrée que si on doit l'inclure
                if ($shouldInclude) {
                    if (empty($name)) {
                        $name = !empty($email) ? $email : sprintf(__('Contact #%d', 'mailerpress'), $userId);
                    }

                    $contactsMap[$userId] = [
                        'user_id' => $userId,
                        'email' => $email,
                        'name' => $name,
                        'workflows' => [],
                    ];
                } else {
                    // Contact MailerPress n'existe plus, ne pas l'inclure
                    // Passer au suivant sans créer d'entrée dans contactsMap
                    continue;
                }
            }

            // Ajouter les logs/steps pour ce workflow
            $automationId = (int) $jobData['automation_id'];
            $jobId = (int) $jobData['job_id'];

            // Récupérer les logs pour ce workflow pour ce contact
            $stepsQuery = $wpdb->prepare(
                "SELECT 
                    step_id,
                    status,
                    data,
                    created_at
                FROM {$logsTable}
                WHERE automation_id = %d 
                    AND user_id = %d
                    AND created_at >= %s
                ORDER BY created_at ASC",
                $automationId,
                $userId,
                $jobData['job_created_at']
            );
            $steps = $wpdb->get_results($stepsQuery, ARRAY_A);

            // Trouver ou créer l'entrée workflow
            $workflowKey = $automationId;
            if (!isset($contactsMap[$userId]['workflows'][$workflowKey])) {
                $contactsMap[$userId]['workflows'][$workflowKey] = [
                    'automation_id' => $automationId,
                    'workflow_name' => $jobData['workflow_name'],
                    'jobs' => [],
                ];
            }

            $contactsMap[$userId]['workflows'][$workflowKey]['jobs'][] = [
                'job_id' => $jobId,
                'status' => $jobData['job_status'],
                'created_at' => $jobData['job_created_at'],
                'updated_at' => $jobData['job_updated_at'],
                'steps' => array_map(function ($step) {
                    $stepData = json_decode($step['data'] ?? '{}', true);
                    return [
                        'step_id' => $step['step_id'],
                        'status' => $step['status'],
                        'created_at' => $step['created_at'],
                        'data' => $stepData,
                    ];
                }, $steps),
            ];
        }

        // Convertir en array et calculer les stats
        $contacts = [];
        foreach ($contactsMap as $userId => $contactData) {
            $totalJobs = 0;
            $completedJobs = 0;
            $failedJobs = 0;
            $lastExecution = null;

            foreach ($contactData['workflows'] as $workflow) {
                foreach ($workflow['jobs'] as $job) {
                    $totalJobs++;
                    if ($job['status'] === 'COMPLETED') {
                        $completedJobs++;
                    } elseif ($job['status'] === 'FAILED') {
                        $failedJobs++;
                    }
                    if (!$lastExecution || $job['created_at'] > $lastExecution) {
                        $lastExecution = $job['created_at'];
                    }
                }
            }

            $contacts[] = [
                'user_id' => $contactData['user_id'],
                'email' => $contactData['email'],
                'name' => $contactData['name'],
                'total_jobs' => $totalJobs,
                'completed_jobs' => $completedJobs,
                'failed_jobs' => $failedJobs,
                'last_execution' => $lastExecution,
                'workflows' => array_values($contactData['workflows']),
            ];
        }

        // Trier selon le paramètre orderby
        $orderby = $request->get_param('orderby') ?? 'total_jobs';
        $order = strtoupper($request->get_param('order') ?? 'DESC');

        usort($contacts, function ($a, $b) use ($orderby, $order) {
            if ($orderby === 'email') {
                $result = strcasecmp($a['email'] ?? '', $b['email'] ?? '');
            } elseif ($orderby === 'name') {
                $result = strcasecmp($a['name'] ?? '', $b['name'] ?? '');
            } else {
                // Par défaut, trier par total_jobs
                $result = ($b['total_jobs'] ?? 0) - ($a['total_jobs'] ?? 0);
            }
            return $order === 'ASC' ? $result : -$result;
        });

        // Filtrer par recherche si fourni
        if (!empty($search)) {
            $searchLower = strtolower($search);
            $contacts = array_filter($contacts, function ($contact) use ($searchLower) {
                return (
                    strpos(strtolower($contact['email'] ?? ''), $searchLower) !== false ||
                    strpos(strtolower($contact['name'] ?? ''), $searchLower) !== false
                );
            });
        }

        // Compter le total avant pagination
        $total_count = count($contacts);
        $total_pages = ceil($total_count / $per_page);

        // Paginer
        $contacts = array_slice($contacts, $offset, $per_page);

        // Convertir en format DataView (posts, count, pages)
        $posts = array_map(function ($contact) {
            return [
                'id' => $contact['user_id'],
                'email' => $contact['email'] ?? __('No email', 'mailerpress'),
                'name' => $contact['name'] ?? '',
            ];
        }, $contacts);

        return new \WP_REST_Response([
            'posts' => $posts,
            'count' => $total_count,
            'pages' => $total_pages,
        ], 200);
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)/logs',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getWorkflowLogs(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $automationId = (int) $request->get_param('id');

        // Check if automation exists
        $automationRepo = new AutomationRepository();
        $automation = $automationRepo->find($automationId);
        if (!$automation) {
            return new \WP_Error(
                'not_found',
                'Automation not found',
                ['status' => 404]
            );
        }

        $logsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_LOG);
        $jobsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_JOBS);
        $stepsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_STEPS);

        // Pagination
        $per_page = (int) ($request->get_param('per_page') ?? 50);
        $page = (int) ($request->get_param('page') ?? 1);
        $offset = ($page - 1) * $per_page;

        // Status filter - only show final results (COMPLETED, FAILED, EXITED)
        // EXITED is considered as FAILED
        $status = $request->get_param('status');

        // Build status condition - only show final statuses by default
        if ($status === 'FAILED') {
            // Include both FAILED and EXITED when filtering for failed
            $statusCondition = " AND (l.status = 'FAILED' OR l.status = 'EXITED')";
        } elseif ($status === 'COMPLETED') {
            $statusCondition = " AND l.status = 'COMPLETED'";
        } else {
            // Default: show only final results (COMPLETED, FAILED, EXITED)
            $statusCondition = " AND (l.status = 'COMPLETED' OR l.status = 'FAILED' OR l.status = 'EXITED')";
        }

        // Get total count
        $totalQuery = $wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$logsTable} l
             WHERE l.automation_id = %d{$statusCondition}",
            $automationId
        );
        $total_count = (int) $wpdb->get_var($totalQuery);

        // Get logs with job and step information
        // Use subquery to get only one job per log (most recent) to avoid duplicates
        $logsQuery = $wpdb->prepare(
            "SELECT 
                l.id,
                l.step_id,
                l.user_id,
                l.status,
                l.data,
                l.created_at,
                (SELECT j.id FROM {$jobsTable} j 
                 WHERE j.automation_id = l.automation_id 
                 AND j.user_id = l.user_id
                 AND DATE(j.created_at) = DATE(l.created_at)
                 ORDER BY j.created_at DESC
                 LIMIT 1) as job_id,
                (SELECT j.status FROM {$jobsTable} j 
                 WHERE j.automation_id = l.automation_id 
                 AND j.user_id = l.user_id
                 AND DATE(j.created_at) = DATE(l.created_at)
                 ORDER BY j.created_at DESC
                 LIMIT 1) as job_status,
                s.type as step_type,
                s.key as step_key
             FROM {$logsTable} l
             LEFT JOIN {$stepsTable} s ON l.step_id = s.step_id 
                 AND l.automation_id = s.automation_id
             WHERE l.automation_id = %d{$statusCondition}
             ORDER BY l.created_at DESC
             LIMIT %d OFFSET %d",
            $automationId,
            $per_page,
            $offset
        );

        $logs = $wpdb->get_results($logsQuery, ARRAY_A);

        // Format logs - convert EXITED to FAILED for display and get email
        $contactsModel = new Contacts;
        $stepRepo = new StepRepository();
        $formattedLogs = array_map(function ($log) use ($contactsModel, $logsTable, $wpdb, $automationId, $stepRepo) {
            $data = json_decode($log['data'] ?? '{}', true);
            $displayStatus = $log['status'];
            // Convert EXITED to FAILED for display
            if ($displayStatus === 'EXITED') {
                $displayStatus = 'FAILED';
            }

            $userId = (int) $log['user_id'];
            $email = '';

            // Try to get email from WordPress user or MailerPress contact
            if ($userId > 0) {
                $user = get_userdata($userId);
                if ($user && $user->user_email) {
                    $email = $user->user_email;
                } else {
                    // Try MailerPress contact
                    $contact = $contactsModel->get($userId);
                    if ($contact) {
                        $email = $contact->email ?? '';
                    }
                }
            }

            // If no email found, try to get it from log data
            if (empty($email)) {
                if (isset($data['customer_email'])) {
                    $email = $data['customer_email'];
                } elseif (isset($data['email'])) {
                    $email = $data['email'];
                } else {
                    // Try to get from other logs for this user
                    $logQuery = $wpdb->prepare(
                        "SELECT data FROM {$logsTable} 
                         WHERE user_id = %d 
                         AND (data LIKE '%%\"customer_email\"%%' OR data LIKE '%%\"email\"%%')
                         ORDER BY created_at DESC
                         LIMIT 1",
                        $userId
                    );
                    $logData = $wpdb->get_var($logQuery);
                    if ($logData) {
                        $logContext = json_decode($logData, true);
                        if (isset($logContext['customer_email'])) {
                            $email = $logContext['customer_email'];
                        } elseif (isset($logContext['email'])) {
                            $email = $logContext['email'];
                        }
                    }
                }
            }

            // Get step information for event name
            $stepType = $log['step_type'] ?? null;
            $stepKey = $log['step_key'] ?? null;
            $eventName = null;

            if ($stepType && $stepKey) {
                // Format event name based on type and key
                if ($stepType === 'CONDITION') {
                    $eventName = __('Condition', 'mailerpress');
                } elseif ($stepType === 'TRIGGER') {
                    // Try to get trigger label from registered triggers
                    try {
                        $manager = WorkflowSystem::getInstance()->getManager();
                        $triggerDefinitions = $manager->getTriggerManager()->getTriggerDefinitions();
                        if (isset($triggerDefinitions[$stepKey]) && !empty($triggerDefinitions[$stepKey]['label'])) {
                            $eventName = $triggerDefinitions[$stepKey]['label'];
                        } else {
                            $eventName = ucwords(str_replace('_', ' ', $stepKey));
                        }
                    } catch (\Exception $e) {
                        $eventName = ucwords(str_replace('_', ' ', $stepKey));
                    }
                } elseif ($stepType === 'ACTION') {
                    // Try to get action label from handlers
                    try {
                        $executor = WorkflowSystem::getInstance()->getManager()->getExecutor();
                        $registry = $executor->getHandlerRegistry();
                        $handlers = $registry->getHandlers();
                        $foundLabel = false;
                        foreach ($handlers as $handler) {
                            if (method_exists($handler, 'supports') && $handler->supports($stepKey)) {
                                if (method_exists($handler, 'getDefinition')) {
                                    $def = $handler->getDefinition();
                                    if ($def && isset($def['label'])) {
                                        $eventName = $def['label'];
                                        $foundLabel = true;
                                        break;
                                    }
                                }
                            }
                        }
                        if (!$foundLabel) {
                            $eventName = ucwords(str_replace('_', ' ', $stepKey));
                        }
                    } catch (\Exception $e) {
                        $eventName = ucwords(str_replace('_', ' ', $stepKey));
                    }
                } elseif ($stepType === 'DELAY') {
                    $eventName = __('Delay', 'mailerpress');
                } else {
                    $eventName = ucwords(str_replace('_', ' ', $stepKey));
                }
            }

            // If this is a condition log, try to get condition rules from step
            $conditionRules = null;
            if (isset($data['condition_met']) && !empty($log['step_id'])) {
                $step = $stepRepo->findByStepId($log['step_id']);
                if ($step && $step->getAutomationId() === $automationId && $step->getKey() === 'condition') {
                    $settings = $step->getSettings();
                    if (isset($settings['condition'])) {
                        $conditionRules = $settings['condition'];
                    }
                }
            }

            return [
                'id' => (int) $log['id'],
                'user_id' => $userId,
                'email' => $email ?: sprintf(__('Contact #%d', 'mailerpress'), $userId),
                'status' => $displayStatus,
                'data' => $data,
                'created_at' => $log['created_at'],
                'job_id' => $log['job_id'] ? (int) $log['job_id'] : null,
                'job_status' => $log['job_status'],
                'condition_rules' => $conditionRules,
                'event_name' => $eventName,
                'step_type' => $stepType,
                'step_key' => $stepKey,
            ];
        }, $logs);

        $total_pages = ceil($total_count / $per_page);

        return new \WP_REST_Response([
            'logs' => $formattedLogs,
            'count' => $total_count,
            'pages' => $total_pages,
            'page' => $page,
            'per_page' => $per_page,
        ], 200);
    }

    /**
     * Delete all AB tests and their participants related to an automation
     * 
     * @param int $automationId
     * @return void
     */
    private function deleteABTestsByAutomationId(int $automationId): void
    {
        global $wpdb;

        $abTestsTable = $wpdb->prefix . 'mailerpress_ab_tests';
        $participantsTable = $wpdb->prefix . 'mailerpress_ab_test_participants';

        // Get all test IDs for this automation
        $testIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$abTestsTable} WHERE automation_id = %d",
            $automationId
        ));

        if (empty($testIds)) {
            return; // No AB tests to clean up
        }

        // Get step IDs from AB tests before deleting them
        $stepIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT step_id FROM {$abTestsTable} WHERE automation_id = %d",
            $automationId
        ));

        // Delete scheduled actions for these tests
        foreach ($testIds as $testId) {
            // Cancel Action Scheduler actions if available
            if (function_exists('as_unschedule_action')) {
                // Cancel actions for each step_id associated with this test
                foreach ($stepIds as $stepId) {
                    as_unschedule_action('mailerpress_ab_test_send_winner', [$testId, $stepId]);
                }
            }

            // Clean up wp_schedule_single_event scheduled tests
            $scheduledTests = \get_option('mailerpress_ab_test_scheduled', []);
            if (is_array($scheduledTests) && in_array($testId, $scheduledTests, true)) {
                $scheduledTests = array_diff($scheduledTests, [$testId]);
                \update_option('mailerpress_ab_test_scheduled', $scheduledTests);
            }
        }

        // Delete all participants for these tests
        if (!empty($testIds)) {
            $testIdsInt = array_map('intval', $testIds);
            $testIdsString = implode(',', $testIdsInt);
            $wpdb->query(
                "DELETE FROM {$participantsTable} WHERE test_id IN ({$testIdsString})"
            );
        }

        // Delete all AB tests for this automation
        $wpdb->delete(
            $abTestsTable,
            ['automation_id' => $automationId],
            ['%d']
        );
    }
}
