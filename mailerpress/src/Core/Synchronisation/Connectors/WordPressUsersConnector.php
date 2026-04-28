<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation\Connectors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Synchronisation\ConnectorInterface;

class WordPressUsersConnector implements ConnectorInterface
{
    public function getKey(): string
    {
        return 'wordpress_users';
    }

    public function getLabel(): string
    {
        return __('WordPress Users', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Synchronize WordPress users as MailerPress contacts automatically.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" role="img" width="28" height="28" viewBox="0 0 28 28">
	<title>WordPress.org</title>
	<path fill="currentColor" d="M13.6052 0.923525C16.1432 0.923525 18.6137 1.67953 20.7062 3.09703C22.7447 4.47403 24.3512 6.41803 25.3097 8.68603C26.9837 12.6415 26.5382 17.164 24.1352 20.7145C22.7582 22.753 20.8142 24.3595 18.5462 25.318C14.5907 26.992 10.0682 26.5465 6.51772 24.1435C4.47922 22.7665 2.87272 20.8225 1.91422 18.5545C0.240225 14.599 0.685725 10.0765 3.08872 6.52603C4.46572 4.48753 6.40973 2.88103 8.67772 1.92253C10.2302 1.26103 11.9177 0.923525 13.6052 0.923525ZM13.6052 0.113525C6.15322 0.113525 0.105225 6.16153 0.105225 13.6135C0.105225 21.0655 6.15322 27.1135 13.6052 27.1135C21.0572 27.1135 27.1052 21.0655 27.1052 13.6135C27.1052 6.16153 21.0572 0.113525 13.6052 0.113525Z"></path>
	<path fill="currentColor" d="M2.36011 13.6133C2.36011 17.9198 4.81711 21.8618 8.70511 23.7383L3.33211 9.03684C2.68411 10.4813 2.36011 12.0338 2.36011 13.6133ZM21.2061 13.0463C21.2061 11.6558 20.7066 10.6973 20.2746 9.94134C19.8426 9.18534 19.1676 8.22684 19.1676 7.30884C19.1676 6.39084 19.9506 5.31084 21.0576 5.31084H21.2061C16.6296 1.11234 9.51511 1.42284 5.31661 6.01284C4.91161 6.45834 4.53361 6.93084 4.20961 7.43034H4.93861C6.11311 7.43034 7.93561 7.28184 7.93561 7.28184C8.54311 7.24134 8.61061 8.13234 8.00311 8.21334C8.00311 8.21334 7.39561 8.28084 6.72061 8.32134L10.8111 20.5118L13.2681 13.1273L11.5131 8.32134C10.9056 8.28084 10.3386 8.21334 10.3386 8.21334C9.73111 8.17284 9.79861 7.25484 10.4061 7.28184C10.4061 7.28184 12.2691 7.43034 13.3626 7.43034C14.4561 7.43034 16.3596 7.28184 16.3596 7.28184C16.9671 7.24134 17.0346 8.13234 16.4271 8.21334C16.4271 8.21334 15.8196 8.28084 15.1446 8.32134L19.2081 20.4173L20.3691 16.7453C20.8821 15.1388 21.1926 14.0048 21.1926 13.0328L21.2061 13.0463ZM13.7946 14.5853L10.4196 24.3998C12.6876 25.0613 15.1041 25.0073 17.3316 24.2243L17.2506 24.0758L13.7946 14.5853ZM23.4741 8.21334C23.5281 8.59134 23.5551 8.98284 23.5551 9.37434C23.5551 10.5218 23.3391 11.8043 22.7046 13.3973L19.2621 23.3333C24.5271 20.2688 26.4036 13.5593 23.4741 8.21334Z"></path>
        </svg>';
    }

    public function isPro(): bool
    {
        return false;
    }

    public function getSyncModes(): array
    {
        return ['manual', 'auto'];
    }

    public function getDefaultSettings(): array
    {
        return [
            'roles'               => ['subscriber'],
            'list_ids'            => [],
            'tag_ids'             => [],
            'subscription_status' => 'subscribed',
            'sync_mode'           => 'auto',
            'sync_on_register'    => true,
            'sync_on_update'      => false,
        ];
    }

    public function isConfigured(array $settings): bool
    {
        // WP Users connector needs at least one role and a target list
        return !empty($settings['roles']) && !empty($settings['list_ids']);
    }

    public function getAvailableSourceFields(array $settings): array
    {
        // Built-in WP user fields always available
        $fields = [
            ['key' => 'user_email',      'label' => __('Email', 'mailerpress'),            'example' => 'user@example.com'],
            ['key' => 'display_name',    'label' => __('Display name', 'mailerpress'),     'example' => 'John Doe'],
            ['key' => 'first_name',      'label' => __('First name', 'mailerpress'),       'example' => 'John'],
            ['key' => 'last_name',       'label' => __('Last name', 'mailerpress'),        'example' => 'Doe'],
            ['key' => 'user_url',        'label' => __('Website URL', 'mailerpress'),      'example' => 'https://example.com'],
            ['key' => 'description',     'label' => __('Biographical info', 'mailerpress'), 'example' => 'About me...'],
            ['key' => 'user_registered', 'label' => __('Registration date', 'mailerpress'), 'example' => '2024-01-01'],
        ];

        // Discover all used user meta keys (excluding WP internal ones starting with _ or wp_)
        global $wpdb;
        $metaKeys = $wpdb->get_col(
            "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
             WHERE meta_key NOT LIKE '\_%'
               AND meta_key NOT LIKE 'wp\_%'
               AND meta_key NOT IN ('session_tokens','rich_editing','syntax_highlighting',
                                    'comment_shortcuts','admin_color','use_ssl','show_admin_bar_front',
                                    'locale','dismissed_wp_pointers','show_welcome_panel',
                                    'closedpostboxes_post','metaboxhidden_post')
             ORDER BY meta_key
             LIMIT 100"
        );

        foreach ((array) $metaKeys as $key) {
            $fields[] = [
                'key'   => 'meta:' . $key,
                'label' => __('User meta:', 'mailerpress') . ' ' . $key,
            ];
        }

        return $fields;
    }

    /**
     * Bulk synchronise all WordPress users matching the configured roles.
     *
     * @param array $settings
     * @return array{synced: int, skipped: int, errors: int}
     */
    public function sync(array $settings): array
    {
        $roles       = !empty($settings['roles']) ? (array) $settings['roles'] : [];
        $listIds     = !empty($settings['list_ids']) ? array_map('intval', (array) $settings['list_ids']) : [];
        $tagIds      = !empty($settings['tag_ids']) ? array_map('intval', (array) $settings['tag_ids']) : [];
        $status      = in_array($settings['subscription_status'] ?? '', ['subscribed', 'pending'], true)
            ? $settings['subscription_status']
            : 'subscribed';
        $fieldMapping = !empty($settings['field_mapping']) ? (array) $settings['field_mapping'] : [];

        $synced  = 0;
        $skipped = 0;
        $errors  = 0;

        $args = [
            'number' => -1,
        ];

        if (!empty($roles)) {
            $args['role__in'] = $roles;
        }

        $users = get_users($args);

        foreach ($users as $user) {
            // Flush cached meta so we read fresh first_name / last_name
            wp_cache_delete( $user->ID, 'user_meta' );

            $result = $this->upsertContact(
                $user,
                $listIds,
                $tagIds,
                $status,
                $fieldMapping
            );

            if ('created' === $result || 'updated' === $result) {
                $synced++;
            } elseif ('skipped' === $result) {
                $skipped++;
            } else {
                $errors++;
            }
        }

        return compact('synced', 'skipped', 'errors');
    }

    /**
     * Register WordPress hooks for real-time sync.
     * Only active when sync_mode = 'auto'.
     */
    public function registerHooks(array $settings): void
    {
        if (($settings['sync_mode'] ?? 'auto') !== 'auto') {
            return;
        }

        if (!empty($settings['sync_on_register'])) {
            add_action('user_register', [$this, 'handleUserRegister'], 10, 1);
        }

        if (!empty($settings['sync_on_update'])) {
            add_action('profile_update', [$this, 'handleProfileUpdate'], 10, 2);
        }
    }

    /**
     * Remove WordPress hooks.
     */
    public function unregisterHooks(): void
    {
        remove_action('user_register', [$this, 'handleUserRegister']);
        remove_action('profile_update', [$this, 'handleProfileUpdate']);
    }

    /**
     * Hook: new user registered.
     */
    public function handleUserRegister(int $userId): void
    {
        $settings = $this->getCurrentSettings();
        if (empty($settings)) {
            return;
        }

        $user = get_user_by('id', $userId);
        if (!$user) {
            return;
        }

        // Filter by configured roles
        $roles     = $settings['roles'] ?? [];
        $userRoles = (array) $user->roles;
        if (!empty($roles) && empty(array_intersect($roles, $userRoles))) {
            return;
        }

        $this->upsertContact(
            $user,
            !empty($settings['list_ids']) ? array_map('intval', (array) $settings['list_ids']) : [],
            !empty($settings['tag_ids']) ? array_map('intval', (array) $settings['tag_ids']) : [],
            in_array($settings['subscription_status'] ?? '', ['subscribed', 'pending'], true) ? $settings['subscription_status'] : 'subscribed',
            !empty($settings['field_mapping']) ? (array) $settings['field_mapping'] : []
        );
    }

    /**
     * Hook: user profile updated — sync on any relevant field change.
     */
    public function handleProfileUpdate(int $userId, object $oldUserdata): void
    {
        $settings = $this->getCurrentSettings();
        if (empty($settings)) {
            return;
        }

        // Flush WP object cache so we get fresh meta (first_name, last_name, etc.)
        clean_user_cache( $userId );
        wp_cache_delete( $userId, 'user_meta' );

        $user = get_user_by('id', $userId);
        if (!$user) {
            return;
        }

        // profile_update only fires when the profile is saved, so always sync.
        // We cannot reliably detect meta changes because $oldUserdata->first_name
        // uses WP_User magic __get() which reads the already-updated DB values.
        $this->upsertContact(
            $user,
            !empty($settings['list_ids']) ? array_map('intval', (array) $settings['list_ids']) : [],
            !empty($settings['tag_ids']) ? array_map('intval', (array) $settings['tag_ids']) : [],
            in_array($settings['subscription_status'] ?? '', ['subscribed', 'pending'], true) ? $settings['subscription_status'] : 'subscribed',
            !empty($settings['field_mapping']) ? (array) $settings['field_mapping'] : []
        );
    }

    /**
     * Create or update a MailerPress contact from a WordPress user object.
     *
     * Strategy:
     * - Unknown email → INSERT + fire mailerpress_contact_created
     * - Known email, not unsubscribed → UPDATE name fields + INSERT IGNORE list/tags
     * - Known email, unsubscribed → skip (respect user's choice)
     * - Invalid email → error
     */
    private function upsertContact(object $user, array $listIds, array $tagIds, string $status, array $fieldMapping = []): string
    {
        global $wpdb;

        if (empty($user->user_email) || !is_email($user->user_email)) {
            return 'error';
        }

        $email = sanitize_email($user->user_email);

        // Read first_name / last_name directly from user meta to bypass object cache.
        // Fall back to splitting display_name only when both are empty.
        $rawFirst = null;
        $rawLast  = null;

        if ( ! empty( $user->ID ) ) {
            $rawFirst = get_user_meta( (int) $user->ID, 'first_name', true );
            $rawLast  = get_user_meta( (int) $user->ID, 'last_name', true );
        }

        if ( empty( $rawFirst ) && empty( $rawLast ) ) {
            $nameParts = explode( ' ', $user->display_name ?? '', 2 );
            $rawFirst  = $nameParts[0] ?? '';
            $rawLast   = $nameParts[1] ?? '';
        }

        $firstName = sanitize_text_field( $rawFirst ?? '' );
        $lastName  = sanitize_text_field( $rawLast ?? '' );
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        $existing = null;

        // First, look up by wp_user_id stored in opt_in_details (handles email changes)
        if ( ! empty( $user->ID ) ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT contact_id, subscription_status, email FROM {$contactTable}
                     WHERE opt_in_source = 'wp_sync'
                       AND JSON_UNQUOTE(JSON_EXTRACT(opt_in_details, '$.wp_user_id')) = %s",
                    (string) (int) $user->ID
                )
            );
        }

