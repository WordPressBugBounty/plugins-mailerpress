<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\Contacts as ContactsModel;
use MailerPress\Models\Lists;
use MailerPress\Models\Tags;

/**
 * Create Contact Step Handler
 * 
 * This action handler creates a new contact in MailerPress based on the workflow context.
 * It can extract email, name, and other information from the trigger context (e.g., WooCommerce order,
 * WordPress user registration, form submission, etc.) and create a corresponding MailerPress contact.
 * 
 * The handler supports:
 * - Creating new contacts or updating existing ones (by email)
 * - Dynamic field mapping from trigger context using output_fields from the trigger definition
 * - Setting subscription status
 * - Adding to multiple lists
 * - Adding multiple tags
 * - Using dynamic placeholders from context (e.g., {{customer_email}}, {{customer_first_name}})
 * 
 * Field Mapping:
 * The action uses `trigger_mapping` field type which dynamically displays available fields
 * from the current workflow trigger's `output_fields` definition. This ensures users can only
 * map fields that are actually available in the trigger context.
 * 
 * @since 1.2.0
 */
class CreateContactStepHandler implements StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'create_contact';
    }

    public function getDefinition(): array
    {
        // Get dynamic lists
        $lists = Lists::getLists();
        $listOptions = [];
        foreach ($lists as $list) {
            $listOptions[] = [
                'value' => (string) $list['list_id'],
                'label' => $list['name'] ?? __('Unnamed list', 'mailerpress'),
            ];
        }

        // Get dynamic tags
        $tags = Tags::getAll();
        $tagOptions = [];
        foreach ($tags as $tag) {
            $tagOptions[] = [
                'value' => (string) $tag->tag_id,
                'label' => $tag->name ?? __('Unnamed tag', 'mailerpress'),
            ];
        }

        return [
            'key' => 'create_contact',
            'label' => __('Create Contact', 'mailerpress'),
            'description' => __('Create a new contact in MailerPress or update an existing one. Map fields from the trigger context to contact fields.', 'mailerpress'),
            'icon' => 'user-plus',
            'category' => 'contact',
            'type' => 'ACTION',
            // Mark this action as requiring trigger context mapping
            'uses_trigger_mapping' => true,
            'settings_schema' => [
                // ===== Field Mapping Section =====
                [
                    'key' => '_section_mapping',
                    'type' => 'section',
                    'label' => __('Field Mapping', 'mailerpress'),
                    'description' => __('Map trigger data to contact fields. Select fields from the trigger or enter custom values.', 'mailerpress'),
                ],
                [
                    'key' => 'email',
                    'label' => __('Email', 'mailerpress'),
                    'type' => 'trigger_mapping',
                    'target_type' => 'email',
                    'required' => true,
                    'placeholder' => __('Select email field from trigger...', 'mailerpress'),
                    'help' => __('The email address for the contact. Map from trigger data.', 'mailerpress'),
                ],
                [
                    'key' => 'first_name',
                    'label' => __('First Name', 'mailerpress'),
                    'type' => 'trigger_mapping',
                    'target_type' => 'string',
                    'required' => false,
                    'placeholder' => __('Select first name field from trigger...', 'mailerpress'),
                    'help' => __('The first name of the contact.', 'mailerpress'),
                ],
                [
                    'key' => 'last_name',
                    'label' => __('Last Name', 'mailerpress'),
                    'type' => 'trigger_mapping',
                    'target_type' => 'string',
                    'required' => false,
                    'placeholder' => __('Select last name field from trigger...', 'mailerpress'),
                    'help' => __('The last name of the contact.', 'mailerpress'),
                ],

                // ===== Contact Settings Section =====
                [
                    'key' => '_section_settings',
                    'type' => 'section',
                    'label' => __('Contact Settings', 'mailerpress'),
                ],
                [
                    'key' => 'subscription_status',
                    'label' => __('Subscription Status', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => 'subscribed', 'label' => __('Subscribed', 'mailerpress')],
                        ['value' => 'pending', 'label' => __('Pending (requires confirmation)', 'mailerpress')],
                        ['value' => 'unsubscribed', 'label' => __('Unsubscribed', 'mailerpress')],
                    ],
                    'default' => 'subscribed',
                    'help' => __('The subscription status for the new contact.', 'mailerpress'),
                ],
                [
                    'key' => 'lists',
                    'label' => __('Add to Lists', 'mailerpress'),
                    'type' => 'multiselect',
                    'data_source' => 'lists',
                    'required' => false,
                    'options' => $listOptions,
                    'help' => __('Select one or more lists to add the contact to.', 'mailerpress'),
                ],
                [
                    'key' => 'tags',
                    'label' => __('Add Tags', 'mailerpress'),
                    'type' => 'multiselect',
                    'data_source' => 'tags',
                    'required' => false,
                    'options' => $tagOptions,
                    'help' => __('Select one or more tags to add to the contact.', 'mailerpress'),
                ],

                // ===== Advanced Settings Section =====
                [
                    'key' => '_section_advanced',
                    'type' => 'section',
                    'label' => __('Advanced Settings', 'mailerpress'),
                ],
                [
                    'key' => 'update_existing',
                    'label' => __('Update if contact exists', 'mailerpress'),
                    'type' => 'toggle',
                    'required' => false,
                    'default' => true,
                    'help' => __('If a contact with this email already exists, update their information. If disabled, the action will skip existing contacts.', 'mailerpress'),
                ],
                [
                    'key' => 'opt_in_source',
                    'label' => __('Opt-in Source', 'mailerpress'),
                    'type' => 'text',
                    'required' => false,
                    'default' => 'workflow',
                    'placeholder' => 'workflow',
                    'help' => __('Identifies where this contact came from (e.g., "woocommerce", "form", "api"). Useful for tracking and segmentation.', 'mailerpress'),
                ],
            ],
        ];
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        global $wpdb;

        $settings = $step->getSettings();

        // Replace placeholders in settings with actual context values
        $emailTemplate = $settings['email'] ?? '';
        $email = $this->replacePlaceholders($emailTemplate, $context);

        $firstName = $this->replacePlaceholders($settings['first_name'] ?? '', $context);
        $lastName = $this->replacePlaceholders($settings['last_name'] ?? '', $context);
        $subscriptionStatus = $settings['subscription_status'] ?? 'subscribed';
        $lists = $this->normalizeArraySetting($settings['lists'] ?? []);
        $tags = $this->normalizeArraySetting($settings['tags'] ?? []);
        $updateExisting = $settings['update_existing'] ?? true;
        $optInSource = $this->replacePlaceholders($settings['opt_in_source'] ?? 'workflow', $context);

        // Validate email
        if (empty($email)) {
            // Provide more helpful error message
            $errorDetails = sprintf(
                __('No email address provided. Email setting: "%s". Available context fields: %s', 'mailerpress'),
                $emailTemplate,
                implode(', ', array_keys($context))
            );
            return StepResult::failed(__('No email address provided. Check your field mapping.', 'mailerpress'));
        }

        $email = sanitize_email($email);
        if (!is_email($email)) {
            return StepResult::failed(sprintf(__('Invalid email address: %s', 'mailerpress'), $email));
        }

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contactsModel = new ContactsModel();

        // Check if contact already exists
        $existingContact = $contactsModel->getContactByEmail($email);

        $contactId = null;
        $isNew = false;

        if ($existingContact) {
            if (!$updateExisting) {
                // Return success but don't update - contact already exists
                return StepResult::success($step->getNextStepId(), [
                    'contact_id' => (int) $existingContact->contact_id,
                    'email' => $email,
                    'status' => 'already_exists',
                    'message' => __('Contact already exists, skipped update.', 'mailerpress'),
                ]);
            }

            // Update existing contact
            $updateData = [
                'updated_at' => current_time('mysql'),
            ];
            $updateFormat = ['%s'];

            if (!empty($firstName)) {
                $updateData['first_name'] = sanitize_text_field($firstName);
                $updateFormat[] = '%s';
            }
            if (!empty($lastName)) {
                $updateData['last_name'] = sanitize_text_field($lastName);
                $updateFormat[] = '%s';
            }

            // Store previous status to detect status change
            $previousStatus = $existingContact->subscription_status;

            // Only update subscription status if it's different and we're "upgrading" to subscribed
            if ($subscriptionStatus === 'subscribed' && $previousStatus !== 'subscribed') {
                $updateData['subscription_status'] = $subscriptionStatus;
                $updateFormat[] = '%s';
            }

            // Update opt_in_source if provided
            if (!empty($optInSource)) {
                $updateData['opt_in_source'] = sanitize_text_field($optInSource);
                $updateFormat[] = '%s';
            }

            $wpdb->update(
                $contactTable,
                $updateData,
                ['contact_id' => $existingContact->contact_id],
                $updateFormat,
                ['%d']
            );

            $contactId = (int) $existingContact->contact_id;

            // Trigger workflow if status changed to subscribed
            if ($subscriptionStatus === 'subscribed' && $previousStatus !== 'subscribed') {
                \do_action('mailerpress_contact_created', $contactId);
            }
        } else {
            // Create new contact
            $insertData = [
                'email' => $email,
                'first_name' => sanitize_text_field($firstName),
                'last_name' => sanitize_text_field($lastName),
                'subscription_status' => $subscriptionStatus,
                'opt_in_source' => sanitize_text_field($optInSource),
                'opt_in_details' => wp_json_encode([
                    'workflow_id' => $job->getAutomationId(),
                    'context_keys' => array_keys($context),
                ]),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'unsubscribe_token' => wp_generate_uuid4(),
                'access_token' => bin2hex(random_bytes(32)),
            ];

            $result = $wpdb->insert(
                $contactTable,
                $insertData,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            if ($result === false) {
                return StepResult::failed(__('Failed to create contact in database.', 'mailerpress'));
            }

            $contactId = (int) $wpdb->insert_id;
            $isNew = true;

            // Trigger contact created hook
            \do_action('mailerpress_contact_created', $contactId);
        }

        // Add to lists (fallback to default list if none specified)
        if (empty($lists)) {
            $listsTable = Tables::get(Tables::MAILERPRESS_LIST);
            $defaultListId = $wpdb->get_var("SELECT list_id FROM {$listsTable} WHERE is_default = 1 LIMIT 1");
            if ($defaultListId) {
                $lists = [(int) $defaultListId];
            }
        }

        $addedLists = [];
        if (!empty($lists) && $contactId) {
            $addedLists = $this->addContactToLists($contactId, $lists);
        }

        // Add tags
        $addedTags = [];
        if (!empty($tags) && $contactId) {
            $addedTags = $this->addTagsToContact($contactId, $tags);
        }

        return StepResult::success($step->getNextStepId(), [
            'contact_id' => $contactId,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => $isNew ? 'created' : 'updated',
            'subscription_status' => $subscriptionStatus,
            'lists_added' => $addedLists,
            'tags_added' => $addedTags,
        ]);
    }

    /**
     * Normalize array settings from different formats
     * Supports: array of IDs, array of objects with id/value, comma-separated string
     */
    private function normalizeArraySetting($value): array
    {
        if (empty($value)) {
            return [];
        }

        // If it's a string, try to parse as comma-separated or JSON
        if (is_string($value)) {
            // Try JSON decode first
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                // Treat as comma-separated
                $value = array_map('trim', explode(',', $value));
            }
        }

        if (!is_array($value)) {
            return [(int) $value];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                // Object with id or value key
                $id = $item['id'] ?? $item['value'] ?? $item['list_id'] ?? $item['tag_id'] ?? null;
                if ($id !== null) {
                    $result[] = (int) $id;
                }
            } elseif (is_numeric($item)) {
                $result[] = (int) $item;
            }
        }

        return array_filter($result);
    }

    /**
     * Replace placeholders like {{email}} with actual values from context
     */
    private function replacePlaceholders(string $template, array $context): string
    {
        if (empty($template)) {
            return '';
        }

        // Common field aliases for different trigger sources
        $aliases = [
            'email' => ['email', 'user_email', 'customer_email', 'billing_email'],
            'first_name' => ['first_name', 'billing_first_name', 'customer_first_name', 'user_first_name'],
            'last_name' => ['last_name', 'billing_last_name', 'customer_last_name', 'user_last_name'],
        ];

        // Replace all {{placeholder}} patterns
        return preg_replace_callback('/\{\{(\w+(?:\.\w+)*)\}\}/', function ($matches) use ($context, $aliases) {
            $key = $matches[1];

            // Support dot notation for nested access (e.g., {{order.id}})
            if (str_contains($key, '.')) {
                $value = $this->getNestedValue($context, $key);
                if ($value !== null) {
                    return is_scalar($value) ? (string) $value : '';
                }
            }

            // Direct match in context
            if (isset($context[$key]) && is_scalar($context[$key])) {
                return (string) $context[$key];
            }

            // Try aliases for common fields
            foreach ($aliases as $fieldName => $fieldAliases) {
                if ($key === $fieldName || in_array($key, $fieldAliases, true)) {
                    foreach ($fieldAliases as $alias) {
                        if (isset($context[$alias]) && is_scalar($context[$alias])) {
                            return (string) $context[$alias];
                        }
                    }
                }
            }

            // Return empty if not found
            return '';
        }, $template);
    }

    /**
     * Get nested value from context using dot notation
     */
    private function getNestedValue(array $context, string $key): mixed
    {
        $parts = explode('.', $key);
        $value = $context;

        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Add contact to multiple lists
     * @return array List IDs that were actually added
     */
    private function addContactToLists(int $contactId, array $lists): array
    {
        global $wpdb;
        $listsTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        $addedLists = [];

        foreach ($lists as $listId) {
            $listId = (int) $listId;

            if ($listId <= 0) {
                continue;
            }

            // Check if already in list
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$listsTable} WHERE contact_id = %d AND list_id = %d",
                $contactId,
                $listId
            ));

            if (!$exists) {
                $result = $wpdb->insert(
                    $listsTable,
                    [
                        'contact_id' => $contactId,
                        'list_id' => $listId,
                    ],
                    ['%d', '%d']
                );

                if ($result !== false) {
                    $addedLists[] = $listId;
                    \do_action('mailerpress_contact_list_added', $contactId, $listId);
                }
            }
        }

        return $addedLists;
    }

    /**
     * Add tags to contact
     * @return array Tag IDs that were actually added
     */
    private function addTagsToContact(int $contactId, array $tags): array
    {
        global $wpdb;
        $tagsTable = Tables::get(Tables::CONTACT_TAGS);
        $addedTags = [];

        foreach ($tags as $tagId) {
            $tagId = (int) $tagId;

            if ($tagId <= 0) {
                continue;
            }

            // Check if tag already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$tagsTable} WHERE contact_id = %d AND tag_id = %d",
                $contactId,
                $tagId
            ));

            if (!$exists) {
                $result = $wpdb->insert(
                    $tagsTable,
                    [
                        'contact_id' => $contactId,
                        'tag_id' => $tagId,
                    ],
                    ['%d', '%d']
                );

                if ($result !== false) {
                    $addedTags[] = $tagId;
                    \do_action('mailerpress_contact_tag_added', $contactId, $tagId);
                }
            }
        }

        return $addedTags;
    }
}
