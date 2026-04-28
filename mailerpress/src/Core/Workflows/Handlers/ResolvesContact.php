<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Models\Contacts as ContactsModel;

/**
 * Trait for resolving a MailerPress contact from workflow context and job data.
 *
 * Resolution order:
 * 1. context['contact_id'] — set by a preceding step (e.g. CreateContact)
 * 2. job->getUserId() as contact_id — for MailerPress-only contacts
 * 3. job->getUserId() as WP user ID — resolved via email lookup
 */
trait ResolvesContact
{
    protected function resolveContact(AutomationJob $job, array $context = []): ?object
    {
        $contactsModel = new ContactsModel();

        // Priority 1: contact_id from context (set by CreateContact or other upstream steps)
        if (!empty($context['contact_id'])) {
            $contact = $contactsModel->get((int) $context['contact_id']);
            if ($contact) {
                return $contact;
            }
        }

        $userId = $job->getUserId();
        if (!$userId) {
            return null;
        }

        // Priority 2: userId is actually a contact_id
        $contact = $contactsModel->get($userId);
        if ($contact) {
            return $contact;
        }

        // Priority 3: userId is a WordPress user ID — resolve via email
        $user = \get_userdata($userId);
        if ($user && $user->user_email) {
            return $contactsModel->getContactByEmail($user->user_email);
        }

        return null;
    }
}
