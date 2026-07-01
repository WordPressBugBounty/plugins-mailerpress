<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Models\Contacts as ContactsModel;

class ContactResolver
{
    /**
     * Resolve contact information from userId, custom recipient email, and context.
     *
     * Returns null if no valid contact/email can be resolved.
     */
    public function resolve(int $userId, ?string $customRecipientEmail, array $context): ?ContactResult
    {
        $contactsModel = new ContactsModel();

        if (!empty($customRecipientEmail)) {
            return $this->resolveFromCustomEmail($customRecipientEmail, $contactsModel, $context);
        }

        // Priority: contact_id from context (set by a preceding step like CreateContact)
        if (!empty($context['contact_id'])) {
            $contact = $contactsModel->get((int) $context['contact_id']);
            if ($contact) {
                return new ContactResult(
                    email: $contact->email ?? '',
                    contactId: (int) $contact->contact_id,
                    isContact: true,
                    displayName: \trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: ($contact->email ?? ''),
                    firstName: $contact->first_name ?? '',
                    lastName: $contact->last_name ?? '',
                    contact: $contact
                );
            }
        }

        $contextEmail = $this->getContextEmail($context);
        if ($contextEmail !== '') {
            return $this->resolveFromCustomEmail($contextEmail, $contactsModel, $context);
        }

        return $this->resolveFromUserId($userId, $contactsModel, $context);
    }

    private function resolveFromCustomEmail(string $email, ContactsModel $contactsModel, array $context): ContactResult
    {
        $contact = $contactsModel->getContactByEmail($email);

        if ($contact) {
            return new ContactResult(
                email: $email,
                contactId: (int) $contact->contact_id,
                isContact: true,
                displayName: \trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $email,
                firstName: $contact->first_name ?? '',
                lastName: $contact->last_name ?? '',
                contact: $contact
            );
        }

        $user = \get_user_by('email', $email);
        if ($user) {
            return new ContactResult(
                email: $email,
                contactId: null,
                isContact: false,
                displayName: $user->display_name ?? '',
                firstName: $user->first_name ?? '',
                lastName: $user->last_name ?? '',
            );
        }

        return new ContactResult(
            email: $email,
            contactId: null,
            isContact: false,
            displayName: \trim(($context['customer_first_name'] ?? '') . ' ' . ($context['customer_last_name'] ?? '')) ?: $email,
            firstName: $context['customer_first_name'] ?? '',
            lastName: $context['customer_last_name'] ?? '',
        );
    }

    private function resolveFromUserId(int $userId, ContactsModel $contactsModel, array $context): ?ContactResult
    {
        if (!$userId) {
            return null;
        }

        // Check if userId is actually a contact_id
        $contactById = $contactsModel->get($userId);

        if ($contactById) {
            return new ContactResult(
                email: $contactById->email ?? '',
                contactId: (int) $contactById->contact_id,
                isContact: true,
                displayName: \trim(($contactById->first_name ?? '') . ' ' . ($contactById->last_name ?? '')) ?: ($contactById->email ?? ''),
                firstName: $contactById->first_name ?? '',
                lastName: $contactById->last_name ?? '',
                contact: $contactById
            );
        }

        // Check if userId is a WordPress user
        $user = \get_userdata($userId);

        if ($user && $user->user_email) {
            $contact = $contactsModel->getContactByEmail($user->user_email);

            return new ContactResult(
                email: $user->user_email,
                contactId: $contact ? (int) $contact->contact_id : null,
                isContact: (bool) $contact,
                displayName: $user->display_name ?? '',
                firstName: $user->first_name ?? '',
                lastName: $user->last_name ?? '',
                contact: $contact ?: null
            );
        }

        // Guest user from checkout or abandoned cart.
        if ($userId < 0) {
            $email = $this->getContextEmail($context);
            if ($email === '') {
                return null;
            }

            $contact = $contactsModel->getContactByEmail($email);

            return new ContactResult(
                email: $email,
                contactId: $contact ? (int) $contact->contact_id : null,
                isContact: (bool) $contact,
                displayName: \trim(($context['customer_first_name'] ?? '') . ' ' . ($context['customer_last_name'] ?? '')) ?: $email,
                firstName: $context['customer_first_name'] ?? '',
                lastName: $context['customer_last_name'] ?? '',
                contact: $contact ?: null
            );
        }

        return null;
    }

    private function getContextEmail(array $context): string
    {
        $email = $context['customer_email']
            ?? $context['email']
            ?? $context['user_email']
            ?? $context['billing_email']
            ?? ($context['billing_address']['email'] ?? '');

        $email = \sanitize_email((string) $email);

        return \is_email($email) ? $email : '';
    }
}
