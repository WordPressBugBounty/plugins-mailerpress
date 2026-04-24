<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Triggers;

\defined('ABSPATH') || exit;

/**
 * Contact Tag Added Trigger
 * 
 * Fires when a tag is added to a contact in MailerPress.
 * This trigger captures tag addition events and extracts relevant
 * contact and tag data to be used in workflow automations.
 * 
 * The trigger listens to the 'mailerpress_contact_tag_added' hook
 * which fires in various places when a tag is added to a contact.
 * 
 * Data available in the workflow context:
 * - contact_id: The unique identifier of the contact
 * - tag_id: The unique identifier of the tag that was added
 * - user_id: The WordPress user ID associated with the contact (if exists)
 * 
 * @since 1.2.0
 */
class ContactTagAddedTrigger
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'tag_added';

    /**
     * WordPress hook name to listen to
     */
    public const HOOK_NAME = 'mailerpress_contact_tag_added';

    /**
     * Register the custom trigger
     * 
     * @param mixed $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        $definition = [
            'label' => __('Tag Added', 'mailerpress'),
            'description' => __('Triggered when a tag is added to a contact. Perfect for automating actions based on categorization, such as sending specific emails based on the tag or adding the contact to targeted lists.', 'mailerpress'),
            'icon' => 'mailerpress',
            'category' => 'mailerpress',
            'settings_schema' => [
                [
                    'key' => 'tag_id',
                    'label' => __('Tag', 'mailerpress'),
                    'type' => 'select',
                    'required' => true,
                    'data_source' => 'tags',
                    'help' => __('Select the tag that will trigger this workflow', 'mailerpress'),
                ],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            self::HOOK_NAME,
            self::contextBuilder(...),
            $definition
        );
    }

    /**
     * Build context from hook parameters
     * 
     * The hook 'mailerpress_contact_tag_added' is called with:
     * do_action('mailerpress_contact_tag_added', $contactId, $tagId);
     * 
     * @param mixed ...$args Hook arguments (contact_id, tag_id)
     * @return array Context data for the workflow
     */
    public static function contextBuilder(...$args): array
    {
        $contactId = (int)($args[0] ?? 0);
        $tagId = (int)($args[1] ?? 0);

        if (!$contactId || !$tagId) {
            return [];
        }

        // Try to rfind WordPess user by email
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
            'tag_id' => $tagId,
            'user_id' => $userId,
        ];
    }
}
