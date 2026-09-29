<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Models\Contacts;

/**
 * Resolves the identifier used as `user_id` throughout the workflow engine.
 *
 * The engine stores a single numeric id on jobs (`automations_jobs.user_id`) that may be:
 *  - a MailerPress contact id,
 *  - a WordPress user id,
 *  - a deterministic "guest" id derived from an email when the visitor is neither.
 *
 * Triggers and goals MUST derive that id the exact same way, otherwise a goal
 * event can never be matched back to the job it is supposed to advance.
 */
class WorkflowUserResolver
{
    public const GUEST_USER_ID_OFFSET = 2147483648;

    /**
     * Resolve the workflow user id from a context array.
     *
     * The context is passed by reference and enriched with the resolved
     * `user_id` (and `contact_id` when a contact was found), mirroring the
     * behaviour the trigger pipeline has always had.
     *
     * @param array $context Workflow context (modified in place)
     *
     * @return int The resolved id, or 0 when nothing could be resolved
     */
    public function resolve(array &$context): int
    {
        $userId = (int) ($context['user_id'] ?? 0);
        if ($userId > 0) {
            $context['user_id'] = $userId;
            return $userId;
        }

        $contactId = (int) ($context['contact_id'] ?? 0);
        if ($contactId > 0) {
            $context['user_id'] = $contactId;
            return $contactId;
        }

        $email = $this->getContextEmail($context);
        if ($email !== '') {
            $contact = (new Contacts())->getContactByEmail($email);

            if ($contact) {
                $contactId = (int) $contact->contact_id;
                $context['contact_id'] = $contactId;
                $context['user_id'] = $contactId;
                return $contactId;
            }

            $guestUserId = $this->guestIdFromEmail($email);
            $context['user_id'] = $guestUserId;
            return $guestUserId;
        }

        $currentUserId = (int) get_current_user_id();
        if ($currentUserId > 0) {
            $context['user_id'] = $currentUserId;
            return $currentUserId;
        }

        return 0;
    }

    /**
     * Deterministic guest id for an email address.
     *
     * Must stay in sync with resolve(): a goal event fired for a guest has to
     * land on the very same id the trigger used when it created the job.
     */
    public function guestIdFromEmail(string $email): int
    {
        return self::GUEST_USER_ID_OFFSET + abs(crc32(strtolower($email)));
    }

    /**
     * Every id under which the same person may have been recorded on a job.
     *
     * Triggers do not agree on what `user_id` means: the tag trigger prefers the
     * WordPress user id when the contact has an account, while the tracking
     * hooks only know the contact id. A goal event therefore has to look the
     * person up under all of their identities, otherwise a pending goal created
     * by one trigger would never be matched by the event that fulfils it.
     *
     * @return int[] Unique, non-zero ids
     */
    public function resolveIdentities(array $context, int $userId = 0): array
    {
        $identities = [];

        if ($userId > 0) {
            $identities[] = $userId;
        }

        $contactId = (int) ($context['contact_id'] ?? 0);
        if ($contactId > 0) {
            $identities[] = $contactId;
        }

        $contactsModel = new Contacts();
        $email = $this->getContextEmail($context);

        // Contact id known -> also register the matching WordPress user id.
        foreach ([$contactId, $userId] as $candidate) {
            if ($candidate <= 0) {
                continue;
            }

            $contact = $contactsModel->get($candidate);

            if ($contact && !empty($contact->email)) {
                if ('' === $email) {
                    $email = $contact->email;
                }

                $user = get_user_by('email', $contact->email);
                if ($user) {
                    $identities[] = (int) $user->ID;
                }
            }
        }

        // Email known -> register both the contact id and the user id it maps to.
        if ('' !== $email) {
            $contact = $contactsModel->getContactByEmail($email);
            if ($contact) {
                $identities[] = (int) $contact->contact_id;
            }

            $user = get_user_by('email', $email);
            if ($user) {
                $identities[] = (int) $user->ID;
            }

            if (!$contact && !$user) {
                $identities[] = $this->guestIdFromEmail($email);
            }
        }

        // WordPress user id known -> register the contact id behind its email.
        if ($userId > 0) {
            $user = get_userdata($userId);

            if ($user && !empty($user->user_email)) {
                $contact = $contactsModel->getContactByEmail($user->user_email);
                if ($contact) {
                    $identities[] = (int) $contact->contact_id;
                }
            }
        }

        return array_values(array_unique(array_filter($identities, fn($id) => $id > 0)));
    }

    /**
     * Extract the first usable email address from a workflow context.
     */
    public function getContextEmail(array $context): string
    {
        $email = $context['customer_email']
            ?? $context['email']
            ?? $context['user_email']
            ?? $context['billing_email']
            ?? ($context['billing_address']['email'] ?? '');

        $email = sanitize_email((string) $email);

        return is_email($email) ? $email : '';
    }
}
