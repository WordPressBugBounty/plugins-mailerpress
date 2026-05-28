<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Triggers;

\defined('ABSPATH') || exit;

class ContactListAddedTrigger
{
    public const TRIGGER_KEY = 'list_added';
    public const HOOK_NAME = 'mailerpress_contact_list_added';

    public static function register($manager): void
    {
        $definition = [
            'label' => __('List Added', 'mailerpress'),
            'description' => __('Triggered when a contact is added to a list. Useful for automations that must run when an existing contact joins a new list.', 'mailerpress'),
            'icon' => 'list',
            'category' => 'mailerpress',
            'settings_schema' => [
                [
                    'key' => 'list_id',
                    'label' => __('List', 'mailerpress'),
                    'type' => 'select',
                    'required' => true,
                    'data_source' => 'lists',
                    'help' => __('Select the list that will trigger this workflow', 'mailerpress'),
                ],
            ],
            'output_fields' => [
                ['key' => 'contact_id', 'label' => __('Contact ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                ['key' => 'list_id', 'label' => __('List ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                ['key' => 'email', 'label' => __('Email', 'mailerpress'), 'type' => 'email', 'group' => 'contact'],
                ['key' => 'first_name', 'label' => __('First Name', 'mailerpress'), 'type' => 'string', 'group' => 'contact'],
                ['key' => 'last_name', 'label' => __('Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'contact'],
                ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            self::HOOK_NAME,
            self::contextBuilder(...),
            $definition
        );
    }

    public static function contextBuilder(...$args): array
    {
        $contactId = (int) ($args[0] ?? 0);
        $listId = (int) ($args[1] ?? 0);

        if (!$contactId || !$listId) {
            return [];
        }

        global $wpdb;
        $contact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email, first_name, last_name, subscription_status FROM {$wpdb->prefix}mailerpress_contact WHERE contact_id = %d",
                $contactId
            ),
            ARRAY_A
        );

        if (!$contact) {
            return [];
        }

        $user = !empty($contact['email']) ? get_user_by('email', $contact['email']) : false;
        $userId = $user ? (int) $user->ID : $contactId;

        return [
            'contact_id' => $contactId,
            'list_id' => $listId,
            'email' => $contact['email'] ?? '',
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'subscription_status' => $contact['subscription_status'] ?? '',
            'user_id' => $userId,
        ];
    }
}
