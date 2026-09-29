<?php

declare(strict_types=1);

namespace MailerPress\Core\Migration\Sources;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migration\MigrationRepository;
use MailerPress\Core\Migration\MigrationSourceInterface;
use MailerPress\Services\ContactUpsertService;

class NewsletterMigrationSource implements MigrationSourceInterface
{
    private const SOURCE_KEY = 'newsletter';
    private const CHUNK_SIZE = 250;
    private const LIST_MAX = 40;
    private const PROFILE_MAX = 20;

    /**
     * @var array<string, array<int, string>>
     */
    private array $columnsCache = [];

    public function __construct(private ContactUpsertService $contactUpsertService)
    {
    }

    public function getKey(): string
    {
        return self::SOURCE_KEY;
    }

    public function getLabel(): string
    {
        return 'Newsletter';
    }

    public function getDescription(): string
    {
        return __('Import contacts, lists and custom fields from Newsletter.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return 'newsletter';
    }

    public function getIconSvg(): string
    {
        return '';
    }

    public function isDetected(): bool
    {
        return $this->tableExists($this->sourceTable('newsletter')) || $this->hasNewsletterConfiguration();
    }

    public function getDetectionDetails(): array
    {
        return [
            'active_plugin' => $this->isNewsletterActive(),
            'users_table' => $this->sourceTable('newsletter'),
            'users_table_exists' => $this->tableExists($this->sourceTable('newsletter')),
            'has_configuration' => $this->hasNewsletterConfiguration(),
            'version' => $this->getNewsletterVersion(),
        ];
    }

    public function getEntityTypes(): array
    {
        return [
            [
                'key' => 'lists',
                'label' => __('Lists', 'mailerpress'),
                'description' => __('Newsletter lists mapped to MailerPress lists.', 'mailerpress'),
                'supported' => true,
                'default' => true,
            ],
            [
                'key' => 'custom_fields',
                'label' => __('Custom fields', 'mailerpress'),
                'description' => __('Newsletter profile fields mapped to MailerPress custom fields.', 'mailerpress'),
                'supported' => true,
                'default' => true,
            ],
            [
                'key' => 'contacts',
                'label' => __('Contacts', 'mailerpress'),
                'description' => __('Subscribers with list and custom field assignments.', 'mailerpress'),
                'supported' => true,
                'default' => true,
            ],
            [
                'key' => 'campaigns',
                'label' => __('Campaigns', 'mailerpress'),
                'description' => __('Detected for future conversion; not imported by this migration yet.', 'mailerpress'),
                'supported' => false,
                'default' => false,
            ],
            [
                'key' => 'forms',
                'label' => __('Forms', 'mailerpress'),
                'description' => __('Detected for future conversion; not imported by this migration yet.', 'mailerpress'),
                'supported' => false,
                'default' => false,
            ],
            [
                'key' => 'statistics',
                'label' => __('Statistics', 'mailerpress'),
                'description' => __('Detected for audit only; not imported by this migration yet.', 'mailerpress'),
                'supported' => false,
                'default' => false,
            ],
        ];
    }

    public function estimate(array $entityTypes = []): array
    {
        $entityTypes = $this->normalizeEntitySelection($entityTypes);
        $entities = [];
        $total = 0;

        foreach ($this->getEntityTypes() as $entityType) {
            $key = (string) $entityType['key'];
            $count = $this->estimateEntity($key);
            $selected = empty($entityTypes) || in_array($key, $entityTypes, true);

            $entities[$key] = [
                'key' => $key,
                'label' => $entityType['label'],
                'count' => $count,
                'supported' => (bool) $entityType['supported'],
                'selected' => $selected,
            ];

            if ($selected && !empty($entityType['supported'])) {
                $total += $count;
            }
        }

        return [
            'total' => $total,
            'entities' => $entities,
            'warnings' => [],
        ];
    }

    public function createChunks(int $runId, array $entityTypes, array $options, MigrationRepository $repository): array
    {
        $created = [];
        $ordered = ['lists', 'custom_fields', 'contacts'];
        $entityTypes = array_values(array_intersect($ordered, $entityTypes));

        foreach ($ordered as $entityType) {
            if (!in_array($entityType, $entityTypes, true)) {
                continue;
            }

            if (in_array($entityType, ['lists', 'custom_fields'], true)) {
                $chunkId = $repository->insertChunk($runId, self::SOURCE_KEY, $entityType, ['scope' => 'core']);
                if ($chunkId > 0) {
                    $created[] = $chunkId;
                }
                continue;
            }

            $table = $this->sourceTable('newsletter');
            if (!$this->tableExists($table)) {
                continue;
            }

            $created = array_merge(
                $created,
                $this->createRangeChunks($repository, $runId, 'contacts', $table, self::CHUNK_SIZE)
            );
        }

        return $created;
    }

    public function processChunk(object $run, object $chunk, MigrationRepository $repository): array
    {
        $payload = json_decode((string) ($chunk->payload ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];

        if ((string) $run->mode === 'dry_run') {
            return [
                'processed' => $this->countChunkRows((string) $chunk->entity_type, $payload),
                'skipped' => 0,
                'errors' => 0,
                'message' => __('Dry run completed without writing data.', 'mailerpress'),
            ];
        }

        return match ((string) $chunk->entity_type) {
            'lists' => $this->processLists($repository, (int) $run->id),
            'custom_fields' => $this->processCustomFields($repository, (int) $run->id),
            'contacts' => $this->processContacts($payload, $repository, $run),
            default => [
                'processed' => 0,
                'skipped' => 1,
                'errors' => 0,
                'skipped_chunk' => true,
                'message' => __('This entity type is not supported by the Newsletter migration source.', 'mailerpress'),
            ],
        };
    }

    private function processSettings(MigrationRepository $repository, int $runId): array
    {
        $settings = $this->readNewsletterSettings();
        $main = is_array($settings['main'] ?? null) ? $settings['main'] : [];

        $defaultSettings = get_option('mailerpress_default_settings', []);
        if (is_string($defaultSettings)) {
            $defaultSettings = json_decode($defaultSettings, true) ?: [];
        }
        if (!is_array($defaultSettings)) {
            $defaultSettings = [];
        }

        $fromAddress = sanitize_email((string) ($main['sender_email'] ?? ''));
        $replyToAddress = sanitize_email((string) ($main['reply_to'] ?? ''));

        if ($fromAddress) {
            $defaultSettings['fromAddress'] = $fromAddress;
        }

        if (!empty($main['sender_name'])) {
            $defaultSettings['fromName'] = sanitize_text_field((string) $main['sender_name']);
        }

        if ($replyToAddress) {
            $defaultSettings['replyToAddress'] = $replyToAddress;
        }

        $pageResult = $this->applyPageSettings($settings, $defaultSettings, $repository, $runId);
        $defaultSettings = $pageResult['settings'];

        update_option('mailerpress_default_settings', $defaultSettings);
        update_option('mailerpress_global_email_senders', [
            'fromAddress' => $defaultSettings['fromAddress'] ?? '',
            'fromName' => $defaultSettings['fromName'] ?? '',
            'replyToAddress' => $defaultSettings['replyToAddress'] ?? '',
            'replyToName' => $defaultSettings['replyToName'] ?? '',
        ]);
        update_option('mailerpress_newsletter_migration_settings_snapshot', $settings, false);

        $repository->saveMapping(self::SOURCE_KEY, 'settings', 'core', 'option', 'mailerpress_default_settings', $runId, [
            'imported_keys' => array_keys($settings),
            'imported_pages' => $pageResult['pages'],
        ]);

        return [
            'processed' => 1,
            'skipped' => 0,
            'errors' => 0,
        ];
    }

    private function applyPageSettings(
        array $settings,
        array $defaultSettings,
        MigrationRepository $repository,
        int $runId
    ): array {
        $main = is_array($settings['main'] ?? null) ? $settings['main'] : [];
        $profile = is_array($settings['profile'] ?? null) ? $settings['profile'] : [];
        $importedPages = [];

        $mainPageId = absint($main['page'] ?? 0);
        $profilePageId = $this->normalizeProfilePageId($profile['page_id'] ?? 0);
        $manageSourcePageId = $profilePageId ?: $mainPageId;
        $manageTargetPageId = 0;

        if ($manageSourcePageId > 0) {
            $manageTargetPageId = $this->ensureMailerPressPageFromNewsletter($manageSourcePageId, 'manage');
            if ($manageTargetPageId > 0) {
                $defaultSettings['subpage'] = [
                    'useDefault' => false,
                    'pageId' => (string) $manageTargetPageId,
                ];
                $importedPages['manage'] = [
                    'source_page_id' => $manageSourcePageId,
                    'target_page_id' => $manageTargetPageId,
                ];
                $repository->saveMapping(self::SOURCE_KEY, 'public_page', 'manage:' . $manageSourcePageId, 'page', $manageTargetPageId, $runId, [
                    'purpose' => 'manage',
                ]);
            }
        }

        $unsubscribeSourcePageId = $mainPageId ?: $manageSourcePageId;
        if ($unsubscribeSourcePageId > 0) {
            $unsubscribeTargetPageId = $unsubscribeSourcePageId === $manageSourcePageId && $manageTargetPageId > 0
                ? $manageTargetPageId
                : $this->ensureMailerPressPageFromNewsletter($unsubscribeSourcePageId, 'unsubscribe');

            if ($unsubscribeTargetPageId > 0) {
                $defaultSettings['unsubpage'] = [
                    'useDefault' => false,
                    'pageId' => (string) $unsubscribeTargetPageId,
                ];
                $importedPages['unsubscribe'] = [
                    'source_page_id' => $unsubscribeSourcePageId,
                    'target_page_id' => $unsubscribeTargetPageId,
                ];
                $repository->saveMapping(self::SOURCE_KEY, 'public_page', 'unsubscribe:' . $unsubscribeSourcePageId, 'page', $unsubscribeTargetPageId, $runId, [
                    'purpose' => 'unsubscribe',
                ]);
            }
        }

        return [
            'settings' => $defaultSettings,
            'pages' => $importedPages,
        ];
    }

    private function processLists(MigrationRepository $repository, int $runId): array
    {
        $processed = 0;
        $skipped = 0;

        foreach ($this->getNewsletterLists() as $sourceId => $list) {
            $targetId = $this->ensureList((string) ($list['name'] ?? ''));
            if ($targetId <= 0) {
                $skipped++;
                continue;
            }

            $repository->saveMapping(self::SOURCE_KEY, 'list', (int) $sourceId, 'list', $targetId, $runId, [
                'status' => (int) ($list['status'] ?? 0),
                'forced' => !empty($list['forced']),
            ]);
            $processed++;
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => 0,
        ];
    }

    private function processCustomFields(MigrationRepository $repository, int $runId): array
    {
        $this->ensureSystemCustomFields();

        $processed = 0;
        $skipped = 0;

        foreach ($this->getNewsletterCustomFields() as $sourceId => $field) {
            $fieldKey = $this->newsletterFieldKey((string) ($field['name'] ?? ''), (int) $sourceId);
            $targetId = $this->ensureCustomFieldDefinition(
                $fieldKey,
                (string) ($field['name'] ?? ''),
                $this->mapCustomFieldType((string) ($field['type'] ?? 'text')),
                $this->extractCsvOptions((string) ($field['options'] ?? ''))
            );

            if ($targetId <= 0) {
                $skipped++;
                continue;
            }

            $repository->saveMapping(self::SOURCE_KEY, 'custom_field', (int) $sourceId, 'custom_field', $fieldKey, $runId, [
                'target_definition_id' => $targetId,
                'source_type' => (string) ($field['type'] ?? 'text'),
                'required' => !empty($field['required']),
                'status' => (int) ($field['status'] ?? 0),
            ]);
            $processed++;
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => 0,
        ];
    }

    private function processContacts(array $payload, MigrationRepository $repository, object $run): array
    {
        $this->ensureSystemCustomFields();

        $rows = $this->getSubscriberRows($payload);
        $options = $this->getRunOptions($run);
        $updateExisting = !array_key_exists('update_existing', $options) || (bool) $options['update_existing'];

        $processed = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $customFields = array_merge(
                $this->subscriberMetadataFields($row),
                $this->getSubscriberCustomFields($row, $repository, (int) $run->id)
            );

            $result = $this->contactUpsertService->upsert([
                'contactEmail' => (string) ($row->email ?? ''),
                'contactFirstName' => (string) ($row->name ?? ''),
                'contactLastName' => (string) ($row->surname ?? ''),
                'contactStatus' => $this->mapSubscriberStatus((string) ($row->status ?? '')),
                'opt_in_source' => 'newsletter_migration',
                'opt_in_details' => [
                    'source' => 'Newsletter',
                    'subscriber_id' => (int) $row->id,
                    'original_status' => (string) ($row->status ?? ''),
                    'original_source' => (string) ($row->source ?? ''),
                    'ip' => (string) ($row->ip ?? ''),
                ],
                'lists' => $this->getSubscriberListIds($row, $repository, (int) $run->id),
                'custom_fields' => $customFields,
                'assign_default_list' => false,
                'update_existing' => $updateExisting,
            ]);

            if (empty($result['success'])) {
                $errors++;
                continue;
            }

            $repository->saveMapping(self::SOURCE_KEY, 'subscriber', (int) $row->id, 'contact', (int) $result['contact_id'], (int) $run->id, [
                'email' => (string) ($row->email ?? ''),
                'created' => (bool) ($result['created'] ?? false),
                'updated' => (bool) ($result['updated'] ?? false),
                'original_status' => (string) ($row->status ?? ''),
            ]);
            $processed++;
        }

        return [
            'processed' => $processed,
            'skipped' => 0,
            'errors' => $errors,
        ];
    }

    private function getSubscriberRows(array $payload): array
    {
        global $wpdb;

        $table = $this->sourceTable('newsletter');
        if (!$this->tableExists($table)) {
            return [];
        }

        $columns = [
            'id',
            'email',
            'name',
            'surname',
            'sex',
            'status',
            'created',
            'updated',
            'last_activity',
            'language',
            'source',
            'wp_user_id',
            'ip',
            'referrer',
            'http_referer',
            'country',
            'region',
            'city',
            'bounce_type',
            'bounce_time',
            'unsub_email_id',
            'unsub_time',
        ];

        for ($i = 1; $i <= self::LIST_MAX; $i++) {
            $columns[] = 'list_' . $i;
        }

        for ($i = 1; $i <= self::PROFILE_MAX; $i++) {
            $columns[] = 'profile_' . $i;
        }

        $select = $this->selectColumns($table, $columns);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$select}
                 FROM {$table}
                 WHERE id BETWEEN %d AND %d
                 ORDER BY id ASC",
                (int) ($payload['min_id'] ?? 0),
                (int) ($payload['max_id'] ?? 0)
            )
        ) ?: [];
    }

    private function getSubscriberListIds(object $row, MigrationRepository $repository, int $runId): array
    {
        $ids = [];

        foreach ($this->getNewsletterLists() as $sourceId => $list) {
            $column = 'list_' . $sourceId;
            if (empty($row->{$column})) {
                continue;
            }

            $mapping = $repository->getMapping(self::SOURCE_KEY, 'list', (int) $sourceId);
            $targetId = $mapping ? (int) $mapping->target_id : 0;

            if ($targetId <= 0) {
                $targetId = $this->ensureList((string) ($list['name'] ?? ''));
                if ($targetId > 0) {
                    $repository->saveMapping(self::SOURCE_KEY, 'list', (int) $sourceId, 'list', $targetId, $runId, [
                        'status' => (int) ($list['status'] ?? 0),
                        'forced' => !empty($list['forced']),
                    ]);
                }
            }

            if ($targetId > 0) {
                $ids[] = $targetId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function getSubscriberCustomFields(object $row, MigrationRepository $repository, int $runId): array
    {
        $fields = [];

        foreach ($this->getNewsletterCustomFields() as $sourceId => $field) {
            $column = 'profile_' . $sourceId;
            $value = (string) ($row->{$column} ?? '');
            if ($value === '') {
                continue;
            }

            $mapping = $repository->getMapping(self::SOURCE_KEY, 'custom_field', (int) $sourceId);
            $fieldKey = $mapping ? (string) $mapping->target_id : $this->newsletterFieldKey((string) ($field['name'] ?? ''), (int) $sourceId);

            if (!$mapping) {
                $targetId = $this->ensureCustomFieldDefinition(
                    $fieldKey,
                    (string) ($field['name'] ?? ''),
                    $this->mapCustomFieldType((string) ($field['type'] ?? 'text')),
                    $this->extractCsvOptions((string) ($field['options'] ?? ''))
                );

                if ($targetId > 0) {
                    $repository->saveMapping(self::SOURCE_KEY, 'custom_field', (int) $sourceId, 'custom_field', $fieldKey, $runId, [
                        'target_definition_id' => $targetId,
                        'source_type' => (string) ($field['type'] ?? 'text'),
                    ]);
                }
            }

            $fields[$fieldKey] = $value;
        }

        return $fields;
    }

    private function subscriberMetadataFields(object $row): array
    {
        $fields = [
            'newsletter_subscriber_id' => (string) ($row->id ?? ''),
            'newsletter_status' => (string) ($row->status ?? ''),
            'newsletter_gender' => (string) ($row->sex ?? ''),
            'newsletter_language' => (string) ($row->language ?? ''),
            'newsletter_source' => (string) ($row->source ?? ''),
            'newsletter_wp_user_id' => (string) ($row->wp_user_id ?? ''),
            'newsletter_ip' => (string) ($row->ip ?? ''),
            'newsletter_referrer' => (string) ($row->referrer ?? ''),
            'newsletter_http_referer' => (string) ($row->http_referer ?? ''),
            'newsletter_country' => (string) ($row->country ?? ''),
            'newsletter_region' => (string) ($row->region ?? ''),
            'newsletter_city' => (string) ($row->city ?? ''),
            'newsletter_created' => (string) ($row->created ?? ''),
            'newsletter_updated' => $this->timestampToString($row->updated ?? 0),
            'newsletter_last_activity' => $this->timestampToString($row->last_activity ?? 0),
            'newsletter_bounce_type' => (string) ($row->bounce_type ?? ''),
            'newsletter_bounce_time' => $this->timestampToString($row->bounce_time ?? 0),
            'newsletter_unsub_time' => $this->timestampToString($row->unsub_time ?? 0),
        ];

        return array_filter($fields, static fn ($value): bool => $value !== '');
    }

    private function ensureSystemCustomFields(): void
    {
        $fields = [
            ['newsletter_subscriber_id', __('Newsletter subscriber ID', 'mailerpress'), 'number'],
            ['newsletter_status', __('Newsletter status', 'mailerpress'), 'text'],
            ['newsletter_gender', __('Newsletter gender', 'mailerpress'), 'text'],
            ['newsletter_language', __('Newsletter language', 'mailerpress'), 'text'],
            ['newsletter_source', __('Newsletter source', 'mailerpress'), 'text'],
            ['newsletter_wp_user_id', __('Newsletter WordPress user ID', 'mailerpress'), 'number'],
            ['newsletter_ip', __('Newsletter IP address', 'mailerpress'), 'text'],
            ['newsletter_referrer', __('Newsletter referrer', 'mailerpress'), 'text'],
            ['newsletter_http_referer', __('Newsletter HTTP referer', 'mailerpress'), 'text'],
            ['newsletter_country', __('Newsletter country', 'mailerpress'), 'text'],
            ['newsletter_region', __('Newsletter region', 'mailerpress'), 'text'],
            ['newsletter_city', __('Newsletter city', 'mailerpress'), 'text'],
            ['newsletter_created', __('Newsletter created at', 'mailerpress'), 'text'],
            ['newsletter_updated', __('Newsletter updated at', 'mailerpress'), 'text'],
            ['newsletter_last_activity', __('Newsletter last activity', 'mailerpress'), 'text'],
            ['newsletter_bounce_type', __('Newsletter bounce type', 'mailerpress'), 'text'],
            ['newsletter_bounce_time', __('Newsletter bounce time', 'mailerpress'), 'text'],
            ['newsletter_unsub_time', __('Newsletter unsubscribe time', 'mailerpress'), 'text'],
        ];

        foreach ($fields as [$key, $label, $type]) {
            $this->ensureCustomFieldDefinition($key, $label, $type);
        }
    }

    private function getNewsletterLists(): array
    {
        $options = $this->readOptionArray('newsletter_lists');
        $lists = [];

        for ($i = 1; $i <= self::LIST_MAX; $i++) {
            $name = trim((string) ($options['list_' . $i] ?? ''));
            if ($name === '') {
                continue;
            }

            $lists[$i] = [
                'id' => $i,
                'name' => $name,
                'status' => (int) ($options['list_' . $i . '_status'] ?? 0),
                'forced' => !empty($options['list_' . $i . '_forced']),
            ];
        }

        return $lists;
    }

    private function getNewsletterCustomFields(): array
    {
        $options = array_merge(
            $this->readOptionArray('newsletter_profile'),
            $this->readOptionArray('newsletter_customfields')
        );
        $fields = [];

        for ($i = 1; $i <= self::PROFILE_MAX; $i++) {
            $name = trim((string) ($options['profile_' . $i] ?? ''));
            if ($name === '') {
                continue;
            }

            $fields[$i] = [
                'id' => $i,
                'name' => $name,
                'type' => (string) ($options['profile_' . $i . '_type'] ?? 'text'),
                'options' => (string) ($options['profile_' . $i . '_options'] ?? ''),
                'placeholder' => (string) ($options['profile_' . $i . '_placeholder'] ?? ''),
                'required' => !empty($options['profile_' . $i . '_rules']),
                'status' => (int) ($options['profile_' . $i . '_status'] ?? 0),
            ];
        }

        return $fields;
    }

    private function readNewsletterSettings(): array
    {
        return [
            'main' => $this->readOptionArray('newsletter_main'),
            'lists' => $this->readOptionArray('newsletter_lists'),
            'customfields' => $this->readOptionArray('newsletter_customfields'),
            'profile' => $this->readOptionArray('newsletter_profile'),
            'subscription' => $this->readOptionArray('newsletter_subscription'),
            'unsubscription' => $this->readOptionArray('newsletter_unsubscription'),
        ];
    }

    private function normalizeProfilePageId(mixed $pageId): int
    {
        if ((string) $pageId === 'url') {
            return 0;
        }

        return absint($pageId);
    }

    private function ensureMailerPressPageFromNewsletter(int $sourcePageId, string $purpose): int
    {
        $sourcePost = get_post($sourcePageId);
        if (!$sourcePost instanceof \WP_Post || $sourcePost->post_status === 'trash') {
            return 0;
        }

        if (
            $sourcePost->post_type === 'page'
            && has_shortcode((string) $sourcePost->post_content, 'mailerpress_pages')
        ) {
            return (int) $sourcePost->ID;
        }

        $existingPageId = $this->getExistingMigratedPageId($sourcePageId, $purpose);
        if ($existingPageId > 0) {
            return $existingPageId;
        }

        $pageId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => $this->getMigratedPageTitle($sourcePost, $purpose),
            'post_content' => $this->convertNewsletterPageContent((string) $sourcePost->post_content),
            'post_author' => (int) ($sourcePost->post_author ?: get_current_user_id() ?: 1),
            'meta_input' => [
                '_mailerpress_newsletter_source_page_id' => (string) $sourcePageId,
                '_mailerpress_newsletter_page_purpose' => $purpose,
            ],
        ], true);

        return is_wp_error($pageId) ? 0 : (int) $pageId;
    }

    private function getExistingMigratedPageId(int $sourcePageId, string $purpose): int
    {
        $posts = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_mailerpress_newsletter_source_page_id',
                    'value' => (string) $sourcePageId,
                ],
                [
                    'key' => '_mailerpress_newsletter_page_purpose',
                    'value' => $purpose,
                ],
            ],
        ]);

        return empty($posts) ? 0 : (int) $posts[0];
    }

    private function getMigratedPageTitle(\WP_Post $sourcePost, string $purpose): string
    {
        $sourceTitle = trim(wp_strip_all_tags((string) $sourcePost->post_title));
        $sourceTitle = $sourceTitle !== '' ? $sourceTitle : __('Newsletter Page', 'mailerpress');

        $prefix = $purpose === 'manage'
            ? __('MailerPress Manage Subscription', 'mailerpress')
            : __('MailerPress Unsubscribe', 'mailerpress');

        return sprintf('%s - %s', $prefix, $sourceTitle);
    }

    private function convertNewsletterPageContent(string $content): string
    {
        $shortcode = '[mailerpress_pages]';
        $converted = $content;
        $replacementCount = 0;

        foreach (['newsletter', 'newsletter_profile', 'newsletter_form'] as $tag) {
            if ($replacementCount > 0) {
                break;
            }

            $converted = preg_replace_callback(
                '/\[' . preg_quote($tag, '/') . '\b[^\]]*\]/i',
                static fn (): string => $shortcode,
                $converted,
                -1,
                $replacementCount
            ) ?? $converted;
        }

        if ($replacementCount === 0 && has_shortcode($converted, 'mailerpress_pages')) {
            return $converted;
        }

        if ($replacementCount === 0) {
            $converted = trim($converted);
            $converted = $converted === '' ? $shortcode : $converted . "\n\n" . $shortcode;
        }

        return $converted;
    }

    private function ensureList(string $name): int
    {
        global $wpdb;

        $name = sanitize_text_field(trim($name));
        if ($name === '') {
            return 0;
        }

        $table = Tables::get(Tables::MAILERPRESS_LIST);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT list_id FROM {$table} WHERE name = %s LIMIT 1", $name));
        if ($existing) {
            return (int) $existing;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'name' => $name,
                'sync' => 0,
            ],
            ['%s', '%d']
        );

        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    private function ensureCustomFieldDefinition(string $fieldKey, string $label, string $type, array $options = []): int
    {
        global $wpdb;

        $fieldKey = sanitize_key($fieldKey);
        $label = sanitize_text_field(trim($label));
        if ($fieldKey === '' || $label === '') {
            return 0;
        }

        $table = Tables::get(Tables::MAILERPRESS_CUSTOM_FIELD_DEFINITIONS);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE field_key = %s LIMIT 1", $fieldKey));
        if ($existing) {
            return (int) $existing;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'field_key' => $fieldKey,
                'label' => $label,
                'type' => $type,
                'options' => empty($options) ? null : maybe_serialize($options),
                'required' => 0,
                'is_editable' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d']
        );

        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    private function mapSubscriberStatus(string $status): string
    {
        return match ($status) {
            'C' => 'subscribed',
            'S' => 'pending',
            'U', 'B', 'P' => 'unsubscribed',
            default => 'pending',
        };
    }

    private function mapCustomFieldType(string $type): string
    {
        return $type === 'select' ? 'select' : 'text';
    }

    private function extractCsvOptions(string $rawOptions): array
    {
        if (trim($rawOptions) === '') {
            return [];
        }

        $options = array_map('trim', explode(',', $rawOptions));
        $options = array_values(array_filter($options, static fn (string $value): bool => $value !== ''));

        return array_values(array_unique($options));
    }

    private function newsletterFieldKey(string $name, int $sourceId): string
    {
        $base = sanitize_key((string) preg_replace('/[^a-z0-9]+/i', '_', remove_accents(strtolower($name))));
        $base = trim($base, '_');

        if ($base === '') {
            $base = 'field_' . $sourceId;
        }

        return 'newsletter_' . $base;
    }

    private function readOptionArray(string $option): array
    {
        $value = get_option($option, []);

        return is_array($value) ? $value : [];
    }

    private function createRangeChunks(
        MigrationRepository $repository,
        int $runId,
        string $entityType,
        string $table,
        int $chunkSize
    ): array {
        global $wpdb;

        $range = $wpdb->get_row("SELECT MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS total FROM {$table}");
        if (!$range || (int) $range->total === 0) {
            return [];
        }

        $created = [];
        $min = (int) $range->min_id;
        $max = (int) $range->max_id;
        $chunkSize = max(1, $chunkSize);

        for ($start = $min; $start <= $max; $start += $chunkSize) {
            $end = min($max, $start + $chunkSize - 1);
            $chunkId = $repository->insertChunk($runId, self::SOURCE_KEY, $entityType, [
                'min_id' => $start,
                'max_id' => $end,
            ]);

            if ($chunkId > 0) {
                $created[] = $chunkId;
            }
        }

        return $created;
    }

    private function countChunkRows(string $entityType, array $payload): int
    {
        if ($entityType === 'lists') {
            return count($this->getNewsletterLists());
        }

        if ($entityType === 'custom_fields') {
            return count($this->getNewsletterCustomFields());
        }

        if ($entityType !== 'contacts') {
            return 0;
        }

        global $wpdb;

        $table = $this->sourceTable('newsletter');
        if (!$this->tableExists($table)) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE id BETWEEN %d AND %d",
                (int) ($payload['min_id'] ?? 0),
                (int) ($payload['max_id'] ?? 0)
            )
        );
    }

    private function estimateEntity(string $key): int
    {
        return match ($key) {
            'lists' => count($this->getNewsletterLists()),
            'custom_fields' => count($this->getNewsletterCustomFields()),
            'contacts' => $this->countTableRows('newsletter'),
            'campaigns' => $this->countTableRows('newsletter_emails'),
            'forms' => $this->countNewsletterForms(),
            'statistics' => $this->countTableRows('newsletter_stats') + $this->countTableRows('newsletter_sent'),
            default => 0,
        };
    }

    private function countSettings(): int
    {
        $settings = $this->readNewsletterSettings();

        foreach ($settings as $value) {
            if (!empty($value)) {
                return 1;
            }
        }

        return 0;
    }

    private function hasNewsletterConfiguration(): bool
    {
        return count($this->getNewsletterLists()) > 0
            || count($this->getNewsletterCustomFields()) > 0;
    }

    private function countNewsletterForms(): int
    {
        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name = %s OR option_name LIKE %s",
                'newsletter_form',
                $wpdb->esc_like('newsletter_form_') . '%'
            )
        );

        return $count;
    }

    private function countTableRows(string $suffix): int
    {
        global $wpdb;

        $table = $this->sourceTable($suffix);
        if (!$this->tableExists($table)) {
            return 0;
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    private function getRunOptions(object $run): array
    {
        $settings = json_decode((string) ($run->settings ?? '{}'), true);
        if (!is_array($settings)) {
            return [];
        }

        return is_array($settings['options'] ?? null) ? $settings['options'] : [];
    }

    private function normalizeEntitySelection(array $entityTypes): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($type): string => sanitize_key((string) $type), $entityTypes))));
    }

    private function timestampToString(mixed $timestamp): string
    {
        $timestamp = absint($timestamp);
        if ($timestamp <= 0) {
            return '';
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function selectColumns(string $table, array $columns): string
    {
        $select = [];
        foreach ($columns as $column) {
            if ($this->hasColumn($table, $column)) {
                $select[] = "`{$column}`";
            } else {
                $select[] = "NULL AS `{$column}`";
            }
        }

        return implode(', ', $select);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->getColumns($table), true);
    }

    private function getColumns(string $table): array
    {
        global $wpdb;

        if (array_key_exists($table, $this->columnsCache)) {
            return $this->columnsCache[$table];
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $this->columnsCache[$table] = is_array($columns) ? array_map('strval', $columns) : [];

        return $this->columnsCache[$table];
    }

    private function sourceTable(string $suffix): string
    {
        global $wpdb;

        return $wpdb->prefix . $suffix;
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private function isNewsletterActive(): bool
    {
        $plugin = 'newsletter/plugin.php';
        $activePlugins = (array) get_option('active_plugins', []);
        $networkPlugins = (array) get_site_option('active_sitewide_plugins', []);

        return in_array($plugin, $activePlugins, true) || array_key_exists($plugin, $networkPlugins);
    }

    private function getNewsletterVersion(): ?string
    {
        if (defined('NEWSLETTER_VERSION')) {
            return (string) NEWSLETTER_VERSION;
        }

        $pluginFile = WP_PLUGIN_DIR . '/newsletter/plugin.php';
        if (!is_readable($pluginFile)) {
            return null;
        }

        $contents = file_get_contents($pluginFile);
        if (!is_string($contents) || !preg_match('/Version:\s*([^\r\n]+)/i', $contents, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }
}
