<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\Contacts as ContactsModel;

class AddTagStepHandler implements StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'add_tag';
    }

    public function getDefinition(): array
    {
        return [
            'key' => 'add_tag',
            'label' => __('Add Tag', 'mailerpress'),
            'description' => __('Add a tag to the contact to categorize and segment them. Tags help organize your contacts and can be used to create conditions in your workflows.', 'mailerpress'),
            'icon' => 'tag',
            'category' => 'contact',
            'type' => 'ACTION',
            'settings_schema' => [
                [
                    'key' => 'tag',
                    'label' => 'Tag',
                    'type' => 'text',
                    'required' => true,
                ],
            ],
        ];
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        global $wpdb;

        $settings = $step->getSettings();
        $tagId = $settings['tag'] ?? '';

        if (empty($tagId)) {
            return StepResult::failed('No tag specified');
        }

        // Convertir en entier pour s'assurer que c'est un ID valide
        $tagId = (int) $tagId;

        if ($tagId <= 0) {
            return StepResult::failed('Invalid tag ID');
        }

        // Récupérer le contact_id depuis le user_id
        // Note: For MailerPress contacts that are not WordPress users, user_id is actually contact_id
        $userId = $job->getUserId();

        if (!$userId) {
            return StepResult::failed('No user ID found');
        }

        $contactsModel = new ContactsModel();
        $contact = null;
        $contactId = null;

        // Check if userId is actually a contact_id (for MailerPress contacts without WordPress user)
        $contactById = $contactsModel->get($userId);

        if ($contactById) {
            // userId is actually a contact_id - this is a MailerPress contact without WordPress user
            $contact = $contactById;
            $contactId = (int) $contact->contact_id;
        } else {
            // userId is a WordPress user ID - try to find the contact by email
            $user = \get_userdata($userId);
            if ($user && $user->user_email) {
                $contact = $contactsModel->getContactByEmail($user->user_email);
                if ($contact) {
                    $contactId = (int) $contact->contact_id;
                }
            }
        }

        if (!$contact || !$contactId) {
            return StepResult::failed('Contact not found');
        }

        // Table de liaison contact-tags
        $tagsTable = Tables::get(Tables::CONTACT_TAGS);

        // Vérifier si le tag existe déjà pour ce contact
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$tagsTable} WHERE contact_id = %d AND tag_id = %d",
            $contactId,
            $tagId
        ));

        if (!$exists) {
            // Ajouter le tag au contact
            $result = $wpdb->insert(
                $tagsTable,
                [
                    'contact_id' => $contactId,
                    'tag_id' => $tagId,
                ],
                ['%d', '%d']
            );

            if ($result === false) {
                return StepResult::failed('Failed to add tag to contact');
            }

            // Déclencher l'action WordPress
            do_action('mailerpress_contact_tag_added', $contactId, $tagId);
        }

        return StepResult::success($step->getNextStepId(), [
            'tag_id' => $tagId,
            'contact_id' => $contactId,
        ]);
    }
}
