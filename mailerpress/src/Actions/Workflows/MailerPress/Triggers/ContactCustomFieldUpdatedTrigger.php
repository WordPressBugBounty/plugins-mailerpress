<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Triggers;

\defined('ABSPATH') || exit;

use MailerPress\Models\CustomFields;

/**
 * Contact Custom Field Updated Trigger
 * 
 * Fires when a custom field is added or updated for a contact in MailerPress.
 * This trigger captures custom field update events and extracts relevant
 * contact and field data to be used in workflow automations.
 * 
 * The trigger listens to the 'mailerpress_contact_custom_field_updated' and
 * 'mailerpress_contact_custom_field_added' hooks which fire when a custom field
 * is updated or added to a contact.
 * 
 * Data available in the workflow context:
 * - contact_id: The unique identifier of the contact
 * - field_key: The key of the custom field that was updated
 * - field_value: The new value of the custom field
 * - user_id: The WordPress user ID associated with the contact (if exists)
 * 
 * @since 1.2.0
 */
class ContactCustomFieldUpdatedTrigger
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'contact_custom_field_updated';

    /**
     * WordPress hook names to listen to
     */
    public const HOOK_NAME_UPDATED = 'mailerpress_contact_custom_field_updated';
    public const HOOK_NAME_ADDED = 'mailerpress_contact_custom_field_added';

    /**
     * Register the custom trigger
     * 
     * @param \MailerPress\Core\Workflows\Services\TriggerManager $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        // Only load custom field options in admin/REST context (not needed on frontend)
        $fieldOptions = [
            [
                'value' => '',
                'label' => __('Any custom field', 'mailerpress'),
            ],
        ];
        if (is_admin() || wp_is_json_request()) {
            $customFieldsModel = new CustomFields();
            $customFields = $customFieldsModel->all();
            foreach ($customFields as $field) {
                $fieldOptions[] = [
                    'value' => $field->field_key,
                    'label' => $field->label ?? $field->field_key,
                ];
            }
        }

        $definition = [
            'label' => __('Custom Field Updated', 'mailerpress'),
            'description' => __('Triggered when a custom field is added or updated for a contact. Ideal for reacting to custom data changes, such as updating segments or triggering workflows based on specific values.', 'mailerpress'),
            'icon' => 'mailerpress',
            'category' => 'mailerpress',
            'settings_schema' => [
                [
                    'key' => 'field_key',
                    'label' => __('Custom Field', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => $fieldOptions,
                    'help' => __('Only trigger when this specific custom field is updated (leave empty to trigger for any field)', 'mailerpress'),
                ],
            ],
        ];

        // Register the trigger with definition (only once to avoid overwriting)
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            self::HOOK_NAME_UPDATED,
            self::contextBuilder(...),
            $definition
        );

        // Also listen to the "added" hook - trigger the same workflow by calling the registered hook
        // This way we reuse the same trigger registration without overwriting it
        add_action(self::HOOK_NAME_ADDED, function (...$args) {
            // Trigger the same hook that was registered, which will use the same trigger key
            do_action(self::HOOK_NAME_UPDATED, ...$args);
        }, 10, 10);
    }

    /**
     * Build context from hook parameters
     * 
     * The hooks are called with:
     * do_action('mailerpress_contact_custom_field_updated', $contactId, $field_key, $field_value);
     * do_action('mailerpress_contact_custom_field_added', $contactId, $field_key, $field_value);
     * 
     * @param mixed ...$args Hook arguments (contact_id, field_key, field_value)
     * @return array Context data for the workflow
     */
    public static function contextBuilder(...$args): array
    {
        $contactId = (int)($args[0] ?? 0);
        $fieldKey = $args[1] ?? '';
        $fieldValue = $args[2] ?? '';

        if (!$contactId || empty($fieldKey)) {
            return [];
        }

        // Try to find WordPress user by email
        // Note: The mailerpress_contact table doesn't have a user_id column,
        // so we need to get the contact email first, then find the user
        $userId = null;
        global $wpdb;
        $contactsTable = $wpdb->prefix . 'mailerpress_contact';
        $contact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email FROM {$contactsTable} WHERE contact_id = %d",
                $contactId
            ),
            ARRAY_A
        );

        if ($contact && isset($contact['email'])) {
            $user = get_user_by('email', $contact['email']);
            if ($user) {
                $userId = $user->ID;
            }
        }

        // If no user found, use contact_id as user_id (for non-subscribers)
        // This allows workflows to run even if contact is not a WordPress user
        if (!$userId) {
            $userId = $contactId;
        }

        return [
            'contact_id' => $contactId,
            'field_key' => $fieldKey,
            'field_value' => $fieldValue,
            'user_id' => $userId,
        ];
    }
}
