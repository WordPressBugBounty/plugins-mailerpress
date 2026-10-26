<?php

namespace MailerPress\Api;

use MailerPress\Actions\Workflows\WooCommerce\CustomerInactiveTrigger;
use MailerPress\Actions\Workflows\MailerPress\Triggers\CustomTrigger;
use MailerPress\Core\ApiAuthentication;
use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\WorkflowSystem;
use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Core\Workflows\Handlers\CreateWordPressUserStepHandler;
use MailerPress\Api\Permissions;
use MailerPress\Models\Contacts;

class Workflows
{
    /**
     * Free workflow templates and the steps ("TYPE:key") each one creates. Without MailerPress Pro, a workflow
     * can only be created from one of them, with exactly these steps. Keep in sync with
     * packages/Workflow/workflowTemplates.ts.
     */
    private const FREE_TEMPLATES = [
        'welcome-email' => ['TRIGGER:mailerpress_contact_optin', 'ACTION:send_email'],
        'new-user-welcome' => ['TRIGGER:contact_subscribed', 'ACTION:send_email'],
    ];

    private function validateSensitiveNodes(array $nodes, \WP_REST_Request $request): bool|\WP_Error
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                return new \WP_Error('invalid_data', __('Invalid workflow node.', 'mailerpress'), ['status' => 400]);
            }

            if (($node['type'] ?? null) === 'TRIGGER' && ($node['key'] ?? null) === CustomTrigger::TRIGGER_KEY) {
                if (ApiAuthentication::isApiKeyRequest($request) || !current_user_can('manage_options')) {
                    return new \WP_Error('rest_forbidden', __('Only administrators can configure custom hook triggers.', 'mailerpress'), ['status' => 403]);
                }

                $settings = $node['settings'] ?? [];
                if (!is_array($settings) || !CustomTrigger::isAllowedHookName($settings['hook_name'] ?? null)) {
                    return new \WP_Error('invalid_hook_name', __('Custom hook names must start with mailerpress_custom_ or be allowed by site code.', 'mailerpress'), ['status' => 400]);
                }
            }

            if (($node['key'] ?? null) === 'create_wp_user') {
                if (ApiAuthentication::isApiKeyRequest($request) || !current_user_can('create_users') || !current_user_can('promote_users')) {
                    return new \WP_Error('rest_forbidden', __('Only user administrators can configure WordPress user creation.', 'mailerpress'), ['status' => 403]);
                }

                $settings = $node['settings'] ?? [];
                if (!is_array($settings) || !CreateWordPressUserStepHandler::isAllowedRole($settings['role'] ?? 'subscriber')) {
                    return new \WP_Error('invalid_role', __('This WordPress user role is not allowed in workflows.', 'mailerpress'), ['status' => 400]);
                }
            }
        }

        return true;
    }

    private function validateStoredSensitiveNodes(int $automationId, StepRepository $stepRepo, \WP_REST_Request $request): bool|\WP_Error
    {
        $nodes = [];
        foreach ($stepRepo->findByAutomationId($automationId) as $step) {
            $nodes[] = [
                'type' => $step->getType(),
                'key' => $step->getKey(),
                'settings' => $step->getSettings(),
            ];
        }

        return $this->validateSensitiveNodes($nodes, $request);
    }

    private function automationOwnerWhere(\WP_REST_Request $request, string $column = 'author'): string
    {
        if (ApiAuthentication::isApiKeyRequest($request) || current_user_can('edit_others_posts')) {
            return '';
        }

        global $wpdb;

        return $wpdb->prepare(" AND {$column} = %d", get_current_user_id());
    }

    private function automationResourceOwnerWhere(\WP_REST_Request $request, string $column = 'automation_id'): string
    {
        if (ApiAuthentication::isApiKeyRequest($request) || current_user_can('edit_others_posts')) {
            return '';
        }

        global $wpdb;

        $automationsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS);

        return $wpdb->prepare(
            " AND {$column} IN (SELECT id FROM {$automationsTable} WHERE author = %d)",
            get_current_user_id()
        );
    }

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
            ['key' => 'user_email', 'label' => __('User email', 'mailerpress'), 'type' => 'string', 'category' => 'user'],
            ['key' => 'user_login', 'label' => __('User Login', 'mailerpress'), 'type' => 'string', 'category' => 'user'],
            ['key' => 'user_role', 'label' => __('User Role', 'mailerpress'), 'type' => 'string', 'category' => 'user'],
            ['key' => 'user.meta:ANY', 'label' => __('User Meta (key)', 'mailerpress'), 'type' => 'any', 'category' => 'user'],
            ['key' => 'mp_subscription_status', 'label' => __('Subscription status', 'mailerpress'), 'type' => 'string', 'category' => 'mailerpress', 'valueType' => 'select', 'valueOptions' => [
                ['label' => __('Subscribed', 'mailerpress'), 'value' => 'subscribed'],
                ['label' => __('Unsubscribed', 'mailerpress'), 'value' => 'unsubscribed'],
                ['label' => __('Pending', 'mailerpress'), 'value' => 'pending'],
            ]],
            ['key' => 'mp_has_tag', 'label' => __('Tag', 'mailerpress'), 'type' => 'array|bool', 'category' => 'mailerpress', 'valueType' => 'token', 'description' => __('Check if contact has specific tags', 'mailerpress'), 'operators' => ['==', '!=', 'in', 'not_in']],
            ['key' => 'mp_in_list', 'label' => __('List', 'mailerpress'), 'type' => 'array|bool', 'category' => 'mailerpress', 'valueType' => 'token', 'description' => __('Check if contact is in specific lists', 'mailerpress'), 'operators' => ['==', '!=', 'in', 'not_in']],
            ['key' => 'mp_email_opened', 'label' => __('Email Opened', 'mailerpress'), 'type' => 'boolean', 'category' => 'mailerpress', 'valueType' => 'select', 'description' => __('Check if a contact has opened a specific email campaign', 'mailerpress')],
            ['key' => 'mp_email_clicked', 'label' => __('Email Clicked', 'mailerpress'), 'type' => 'boolean', 'category' => 'mailerpress', 'valueType' => 'select', 'description' => __('Check if a contact has clicked a link in a specific email campaign', 'mailerpress')],
        ];

        if ($this->isWooCommerceActive()) {
            $fields = array_merge($fields, [
                ['key' => 'wc_total_spent', 'label' => __('Total Spent', 'mailerpress'), 'type' => 'number', 'category' => 'woocommerce', 'valueType' => 'number', 'description' => __('Total amount spent by the customer', 'mailerpress')],
                ['key' => 'wc_order_count', 'label' => __('Order Count', 'mailerpress'), 'type' => 'number', 'category' => 'woocommerce', 'valueType' => 'number', 'description' => __('Number of orders placed by the customer', 'mailerpress')],
                ['key' => 'wc_last_order_status', 'label' => __('Last Order Status', 'mailerpress'), 'type' => 'string', 'category' => 'woocommerce', 'valueType' => 'select'],
                ['key' => 'wc_has_purchased_product', 'label' => __('Has Purchased Product', 'mailerpress'), 'type' => 'array|bool', 'category' => 'woocommerce', 'valueType' => 'token', 'description' => __('Check if customer has purchased specific products', 'mailerpress')],
                ['key' => 'wc_purchased_in_category', 'label' => __('Purchased in Category', 'mailerpress'), 'type' => 'boolean', 'category' => 'woocommerce', 'valueType' => 'select', 'description' => __('Check if customer has purchased a product in a specific category', 'mailerpress')],
                ['key' => 'wc_order_created', 'label' => __('Order Created', 'mailerpress'), 'type' => 'boolean', 'category' => 'woocommerce', 'valueType' => 'select', 'description' => __('Check if an order_id exists in the workflow context (useful for abandoned cart recovery)', 'mailerpress')],
                ['key' => 'wc_has_reviewed_order', 'label' => __('Has Reviewed Order', 'mailerpress'), 'type' => 'boolean', 'category' => 'woocommerce', 'valueType' => 'select', 'description' => __('Check if customer has left a product review for products in the current order', 'mailerpress')],
                ['key' => 'order_total', 'label' => __('Order total', 'mailerpress'), 'type' => 'number', 'category' => 'woocommerce', 'valueType' => 'number', 'description' => __('Total amount of the current order from workflow context', 'mailerpress')],
            ]);
        }

        // SureCart condition fields
        if (class_exists('\SureCart\Models\Purchase') || function_exists('surecart')) {
            $fields = array_merge($fields, [
                ['key' => 'sc_total_spent', 'label' => __('Total Spent', 'mailerpress'), 'type' => 'number', 'category' => 'surecart', 'valueType' => 'number', 'description' => __('Total amount spent by the customer in SureCart', 'mailerpress')],
                ['key' => 'sc_order_count', 'label' => __('Order Count', 'mailerpress'), 'type' => 'number', 'category' => 'surecart', 'valueType' => 'number', 'description' => __('Number of orders/purchases placed by the customer', 'mailerpress')],
                ['key' => 'sc_last_order_status', 'label' => __('Last Order Status', 'mailerpress'), 'type' => 'string', 'category' => 'surecart', 'valueType' => 'select', 'description' => __('Status of the customer\'s last order', 'mailerpress')],
                ['key' => 'sc_has_purchased_product', 'label' => __('Has Purchased Product', 'mailerpress'), 'type' => 'array|bool', 'category' => 'surecart', 'valueType' => 'token', 'description' => __('Check if customer has purchased specific SureCart products', 'mailerpress')],
                ['key' => 'sc_order_created', 'label' => __('Order Created', 'mailerpress'), 'type' => 'boolean', 'category' => 'surecart', 'valueType' => 'select', 'description' => __('Check if an order_id exists in the workflow context (useful for abandoned cart recovery)', 'mailerpress')],
                ['key' => 'order_total', 'label' => __('Order total', 'mailerpress'), 'type' => 'number', 'category' => 'surecart', 'valueType' => 'number', 'description' => __('Total amount of the current order from workflow context', 'mailerpress')],
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
        permissionCallback: [Permissions::class, 'canUseEditorContent']
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
        permissionCallback: [Permissions::class, 'canUseEditorContent']
    )]
    public function getWooCommerceProducts(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!function_exists('wc_get_products')) {
            return new \WP_REST_Response([], 200);
        }

        $search = trim(sanitize_text_field((string) ($request->get_param('search') ?? '')));
        $search_product_id = preg_match('/^#?([0-9]+)$/D', $search, $id_match)
            ? absint($id_match[1])
            : null;
        $per_page = min(100, max(1, (int) ($request->get_param('per_page') ?? 50)));
        $include_variations = filter_var($request->get_param('include_variations'), FILTER_VALIDATE_BOOLEAN);
        $paginate = !$include_variations && filter_var($request->get_param('paginate'), FILTER_VALIDATE_BOOLEAN);

        $args = [
            'limit' => $per_page,
            'page' => max(1, (int) ($request->get_param('page') ?? 1)),
            'paginate' => $paginate,
            'status' => 'publish',
            'orderby' => ['title' => 'ASC', 'ID' => 'ASC'],
            'order' => 'ASC',
        ];

        if ($search_product_id !== null) {
            $args['include'] = [$search_product_id];
        } elseif ($search !== '') {
            $args['s'] = $search;
        }

        // Product search only matches titles and content, so also resolve an exact slug.
        $slug_product = null;
        if ($search_product_id === null && $search !== '' && $args['page'] === 1 && sanitize_title($search) === $search) {
            $slug_post = get_page_by_path($search, OBJECT, 'product');
            if ($slug_post && 'publish' === $slug_post->post_status) {
                $slug_product = wc_get_product($slug_post->ID);
            }
        }

        $products = wc_get_products($args);

        if (is_wp_error($products)) {
            return new \WP_REST_Response([], 200);
        }

        $total = $paginate ? (int) $products->total : 0;
        $total_pages = $paginate ? (int) $products->max_num_pages : 0;
        $products = $paginate ? $products->products : $products;

        $full = filter_var($request->get_param('full'), FILTER_VALIDATE_BOOLEAN);
        $formatted = [];
        $seen = [];

        foreach ($products as $product) {
            $this->appendWooCommerceProductOption($formatted, $seen, $product, $full);

            if ($include_variations && method_exists($product, 'get_children')) {
                foreach ((array) $product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $this->appendWooCommerceProductOption($formatted, $seen, $variation, $full);
                    }
                }
            }
        }

        if ($slug_product) {
            $count_before = count($formatted);
            $this->appendWooCommerceProductOption($formatted, $seen, $slug_product, $full);
            if ($paginate && count($formatted) > $count_before) {
                $total++;
            }
        }

        if ($include_variations) {
            $variation_args = [
                'limit' => $per_page,
                'status' => ['publish', 'private'],
                'type' => ['variation', 'subscription_variation'],
                'orderby' => 'title',
                'order' => 'ASC',
            ];

            if ($search_product_id !== null) {
                $variation_args['include'] = [$search_product_id];
            } elseif ($search !== '') {
                $variation_args['s'] = $search;
            }

            $variations = wc_get_products($variation_args);
            if (!is_wp_error($variations)) {
                foreach ($variations as $variation) {
                    $this->appendWooCommerceProductOption($formatted, $seen, $variation, $full);
                }
            }
        }

        usort($formatted, function ($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        $response = new \WP_REST_Response($formatted, 200);
        if ($paginate) {
            $response->header('X-WP-Total', $total);
            $response->header('X-WP-TotalPages', $total_pages);
        }

        return $response;
    }

    private function appendWooCommerceProductOption(array &$formatted, array &$seen, $product, bool $full): void
    {
        if (!$product || !method_exists($product, 'get_id')) {
            return;
        }

        $product_id = (int) $product->get_id();
        if ($product_id <= 0 || isset($seen[$product_id])) {
            return;
        }

        $seen[$product_id] = true;
        $is_variation = method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0;
        $parent_id = $is_variation ? (int) $product->get_parent_id() : 0;
        $parent = $parent_id > 0 ? wc_get_product($parent_id) : null;

        $label = $product->get_name() . ' (#' . $product_id . ')';
        if ($is_variation) {
            $parent_name = $parent ? $parent->get_name() : $product->get_name();
            $variation_label = function_exists('wc_get_formatted_variation')
                ? wc_get_formatted_variation($product, true, false, true)
                : '';
            $label = $parent_name . ' - ' . ($variation_label ?: __('Variation', 'mailerpress')) . ' (#' . $product_id . ')';
        }

        $item = [
            'label' => $label,
            'value' => (string) $product_id,
            'type' => method_exists($product, 'get_type') ? $product->get_type() : '',
            'is_variation' => $is_variation,
            'parent_id' => $parent_id ? (string) $parent_id : '',
        ];

        if ($full) {
            $imageId = $product->get_image_id();
            if (!$imageId && $parent) {
                $imageId = $parent->get_image_id();
            }

            $imageUrl = $imageId ? wp_get_attachment_image_url($imageId, 'large') : '';

            $images = [];
            if ($imageId) {
                foreach (['thumbnail', 'medium', 'large'] as $size) {
                    $url = wp_get_attachment_image_url($imageId, $size);
                    if ($url) {
                        $images[$size] = $url;
                    }
                }
            }

            $item['product_name'] = $product->get_name();
            $item['product_price'] = $product->get_price();
            $item['product_currency'] = get_woocommerce_currency();
            $item['product_description'] = $product->get_short_description();
            $item['product_url'] = get_permalink($parent_id ?: $product_id);
            $item['product_image_url'] = $imageUrl ?: '';
            $item['product_images'] = $images;
        }

        $formatted[] = $item;
    }

    #[Endpoint(
        'workflows/woocommerce/product-tags',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canUseEditorContent']
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
        'workflows/surecart/products',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canUseEditorContent']
    )]
    public function getSureCartProducts(\WP_REST_Request $request): \WP_REST_Response
    {
        // Only return products if SureCart is active
        if (!class_exists('\SureCart\Models\Product') && !function_exists('surecart')) {
            return new \WP_REST_Response([], 200);
        }

        try {
            $search = $request->get_param('search') ?? '';
            $per_page = (int) ($request->get_param('per_page') ?? 50);

            // Use SureCart Product model to fetch products
            $products = \SureCart\Models\Product::where([
                'archived' => false,
            ])->paginate([
                'per_page' => $per_page,
            ]);

            if (is_wp_error($products)) {
                return new \WP_REST_Response([], 200);
            }

            $formatted = [];

            // SureCart returns products in a data property
            $productList = $products->data ?? [];

            foreach ($productList as $product) {
                $productId = is_object($product) ? ($product->id ?? '') : ($product['id'] ?? '');
                $productName = is_object($product) ? ($product->name ?? '') : ($product['name'] ?? '');

                if (empty($productId)) {
                    continue;
                }

                // Apply search filter if provided
                if (!empty($search)) {
                    $searchLower = strtolower($search);
                    $nameLower = strtolower($productName);
                    if (strpos($nameLower, $searchLower) === false && strpos($productId, $search) === false) {
                        continue;
                    }
                }

                $formatted[] = [
                    'label' => $productName ? ($productName . ' (#' . $productId . ')') : ('Product #' . $productId),
                    'value' => (string) $productId,
                ];
            }

            // Sort by label
            usort($formatted, function ($a, $b) {
                return strcmp($a['label'], $b['label']);
            });

            return new \WP_REST_Response($formatted, 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([], 200);
        }
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
        $whereParams = [];
        $joins = [];
        $joinParams = [];

        if (!empty($search)) {
            $where[] = 'a.name LIKE %s';
            $whereParams[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!empty($status)) {
            $where[] = 'a.status = %s';
            $whereParams[] = $status;
        }

        // Apply date bounds before counting and paginating the workflows.
        foreach ( [ 'created_at', 'updated_at' ] as $column ) {
            foreach ( (array) $request->get_param( $column ) as $entry ) {
                if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['value'] ) || ! is_string( $entry['value'] ) ) {
                    continue;
                }
                $value = sanitize_text_field( $entry['value'] );
                $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
                if ( false === $date || $value !== $date->format( 'Y-m-d' ) ) {
                    continue;
                }
                if ( 'from' === $entry['id'] ) {
                    $where[] = "a.{$column} >= %s";
                    $whereParams[] = $date->format( 'Y-m-d H:i:s' );
                } elseif ( 'to' === $entry['id'] ) {
                    $where[] = "a.{$column} < %s";
                    $whereParams[] = $date->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
                }
            }
        }

        if (
            !ApiAuthentication::isApiKeyRequest($request)
            && !current_user_can('edit_others_posts')
        ) {
            $where[] = 'a.author = %d';
            $whereParams[] = get_current_user_id();
        }

        // Use INNER JOIN instead of EXISTS for better performance when filtering by trigger_type
        if (!empty($triggerType)) {
            $joins[] = "INNER JOIN {$stepsTable} s ON s.automation_id = a.id AND s.type = 'TRIGGER' AND s.key = %s";
            $joinParams[] = $triggerType;
        }

        $params = array_merge($joinParams, $whereParams);

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
        $posts = array_map(function ($row) use ($statsMap) {
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

            return $result;
        }, $results);

        // Lets extensions add information to each workflow, e.g. the A/B test summary of MailerPress Pro.
        $posts = apply_filters('mailerpress_workflow_list_items', $posts);

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
                __('Missing automation or nodes data', 'mailerpress'),
                ['status' => 400]
            );
        }

        $isTemplate = isset($body['template_id']);

        // Without Pro, only the free templates can be used, with their own steps.
        if ($isTemplate && !$this->isProActive()) {
            $templateId = (string) $body['template_id'];

            if (!isset(self::FREE_TEMPLATES[$templateId])) {
                return new \WP_Error(
                    'pro_required',
                    __('This template requires MailerPress Pro. Please upgrade to use this template.', 'mailerpress'),
                    ['status' => 403]
                );
            }

            if (!$this->nodesMatchTemplate((array) $body['nodes'], self::FREE_TEMPLATES[$templateId])) {
                return new \WP_Error(
                    'pro_required',
                    __('Adding steps to workflows requires MailerPress Pro. Please upgrade to continue.', 'mailerpress'),
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

        if ($this->nodesContainGoal($nodes) && !$this->isProActive()) {
            return new \WP_Error(
                'pro_required',
                __('Goals require MailerPress Pro. Please upgrade to measure conversions inside your automations.', 'mailerpress'),
                ['status' => 403]
            );
        }

        $sensitiveNodesValidation = $this->validateSensitiveNodes($nodes, $request);
        if ($sensitiveNodesValidation !== true) {
            return $sensitiveNodesValidation;
        }

        if (($automationData['status'] ?? null) === 'ENABLED') {
            $validationErrors = $this->validateWorkflowBeforeActivation($nodes);
            if (!empty($validationErrors)) {
                return new \WP_Error('activation_validation_failed', implode("\n", $validationErrors), ['status' => 400, 'errors' => $validationErrors]);
            }
        }

        // Create automation
        $automationId = $automationRepo->create([
            'name' => $automationData['name'] ?? __('New Workflow', 'mailerpress'),
            'status' => $automationData['status'] ?? 'DRAFT',
            'run_once_per_subscriber' => $automationData['run_once_per_subscriber'] ?? false,
            'author' => get_current_user_id() ?: null,
        ]);

        if (!$automationId) {
            return new \WP_Error(
                'create_failed',
                __('Failed to create automation', 'mailerpress'),
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

            // Sync branches for CONDITION steps
            if ($node['type'] === 'CONDITION' && !empty($settings['branches']) && $stepId) {
                $stepRepo->syncBranches($stepId, $settings['branches']);
            }

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
     * Whether the submitted nodes create exactly the steps of a template (same types and keys, settings aside).
     *
     * @param string[] $signature "TYPE:key" of each template step.
     */
    private function nodesMatchTemplate(array $nodes, array $signature): bool
    {
        $submitted = array_map(
            static fn($node): string => \is_array($node) ? strtoupper((string) ($node['type'] ?? '')) . ':' . (string) ($node['key'] ?? '') : '',
            $nodes
        );
        sort($submitted);
        sort($signature);

        return $submitted === $signature;
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

    private function isPluginActiveSafely(string $pluginFile): bool
    {
        if (function_exists('is_plugin_active') && is_plugin_active($pluginFile)) {
            return true;
        }

        $activePlugins = (array) get_option('active_plugins', []);
        if (function_exists('apply_filters')) {
            $activePlugins = (array) apply_filters('active_plugins', $activePlugins);
        }

        if (in_array($pluginFile, $activePlugins, true)) {
            return true;
        }

        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $networkActivePlugins = (array) get_site_option('active_sitewide_plugins', []);
            if (isset($networkActivePlugins[$pluginFile])) {
                return true;
            }
        }

        return false;
    }

    private function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce')
            || function_exists('WC')
            || function_exists('wc_get_order')
            || function_exists('wc_get_order_statuses')
            || function_exists('wc_get_customer_total_spent')
            || defined('WC_VERSION')
            || defined('WC_PLUGIN_FILE')
            || $this->isPluginActiveSafely('woocommerce/woocommerce.php');
    }

    /**
     * Validate workflow nodes before activation.
     * Checks that all send_email nodes have templates with actual HTML content.
     */
    private function validateWorkflowBeforeActivation(array $nodes): array
    {
        $errors = [];
        $campaignsModel = new \MailerPress\Models\Campaigns();

        foreach ($nodes as $node) {
            $key = $node['key'] ?? '';
            $type = $node['type'] ?? '';

            // Lets extensions validate their steps, e.g. the goals of MailerPress Pro.
            $errors = array_merge($errors, (array) apply_filters('mailerpress_workflow_node_errors', [], $node));

            if ($type !== 'ACTION' || ($key !== 'send_email' && $key !== 'send_mail')) {
                continue;
            }

            $settings = $node['settings'] ?? [];
            $templateId = $settings['template_id'] ?? null;
            $emailName = $settings['name'] ?? __('Unnamed email', 'mailerpress');

            if (empty($templateId)) {
                $errors[] = sprintf(
                    __('"%s": no email template assigned. Please create the email content first.', 'mailerpress'),
                    $emailName
                );
                continue;
            }

            $campaign = $campaignsModel->find((int) $templateId);

            if (!$campaign) {
                $errors[] = sprintf(
                    __('"%s": email template #%s not found. It may have been deleted.', 'mailerpress'),
                    $emailName,
                    $templateId
                );
                continue;
            }

            // Check for actual HTML content
            $hasContent = !empty($campaign->content_html);
            $hasCachedContent = !empty(get_option('mailerpress_batch_' . $templateId . '_html'));

            if (!$hasContent && !$hasCachedContent) {
                $errors[] = sprintf(
                    __('"%s": email template has no content. Please open the email editor and design your email before activating.', 'mailerpress'),
                    $emailName
                );
            }
        }

        return $errors;
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEditAutomation']
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
                __('Missing automation or nodes data', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Check if automation exists
        $existingAutomation = $automationRepo->find($automationId);
        if (!$existingAutomation) {
            return new \WP_Error(
                'not_found',
                __('Automation not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        $automationData = $body['automation'];
        $nodes = $body['nodes'];

        // Count non-trigger nodes (steps that require Pro)
        $nonTriggerNodes = array_filter($nodes, function ($node) {
            return ($node['type'] ?? '') !== 'TRIGGER';
        });

        // Free users can have up to 1 non-trigger node (1 trigger + 1 action from free templates)
        // Pro users have no limits
        if (count($nonTriggerNodes) > 1 && !$this->isProActive()) {
            return new \WP_Error(
                'pro_required',
                __('Adding more steps to workflows requires MailerPress Pro. Free users can use 1 trigger and 1 action.', 'mailerpress'),
                ['status' => 403]
            );
        }

        if ($this->nodesContainGoal($nodes) && !$this->isProActive()) {
            return new \WP_Error(
                'pro_required',
                __('Goals require MailerPress Pro. Please upgrade to measure conversions inside your automations.', 'mailerpress'),
                ['status' => 403]
            );
        }

        $sensitiveNodesValidation = $this->validateSensitiveNodes($nodes, $request);
        if ($sensitiveNodesValidation !== true) {
            return $sensitiveNodesValidation;
        }

        // Validate email content before enabling
        $newStatus = $automationData['status'] ?? null;
        if ($newStatus === 'ENABLED') {
            $validationErrors = $this->validateWorkflowBeforeActivation($nodes);
            if (!empty($validationErrors)) {
                return new \WP_Error(
                    'activation_validation_failed',
                    implode("\n", $validationErrors),
                    ['status' => 400, 'errors' => $validationErrors]
                );
            }
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

            // Sync branches for CONDITION steps
            if ($node['type'] === 'CONDITION' && !empty($settings['branches']) && $stepId) {
                $stepRepo->syncBranches($stepId, $settings['branches']);
            }

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

        // Lets extensions react to the saved steps, e.g. MailerPress Pro drops the goal records of deleted steps.
        do_action('mailerpress_workflow_steps_saved', $automationId, array_values(array_filter($newStepIds)));

        // Get updated automation
        $automation = $automationRepo->find($automationId);

        // Trigger hook for custom triggers to re-register hooks
        do_action('mailerpress_workflow_updated', $automationId);

        if (($automationData['status'] ?? null) === 'ENABLED' && $this->nodesContainTrigger($nodes, CustomerInactiveTrigger::TRIGGER_KEY)) {
            CustomerInactiveTrigger::scheduleImmediateCheck();
        }

        return new \WP_REST_Response([
            'automation' => $automation ? $automation->toArray() : null,
            'nodes' => $createdNodes,
        ], 200);
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)/retry-failed',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEditAutomation']
    )]
    public function retryFailedJobs(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $automationId = (int) $request->get_param('id');
        $jobId = $request->get_param('job_id');

        $jobRepo = new AutomationJobRepository();
        $executor = new \MailerPress\Core\Workflows\Services\WorkflowExecutor();

        // Retry a single job or all failed jobs for this automation
        if ($jobId) {
            $job = $jobRepo->find((int) $jobId);
            if (!$job || $job->getAutomationId() !== $automationId) {
                return new \WP_Error('not_found', __('Job not found', 'mailerpress'), ['status' => 404]);
            }
            if ($job->getStatus() !== 'FAILED') {
                return new \WP_Error('invalid_status', __('Only failed jobs can be retried', 'mailerpress'), ['status' => 400]);
            }

            // Reset job to ACTIVE and re-execute
            $job->setStatus('ACTIVE');
            $job->setRetryCount(0);
            $job->setLastError(null);
            $jobRepo->update($job);
            $executor->executeJob($job->getId());

            return new \WP_REST_Response(['retried' => 1, 'job_id' => $job->getId()], 200);
        }

        // Retry all failed jobs for this automation
        global $wpdb;
        $table = \MailerPress\Core\Enums\Tables::get(\MailerPress\Core\Enums\Tables::MAILERPRESS_AUTOMATION_JOBS);
        $failedJobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE automation_id = %d AND status = 'FAILED' ORDER BY created_at ASC",
                $automationId
            )
        );

        if (empty($failedJobs)) {
            return new \WP_REST_Response(['retried' => 0, 'message' => __('No failed jobs to retry', 'mailerpress')], 200);
        }

        $retriedCount = 0;
        foreach ($failedJobs as $failedJob) {
            $job = $jobRepo->find((int) $failedJob->id);
            if ($job) {
                $job->setStatus('ACTIVE');
                $job->setRetryCount(0);
                $job->setLastError(null);
                $jobRepo->update($job);

                // Schedule via ActionScheduler to avoid timeout
                if (function_exists('as_schedule_single_action')) {
                    as_schedule_single_action(
                        time() + ($retriedCount * 2), // stagger by 2 seconds
                        'mailerpress_continue_workflow',
                        [['job_id' => $job->getId()]],
                        'mailerpress_workflows'
                    );
                } else {
                    $executor->executeJob($job->getId());
                }
                $retriedCount++;
            }
        }

        return new \WP_REST_Response([
            'retried' => $retriedCount,
            'message' => sprintf(__('%d failed jobs queued for retry', 'mailerpress'), $retriedCount),
        ], 200);
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canReadAutomation']
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
                __('Automation not found', 'mailerpress'),
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
                'type' => strtoupper($step->getType()),
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
        permissionCallback: [Permissions::class, 'canDeleteAutomation']
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
                __('Automation not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Delete all steps first
        $stepRepo->deleteByAutomationId($automationId);

        // Lets extensions delete their data, e.g. the A/B tests of MailerPress Pro.
        do_action('mailerpress_workflow_deleting', $automationId);

        // Delete automation
        $deleted = $automationRepo->delete($automationId);

        if (!$deleted) {
            return new \WP_Error(
                'delete_failed',
                __('Failed to delete automation', 'mailerpress'),
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
        permissionCallback: [Permissions::class, 'canDeleteAutomations']
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

                // Lets extensions delete their data, e.g. the A/B tests of MailerPress Pro.
                do_action('mailerpress_workflow_deleting', $automationId);

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
        permissionCallback: [Permissions::class, 'canUpdateAutomationStatus']
    )]
    public function updateStatus(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $automationRepo = new AutomationRepository();
        $stepRepo = new StepRepository();

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

            if ($status === 'ENABLED') {
                foreach ($automations as $automation) {
                    $validation = $this->validateStoredSensitiveNodes((int) $automation->getId(), $stepRepo, $request);
                    if ($validation !== true) {
                        return $validation;
                    }
                }
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

                        if ($status === 'ENABLED' && $stepRepo->findTriggerByKey($automationId, CustomerInactiveTrigger::TRIGGER_KEY)) {
                            CustomerInactiveTrigger::scheduleImmediateCheck();
                        }
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
                __('No workflow IDs provided.', 'mailerpress'),
                ['status' => 400]
            );
        }

        if ($status === 'ENABLED') {
            foreach ($ids as $id) {
                if ($automationRepo->find($id)) {
                    $validation = $this->validateStoredSensitiveNodes($id, $stepRepo, $request);
                    if ($validation !== true) {
                        return $validation;
                    }
                }
            }
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

                if ($status === 'ENABLED' && $stepRepo->findTriggerByKey($id, CustomerInactiveTrigger::TRIGGER_KEY)) {
                    CustomerInactiveTrigger::scheduleImmediateCheck();
                }
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
            /* translators: %d: number of workflows. */
            ? sprintf(_n('Status updated for %d workflow.', 'Status updated for %d workflows.', $updatedCount, 'mailerpress'), $updatedCount)
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
        permissionCallback: [Permissions::class, 'canReadAutomation']
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
                __('Automation not found', 'mailerpress'),
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
        $automationOwnerWhere = $this->automationOwnerWhere($request);
        $automationAliasOwnerWhere = $this->automationOwnerWhere($request, 'a.author');
        $jobOwnerWhere = $this->automationResourceOwnerWhere($request);
        $logOwnerWhere = $this->automationResourceOwnerWhere($request);
        $logAliasOwnerWhere = $this->automationResourceOwnerWhere($request, 'l.automation_id');

        // Statistiques globales
        $totalWorkflows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$automationsTable} WHERE 1=1 {$automationOwnerWhere}");
        $enabledWorkflows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$automationsTable} WHERE status = 'ENABLED' {$automationOwnerWhere}");
        $draftWorkflows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$automationsTable} WHERE status = 'DRAFT' {$automationOwnerWhere}");

        // Statistiques des jobs (toutes périodes)
        $totalJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE 1=1 {$jobOwnerWhere}");
        $activeJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status IN ('ACTIVE', 'PROCESSING', 'WAITING') {$jobOwnerWhere}");
        $completedJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'COMPLETED' {$jobOwnerWhere}");
        $failedJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'FAILED' {$jobOwnerWhere}");
        $uniqueUsers = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$jobsTable} WHERE 1=1 {$jobOwnerWhere}");

        // Construire la condition WHERE selon la période
        if ($isToday) {
            $dateCondition = "DATE(created_at) = CURDATE()";
        } else {
            $dateCondition = $wpdb->prepare("created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $interval);
        }

        // Statistiques sur la période sélectionnée
        $intervalJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE {$dateCondition} {$jobOwnerWhere}");
        $intervalCompleted = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'COMPLETED' AND {$dateCondition} {$jobOwnerWhere}");
        $intervalFailed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'FAILED' AND {$dateCondition} {$jobOwnerWhere}");
        $intervalUniqueUsers = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$jobsTable} WHERE {$dateCondition} {$jobOwnerWhere}");

        // Calcul des taux
        $successRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 2) : 0;
        $failureRate = $totalJobs > 0 ? round(($failedJobs / $totalJobs) * 100, 2) : 0;
        $intervalSuccessRate = $intervalJobs > 0 ? round(($intervalCompleted / $intervalJobs) * 100, 2) : 0;

        // Previous period comparison for trend indicators
        if ($isToday) {
            $prevDateCondition = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } else {
            $prevDateCondition = $wpdb->prepare(
                "created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $interval * 2,
                $interval
            );
        }
        $prevJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE {$prevDateCondition} {$jobOwnerWhere}");
        $prevCompleted = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'COMPLETED' AND {$prevDateCondition} {$jobOwnerWhere}");
        $prevFailed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable} WHERE status = 'FAILED' AND {$prevDateCondition} {$jobOwnerWhere}");
        $prevUniqueUsers = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$jobsTable} WHERE {$prevDateCondition} {$jobOwnerWhere}");
        $prevSuccessRate = $prevJobs > 0 ? round(($prevCompleted / $prevJobs) * 100, 2) : 0;

        // Average execution time (completed jobs only, in seconds)
        $avgExecutionTime = (float) $wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at))
             FROM {$jobsTable}
             WHERE status = 'COMPLETED' AND {$dateCondition} AND updated_at IS NOT NULL AND updated_at > created_at
             {$jobOwnerWhere}"
        );

        // Recent activity feed (last 5 executions)
        $recentActivity = $wpdb->get_results(
            "SELECT j.id as job_id, j.automation_id, j.user_id, j.status, j.created_at, j.updated_at,
                    a.name as workflow_name,
                    u.user_email
             FROM {$jobsTable} j
             INNER JOIN {$automationsTable} a ON j.automation_id = a.id
             LEFT JOIN {$wpdb->users} u ON j.user_id = u.ID
             WHERE 1=1 {$automationAliasOwnerWhere}
             ORDER BY j.created_at DESC
             LIMIT 5",
            ARRAY_A
        );

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
                MAX(j.created_at) as last_execution,
                AVG(CASE WHEN j.status = 'COMPLETED' AND j.updated_at IS NOT NULL AND j.updated_at > j.created_at
                    THEN TIMESTAMPDIFF(SECOND, j.created_at, j.updated_at) ELSE NULL END) as avg_duration
            FROM {$automationsTable} a
            LEFT JOIN {$jobsTable} j ON a.id = j.automation_id
                AND DATE(j.created_at) = CURDATE()
            WHERE 1=1 {$automationAliasOwnerWhere}
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
                    MAX(j.created_at) as last_execution,
                    AVG(CASE WHEN j.status = 'COMPLETED' AND j.updated_at IS NOT NULL AND j.updated_at > j.created_at
                        THEN TIMESTAMPDIFF(SECOND, j.created_at, j.updated_at) ELSE NULL END) as avg_duration
                FROM {$automationsTable} a
                LEFT JOIN {$jobsTable} j ON a.id = j.automation_id
                    AND j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                WHERE 1=1 {$automationAliasOwnerWhere}
                GROUP BY a.id, a.name, a.status
                HAVING total_jobs > 0
                ORDER BY completed_jobs DESC, total_jobs DESC
                LIMIT 10",
                $interval
            );
        }
        $topWorkflows = $wpdb->get_results($topWorkflowsQuery, ARRAY_A);

        // Per-workflow sparkline data (jobs per day for last 7 days) and last error
        $workflowIds = array_map(function ($wf) { return (int) $wf['id']; }, $topWorkflows);
        $sparklineData = [];
        $lastErrors = [];

        if (!empty($workflowIds)) {
            $idPlaceholders = implode(',', array_fill(0, count($workflowIds), '%d'));

            // Sparkline: jobs per day per workflow (last 7 days)
            $sparklineQuery = $wpdb->prepare(
                "SELECT automation_id, DATE(created_at) as date, COUNT(*) as total
                 FROM {$jobsTable}
                 WHERE automation_id IN ({$idPlaceholders})
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY automation_id, DATE(created_at)
                 ORDER BY automation_id, date ASC",
                ...$workflowIds
            );
            $sparklineRows = $wpdb->get_results($sparklineQuery, ARRAY_A);
            foreach ($sparklineRows as $row) {
                $sparklineData[(int) $row['automation_id']][] = [
                    'date' => $row['date'],
                    'total' => (int) $row['total'],
                ];
            }

            // Last error per workflow
            $lastErrorQuery = $wpdb->prepare(
                "SELECT j.automation_id, j.id as job_id, j.created_at, j.user_id, u.user_email,
                        (SELECT l.data FROM {$logsTable} l
                         WHERE l.automation_id = j.automation_id
                         AND l.user_id = j.user_id
                         AND l.status = 'EXITED'
                         ORDER BY l.created_at DESC LIMIT 1) as error_data
                 FROM {$jobsTable} j
                 INNER JOIN (
                     SELECT automation_id, MAX(id) as max_id
                     FROM {$jobsTable}
                     WHERE automation_id IN ({$idPlaceholders}) AND status = 'FAILED'
                     GROUP BY automation_id
                 ) latest ON j.id = latest.max_id
                 LEFT JOIN {$wpdb->users} u ON j.user_id = u.ID",
                ...$workflowIds
            );
            $lastErrorRows = $wpdb->get_results($lastErrorQuery, ARRAY_A);
            foreach ($lastErrorRows as $row) {
                $errorMessage = null;
                if (!empty($row['error_data'])) {
                    $decoded = json_decode($row['error_data'], true);
                    $errorMessage = $decoded['error'] ?? $decoded['message'] ?? null;
                }
                $lastErrors[(int) $row['automation_id']] = [
                    'job_id' => (int) $row['job_id'],
                    'date' => $row['created_at'],
                    'user_email' => $row['user_email'] ?? null,
                    'message' => $errorMessage,
                ];
            }
        }

        // Évolution des jobs par jour sur la période
        if ($isToday) {
            $jobsByDayQuery = "SELECT
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed
            FROM {$jobsTable}
            WHERE DATE(created_at) = CURDATE()
            {$jobOwnerWhere}
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
                {$jobOwnerWhere}
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
             WHERE 1=1 {$jobOwnerWhere}
             GROUP BY status",
            ARRAY_A
        );

        $statusDistribution = [];
        foreach ($jobsByStatus as $stat) {
            $statusDistribution[$stat['status']] = (int) $stat['count'];
        }

        // Heatmap d'activité : nombre de jobs par jour de la semaine (0=Lun..6=Dim) et heure (0-23)
        if ($isToday) {
            $heatmapQuery = "SELECT
                WEEKDAY(created_at) as day_of_week,
                HOUR(created_at) as hour_of_day,
                COUNT(*) as count
            FROM {$jobsTable}
            WHERE DATE(created_at) = CURDATE()
            {$jobOwnerWhere}
            GROUP BY WEEKDAY(created_at), HOUR(created_at)";
        } else {
            $heatmapQuery = $wpdb->prepare(
                "SELECT
                    WEEKDAY(created_at) as day_of_week,
                    HOUR(created_at) as hour_of_day,
                    COUNT(*) as count
                FROM {$jobsTable}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                {$jobOwnerWhere}
                GROUP BY WEEKDAY(created_at), HOUR(created_at)",
                $interval
            );
        }
        $heatmapRows = $wpdb->get_results($heatmapQuery, ARRAY_A);

        // Construire la matrice 7x24
        $activityHeatmap = [];
        foreach ($heatmapRows as $row) {
            $activityHeatmap[] = [
                'day' => (int) $row['day_of_week'],
                'hour' => (int) $row['hour_of_day'],
                'count' => (int) $row['count'],
            ];
        }

        $dashboard = [
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
                'avg_execution_time' => round($avgExecutionTime, 1),
            ],
            'previous_period' => [
                'total_jobs' => $prevJobs,
                'completed_jobs' => $prevCompleted,
                'failed_jobs' => $prevFailed,
                'unique_users' => $prevUniqueUsers,
                'success_rate' => $prevSuccessRate,
            ],
            'top_workflows' => array_map(function ($wf) use ($sparklineData, $lastErrors) {
                $wfId = (int) $wf['id'];
                return [
                    'id' => $wfId,
                    'name' => $wf['name'],
                    'status' => $wf['status'],
                    'total_jobs' => (int) $wf['total_jobs'],
                    'completed_jobs' => (int) $wf['completed_jobs'],
                    'failed_jobs' => (int) $wf['failed_jobs'],
                    'unique_users' => (int) $wf['unique_users'],
                    'last_execution' => $wf['last_execution'],
                    'avg_duration' => $wf['avg_duration'] !== null ? round((float) $wf['avg_duration'], 1) : null,
                    'sparkline' => $sparklineData[$wfId] ?? [],
                    'last_error' => $lastErrors[$wfId] ?? null,
                ];
            }, $topWorkflows),
            'recent_activity' => array_map(function ($activity) {
                return [
                    'job_id' => (int) $activity['job_id'],
                    'automation_id' => (int) $activity['automation_id'],
                    'workflow_name' => $activity['workflow_name'],
                    'user_email' => $activity['user_email'] ?? null,
                    'status' => $activity['status'],
                    'created_at' => $activity['created_at'],
                    'updated_at' => $activity['updated_at'],
                ];
            }, $recentActivity ?: []),
            'jobs_by_day' => array_map(function ($day) {
                return [
                    'date' => $day['date'],
                    'total' => (int) $day['total'],
                    'completed' => (int) $day['completed'],
                    'failed' => (int) $day['failed'],
                ];
            }, $jobsByDay),
            'status_distribution' => $statusDistribution,
            'activity_heatmap' => $activityHeatmap,
        ];

        /** Filters the workflow dashboard data, e.g. to add statistics computed by an extension. */
        $dashboard = apply_filters('mailerpress_workflow_dashboard_data', $dashboard, [
            'interval' => $interval,
            'log_owner_where' => $logOwnerWhere,
            'log_alias_owner_where' => $logAliasOwnerWhere,
        ]);

        return new \WP_REST_Response($dashboard, 200);
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
        $ownerWhere = $this->automationOwnerWhere($request, 'a.author');

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
            {$ownerWhere}
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
                {$ownerWhere}
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
                         AND automation_id = %d
                         AND (data LIKE '%%\"customer_email\"%%' OR data LIKE '%%\"email\"%%')
                         ORDER BY created_at DESC
                         LIMIT 1",
                        $userId,
                        (int) $jobData['automation_id']
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

            // Récupérer les logs pour ce workflow pour ce contact, avec type et key depuis la table steps
            $stepsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_STEPS);
            $stepsQuery = $wpdb->prepare(
                "SELECT
                    l.step_id,
                    l.status,
                    l.data,
                    l.created_at,
                    s.type AS step_type,
                    s.key AS step_key
                FROM {$logsTable} l
                LEFT JOIN {$stepsTable} s ON s.automation_id = l.automation_id AND s.step_id = l.step_id
                WHERE l.automation_id = %d
                    AND l.user_id = %d
                    AND l.created_at >= %s
                ORDER BY l.created_at ASC",
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
                        'step_type' => $step['step_type'] ?? null,
                        'step_key' => $step['step_key'] ?? null,
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
                'total_jobs' => $contact['total_jobs'] ?? 0,
                'completed_jobs' => $contact['completed_jobs'] ?? 0,
                'failed_jobs' => $contact['failed_jobs'] ?? 0,
                'last_execution' => $contact['last_execution'] ?? null,
                'workflows' => $contact['workflows'] ?? [],
            ];
        }, $contacts);

        return new \WP_REST_Response([
            'posts' => $posts,
            'count' => $total_count,
            'pages' => $total_pages,
        ], 200);
    }

    #[Endpoint(
        'workflows/failed-jobs',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAutomations']
    )]
    public function getFailedJobs(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $intervalParam = $request->get_param('interval');
        $isToday = ($intervalParam === 'today');
        $interval = $isToday ? 1 : ((int) $intervalParam ?: 30);

        $automationsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS);
        $jobsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_JOBS);
        $logsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_LOG);
        $ownerWhere = $this->automationOwnerWhere($request, 'a.author');

        // Construire la condition WHERE selon la période
        if ($isToday) {
            $dateCondition = "DATE(j.created_at) = CURDATE()";
        } else {
            $dateCondition = $wpdb->prepare("j.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $interval);
        }

        // Récupérer tous les jobs échoués avec leurs détails
        $failedJobsQuery = "SELECT
                j.id,
                j.automation_id,
                j.user_id,
                j.status,
                j.created_at,
                j.updated_at,
                a.name as workflow_name
            FROM {$jobsTable} j
            INNER JOIN {$automationsTable} a ON j.automation_id = a.id
            WHERE j.status = 'FAILED'
                AND {$dateCondition}
                {$ownerWhere}
            ORDER BY j.created_at DESC
            LIMIT 500";

        $failedJobs = $wpdb->get_results($failedJobsQuery, ARRAY_A);

        // Enrichir avec les informations utilisateur et les steps
        $enrichedJobs = [];
        $contactsModel = new Contacts();

        foreach ($failedJobs as $job) {
            $userId = (int) $job['user_id'];
            $jobId = (int) $job['id'];
            $automationId = (int) $job['automation_id'];

            // Récupérer l'email de l'utilisateur
            $userEmail = '';
            if ($userId > 0) {
                $user = get_userdata($userId);
                if ($user && $user->user_email) {
                    $userEmail = $user->user_email;
                } else {
                    $contact = $contactsModel->get($userId);
                    if ($contact) {
                        $userEmail = $contact->email ?? '';
                    }
                }
            }

            // Si pas d'email trouvé, chercher dans les logs
            if (empty($userEmail)) {
                $logQuery = $wpdb->prepare(
                    "SELECT data FROM {$logsTable}
                     WHERE user_id = %d
                     AND automation_id = %d
                     AND (data LIKE '%%\"customer_email\"%%' OR data LIKE '%%\"email\"%%')
                     ORDER BY created_at DESC
                     LIMIT 1",
                    $userId,
                    $automationId
                );
                $logData = $wpdb->get_var($logQuery);

                if ($logData) {
                    $logContext = json_decode($logData, true);
                    if (isset($logContext['customer_email'])) {
                        $userEmail = $logContext['customer_email'];
                    } elseif (isset($logContext['email'])) {
                        $userEmail = $logContext['email'];
                    }
                }
            }

            // Récupérer les steps/logs pour ce job
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
                $job['created_at']
            );
            $steps = $wpdb->get_results($stepsQuery, ARRAY_A);

            $enrichedJobs[] = [
                'id' => $jobId,
                'automation_id' => $automationId,
                'workflow_name' => $job['workflow_name'],
                'user_id' => $userId,
                'user_email' => $userEmail,
                'status' => $job['status'],
                'created_at' => $job['created_at'],
                'updated_at' => $job['updated_at'],
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

        return new \WP_REST_Response([
            'failed_jobs' => $enrichedJobs,
            'count' => count($enrichedJobs),
        ], 200);
    }

    #[Endpoint(
        'workflows/(?P<id>\d+)/logs',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canReadAutomation']
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
                __('Automation not found', 'mailerpress'),
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
     * Does the payload contain at least one goal step?
     */
    private function nodesContainGoal(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'GOAL') {
                return true;
            }
        }

        return false;
    }

    private function nodesContainTrigger(array $nodes, string $triggerKey): bool
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'TRIGGER' && ($node['key'] ?? '') === $triggerKey) {
                return true;
            }
        }

        return false;
    }
}