        // Fallback: look up by email (covers contacts synced before wp_user_id tracking)
        if ( ! $existing ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT contact_id, subscription_status, email FROM {$contactTable} WHERE email = %s",
                    $email
                )
            );
        }

        if ($existing) {
            // Do not override the status of an unsubscribed contact
            if ('unsubscribed' === $existing->subscription_status) {
                return 'skipped';
            }

            $updateData = [
                'first_name'  => $firstName,
                'last_name'   => $lastName,
                'updated_at'  => current_time('mysql'),
            ];

            // Update email if it changed (WP user changed their email)
            if ( $existing->email !== $email ) {
                $updateData['email'] = $email;
            }

            // Ensure opt_in_details has wp_user_id (backfill for old contacts)
            if ( ! empty( $user->ID ) ) {
                $updateData['opt_in_details'] = wp_json_encode( ['wp_user_id' => (int) $user->ID] );
            }

            $wpdb->update(
                $contactTable,
                $updateData,
                ['contact_id' => (int) $existing->contact_id]
            );

            $this->assignListAndTags((int) $existing->contact_id, $listIds, $tagIds);
            $this->applyFieldMapping((int) $existing->contact_id, $user, $fieldMapping);

            return 'updated';
        }

        // New contact
        $inserted = $wpdb->insert(
            $contactTable,
            [
                'email'               => $email,
                'first_name'          => $firstName,
                'last_name'           => $lastName,
                'subscription_status' => $status,
                'opt_in_source'       => 'wp_sync',
                'opt_in_details'      => wp_json_encode(['wp_user_id' => (int) $user->ID]),
                'unsubscribe_token'   => wp_generate_password(64, false),
                'access_token'        => wp_generate_password(64, false),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$inserted) {
            return 'error';
        }

        $contactId = (int) $wpdb->insert_id;
        $this->assignListAndTags($contactId, $listIds, $tagIds);
        $this->applyFieldMapping($contactId, $user, $fieldMapping);

        do_action('mailerpress_contact_created', $contactId);

        return 'created';
    }

    /**
     * Apply field mapping: read source fields from the WP user and write to contact custom fields.
     *
     * @param array $fieldMapping [['source_field' => 'meta:phone', 'target_field' => 'phone'], ...]
     */
    private function applyFieldMapping(int $contactId, object $user, array $fieldMapping): void
    {
        if (empty($fieldMapping) || empty($user->ID)) {
            return;
        }

        global $wpdb;
        $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);

        foreach ($fieldMapping as $mapping) {
            $sourceField = $mapping['source_field'] ?? '';
            $targetField = $mapping['target_field'] ?? '';
            if (empty($sourceField) || empty($targetField)) {
                continue;
            }

            // Resolve value from WP user
            if (str_starts_with($sourceField, 'meta:')) {
                $metaKey = substr($sourceField, 5);
                $value   = get_user_meta($user->ID, $metaKey, true);
            } else {
                $wpUser = get_userdata($user->ID);
                $value  = $wpUser ? ($wpUser->$sourceField ?? '') : '';
            }

            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            if ($value === '') {
                continue;
            }

            // UPSERT into contact_custom_fields
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$customFieldsTable} (contact_id, field_key, field_value)
                     VALUES (%d, %s, %s)
                     ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)",
                    $contactId,
                    $targetField,
                    sanitize_text_field($value)
                )
            );
        }
    }

    /**
     * Assign a contact to a list and tags using INSERT IGNORE to ensure idempotency.
     */
    private function assignListAndTags(int $contactId, array $listIds, array $tagIds): void
    {
        global $wpdb;

        if (!empty($listIds)) {
            $listTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
            foreach ($listIds as $listId) {
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO {$listTable} (contact_id, list_id) VALUES (%d, %d)",
                        $contactId,
                        (int) $listId
                    )
                );
            }
        }

        if (!empty($tagIds)) {
            $tagTable = Tables::get(Tables::CONTACT_TAGS);
            foreach ($tagIds as $tagId) {
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO {$tagTable} (contact_id, tag_id) VALUES (%d, %d)",
                        $contactId,
                        $tagId
                    )
                );
            }
        }
    }

    /**
     * Read current settings from DB (used in hook callbacks to avoid circular DI).
     */
    private function getCurrentSettings(): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_SYNC_CONNECTORS);

        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT settings FROM {$table} WHERE connector_key = %s AND status = 'active'",
                $this->getKey()
            )
        );

        if (!$record || empty($record->settings)) {
            return [];
        }

        return json_decode($record->settings, true) ?? [];
    }
}
