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

        $contextEmail = $this->getContextEmail($context);

        // Older send results stored the WordPress user ID as contact_id even without a contact.
        // Resume those runs from their known email, never from a newly imported namesake contact.
        if ($userId > 0 && (int) ($context['contact_id'] ?? 0) === $userId
            && false === ($context['is_contact'] ?? null) && !empty($context['email_sent'])) {
            $email = $contextEmail ?: \sanitize_email((string) ($context['recipient'] ?? ''));
            if ($email !== '' && \is_email($email)) {
                return $this->resolveFromCustomEmail($email, $contactsModel, $context);
            }

            return $this->resolveFromUserId($userId, $contactsModel, $context);
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

            // An explicit contact must never fall back to another identity.
            return null;
        }

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
        if ($userId <= 0) {
            return null;
        }

        // Contact-based runs provide contact_id in context; userId alone is a WordPress user.
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
