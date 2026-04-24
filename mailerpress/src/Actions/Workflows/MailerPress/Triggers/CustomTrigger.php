<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Triggers;

\defined('ABSPATH') || exit;

/**
 * Custom Hook Trigger
 * 
 * Allows developers to use any WordPress hook as a workflow trigger.
 * This is useful for integrating custom plugins or creating custom hooks.
 * 
 * Usage example:
 * - Hook name: 'my_custom_plugin_event'
 * - When this hook is fired: do_action('my_custom_plugin_event', $userId, $data);
 * - The workflow will receive all hook arguments in the context
 * 
 * @since 1.2.0
 */
class CustomTrigger
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'custom_trigger';

    /**
     * Whether dynamic hooks have already been registered in this request
     */
    private static bool $dynamicHooksRegistered = false;

    /**
     * Register the custom trigger
     * 
     * @param mixed $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        $definition = [
            'label' => __('Custom Hook', 'mailerpress'),
            'description' => __('Trigger a workflow on any WordPress hook. Perfect for developers and custom integrations. Configure the hook name and parameters below. Allows you to integrate your own plugins and features with the workflow system.', 'mailerpress'),
            'icon' => 'code',
            'category' => 'developer',
            'settings_schema' => [
                [
                    'key' => 'hook_name',
                    'label' => __('Hook Name', 'mailerpress'),
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => __('e.g., my_custom_plugin_event', 'mailerpress'),
                    'help' => __('Enter the WordPress hook name (action or filter) to listen to. This hook will be fired by your custom code or plugin.', 'mailerpress'),
                ],
                [
                    'key' => 'parameter_1_type',
                    'label' => __('Parameter 1 Type', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => 'email', 'label' => __('Email Address', 'mailerpress')],
                        ['value' => 'user_id', 'label' => __('User ID', 'mailerpress')],
                        ['value' => 'contact_id', 'label' => __('Contact ID', 'mailerpress')],
                        ['value' => 'custom', 'label' => __('Custom Data', 'mailerpress')],
                        ['value' => 'none', 'label' => __('None', 'mailerpress')],
                    ],
                    'help' => __('Specify the type of the first parameter passed to the hook. "Custom Data" is an array that can contain any data you want to pass to the workflow (e.g., order details, product info, custom fields).', 'mailerpress'),
                ],
                [
                    'key' => 'parameter_2_type',
                    'label' => __('Parameter 2 Type (Optional)', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => 'none', 'label' => __('None', 'mailerpress')],
                        ['value' => 'email', 'label' => __('Email Address', 'mailerpress')],
                        ['value' => 'user_id', 'label' => __('User ID', 'mailerpress')],
                        ['value' => 'contact_id', 'label' => __('Contact ID', 'mailerpress')],
                        ['value' => 'custom', 'label' => __('Custom Data', 'mailerpress')],
                    ],
                    'help' => __('Specify the type of the second parameter passed to the hook (optional). "Custom Data" is an array that can contain any data you want to pass to the workflow.', 'mailerpress'),
                ],
                [
                    'key' => 'code_example',
                    'label' => __('Code Example', 'mailerpress'),
                    'type' => 'textarea',
                    'required' => false,
                    'rows' => 6,
                    'readonly' => true,
                    'help' => __('Copy this code example to use in your plugin or theme. The hook name and parameters are automatically generated based on your configuration. Custom Data format: array with keys containing scalar values or arrays with "value" key.', 'mailerpress'),
                ],
            ],
        ];

        // Register the trigger with a generic hook that will be dynamically handled
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'mailerpress_custom_trigger', // Generic hook, will be dynamically registered
            self::contextBuilder(...),
            $definition
        );

        // Register dynamic hook listener on init
        // Use priority 20 to ensure it runs after plugins are loaded
        // Also register on plugins_loaded with higher priority to catch early hooks
        add_action('plugins_loaded', function () use ($manager) {
            self::registerDynamicHooks($manager);
        }, 20);

        add_action('init', function () use ($manager) {
            self::registerDynamicHooks($manager);
        }, 20); // Priority 20 to ensure it runs after plugins are loaded

        // Re-register hooks when workflow is updated
        add_action('mailerpress_workflow_updated', function () use ($manager) {
            self::$dynamicHooksRegistered = false;
            self::registerDynamicHooks($manager);
        });

        // Re-register hooks when workflow status changes
        add_action('mailerpress_workflow_status_changed', function () use ($manager) {
            self::$dynamicHooksRegistered = false;
            self::registerDynamicHooks($manager);
        });
    }

    /**
     * Register dynamic hooks for custom triggers
     * 
     * This method scans all enabled automations and registers hooks
     * for custom triggers based on their settings.
     * 
     * @param mixed $manager The trigger manager instance
     */
    public static function registerDynamicHooks($manager): void
    {
        // Prevent duplicate registration within the same request
        if (self::$dynamicHooksRegistered) {
            return;
        }
        self::$dynamicHooksRegistered = true;

        global $wpdb;

        // Get all enabled automations with custom_trigger
        $automationsTable = $wpdb->prefix . 'mailerpress_automations';
        $stepsTable = $wpdb->prefix . 'mailerpress_automations_steps';

        // Use a single query to check both tables exist instead of 2 SHOW TABLES
        $tableCheck = $wpdb->get_col(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($automationsTable)
            )
        );
        if (empty($tableCheck)) {
            return;
        }
        $tableCheck = $wpdb->get_col(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($stepsTable)
            )
        );
        if (empty($tableCheck)) {
            return;
        }

        $automations = $wpdb->get_results(
            "SELECT a.id, a.status, s.step_id, s.settings
            FROM {$automationsTable} a
            INNER JOIN {$stepsTable} s ON a.id = s.automation_id
            WHERE a.status = 'ENABLED'
            AND s.type = 'TRIGGER'
            AND s.key = 'custom_trigger'
            AND s.settings IS NOT NULL
            AND s.settings != ''"
        );


        foreach ($automations as $automation) {
            $settings = json_decode($automation->settings, true);
            $hookName = $settings['hook_name'] ?? '';

            if (empty($hookName)) {
                continue;
            }

            // Remove existing hook if any (to avoid duplicates)
            remove_action($hookName, [self::class, 'handleCustomHook'], 10);

            // Register the custom hook
            add_action($hookName, [self::class, 'handleCustomHook'], 10, 10);
        }
    }

    /**
     * Handle custom hook execution
     * 
     * This method is called when any registered custom hook is fired.
     * It then triggers the workflow system with the custom trigger key.
     * 
     * @param mixed ...$args Hook arguments
     */
    public static function handleCustomHook(...$args): void
    {
        // Get the hook name that was called
        $hookName = current_filter();

        if (empty($hookName)) {
            return;
        }

        // Trigger the workflow system with the custom hook name
        // We'll use a special context that includes the hook name
        $context = [
            'hook_name' => $hookName,
            'hook_arguments' => $args,
        ];

        // Get the workflow system and trigger manager
        $workflowSystem = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
        $manager = $workflowSystem->getManager()->getTriggerManager();

        // Find all automations with custom_trigger that use this hook
        global $wpdb;
        $automationsTable = $wpdb->prefix . 'mailerpress_automations';
        $stepsTable = $wpdb->prefix . 'mailerpress_automations_steps';

        // Check if tables exist before querying
        $automationsExists = $wpdb->get_var("SHOW TABLES LIKE '{$automationsTable}'") === $automationsTable;
        $stepsExists = $wpdb->get_var("SHOW TABLES LIKE '{$stepsTable}'") === $stepsTable;

        if (!$automationsExists || !$stepsExists) {
            return; // Tables don't exist yet, skip
        }

        // Check if the 'id' column exists in automations table
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$automationsTable}", 0);
        if (!in_array('id', $columns, true)) {
            return; // Column 'id' doesn't exist yet, skip
        }

        $automations = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, s.step_id, s.settings
            FROM {$automationsTable} a
            INNER JOIN {$stepsTable} s ON a.id = s.automation_id
            WHERE a.status = 'ENABLED'
            AND s.type = 'TRIGGER'
            AND s.key = 'custom_trigger'
            AND s.settings LIKE %s",
            '%' . $wpdb->esc_like($hookName) . '%'
        ));

        foreach ($automations as $automation) {
            $settings = json_decode($automation->settings, true);
            $automationHookName = $settings['hook_name'] ?? '';

            if ($automationHookName !== $hookName) {
                continue;
            }


            // Use the context builder to create proper context
            // Pass settings separately to avoid confusion with hook arguments
            $context = self::contextBuilder($settings, ...$args);

            // Manually trigger the workflow
            $manager->handleCustomTrigger('custom_trigger', $context, (int) $automation->id, $automation->step_id);
        }
    }

    /**
     * Build context from hook parameters
     * 
     * This extracts relevant data from the hook arguments and settings
     * to create a context for the workflow.
     * 
     * Parameter structure:
     * - Parameter 1: Email address (required) - used to find/create contact
     * - Parameter 2: Custom data array (optional) - structure: ['key' => ['value' => 'scalar']]
     * 
     * @param array|null $settings Trigger settings (first parameter for manual calls)
     * @param mixed ...$args Hook arguments
     * @return array Context data for the workflow
     */
    public static function contextBuilder($settings = null, ...$args): array
    {
        // If first argument is not an array, it means settings weren't passed
        // and all arguments are hook arguments
        if (!is_array($settings) || !isset($settings['hook_name'])) {
            // All arguments are hook arguments, settings is null
            $args = $settings !== null ? [$settings, ...$args] : $args;
            $settings = null;
        }

        $context = [
            'hook_name' => $settings['hook_name'] ?? current_filter(),
            'hook_arguments' => $args,
            'hook_arguments_count' => count($args),
        ];

        $email = null;
        $customData = null;
        $userId = null;

        // Process parameters based on configured types
        $parameterTypes = [
            1 => $settings['parameter_1_type'] ?? null,
            2 => $settings['parameter_2_type'] ?? null,
        ];

        // Process each parameter based on its configured type
        foreach ($parameterTypes as $index => $paramType) {
            $argIndex = $index - 1; // Convert to 0-based index


            if (empty($paramType) || $paramType === 'none' || !isset($args[$argIndex])) {
                continue;
            }

            $arg = $args[$argIndex];

            switch ($paramType) {
                case 'email':
                    if (is_string($arg) && is_email($arg)) {
                        $email = sanitize_email($arg);
                        $context['email'] = $email;

                        // Find or create contact by email
                        $contactsModel = new \MailerPress\Models\Contacts();
                        $contact = $contactsModel->getContactByEmail($email);

                        if ($contact) {
                            $userId = (int) $contact->contact_id;
                            $context['contact_id'] = $userId;
                            $context['user_id'] = $userId;

                            // Add contact data to context
                            $context['first_name'] = $contact->first_name ?? '';
                            $context['last_name'] = $contact->last_name ?? '';
                            $context['subscription_status'] = $contact->subscription_status ?? 'pending';
                        } else {
                            // Contact doesn't exist - create it
                            global $wpdb;
                            $contactTable = $wpdb->prefix . 'mailerpress_contact';

                            $wpdb->insert($contactTable, [
                                'email' => $email,
                                'subscription_status' => 'subscribed',
                                'opt_in_source' => 'custom_trigger',
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql'),
                            ]);

                            $userId = (int) $wpdb->insert_id;
                            $context['contact_id'] = $userId;
                            $context['user_id'] = $userId;
                            $context['subscription_status'] = 'subscribed';

                            // Trigger contact_created hook
                            do_action('mailerpress_contact_created', $userId);
                        }
                    }
                    break;

                case 'user_id':
                    if (is_numeric($arg)) {
                        $userId = (int) $arg;
                        $context['user_id'] = $userId;
                        $context["parameter_{$index}_user_id"] = $userId;
                    }
                    break;

                case 'contact_id':
                    if (is_numeric($arg)) {
                        $contactId = (int) $arg;
                        $context['contact_id'] = $contactId;
                        $context['user_id'] = $contactId; // Use contact_id as user_id
                        $context["parameter_{$index}_contact_id"] = $contactId;
                        $userId = $contactId;
                    }
                    break;

                case 'custom':
                    if (is_array($arg)) {
                        $customData = $arg;
                        $context['custom_data'] = $customData;
                        $context["parameter_{$index}_custom_data"] = $customData;

                        // Extract custom data values
                        // Format: ['key' => ['value' => 'scalar']]
                        foreach ($customData as $key => $valueArray) {
                            if (is_array($valueArray) && isset($valueArray['value'])) {
                                $value = $valueArray['value'];
                                // Only add scalar values to context
                                if (is_scalar($value)) {
                                    $context[$key] = $value;
                                }
                            } elseif (is_scalar($valueArray)) {
                                // Also support direct scalar values
                                $context[$key] = $valueArray;
                            }
                        }
                    }
                    break;
            }
        }

        // Fallback: If parameter_1_type is not configured, try to auto-detect email from first argument
        if (empty($email) && empty($parameterTypes[1]) && !empty($args[0])) {
            $firstArg = $args[0];
            // If first argument is a string and looks like an email, treat it as email
            if (is_string($firstArg) && is_email($firstArg)) {
                $email = sanitize_email($firstArg);
                $context['email'] = $email;

                // Find or create contact by email
                $contactsModel = new \MailerPress\Models\Contacts();
                $contact = $contactsModel->getContactByEmail($email);

                if ($contact) {
                    $userId = (int) $contact->contact_id;
                    $context['contact_id'] = $userId;
                    $context['user_id'] = $userId;

                    // Add contact data to context
                    $context['first_name'] = $contact->first_name ?? '';
                    $context['last_name'] = $contact->last_name ?? '';
                    $context['subscription_status'] = $contact->subscription_status ?? 'pending';
                } else {
                    // Contact doesn't exist - create it
                    global $wpdb;
                    $contactTable = $wpdb->prefix . 'mailerpress_contact';

                    $wpdb->insert($contactTable, [
                        'email' => $email,
                        'subscription_status' => 'subscribed',
                        'opt_in_source' => 'custom_trigger',
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);

                    $userId = (int) $wpdb->insert_id;
                    $context['contact_id'] = $userId;
                    $context['user_id'] = $userId;
                    $context['subscription_status'] = 'subscribed';

                    // Trigger contact_created hook
                    do_action('mailerpress_contact_created', $userId);
                }
            }
        }

        // Fallback: If parameter_2_type is not configured but second argument is an array, treat it as custom data
        if (empty($customData) && empty($parameterTypes[2]) && !empty($args[1]) && is_array($args[1])) {
            $customData = $args[1];
            $context['custom_data'] = $customData;
            $context['parameter_2_custom_data'] = $customData;

            // Extract custom data values
            foreach ($customData as $key => $valueArray) {
                if (is_array($valueArray) && isset($valueArray['value'])) {
                    $value = $valueArray['value'];
                    if (is_scalar($value)) {
                        $context[$key] = $value;
                    }
                } elseif (is_scalar($valueArray)) {
                    $context[$key] = $valueArray;
                }
            }
        }

        // Fallback: Try to extract user_id from arguments if specified in settings
        if (empty($userId) && $settings && isset($settings['user_id_argument']) && $settings['user_id_argument'] !== '') {
            $userIdIndex = (int) $settings['user_id_argument'];
            if (isset($args[$userIdIndex])) {
                $userId = (int) $args[$userIdIndex];
                $context['user_id'] = $userId;
            }
        }

        // Fallback: Try to get user_id from common argument positions
        if (empty($userId)) {
            // Check first argument (if not email)
            if (!empty($args[0]) && is_numeric($args[0])) {
                $userId = (int) $args[0];
                $context['user_id'] = $userId;
            }
            // Check if any argument is a user object
            elseif (!empty($args[0]) && is_object($args[0]) && isset($args[0]->ID)) {
                $userId = (int) $args[0]->ID;
                $context['user_id'] = $userId;
            }
            // Check if any argument is an array with user_id
            elseif (!empty($args[0]) && is_array($args[0]) && isset($args[0]['user_id'])) {
                $userId = (int) $args[0]['user_id'];
                $context['user_id'] = $userId;
            }
            // Fallback to current user
            else {
                $userId = get_current_user_id();
                if ($userId) {
                    $context['user_id'] = $userId;
                }
            }
        }

        // Extract all arguments as named context for easier access
        foreach ($args as $index => $arg) {
            $context["arg_{$index}"] = $arg;

            // If argument is an array, merge its keys into context (but not custom_data structure)
            if (is_array($arg) && $index !== 1) { // Skip index 1 if it's custom_data
                $context = array_merge($context, $arg);
            }

            // If argument is an object, try to extract common properties
            if (is_object($arg)) {
                if (isset($arg->ID)) {
                    $context['object_id'] = $arg->ID;
                }
                if (isset($arg->post_id)) {
                    $context['post_id'] = $arg->post_id;
                }
                if (isset($arg->order_id)) {
                    $context['order_id'] = $arg->order_id;
                }
            }
        }

        return $context;
    }
}
