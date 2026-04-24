<?php

declare(strict_types=1);

namespace MailerPress\Models;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class Contacts
{
    /**
     * @return null|array|object|\stdClass[]
     */
    public function getContactsWithTags(array|string $tags)
    {
        global $wpdb;

        // Convert tags to an array if it's not already
        $tag_ids = \is_array($tags) ? $tags : explode(',', $tags);

        // Sanitize tag IDs
        $tag_ids = array_map('intval', $tag_ids);

        // Create placeholders for tag IDs
        $tag_id_placeholders = implode(', ', array_fill(0, \count($tag_ids), '%d'));

        // Prepare the query with the correct number of arguments
        $prepare_args = array_merge($tag_ids, ['subscribed']);

        // Execute the query and return the results
        return $wpdb->get_results($wpdb->prepare("
        SELECT DISTINCT c.*
        FROM {$wpdb->prefix}mailerpress_contact c
        JOIN {$wpdb->prefix}mailerpress_contact_tags ct ON c.contact_id = ct.contact_id
        WHERE ct.tag_id IN ({$tag_id_placeholders})
        AND c.subscription_status = %s
    ", $prepare_args));
    }

    /**
     * Fetch subscribed contacts filtered by lists and/or tags.
     *
     * - If $ids_only = true, returns an array<int> of contact_id values.
     * - Otherwise returns an array<object> (default $wpdb->get_results() rows).
     * - Supports pagination via $limit / $offset (optional; backward-compatible).
     *
     * @param array|string $lists List IDs (array or comma-separated string).
     * @param array|string $tags Tag IDs (array or comma-separated string).
     * @param bool $ids_only Only return contact_id values.
     * @param int|null $limit Max rows to return; null = no limit (legacy behavior).
     * @param int $offset Offset used when $limit not null.
     *
     * @return array
     */
    public function getContactsWithTagsAndLists(
        array|string $lists = [],
        array|string $tags = [],
        bool $ids_only = false,
        ?int $limit = null,
        int $offset = 0
    ) {
        global $wpdb;

        // Normalize & sanitize input -----------------------------
        $list_ids = is_array($lists) ? $lists : explode(',', (string)$lists);
        $tag_ids = is_array($tags) ? $tags : explode(',', (string)$tags);

        $list_ids = array_values(array_filter(array_map('intval', $list_ids), static fn($v) => $v > 0));
        $tag_ids = array_values(array_filter(array_map('intval', $tag_ids), static fn($v) => $v > 0));

        // Dynamic SELECT -----------------------------------------
        $select = $ids_only ? 'c.contact_id' : 'c.*';

        // Base query & args --------------------------------------
        $query = "
        SELECT DISTINCT {$select}
        FROM {$wpdb->prefix}mailerpress_contact c
        WHERE c.subscription_status = %s
    ";
        $prepare_args = ['subscribed'];

        // Lists filter (ANY match) -------------------------------
        if (!empty($list_ids)) {
            $list_placeholders = implode(', ', array_fill(0, count($list_ids), '%d'));
            $query .= "
            AND EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}mailerpress_contact_lists cl
                WHERE cl.contact_id = c.contact_id
                  AND cl.list_id IN ($list_placeholders)
            )
        ";
            $prepare_args = array_merge($prepare_args, $list_ids);
        }

        // Tags filter (ANY match) --------------------------------
        if (!empty($tag_ids)) {
            $tag_placeholders = implode(', ', array_fill(0, count($tag_ids), '%d'));
            $query .= "
            AND EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}mailerpress_contact_tags ct
                WHERE ct.contact_id = c.contact_id
                  AND ct.tag_id IN ($tag_placeholders)
            )
        ";
            $prepare_args = array_merge($prepare_args, $tag_ids);
        }

        // Pagination ---------------------------------------------
        if ($limit !== null) {
            $limit = max(0, (int)$limit);
            $offset = max(0, (int)$offset);
            $query .= " LIMIT %d OFFSET %d";
            $prepare_args[] = $limit;
            $prepare_args[] = $offset;
        }

        // Prepare & execute --------------------------------------
        $sql = $wpdb->prepare($query, $prepare_args);

        if ($ids_only) {
            $ids = $wpdb->get_col($sql);
            return array_values(array_unique(array_map('intval', $ids)));
        }

        return $wpdb->get_results($sql); // OBJECT rows
    }

    public function getByAccessToken(string $contactId)
    {
        global $wpdb;

        $contact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mailerpress_contact WHERE access_token = %s",
                $contactId
            )
        );

        if ($contact) {
            return $contact;
        }

        return null;
    }

    public function get(int $contactId)
    {
        global $wpdb;

        $contact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mailerpress_contact WHERE contact_id = %d",
                $contactId
            )
        );

        if ($contact) {
            return $contact;
        }

        return null;
    }

    public function getContactByToken(string $token)
    {
        global $wpdb;

        $contact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mailerpress_contact WHERE unsubscribe_token = %s",
                $token
            )
        );

        if ($contact) {
            return $contact;
        }

        return null;
    }

    public function getContactByEmail(string $email)
    {
        global $wpdb;

        $contact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mailerpress_contact WHERE email = %s",
                sanitize_email($email)
            )
        );

        if ($contact) {
            return $contact;
        }

        return null;
    }

    public function unsubscribe(string $contactId, ?string $batchId = null)
    {
        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);

        if (empty($contactId)) {
            wp_die('Invalid unsubscribe request. Token is missing.');
        }

        $updated = $wpdb->update(
            $table_name,
            [
                'subscription_status' => 'unsubscribed',
                'unsubscribe_token' => wp_generate_uuid4(),
            ], // New status
            ['contact_id' => $contactId], // Where condition
            ['%s', '%s'], // Format for the new value
            ['%d']  // Format for the where condition
        );

        if (null !== $batchId && null !== $contactId) {
            global $wpdb;
            $table = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);

            // Sanitize and validate as absolute integers
            $batchId = absint($batchId);
            $contactId = absint($contactId);

            if ($batchId === 0 || $contactId === 0) {
                return; // Stop if input is invalid
            }

            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE contact_id = %d AND batch_id = %d",
                    $contactId,
                    $batchId
                )
            );

            $now = current_time('mysql'); // WordPress-safe current datetime

            if (empty($existing)) {
                // Insert new record with unsubscribed_at
                $wpdb->insert(
                    $table,
                    [
                        'batch_id' => $batchId,
                        'contact_id' => $contactId,
                        'unsubscribed_at' => $now,
                    ],
                    [
                        '%d',
                        '%d',
                        '%s',
                    ]
                );
            } else {
                // Update only if unsubscribed_at is still null
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table} SET unsubscribed_at = %s WHERE id = %d AND unsubscribed_at IS NULL",
                        $now,
                        $existing
                    )
                );
            }
        }

        if (false !== $updated) {
            return true;
        }

        return false;
    }

    public function subscribe(string $contactId)
    {
        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);

        if (empty($contactId)) {
            wp_die('Invalid unsubscribe request. Token is missing.');
        }

        $updated = $wpdb->update(
            $table_name,
            [
                'subscription_status' => 'subscribed',
                'unsubscribe_token' => wp_generate_uuid4(),
            ], // New status
            ['contact_id' => $contactId], // Where condition
            ['%s', '%s'], // Format for the new value
            ['%d']  // Format for the where condition
        );

        if (false !== $updated) {
            // Cancel scheduled reminder action if contact confirms before reminder is sent
            if (\function_exists('as_unschedule_action')) {
                as_unschedule_action('mailerpress_send_confirmation_reminder', [(int) $contactId], 'mailerpress');
            }

            // Déclencher les webhooks pour la confirmation d'abonnement
            $contactIdInt = (int) $contactId;
            do_action('mailerpress_subscription_confirmed', $contactIdInt);
            do_action('mailerpress_contact_updated', $contactIdInt);

            return true;
        }

        return false;
    }

    public function all()
    {
        global $wpdb;

        $contact = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mailerpress_contact WHERE contact_id = %d",
                $contactId
            )
        );

        if ($contact) {
            return $contact;
        }

        return null;
    }

    public function count()
    {
        global $wpdb;

        // Get the count of contacts in the table
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mailerpress_contact"
        );

        return $count;
    }
}
