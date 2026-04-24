<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\Contacts as ContactsModel;

class AddToListStepHandler implements StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'add_to_list';
    }

    public function getDefinition(): array
    {
        return [
            'key' => 'add_to_list',
            'label' => __('Add to List', 'mailerpress'),
            'description' => __('Add the contact to an email list. Lists help organize your contacts and allow you to target specific groups in your email campaigns.', 'mailerpress'),
            'icon' => 'list',
            'category' => 'contact',
            'type' => 'ACTION',
            'settings_schema' => [
                [
                    'key' => 'list',
                    'label' => 'List',
                    'type' => 'select_dynamic',
                    'data_source' => 'lists',
                    'required' => true,
                ],
            ],
        ];
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        global $wpdb;
        
        $settings = $step->getSettings();
        $listId = $settings['list'] ?? '';

        if (empty($listId)) {
            return StepResult::failed('No list specified');
        }

        // Convertir en entier pour s'assurer que c'est un ID valide
        $listId = (int) $listId;
        
        if ($listId <= 0) {
            return StepResult::failed('Invalid list ID');
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

        // Table de liaison contact-lists
        $listsTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        // Vérifier si le contact est déjà dans cette liste
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$listsTable} WHERE contact_id = %d AND list_id = %d",
            $contactId,
            $listId
        ));

        if (!$exists) {
            // Ajouter le contact à la liste
            $result = $wpdb->insert(
                $listsTable,
                [
                    'contact_id' => $contactId,
                    'list_id' => $listId,
                ],
                ['%d', '%d']
            );

            if ($result === false) {
                return StepResult::failed('Failed to add contact to list');
            }

            // Déclencher l'action WordPress
            do_action('mailerpress_contact_list_added', $contactId, $listId);
        }

        return StepResult::success($step->getNextStepId(), [
            'list_id' => $listId,
            'contact_id' => $contactId,
        ]);
    }
}

