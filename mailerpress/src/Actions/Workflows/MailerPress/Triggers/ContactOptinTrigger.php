<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Triggers;

use MailerPress\Models\Contacts;
use MailerPress\Models\Lists;
use MailerPress\Models\Tags;

\defined('ABSPATH') || exit;

/**
 * Contact Opt-in Trigger
 * 
 * Fires when a new contact is created in MailerPress.
 * This trigger captures contact opt-in events and extracts relevant
 * contact data to be used in workflow automations.
 * 
 * The trigger listens to the 'mailerpress_contact_created' hook
 * which fires in src/Api/Contacts.php when a new contact is added.
 * 
 * Data available in the workflow context:
 * - contact_id: The unique identifier of the created contact
 * - email: The contact's email address
 * - first_name: The contact's first name
 * - last_name: The contact's last name
 * - subscription_status: The contact's subscription status (pending, subscribed, etc.)
 * - opt_in_source: The source of the opt-in (e.g., 'manual', 'form', 'import')
 * - opt_in_details: Additional details about the opt-in
 * - created_at: Timestamp when the contact was created
 * - lists: Array of list IDs the contact belongs to
 * - tags: Array of tag IDs associated with the contact
 * - user_id: The contact_id is used as user_id (this trigger works with MailerPress contacts only)
 * 
 * @since 1.2.0
 */
class ContactOptinTrigger
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'mailerpress_contact_optin';

    /**
     * WordPress hook name to listen to
     */
    public const HOOK_NAME = 'mailerpress_contact_created';

    /**
     * Register the custom trigger
     * 
     * @param mixed $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        // Subscription status options
        $statusOptions = [
            [
                'value' => '',
                'label' => __('Any status', 'mailerpress'),
            ],
            [
                'value' => 'subscribed',
                'label' => __('Subscribed', 'mailerpress'),
            ],
            [
                'value' => 'pending',
                'label' => __('Pending', 'mailerpress'),
            ],
            [
                'value' => 'unsubscribed',
                'label' => __('Unsubscribed', 'mailerpress'),
            ]
        ];

        // Get dynamic lists
        $lists = Lists::getLists();
        $listOptions = [
            [
                'value' => '',
                'label' => __('Any list', 'mailerpress'),
            ],
        ];
        foreach ($lists as $list) {
            $listOptions[] = [
                'value' => (string) $list['list_id'],
                'label' => $list['name'] ?? __('Unnamed list', 'mailerpress'),
            ];
        }

        // Get dynamic tags
        $tags = Tags::getAll();
        $tagOptions = [
            [
                'value' => '',
                'label' => __('Any tag', 'mailerpress'),
            ],
        ];
        foreach ($tags as $tag) {
            $tagOptions[] = [
                'value' => (string) $tag->tag_id,
                'label' => $tag->name ?? __('Unnamed tag', 'mailerpress'),
            ];
        }

        $definition = [
            'label' => __('Contact Subscribed', 'mailerpress'),
            'description' => __('Triggered when a new contact subscribes to MailerPress. Perfect for sending welcome emails, automatically adding tags, or starting personalized onboarding sequences.', 'mailerpress'),
            'icon' => 'mailerpress',
            'category' => 'mailerpress',
            'settings_schema' => [
                [
                    'key' => 'subscription_status',
                    'label' => __('Subscription Status', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'options' => $statusOptions,
                    'help' => __('Only trigger when contact has this subscription status (leave empty for any status)', 'mailerpress'),
                ],
                [
                    'key' => 'lists',
                    'label' => __('Lists', 'mailerpress'),
                    'type' => 'token',
                    'required' => false,
                    'options' => $listOptions,
                    'help' => __('Only trigger when contact is in one of these lists (leave empty for any list)', 'mailerpress'),
                ],
                [
                    'key' => 'tags',
                    'label' => __('Tags', 'mailerpress'),
                    'type' => 'token',
                    'required' => false,
                    'options' => $tagOptions,
                    'help' => __('Only trigger when contact has one of these tags (leave empty for any tag)', 'mailerpress'),
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
     * This is called when the WordPress hook is triggered in Contacts.php:663
     * do_action('mailerpress_contact_created', $contactId);
     * 
     * The hook passes only the contact ID, so we fetch the full contact
     * data from the database and extract relevant information for the workflow.
     * 
     * @param mixed ...$args Hook arguments passed by WordPress (first arg is contact_id)
     * @param array|null $settings Trigger settings from the workflow configuration
     * @return array Context data for the workflow
     */
    public static function contextBuilder(...$args): array
    {
        $contactId = (int)($args[0] ?? 0);

        if (!$contactId) {
            return [];
        }

        try {
            global $wpdb;

            $contactsModel = new Contacts();
            $contact = $contactsModel->get($contactId);

            if (!$contact) {
                return [];
            }

            // For MailerPress contacts, use contact_id as user_id
            // This trigger works with MailerPress contacts only, not WordPress users
            // The workflow system can handle contact_id as user_id
            $userId = $contactId;

            // Get contact lists
            $contactLists = $wpdb->get_col($wpdb->prepare(
                "SELECT list_id FROM {$wpdb->prefix}mailerpress_contact_lists WHERE contact_id = %d",
                $contactId
            ));
            $contactLists = array_map('intval', $contactLists);

            // Get contact tags
            $contactTags = $wpdb->get_col($wpdb->prepare(
                "SELECT tag_id FROM {$wpdb->prefix}mailerpress_contact_tags WHERE contact_id = %d",
                $contactId
            ));
            $contactTags = array_map('intval', $contactTags);

            return [
                'contact_id' => $contact->contact_id,
                'email' => $contact->email ?? '',
                'first_name' => $contact->first_name ?? '',
                'last_name' => $contact->last_name ?? '',
                'subscription_status' => $contact->subscription_status ?? 'pending',
                'opt_in_source' => $contact->opt_in_source ?? 'unknown',
                'opt_in_details' => $contact->opt_in_details ?? '',
                'created_at' => $contact->created_at ?? \current_time('mysql'),
                'lists' => $contactLists,
                'tags' => $contactTags,
                'user_id' => $userId,
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}
