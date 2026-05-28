<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;
use MailerPress\Models\CustomFields;
use MailerPress\Models\Contacts as ContactsModel;

class ContactUpsertService
{
    private const STANDARD_CUSTOM_FIELD_KEYS = [
        'email',
        'first_name',
        'last_name',
        'created_at',
        'updated_at',
    ];

    public function upsert(array $data): array
    {
        global $wpdb;

        $email = sanitize_email($data['contactEmail'] ?? $data['email'] ?? '');
        if (empty($email) || !is_email($email)) {
            return [
                'success' => false,
                'error' => __('A valid email address is required.', 'mailerpress'),
            ];
        }

        $updateExisting = filter_var($data['update_existing'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $updateExisting = $updateExisting ?? true;

        $updateContactFields = filter_var($data['update_contact_fields'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $updateContactFields = $updateContactFields ?? true;

        $assignDefaultList = filter_var($data['assign_default_list'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $assignDefaultList = $assignDefaultList ?? true;

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contactsModel = new ContactsModel();
        $existingContact = $contactsModel->getContactByEmail($email);

        if ($existingContact && !$updateExisting) {
            return [
                'success' => true,
                'created' => false,
                'updated' => false,
                'contact_id' => (int) $existingContact->contact_id,
                'email' => $email,
                'status' => 'already_exists',
                'lists_added' => [],
                'tags_added' => [],
                'custom_fields_added' => [],
                'custom_fields_updated' => [],
            ];
        }

        $isNew = !$existingContact;
        $contactId = $existingContact ? (int) $existingContact->contact_id : 0;
        $contactUpdated = false;

        $firstName = $this->firstPresent($data, ['contactFirstName', 'first_name', 'firstName']);
        $lastName = $this->firstPresent($data, ['contactLastName', 'last_name', 'lastName']);
        $subscriptionStatus = $this->normalizeStatus(
            $this->firstPresent($data, ['contactStatus', 'subscription_status', 'subscriptionStatus'])
        );
        $optInSource = sanitize_text_field((string) ($data['opt_in_source'] ?? 'unknown'));
        $optInDetails = $data['optin_details'] ?? $data['opt_in_details'] ?? '';

        if ($isNew) {
            $subscriptionStatus = $subscriptionStatus ?: 'subscribed';

            $inserted = $wpdb->insert(
                $contactTable,
                [
                    'email' => $email,
                    'first_name' => sanitize_text_field((string) ($firstName ?? '')),
                    'last_name' => sanitize_text_field((string) ($lastName ?? '')),
                    'subscription_status' => $subscriptionStatus,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'unsubscribe_token' => wp_generate_uuid4(),
                    'opt_in_source' => $optInSource,
                    'opt_in_details' => is_scalar($optInDetails) ? (string) $optInDetails : wp_json_encode($optInDetails),
                    'access_token' => bin2hex(random_bytes(32)),
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            if ($inserted === false) {
                return [
                    'success' => false,
                    'error' => __('Failed to create contact in database.', 'mailerpress'),
                ];
            }

            $contactId = (int) $wpdb->insert_id;
            $contactUpdated = true;
        } elseif ($updateContactFields) {
            $updateData = ['updated_at' => current_time('mysql')];
            $updateFormat = ['%s'];

            if ($firstName !== null) {
                $updateData['first_name'] = sanitize_text_field((string) $firstName);
                $updateFormat[] = '%s';
            }

            if ($lastName !== null) {
                $updateData['last_name'] = sanitize_text_field((string) $lastName);
                $updateFormat[] = '%s';
            }

            if ($subscriptionStatus) {
                $updateData['subscription_status'] = $subscriptionStatus;
                $updateFormat[] = '%s';
            }

            if (isset($data['opt_in_source'])) {
                $updateData['opt_in_source'] = $optInSource;
                $updateFormat[] = '%s';
            }

            if (array_key_exists('optin_details', $data) || array_key_exists('opt_in_details', $data)) {
                $updateData['opt_in_details'] = is_scalar($optInDetails) ? (string) $optInDetails : wp_json_encode($optInDetails);
                $updateFormat[] = '%s';
            }

            if (count($updateData) > 1) {
                $wpdb->update(
                    $contactTable,
                    $updateData,
                    ['contact_id' => $contactId],
                    $updateFormat,
                    ['%d']
                );
                $contactUpdated = true;
            }
        }

        $rawListItems = $this->collectListInput($data);
        $lists = $this->normalizeListIds($rawListItems);
        $rawListsProvided = !empty($rawListItems);
        if (empty($lists) && !$rawListsProvided && $assignDefaultList && ($isNew || !$this->contactHasLists($contactId))) {
            $defaultListId = $this->getDefaultListId();
            if ($defaultListId) {
                $lists = [$defaultListId];
            }
        }

        $listMode = $data['list_mode'] ?? $data['lists_mode'] ?? 'append';
        $addedLists = $this->syncLists($contactId, $lists, $listMode === 'replace');

        $tags = $this->normalizeTagIds($this->collectTagInput($data));
        $tagMode = $data['tag_mode'] ?? $data['tags_mode'] ?? 'append';
        $addedTags = $this->syncTags($contactId, $tags, $tagMode === 'replace');

        $customFields = $this->normalizeCustomFields($data['custom_fields'] ?? []);
        if (!empty($data['auto_map_custom_fields'])) {
            $customFields = array_merge($this->autoMapCustomFields($data), $customFields);
        }
        $customFieldChanges = $this->syncCustomFields($contactId, $customFields);

        if ($isNew) {
            do_action('mailerpress_contact_created', $contactId);
        } elseif ($contactUpdated || !empty($addedLists) || !empty($addedTags) || !empty($customFieldChanges['added']) || !empty($customFieldChanges['updated'])) {
            do_action('mailerpress_contact_updated', $contactId);
        }

        return [
            'success' => true,
            'created' => $isNew,
            'updated' => !$isNew && ($contactUpdated || !empty($addedLists) || !empty($addedTags) || !empty($customFieldChanges['added']) || !empty($customFieldChanges['updated'])),
            'contact_id' => $contactId,
            'email' => $email,
            'status' => $isNew ? 'created' : 'updated',
            'lists_added' => $addedLists,
            'lists_requested' => $rawListItems,
            'lists_resolved' => $lists,
            'tags_added' => $addedTags,
            'custom_fields_added' => $customFieldChanges['added'],
            'custom_fields_updated' => $customFieldChanges['updated'],
        ];
    }

    private function firstPresent(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return null;
    }

    private function normalizeStatus(mixed $status): string
    {
        $status = sanitize_text_field((string) ($status ?? ''));
        return in_array($status, ['subscribed', 'pending', 'unsubscribed'], true) ? $status : '';
    }

    private function collectListInput(array $data): array
    {
        $lists = $data['lists'] ?? $data['contact_lists'] ?? $data['list_ids'] ?? [];
        $items = $this->expandRelationInput($lists);

        if (isset($data['list_id'])) {
            $items = array_merge($items, $this->expandRelationInput($data['list_id']));
        }

        if (isset($data['list'])) {
            $items = array_merge($items, $this->expandRelationInput($data['list']));
        }

        if (isset($data['list_name'])) {
            $items = array_merge($items, $this->expandRelationInput($data['list_name']));
        }

        return $items;
    }

    private function collectTagInput(array $data): array
    {
        $tags = $data['tags'] ?? $data['contact_tags'] ?? $data['tag_ids'] ?? [];
        $items = $this->expandRelationInput($tags);

        if (isset($data['tag_id'])) {
            $items = array_merge($items, $this->expandRelationInput($data['tag_id']));
        }

        if (isset($data['tag'])) {
            $items = array_merge($items, $this->expandRelationInput($data['tag']));
        }

        if (isset($data['tag_name'])) {
            $items = array_merge($items, $this->expandRelationInput($data['tag_name']));
        }

        return $items;
    }

    private function expandRelationInput(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            if ($this->isRelationObject($value)) {
                return [$value];
            }

            $items = [];
            foreach ($value as $item) {
                $items = array_merge($items, $this->expandRelationInput($item));
            }
            return $items;
        }

        if (!is_string($value)) {
            return [$value];
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $unescaped = wp_unslash($value);
        if ($unescaped !== $value) {
            $decoded = $this->decodeRelationString($unescaped);
            if ($decoded !== null) {
                return $this->expandRelationInput($decoded);
            }
            $value = $unescaped;
        }

        $decoded = $this->decodeRelationString($value);
        if ($decoded !== null) {
            return $this->expandRelationInput($decoded);
        }

        if (preg_match('/^\d+(?:\s*,\s*\d+)+$/', $value)) {
            return array_map('trim', explode(',', $value));
        }

        return [$value];
    }

    private function decodeRelationString(string $value): mixed
    {
        if (!str_starts_with($value, '[') && !str_starts_with($value, '{')) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $jsonLike = preg_replace('/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)(\s*:)/', '$1"$2"$3', $value);
        $jsonLike = preg_replace("/'([^']*)'/", '"$1"', (string) $jsonLike);

        $decoded = json_decode($jsonLike, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function isRelationObject(array $value): bool
    {
        return isset($value['id'])
            || isset($value['value'])
            || isset($value['list_id'])
            || isset($value['tag_id'])
            || isset($value['name'])
            || isset($value['label']);
    }

    private function normalizeListIds(array $lists): array
    {
        return $this->normalizeRelationIds($lists, Tables::get(Tables::MAILERPRESS_LIST), 'list_id');
    }

    private function normalizeTagIds(array $tags): array
    {
        return $this->normalizeRelationIds($tags, Tables::get(Tables::MAILERPRESS_TAGS), 'tag_id');
    }

    private function normalizeRelationIds(array $items, string $table, string $idColumn): array
    {
        global $wpdb;

        $ids = [];
        foreach ($items as $item) {
            $id = 0;
            $name = '';

            if (is_array($item)) {
                $id = (int) ($item['id'] ?? $item['value'] ?? $item[$idColumn] ?? 0);
                $name = sanitize_text_field((string) ($item['name'] ?? $item['label'] ?? ''));
            } elseif (is_numeric($item)) {
                $id = (int) $item;
            } elseif (is_string($item)) {
                $name = sanitize_text_field($item);
            }

            if ($id > 0) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT {$idColumn} FROM {$table} WHERE {$idColumn} = %d", $id));
                if ($exists) {
                    $ids[] = $id;
                }
                continue;
            }

            if ($name !== '') {
                $resolvedId = $wpdb->get_var($wpdb->prepare("SELECT {$idColumn} FROM {$table} WHERE name = %s LIMIT 1", $name));
                if ($resolvedId) {
                    $ids[] = (int) $resolvedId;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function syncLists(int $contactId, array $listIds, bool $replace): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        if ($replace) {
            $current = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT list_id FROM {$table} WHERE contact_id = %d", $contactId)));
            $toRemove = array_diff($current, $listIds);
            foreach ($toRemove as $listId) {
                $deleted = $wpdb->delete($table, ['contact_id' => $contactId, 'list_id' => (int) $listId], ['%d', '%d']);
                if ($deleted !== false && $deleted > 0) {
                    do_action('mailerpress_contact_list_removed', $contactId, (int) $listId);
                }
            }
        }

        return $this->insertRelations($contactId, $listIds, $table, 'list_id', 'mailerpress_contact_list_added');
    }

    private function syncTags(int $contactId, array $tagIds, bool $replace): array
    {
        global $wpdb;

        $table = Tables::get(Tables::CONTACT_TAGS);
        if ($replace) {
            $current = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT tag_id FROM {$table} WHERE contact_id = %d", $contactId)));
            $toRemove = array_diff($current, $tagIds);
            foreach ($toRemove as $tagId) {
                $deleted = $wpdb->delete($table, ['contact_id' => $contactId, 'tag_id' => (int) $tagId], ['%d', '%d']);
                if ($deleted !== false && $deleted > 0) {
                    do_action('mailerpress_contact_tag_removed', $contactId, (int) $tagId);
                }
            }
        }

        return $this->insertRelations($contactId, $tagIds, $table, 'tag_id', 'mailerpress_contact_tag_added');
    }

    private function insertRelations(int $contactId, array $ids, string $table, string $relationColumn, string $hook): array
    {
        global $wpdb;

        $added = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE contact_id = %d AND {$relationColumn} = %d",
                $contactId,
                $id
            ));

            if ($exists) {
                continue;
            }

            $inserted = $wpdb->insert(
                $table,
                [
                    'contact_id' => $contactId,
                    $relationColumn => $id,
                ],
                ['%d', '%d']
            );

            if ($inserted !== false) {
                $added[] = $id;
                do_action($hook, $contactId, $id);
            }
        }

        return $added;
    }

    private function normalizeCustomFields(mixed $customFields): array
    {
        if (!is_array($customFields)) {
            return [];
        }

        $normalized = [];
        foreach ($customFields as $key => $value) {
            if (is_array($value) && isset($value['field_key'])) {
                $fieldKey = sanitize_key((string) $value['field_key']);
                $fieldValue = $value['field_value'] ?? $value['value'] ?? '';
            } else {
                $fieldKey = is_string($key) ? sanitize_key($key) : '';
                $fieldValue = is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
            }

            if ($fieldKey === '' || in_array($fieldKey, self::STANDARD_CUSTOM_FIELD_KEYS, true)) {
                continue;
            }

            $normalized[$fieldKey] = $fieldValue;
        }

        return $normalized;
    }

    private function syncCustomFields(int $contactId, array $customFields): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
        $added = [];
        $updated = [];

        foreach ($customFields as $fieldKey => $fieldValue) {
            $sanitizedValue = CustomFields::sanitizeValue($fieldKey, $fieldValue);
            if ($sanitizedValue === null) {
                continue;
            }

            $dbValue = is_scalar($sanitizedValue)
                ? (string) $sanitizedValue
                : wp_json_encode($sanitizedValue);

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT field_id FROM {$table} WHERE contact_id = %d AND field_key = %s LIMIT 1",
                $contactId,
                $fieldKey
            ));

            if ($existing) {
                $wpdb->update(
                    $table,
                    ['field_value' => $dbValue, 'updated_at' => current_time('mysql')],
                    ['field_id' => (int) $existing],
                    ['%s', '%s'],
                    ['%d']
                );
                $updated[] = $fieldKey;
                do_action('mailerpress_contact_custom_field_updated', $contactId, $fieldKey, $sanitizedValue);
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'contact_id' => $contactId,
                    'field_key' => $fieldKey,
                    'field_value' => $dbValue,
                ],
                ['%d', '%s', '%s']
            );

            $added[] = $fieldKey;
            do_action('mailerpress_contact_custom_field_added', $contactId, $fieldKey, $sanitizedValue);
        }

        return [
            'added' => $added,
            'updated' => $updated,
        ];
    }

    private function autoMapCustomFields(array $data): array
    {
        $mapped = [];
        foreach ((new CustomFields())->all() as $field) {
            $fieldKey = (string) ($field->field_key ?? '');
            if ($fieldKey === '' || in_array($fieldKey, self::STANDARD_CUSTOM_FIELD_KEYS, true)) {
                continue;
            }

            if (array_key_exists($fieldKey, $data) && is_scalar($data[$fieldKey])) {
                $mapped[$fieldKey] = $data[$fieldKey];
            }
        }

        return $mapped;
    }

    private function getDefaultListId(): int
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_LIST);
        return (int) $wpdb->get_var("SELECT list_id FROM {$table} WHERE is_default = 1 LIMIT 1");
    }

    private function contactHasLists(int $contactId): bool
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE contact_id = %d", $contactId)) > 0;
    }
}
