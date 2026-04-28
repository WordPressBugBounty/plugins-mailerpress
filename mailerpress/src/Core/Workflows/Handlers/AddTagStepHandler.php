<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\Contacts as ContactsModel;

class AddTagStepHandler implements StepHandlerInterface
{
    use ResolvesContact;
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

        $contact = $this->resolveContact($job, $context);

        if (!$contact) {
            return StepResult::failed('Contact not found');
        }

        $contactId = (int) $contact->contact_id;

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
