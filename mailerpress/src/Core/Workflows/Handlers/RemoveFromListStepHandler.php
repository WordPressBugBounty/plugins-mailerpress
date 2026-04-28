<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\Contacts as ContactsModel;

class RemoveFromListStepHandler implements StepHandlerInterface
{
    use ResolvesContact;
    public function supports(string $key): bool
    {
        return $key === 'remove_from_list';
    }

    public function getDefinition(): array
    {
        return [
            'key' => 'remove_from_list',
            'label' => __('Remove from List', 'mailerpress'),
            'description' => __('Remove the contact from an email list. This helps you manage list membership and targeting.', 'mailerpress'),
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
        $listId = $settings['list'] ?? $settings['list_id'] ?? '';

        if (empty($listId)) {
            return StepResult::failed('No list specified');
        }

        // Convertir en entier pour s'assurer que c'est un ID valide
        $listId = (int) $listId;

        if ($listId <= 0) {
            return StepResult::failed('Invalid list ID');
        }

        $contact = $this->resolveContact($job, $context);

        if (!$contact) {
            return StepResult::failed('Contact not found');
        }

        $contactId = (int) $contact->contact_id;

        // Table de liaison contact-lists
        $listsTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        // Vérifier si le contact est dans cette liste
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$listsTable} WHERE contact_id = %d AND list_id = %d",
            $contactId,
            $listId
        ));

        if ($exists) {
            // Supprimer le contact de la liste
            $deleted = $wpdb->delete(
                $listsTable,
                [
                    'contact_id' => $contactId,
                    'list_id' => $listId,
                ],
                ['%d', '%d']
            );

            if ($deleted === false) {
                return StepResult::failed('Failed to remove contact from list');
            }

            // Déclencher l'action WordPress seulement si la suppression a réussi
            if ($deleted > 0) {
                // Flush WordPress cache to ensure condition checks see the change immediately
                wp_cache_delete($contactId, 'mailerpress_contact_lists');

                // Force database query cache flush to ensure next condition check sees the change
                $wpdb->flush();

                do_action('mailerpress_contact_list_removed', $contactId, $listId);
            }
        }

        return StepResult::success($step->getNextStepId(), [
            'list_id' => $listId,
            'contact_id' => $contactId,
        ]);
    }
}
