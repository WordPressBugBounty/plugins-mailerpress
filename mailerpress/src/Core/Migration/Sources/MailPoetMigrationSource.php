<?php

declare(strict_types=1);

namespace MailerPress\Core\Migration\Sources;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migration\MigrationRepository;
use MailerPress\Core\Migration\CustomFieldsImport;
use MailerPress\Core\Migration\MigrationSourceInterface;
use MailerPress\Services\ContactUpsertService;

class MailPoetMigrationSource implements MigrationSourceInterface
{
    private const SOURCE_KEY = 'mailpoet';
    private const CHUNK_SIZE = 1000;
    private const STRUCTURE_CHUNK_SIZE = 1000;

    private ?string $sourcePrefix = null;

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
        return 'MailPoet';
    }

    public function getDescription(): string
    {
        return __('Import contacts, lists, tags and custom fields from MailPoet.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return 'mailpoet';
    }

    public function getIconSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 152.02 156.4" fill="#FF5301" role="img" aria-hidden="true" focusable="false"><path d="M37.71,89.1c3.5,0,5.9-.8,7.2-2.3a8,8,0,0,0,2-5.4V35.7l17,45.1a12.68,12.68,0,0,0,3.7,5.4c1.6,1.3,4,2,7.2,2a12.54,12.54,0,0,0,5.9-1.4,8.41,8.41,0,0,0,3.9-5l18.1-50V81a8.53,8.53,0,0,0,2.1,6.1c1.4,1.4,3.7,2.2,6.9,2.2,3.5,0,5.9-.8,7.2-2.3a8,8,0,0,0,2-5.4V8.7a7.48,7.48,0,0,0-3.3-6.6c-2.1-1.4-5-2.1-8.6-2.1a19.3,19.3,0,0,0-9.4,2,11.63,11.63,0,0,0-5.1,6.8L74.91,67.1,54.41,8.4a12.4,12.4,0,0,0-4.5-6.2c-2.1-1.5-5-2.2-8.8-2.2a16.51,16.51,0,0,0-8.9,2.1c-2.3,1.5-3.5,3.9-3.5,7.2V80.8c0,2.8.7,4.8,2,6.2C32.21,88.4,34.41,89.1,37.71,89.1Z"/><path d="M149,116.6l-2.4-1.9a7.4,7.4,0,0,0-9.4.3,19.65,19.65,0,0,1-12.5,4.6h-21.4A37.08,37.08,0,0,0,77,130.5l-1.1,1.2-1.1-1.1a37.25,37.25,0,0,0-26.3-10.9H27a19.59,19.59,0,0,1-12.4-4.6,7.28,7.28,0,0,0-9.4-.3l-2.4,1.9A7.43,7.43,0,0,0,0,122.2a7.14,7.14,0,0,0,2.4,5.7A37.28,37.28,0,0,0,27,137.4h21.6a19.59,19.59,0,0,1,18.9,14.4v.2c.1.7,1.2,4.4,8.5,4.4s8.4-3.7,8.5-4.4v-.2a19.59,19.59,0,0,1,18.9-14.4H125a37.28,37.28,0,0,0,24.6-9.5,7.42,7.42,0,0,0,2.4-5.7A7.86,7.86,0,0,0,149,116.6Z"/></svg>';
    }

    public function isDetected(): bool
    {
        $table = $this->sourceTable('subscribers');

        return $table !== null && $this->tableExists($table);
    }

    public function getDetectionDetails(): array
    {
        $prefix = $this->getSourcePrefix();

        return [
            'active_plugin' => $this->isMailPoetActive(),
            'db_prefix' => $prefix,
            'subscribers_table' => $prefix ? $prefix . 'subscribers' : null,
            'version' => $this->getMailPoetVersion(),
        ];
    }

    public function getEntityTypes(): array
    {
        return [
            [
                'key' => 'lists',
                'label' => __('Lists', 'mailerpress'),
                'description' => __('MailPoet lists mapped to MailerPress lists.', 'mailerpress'),
                'supported' => true,
                'default' => true,
            ],
            [
                'key' => 'tags',
                'label' => __('Tags', 'mailerpress'),
                'description' => __('MailPoet tags mapped to MailerPress tags.', 'mailerpress'),
                'supported' => true,
                'default' => true,
            ],
            [
                'key' => 'custom_fields',
                'label' => __('Custom fields', 'mailerpress'),
                'description' => CustomFieldsImport::enabled()
                    ? __('Custom field definitions and subscriber values.', 'mailerpress')
                    : __('Custom fields are imported with MailerPress Pro.', 'mailerpress'),
                'supported' => CustomFieldsImport::enabled(),
                'default' => true,
            ],
            [
                'key' => 'contacts',
                'label' => __('Contacts', 'mailerpress'),
                'description' => __('Subscribers with list, tag and custom field assignments.', 'mailerpress'),
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
                'key' => 'templates',
                'label' => __('Templates', 'mailerpress'),
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
        $ordered = ['lists', 'tags', 'custom_fields', 'contacts'];
        $entityTypes = array_values(array_intersect($ordered, $entityTypes));
        if (!CustomFieldsImport::enabled()) {
            $entityTypes = array_values(array_diff($entityTypes, ['custom_fields']));
        }

        foreach ($ordered as $entityType) {
            if (!in_array($entityType, $entityTypes, true)) {
                continue;
            }

            if ($entityType === 'lists') {
                $table = $this->sourceTable('segments');
                if (!$table || !$this->tableExists($table)) {
                    continue;
                }

                $created = array_merge(
                    $created,
                    $this->createMailPoetSegmentChunks(
                        $repository,
                        $runId,
                        $entityType
                    )
                );
                continue;
            }

            $table = match ($entityType) {
                'tags' => $this->sourceTable('tags'),
                'custom_fields' => $this->sourceTable('custom_fields'),
                'contacts' => $this->sourceTable('subscribers'),
                default => null,
            };

            if (!$table || !$this->tableExists($table)) {
                continue;
            }

            $created = array_merge(
                $created,
                $this->createRangeChunks(
                    $repository,
                    $runId,
                    $entityType,
                    $table,
                    $entityType === 'contacts' ? self::CHUNK_SIZE : self::STRUCTURE_CHUNK_SIZE,
                    $entityType !== 'tags'
                )
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
            'lists' => $this->processLists($payload, $repository, (int) $run->id),
            'tags' => $this->processTags($payload, $repository, (int) $run->id),
            'custom_fields' => CustomFieldsImport::enabled()
                ? $this->processCustomFields($payload, $repository, (int) $run->id)
                : CustomFieldsImport::skippedChunk(),
            'contacts' => $this->processContacts($payload, $repository, $run),
            default => [
                'processed' => 0,
                'skipped' => 1,
                'errors' => 0,
                'skipped_chunk' => true,
                'message' => __('This entity type is not supported by the MailPoet migration source.', 'mailerpress'),
            ],
        };
    }

    private function processSettings(MigrationRepository $repository, int $runId): array
    {
        $settings = $this->readMailPoetSettings();
        $sender = $this->extractMailPoetSenderSettings($settings, 'sender', 'sender_name', 'sender_address');
        $replyTo = $this->extractMailPoetSenderSettings($settings, 'reply_to', 'reply_to_name', 'reply_to_address');

        $defaultSettings = get_option('mailerpress_default_settings', []);
        if (is_string($defaultSettings)) {
            $defaultSettings = json_decode($defaultSettings, true) ?: [];
        }
        if (!is_array($defaultSettings)) {
            $defaultSettings = [];
        }

        $fromAddress = sanitize_email((string) ($sender['address'] ?? ''));
        $replyToAddress = sanitize_email((string) ($replyTo['address'] ?? ''));

        if ($fromAddress) {
            $defaultSettings['fromAddress'] = $fromAddress;
        }

        if (!empty($sender['name'])) {
            $defaultSettings['fromName'] = sanitize_text_field((string) $sender['name']);
        }

        if ($replyToAddress) {
            $defaultSettings['replyToAddress'] = $replyToAddress;
        }

        if (!empty($replyTo['name'])) {
            $defaultSettings['replyToName'] = sanitize_text_field((string) $replyTo['name']);
        }

        $subscriptionResult = $this->applySubscriptionSettings($settings, $defaultSettings, $repository, $runId);
        $defaultSettings = $subscriptionResult['settings'];

        update_option('mailerpress_default_settings', $defaultSettings);
        update_option('mailerpress_global_email_senders', [
            'fromAddress' => $defaultSettings['fromAddress'] ?? '',
            'fromName' => $defaultSettings['fromName'] ?? '',
            'replyToAddress' => $defaultSettings['replyToAddress'] ?? '',
            'replyToName' => $defaultSettings['replyToName'] ?? '',
        ]);
        update_option('mailerpress_mailpoet_migration_settings_snapshot', $settings, false);

        $repository->saveMapping(self::SOURCE_KEY, 'settings', 'core', 'option', 'mailerpress_default_settings', $runId, [
            'imported_keys' => array_keys($settings),
            'imported_pages' => $subscriptionResult['pages'],
            'preserved_subscription_settings' => $subscriptionResult['preserved_subscription_settings'],
        ]);

        return [
            'processed' => 1,
            'skipped' => 0,
            'errors' => 0,
        ];
    }

    private function extractMailPoetSenderSettings(
        array $settings,
        string $groupKey,
        string $fallbackNameKey,
        string $fallbackAddressKey
    ): array {
        $group = is_array($settings[$groupKey] ?? null) ? $settings[$groupKey] : [];

        return [
            'name' => $group['name']
                ?? $settings[$groupKey . '.name']
                ?? $settings[$fallbackNameKey]
                ?? '',
            'address' => $group['address']
                ?? $settings[$groupKey . '.address']
                ?? $settings[$fallbackAddressKey]
                ?? '',
        ];
    }

    private function applySubscriptionSettings(
        array $settings,
        array $defaultSettings,
        MigrationRepository $repository,
        int $runId
    ): array {
        $subscription = is_array($settings['subscription'] ?? null) ? $settings['subscription'] : [];
        $pages = is_array($subscription['pages'] ?? null) ? $subscription['pages'] : [];
        $importedPages = [];

        $this->trashGeneratedDefaultSubscriptionPages();

        $manageSourcePageId = $this->resolveMailPoetSubscriptionPageId($pages, 'manage');
        if ($manageSourcePageId > 0) {
            if ($this->isMailPoetDefaultPageId($manageSourcePageId)) {
                $defaultSettings['subpage'] = $this->defaultMailerPressPageSetting();
                $importedPages['manage'] = [
                    'source_page_id' => $manageSourcePageId,
                    'target_page_id' => 'default',
                ];
            } else {
                $manageTargetPageId = $this->ensureMailerPressPageFromMailPoet($manageSourcePageId, 'manage');
                if ($manageTargetPageId > 0) {
                    $defaultSettings['subpage'] = [
                        'useDefault' => false,
                        'pageId' => (string) $manageTargetPageId,
                    ];
                    $importedPages['manage'] = [
                        'source_page_id' => $manageSourcePageId,
                        'target_page_id' => $manageTargetPageId,
                    ];
                    $repository->saveMapping(self::SOURCE_KEY, 'subscription_page', 'manage:' . $manageSourcePageId, 'page', $manageTargetPageId, $runId, [
                        'purpose' => 'manage',
                    ]);
                }
            }
        }

        $unsubscribeSuccessSourcePageId = $this->resolveMailPoetSubscriptionPageId($pages, 'unsubscribe');
        $unsubscribeConfirmationSourcePageId = $this->resolveMailPoetSubscriptionPageId($pages, 'confirm_unsubscribe');

        $unsubscribeSourcePageId = $unsubscribeConfirmationSourcePageId ?: $unsubscribeSuccessSourcePageId;
        if ($unsubscribeSourcePageId > 0) {
            $isDefaultUnsubscribeSource = $this->isMailPoetDefaultPageId($unsubscribeSourcePageId);

            if ($isDefaultUnsubscribeSource) {
                $defaultSettings['unsubpage'] = $this->defaultMailerPressPageSetting();
                $importedPages['unsubscribe_confirmation'] = [
                    'source_page_id' => $unsubscribeSourcePageId,
                    'target_page_id' => 'default',
                ];
            } else {
                $unsubscribeTargetPageId = $this->ensureMailerPressPageFromMailPoet(
                    $unsubscribeSourcePageId,
                    'unsubscribe_confirmation'
                );

                if ($unsubscribeTargetPageId > 0) {
                    $defaultSettings['unsubpage'] = [
                        'useDefault' => false,
                        'pageId' => (string) $unsubscribeTargetPageId,
                    ];
                    $importedPages['unsubscribe_confirmation'] = [
                        'source_page_id' => $unsubscribeSourcePageId,
                        'target_page_id' => $unsubscribeTargetPageId,
                    ];
                    $repository->saveMapping(self::SOURCE_KEY, 'subscription_page', 'unsubscribe_confirmation:' . $unsubscribeSourcePageId, 'page', $unsubscribeTargetPageId, $runId, [
                        'purpose' => 'unsubscribe_confirmation',
                    ]);
                }
            }
        }

        return [
            'settings' => $defaultSettings,
            'pages' => $importedPages,
            'preserved_subscription_settings' => [
                'manage_subscription_page_style' => isset($subscription['manage_subscription_page_style'])
                    ? sanitize_key((string) $subscription['manage_subscription_page_style'])
                    : null,
                'unsubscribe_survey' => is_array($subscription['unsubscribe_survey'] ?? null)
                    ? [
                        'enabled' => $this->normalizeMailPoetBoolean($subscription['unsubscribe_survey']['enabled'] ?? false),
                        'allow_other_text' => $this->normalizeMailPoetBoolean($subscription['unsubscribe_survey']['allow_other_text'] ?? false),
                    ]
                    : null,
            ],
        ];
    }

    private function defaultMailerPressPageSetting(): array
    {
        return [
            'useDefault' => true,
            'pageId' => '',
        ];
    }

    private function isMailPoetDefaultPageId(int $pageId): bool
    {
        $mailPoetPagesClass = '\MailPoet\Settings\Pages';
        if (
            class_exists($mailPoetPagesClass)
            && is_callable([$mailPoetPagesClass, 'isMailpoetPage'])
            && (bool) $mailPoetPagesClass::isMailpoetPage($pageId)
        ) {
            return true;
        }

        $post = get_post($pageId);

        return $post instanceof \WP_Post && $this->isMailPoetDefaultPagePost($post);
    }

    private function isMailPoetDefaultPagePost(\WP_Post $post): bool
    {
        if (!has_shortcode((string) $post->post_content, 'mailpoet_page')) {
            return false;
        }

        if ($post->post_type === 'mailpoet_page') {
            return (string) $post->post_name === 'subscriptions';
        }

        $title = trim(wp_strip_all_tags(wp_specialchars_decode((string) $post->post_title, ENT_QUOTES)));

        return $post->post_type === 'page'
            && $title === 'MailPoet Page'
            && in_array((string) $post->post_name, ['subscriptions', 'mailpoet-page'], true);
    }

    private function resolveMailPoetSubscriptionPageId(array $pages, string $key): int
    {
        $pageId = absint($pages[$key] ?? 0);
        if ($pageId > 0 && get_post($pageId) instanceof \WP_Post) {
            return $pageId;
        }

        return $this->getMailPoetDefaultSubscriptionPageId();
    }

    private function getMailPoetDefaultSubscriptionPageId(): int
    {
        $mailPoetPagesClass = '\MailPoet\Settings\Pages';
        if (
            class_exists($mailPoetPagesClass)
            && is_callable([$mailPoetPagesClass, 'getMailPoetPage'])
        ) {
            $page = $mailPoetPagesClass::getMailPoetPage('subscriptions');
            if ($page instanceof \WP_Post) {
                return (int) $page->ID;
            }
        }

        $posts = get_posts([
            'post_type' => ['mailpoet_page', 'page'],
            'post_status' => ['publish', 'draft', 'private'],
            'post_name__in' => ['subscriptions', 'mailpoet-page'],
            'posts_per_page' => 5,
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        foreach ($posts as $post) {
            if ($post instanceof \WP_Post && $this->isMailPoetDefaultPagePost($post)) {
                return (int) $post->ID;
            }
        }

        return 0;
    }

    private function ensureMailerPressPageFromMailPoet(
        int $sourcePageId,
        string $purpose,
        array $shortcodeAttributes = []
    ): int {
        $sourcePost = get_post($sourcePageId);
        if (!$sourcePost instanceof \WP_Post || $sourcePost->post_status === 'trash') {
            return 0;
        }

        if ($sourcePost->post_type === 'page') {
            return $this->prepareExistingWordPressPageFromMailPoet($sourcePost, $purpose, $shortcodeAttributes);
        }

        $existingPageId = $this->getExistingMigratedPageId($sourcePageId, $purpose);
        if ($existingPageId > 0) {
            return $existingPageId;
        }

        $title = $this->getMigratedPageTitle($sourcePost, $purpose);
        $content = $this->convertMailPoetPageContent((string) $sourcePost->post_content, $shortcodeAttributes);
        $pageId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $content,
            'post_author' => (int) ($sourcePost->post_author ?: get_current_user_id() ?: 1),
            'meta_input' => [
                '_mailerpress_mailpoet_source_page_id' => (string) $sourcePageId,
                '_mailerpress_mailpoet_page_purpose' => $purpose,
            ],
        ], true);

        return is_wp_error($pageId) ? 0 : (int) $pageId;
    }

    private function prepareExistingWordPressPageFromMailPoet(
        \WP_Post $sourcePost,
        string $purpose,
        array $shortcodeAttributes = []
    ): int {
        $convertedContent = $this->convertMailPoetPageContent((string) $sourcePost->post_content, $shortcodeAttributes);

        if ($convertedContent !== (string) $sourcePost->post_content) {
            $updated = wp_update_post([
                'ID' => (int) $sourcePost->ID,
                'post_content' => $convertedContent,
            ], true);

            if (is_wp_error($updated)) {
                return 0;
            }
        }

        update_post_meta((int) $sourcePost->ID, '_mailerpress_mailpoet_source_page_id', (string) $sourcePost->ID);
        update_post_meta((int) $sourcePost->ID, '_mailerpress_mailpoet_page_purpose', $purpose);

        return (int) $sourcePost->ID;
    }

    private function trashGeneratedDefaultSubscriptionPages(): void
    {
        $posts = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'suppress_filters' => true,
            'meta_query' => [
                [
                    'key' => '_mailerpress_mailpoet_page_purpose',
                    'value' => ['manage', 'unsubscribe_confirmation', 'unsubscribe_success'],
                    'compare' => 'IN',
                ],
            ],
        ]);

        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $sourcePageId = absint(get_post_meta((int) $post->ID, '_mailerpress_mailpoet_source_page_id', true));
            $purpose = (string) get_post_meta((int) $post->ID, '_mailerpress_mailpoet_page_purpose', true);
            $wasGeneratedPage = $this->isGeneratedMailerPressSubscriptionPage($post, $purpose)
                && $sourcePageId > 0
                && $sourcePageId !== (int) $post->ID
                && ($purpose === 'unsubscribe_success' || $this->isMailPoetDefaultPageId($sourcePageId));

            if ($wasGeneratedPage) {
                wp_trash_post((int) $post->ID);
            }
        }
    }

    private function isGeneratedMailerPressSubscriptionPage(\WP_Post $post, string $purpose): bool
    {
        $titlePrefix = match ($purpose) {
            'manage' => __('MailerPress Manage Subscription', 'mailerpress'),
            'unsubscribe_success' => __('MailerPress Unsubscribe Success', 'mailerpress'),
            default => __('MailerPress Unsubscribe', 'mailerpress'),
        };

        return strncmp((string) $post->post_title, $titlePrefix, strlen($titlePrefix)) === 0;
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
                    'key' => '_mailerpress_mailpoet_source_page_id',
                    'value' => (string) $sourcePageId,
                ],
                [
                    'key' => '_mailerpress_mailpoet_page_purpose',
                    'value' => $purpose,
                ],
            ],
        ]);

        return empty($posts) ? 0 : (int) $posts[0];
    }

    private function getMigratedPageTitle(\WP_Post $sourcePost, string $purpose): string
    {
        $sourceTitle = trim(wp_strip_all_tags((string) $sourcePost->post_title));
        $sourceTitle = $sourceTitle !== '' ? $sourceTitle : __('MailPoet Page', 'mailerpress');

        $prefix = match ($purpose) {
            'manage' => __('MailerPress Manage Subscription', 'mailerpress'),
            default => __('MailerPress Unsubscribe', 'mailerpress'),
        };

        return sprintf('%s - %s', $prefix, $sourceTitle);
    }

    private function convertMailPoetPageContent(string $content, array $shortcodeAttributes = []): string
    {
        $shortcode = $this->buildMailerPressPagesShortcode($shortcodeAttributes);
        $converted = $content;
        $replacementCount = 0;

        $converted = preg_replace_callback('/\[mailpoet_page\b[^\]]*\]/i', static fn (): string => $shortcode, $converted, -1, $replacementCount) ?? $converted;
        if ($replacementCount === 0) {
            $converted = preg_replace_callback('/\[mailpoet_manage_subscription\b[^\]]*\]/i', static fn (): string => $shortcode, $converted, -1, $replacementCount) ?? $converted;
        }

        if ($replacementCount === 0 && has_shortcode($converted, 'mailerpress_pages')) {
            $pattern = get_shortcode_regex(['mailerpress_pages']);
            $converted = preg_replace_callback("/$pattern/s", static fn (): string => $shortcode, $converted, 1) ?? $converted;
            $replacementCount = 1;
        }

        if ($replacementCount === 0) {
            $converted = trim($converted);
            $converted = $converted === '' ? $shortcode : $converted . "\n\n" . $shortcode;
        }

        return $converted;
    }

    private function buildMailerPressPagesShortcode(array $attributes = []): string
    {
        $parts = ['mailerpress_pages'];
        foreach ($attributes as $key => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $parts[] = sprintf('%s="%s"', sanitize_key((string) $key), str_replace('"', '&quot;', $value));
        }

        return '[' . implode(' ', $parts) . ']';
    }

    private function normalizeMailPoetBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower((string) $value), ['1', 'yes', 'true', 'on'], true);
    }

    private function processLists(array $payload, MigrationRepository $repository, int $runId): array
    {
        $rows = $this->getMailPoetSegmentRowsForRange($payload);
        $processed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $targetId = $this->ensureList((string) ($row->name ?? ''));
            if ($targetId <= 0) {
                $skipped++;
                continue;
            }

            $repository->saveMapping(self::SOURCE_KEY, 'segment', (int) $row->id, 'list', $targetId, $runId, [
                'type' => (string) ($row->type ?? ''),
                'description' => (string) ($row->description ?? ''),
            ]);
            $processed++;
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => 0,
        ];
    }

    private function processTags(array $payload, MigrationRepository $repository, int $runId): array
    {
        $rows = $this->getRowsForRange('tags', $payload, false);
        $processed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $targetId = $this->ensureTag((string) ($row->name ?? ''));
            if ($targetId <= 0) {
                $skipped++;
                continue;
            }

            $repository->saveMapping(self::SOURCE_KEY, 'tag', (int) $row->id, 'tag', $targetId, $runId, [
                'description' => (string) ($row->description ?? ''),
            ]);
            $processed++;
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => 0,
        ];
    }

    private function processCustomFields(array $payload, MigrationRepository $repository, int $runId): array
    {
        $this->ensureSystemCustomFields();

        $rows = $this->getRowsForRange('custom_fields', $payload, true);
        $processed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $fieldKey = $this->mailPoetFieldKey((string) ($row->name ?? ''), (int) $row->id);
            $targetId = $this->ensureCustomFieldDefinition(
                $fieldKey,
                (string) ($row->name ?? ''),
                $this->mapCustomFieldType((string) ($row->type ?? 'text')),
                $this->extractCustomFieldOptions($this->decodeValue($row->params ?? ''))
            );

            if ($targetId <= 0) {
                $skipped++;
                continue;
            }

            $repository->saveMapping(self::SOURCE_KEY, 'custom_field', (int) $row->id, 'custom_field', $fieldKey, $runId, [
                'target_definition_id' => $targetId,
                'source_type' => (string) ($row->type ?? ''),
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
        $skipped = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $customFields = array_merge(
                $this->subscriberMetadataFields($row),
                $this->getSubscriberCustomFields((int) $row->id, $repository, (int) $run->id)
            );

            $result = $this->contactUpsertService->upsert([
                'contactEmail' => (string) ($row->email ?? ''),
                'contactFirstName' => (string) ($row->first_name ?? ''),
                'contactLastName' => (string) ($row->last_name ?? ''),
                'contactStatus' => $this->mapSubscriberStatus((string) ($row->status ?? '')),
                'last_engagement_at' => (string) ($row->last_engagement_at ?? ''),
                'last_open_at' => (string) ($row->last_open_at ?? ''),
                'last_click_at' => (string) ($row->last_click_at ?? ''),
                'last_sending_at' => (string) ($row->last_sending_at ?? ''),
                'last_subscribed_at' => (string) ($row->last_subscribed_at ?? ''),
                'email_count' => $row->email_count ?? null,
                'inactivation_reason' => 'mailpoet_migration',
                'opt_in_source' => 'mailpoet_migration',
                'opt_in_details' => [
                    'source' => 'MailPoet',
                    'subscriber_id' => (int) $row->id,
                    'original_status' => (string) ($row->status ?? ''),
                    'subscribed_ip' => (string) ($row->subscribed_ip ?? ''),
                    'confirmed_ip' => (string) ($row->confirmed_ip ?? ''),
                ],
                'lists' => $this->getSubscriberListIds((int) $row->id, $repository, (int) $run->id),
                'tags' => $this->getSubscriberTagIds((int) $row->id, $repository, (int) $run->id),
                // Custom field values are only imported with MailerPress Pro (see CustomFieldsImport).
                'custom_fields' => CustomFieldsImport::enabled() ? $customFields : [],
                'assign_default_list' => false,
                'update_existing' => $updateExisting,
                // Migrated contacts already opted in elsewhere: no confirmation email, automation or webhook.
                'suppress_contact_hooks' => true,
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
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function getSubscriberRows(array $payload): array
    {
        global $wpdb;

        $table = $this->sourceTable('subscribers');
        if (!$table || !$this->tableExists($table)) {
            return [];
        }

        $columns = [
            'id',
            'email',
            'first_name',
            'last_name',
            'status',
            'source',
            'wp_user_id',
            'is_woocommerce_user',
            'subscribed_ip',
            'confirmed_ip',
            'confirmed_at',
            'last_subscribed_at',
            'created_at',
            'updated_at',
            'unsubscribe_token',
            'engagement_score',
            'email_count',
            'last_engagement_at',
            'last_sending_at',
            'last_open_at',
            'last_click_at',
            'last_purchase_at',
            'time_zone',
            'time_zone_source',
        ];
        $select = $this->selectColumns($table, $columns);
        $deletedWhere = $this->hasColumn($table, 'deleted_at') ? ' AND `deleted_at` IS NULL' : '';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$select}
                 FROM {$table}
                 WHERE id BETWEEN %d AND %d{$deletedWhere}
                 ORDER BY id ASC",
                (int) ($payload['min_id'] ?? 0),
                (int) ($payload['max_id'] ?? 0)
            )
        ) ?: [];
    }

    private function getSubscriberListIds(int $subscriberId, MigrationRepository $repository, int $runId): array
    {
        global $wpdb;

        $relationTable = $this->sourceTable('subscriber_segment');
        $segmentTable = $this->sourceTable('segments');
        if (!$relationTable || !$segmentTable || !$this->tableExists($relationTable) || !$this->tableExists($segmentTable)) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, s.name, s.type, s.description
                 FROM {$relationTable} ss
                 INNER JOIN {$segmentTable} s ON s.id = ss.segment_id
                 WHERE ss.subscriber_id = %d AND ss.status = 'subscribed' AND s.deleted_at IS NULL",
                $subscriberId
            )
        ) ?: [];

        $ids = [];
        foreach ($rows as $row) {
            if ((string) ($row->type ?? '') === 'dynamic') {
                continue;
            }

            $mapping = $repository->getMapping(self::SOURCE_KEY, 'segment', (int) $row->id);
            $targetId = $mapping ? (int) $mapping->target_id : 0;

            if ($targetId <= 0) {
                $targetId = $this->ensureList((string) ($row->name ?? ''));
                if ($targetId > 0) {
                    $repository->saveMapping(self::SOURCE_KEY, 'segment', (int) $row->id, 'list', $targetId, $runId, [
                        'type' => (string) ($row->type ?? ''),
                        'description' => (string) ($row->description ?? ''),
                    ]);
                }
            }

            if ($targetId > 0) {
                $ids[] = $targetId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function getSubscriberTagIds(int $subscriberId, MigrationRepository $repository, int $runId): array
    {
        global $wpdb;

        $relationTable = $this->sourceTable('subscriber_tag');
        $tagTable = $this->sourceTable('tags');
        if (!$relationTable || !$tagTable || !$this->tableExists($relationTable) || !$this->tableExists($tagTable)) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.name, t.description
                 FROM {$relationTable} st
                 INNER JOIN {$tagTable} t ON t.id = st.tag_id
                 WHERE st.subscriber_id = %d",
                $subscriberId
            )
        ) ?: [];

        $ids = [];
        foreach ($rows as $row) {
            $mapping = $repository->getMapping(self::SOURCE_KEY, 'tag', (int) $row->id);
            $targetId = $mapping ? (int) $mapping->target_id : 0;

            if ($targetId <= 0) {
                $targetId = $this->ensureTag((string) ($row->name ?? ''));
                if ($targetId > 0) {
                    $repository->saveMapping(self::SOURCE_KEY, 'tag', (int) $row->id, 'tag', $targetId, $runId, [
                        'description' => (string) ($row->description ?? ''),
                    ]);
                }
            }

            if ($targetId > 0) {
                $ids[] = $targetId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function getSubscriberCustomFields(int $subscriberId, MigrationRepository $repository, int $runId): array
    {
        global $wpdb;

        $relationTable = $this->sourceTable('subscriber_custom_field');
        $fieldTable = $this->sourceTable('custom_fields');
        if (!$relationTable || !$fieldTable || !$this->tableExists($relationTable) || !$this->tableExists($fieldTable)) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cf.id, cf.name, cf.type, cf.params, scf.value
                 FROM {$relationTable} scf
                 INNER JOIN {$fieldTable} cf ON cf.id = scf.custom_field_id
                 WHERE scf.subscriber_id = %d AND cf.deleted_at IS NULL",
                $subscriberId
            )
        ) ?: [];

        $fields = [];
        foreach ($rows as $row) {
            $mapping = $repository->getMapping(self::SOURCE_KEY, 'custom_field', (int) $row->id);
            $fieldKey = $mapping ? (string) $mapping->target_id : $this->mailPoetFieldKey((string) ($row->name ?? ''), (int) $row->id);

            if (!$mapping) {
                $targetId = $this->ensureCustomFieldDefinition(
                    $fieldKey,
                    (string) ($row->name ?? ''),
                    $this->mapCustomFieldType((string) ($row->type ?? 'text')),
                    $this->extractCustomFieldOptions($this->decodeValue($row->params ?? ''))
                );

                if ($targetId > 0) {
                    $repository->saveMapping(self::SOURCE_KEY, 'custom_field', (int) $row->id, 'custom_field', $fieldKey, $runId, [
                        'target_definition_id' => $targetId,
                        'source_type' => (string) ($row->type ?? ''),
                    ]);
                }
            }

            $fields[$fieldKey] = (string) ($row->value ?? '');
        }

        return $fields;
    }

    private function subscriberMetadataFields(object $row): array
    {
        $fields = [
            'mailpoet_subscriber_id' => (string) ($row->id ?? ''),
            'mailpoet_status' => (string) ($row->status ?? ''),
            'mailpoet_source' => (string) ($row->source ?? ''),
            'mailpoet_wp_user_id' => (string) ($row->wp_user_id ?? ''),
            'mailpoet_is_woocommerce_user' => !empty($row->is_woocommerce_user) ? '1' : '0',
            'mailpoet_confirmed_at' => (string) ($row->confirmed_at ?? ''),
            'mailpoet_last_subscribed_at' => (string) ($row->last_subscribed_at ?? ''),
            'mailpoet_engagement_score' => (string) ($row->engagement_score ?? ''),
            'mailpoet_last_engagement_at' => (string) ($row->last_engagement_at ?? ''),
            'mailpoet_last_sending_at' => (string) ($row->last_sending_at ?? ''),
            'mailpoet_last_open_at' => (string) ($row->last_open_at ?? ''),
            'mailpoet_last_click_at' => (string) ($row->last_click_at ?? ''),
            'mailpoet_last_purchase_at' => (string) ($row->last_purchase_at ?? ''),
            'mailpoet_time_zone' => (string) ($row->time_zone ?? ''),
        ];

        return array_filter($fields, static fn ($value): bool => $value !== '');
    }

    private function ensureSystemCustomFields(): void
    {
        $fields = [
            ['mailpoet_subscriber_id', __('MailPoet subscriber ID', 'mailerpress'), 'number'],
            ['mailpoet_status', __('MailPoet status', 'mailerpress'), 'text'],
            ['mailpoet_source', __('MailPoet source', 'mailerpress'), 'text'],
            ['mailpoet_wp_user_id', __('MailPoet WordPress user ID', 'mailerpress'), 'number'],
            ['mailpoet_is_woocommerce_user', __('MailPoet WooCommerce user', 'mailerpress'), 'checkbox'],
            ['mailpoet_confirmed_at', __('MailPoet confirmed at', 'mailerpress'), 'date'],
            ['mailpoet_last_subscribed_at', __('MailPoet last subscribed at', 'mailerpress'), 'date'],
            ['mailpoet_engagement_score', __('MailPoet engagement score', 'mailerpress'), 'number'],
            ['mailpoet_last_engagement_at', __('MailPoet last engagement at', 'mailerpress'), 'date'],
            ['mailpoet_last_sending_at', __('MailPoet last sending at', 'mailerpress'), 'date'],
            ['mailpoet_last_open_at', __('MailPoet last open at', 'mailerpress'), 'date'],
            ['mailpoet_last_click_at', __('MailPoet last click at', 'mailerpress'), 'date'],
            ['mailpoet_last_purchase_at', __('MailPoet last purchase at', 'mailerpress'), 'date'],
            ['mailpoet_time_zone', __('MailPoet time zone', 'mailerpress'), 'text'],
        ];

        foreach ($fields as [$key, $label, $type]) {
            $this->ensureCustomFieldDefinition($key, $label, $type, [], true);
        }
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

    private function ensureTag(string $name): int
    {
        global $wpdb;

        $name = sanitize_text_field(trim($name));
        if ($name === '') {
            return 0;
        }

        $table = Tables::get(Tables::MAILERPRESS_TAGS);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT tag_id FROM {$table} WHERE name = %s LIMIT 1", $name));
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

    private function ensureCustomFieldDefinition(string $fieldKey, string $label, string $type, array $options = [], bool $updateExisting = false): int
    {
        if (!CustomFieldsImport::enabled()) {
            return 0;
        }

        global $wpdb;

        $fieldKey = sanitize_key($fieldKey);
        $label = sanitize_text_field(trim($label));
        if ($fieldKey === '' || $label === '') {
            return 0;
        }

        $table = Tables::get(Tables::MAILERPRESS_CUSTOM_FIELD_DEFINITIONS);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE field_key = %s LIMIT 1", $fieldKey));
        if ($existing) {
            if ($updateExisting) {
                $wpdb->update(
                    $table,
                    [
                        'label' => $label,
                        'type' => $type,
                    ],
                    ['id' => (int) $existing],
                    ['%s', '%s'],
                    ['%d']
                );
            }

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

    private function readMailPoetSettings(): array
    {
        global $wpdb;

        $table = $this->sourceTable('settings');
        if (!$table || !$this->tableExists($table)) {
            return [];
        }

        $names = [
            'sender',
            'sender.name',
            'sender.address',
            'sender_name',
            'sender_address',
            'reply_to',
            'reply_to.name',
            'reply_to.address',
            'reply_to_name',
            'reply_to_address',
            'signup_confirmation',
            'subscription',
            'mta',
            'mta_group',
        ];
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT name, value FROM {$table} WHERE name IN ({$placeholders})",
                ...$names
            )
        ) ?: [];

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row->name] = $this->decodeValue($row->value);
        }

        return $settings;
    }

    private function getRowsForRange(string $suffix, array $payload, bool $excludeDeleted): array
    {
        global $wpdb;

        $table = $this->sourceTable($suffix);
        if (!$table || !$this->tableExists($table)) {
            return [];
        }

        $columns = match ($suffix) {
            'segments' => ['id', 'name', 'type', 'description', 'deleted_at'],
            'custom_fields' => ['id', 'name', 'type', 'params', 'deleted_at'],
            'tags' => ['id', 'name', 'description'],
            default => ['id'],
        };
        $select = $this->selectColumns($table, $columns);
        $deletedWhere = $excludeDeleted && $this->hasColumn($table, 'deleted_at') ? ' AND `deleted_at` IS NULL' : '';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$select}
                 FROM {$table}
                 WHERE id BETWEEN %d AND %d{$deletedWhere}
                 ORDER BY id ASC",
                (int) ($payload['min_id'] ?? 0),
                (int) ($payload['max_id'] ?? 0)
            )
        ) ?: [];
    }

    private function getMailPoetSegmentRowsForRange(array $payload): array
    {
        global $wpdb;

        $table = $this->sourceTable('segments');
        if (!$table || !$this->tableExists($table)) {
            return [];
        }

        if (!$this->hasColumn($table, 'type')) {
            return $this->getRowsForRange('segments', $payload, true);
        }

        $select = $this->selectColumns($table, ['id', 'name', 'type', 'description', 'deleted_at']);
        $typeWhere = $wpdb->prepare('(`type` <> %s OR `type` IS NULL OR `type` = \'\')', 'dynamic');
        $deletedWhere = $this->hasColumn($table, 'deleted_at') ? ' AND `deleted_at` IS NULL' : '';
        $ids = $this->normalizePayloadIds($payload);

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$select}
                     FROM {$table}
                     WHERE id IN ({$placeholders})
                       AND {$typeWhere}{$deletedWhere}
                     ORDER BY id ASC",
                    ...$ids
                )
            ) ?: [];

            return $rows;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$select}
                 FROM {$table}
                 WHERE id BETWEEN %d AND %d
                   AND {$typeWhere}{$deletedWhere}
                 ORDER BY id ASC",
                (int) ($payload['min_id'] ?? 0),
                (int) ($payload['max_id'] ?? 0)
            )
        ) ?: [];

        return $rows;
    }

    private function normalizePayloadIds(array $payload): array
    {
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $id = absint($id);
            if ($id > 0) {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function getMailPoetSourceRow(string $suffix, int $id, array $columns, bool $excludeDeleted): ?object
    {
        global $wpdb;

        $table = $this->sourceTable($suffix);
        if (!$table || !$this->tableExists($table)) {
            return null;
        }

        $select = $this->selectColumns($table, $columns);
        $deletedWhere = $excludeDeleted && $this->hasColumn($table, 'deleted_at') ? ' AND `deleted_at` IS NULL' : '';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$select}
                 FROM {$table}
                 WHERE id = %d{$deletedWhere}
                 LIMIT 1",
                $id
            )
        ) ?: null;
    }

    private function createRangeChunks(
        MigrationRepository $repository,
        int $runId,
        string $entityType,
        string $table,
        int $chunkSize,
        bool $excludeDeleted
    ): array {
        global $wpdb;

        $deletedWhere = $excludeDeleted && $this->hasColumn($table, 'deleted_at') ? 'WHERE `deleted_at` IS NULL' : '';
        $range = $wpdb->get_row("SELECT MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS total FROM {$table} {$deletedWhere}");
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

    private function createMailPoetSegmentChunks(MigrationRepository $repository, int $runId, string $entityType): array
    {
        global $wpdb;

        $table = $this->sourceTable('segments');
        if (!$table || !$this->tableExists($table)) {
            return [];
        }

        if (!$this->hasColumn($table, 'type')) {
            return $this->createRangeChunks($repository, $runId, $entityType, $table, self::STRUCTURE_CHUNK_SIZE, true);
        }

        $typeWhere = $wpdb->prepare('(`type` <> %s OR `type` IS NULL OR `type` = \'\')', 'dynamic');
        $deletedWhere = $this->hasColumn($table, 'deleted_at') ? ' AND `deleted_at` IS NULL' : '';
        $range = $wpdb->get_row("SELECT MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS total FROM {$table} WHERE {$typeWhere}{$deletedWhere}");
        if (!$range || (int) $range->total === 0) {
            return [];
        }

        $created = [];
        $min = (int) $range->min_id;
        $max = (int) $range->max_id;

        for ($start = $min; $start <= $max; $start += self::STRUCTURE_CHUNK_SIZE) {
            $end = min($max, $start + self::STRUCTURE_CHUNK_SIZE - 1);
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

    private function countMailPoetSegmentRowsInRange(array $payload): int
    {
        global $wpdb;

        $table = $this->sourceTable('segments');
        if (!$table || !$this->tableExists($table)) {
            return 0;
        }

        if (!$this->hasColumn($table, 'type')) {
            return $this->countRowsInRange($table, $payload, true);
        }

        $typeWhere = $wpdb->prepare('(`type` <> %s OR `type` IS NULL OR `type` = \'\')', 'dynamic');
        $deletedWhere = $this->hasColumn($table, 'deleted_at') ? ' AND `deleted_at` IS NULL' : '';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table}
                 WHERE id BETWEEN %d AND %d
                   AND {$typeWhere}{$deletedWhere}",
                (int) ($payload['min_id'] ?? 0),
                (int) ($payload['max_id'] ?? 0)
            )
        );
    }

    private function countChunkRows(string $entityType, array $payload): int
    {
        if ($entityType === 'lists') {
            return $this->countMailPoetSegmentRowsInRange($payload);
        }

        $table = match ($entityType) {
            'tags' => $this->sourceTable('tags'),
            'custom_fields' => $this->sourceTable('custom_fields'),
            'contacts' => $this->sourceTable('subscribers'),
            default => null,
        };

        if (!$table || !$this->tableExists($table)) {
            return 0;
        }

        $excludeDeleted = in_array($entityType, ['lists', 'custom_fields', 'contacts'], true);

        return $this->countRowsInRange($table, $payload, $excludeDeleted);
    }

    private function estimateEntity(string $key): int
    {
        return match ($key) {
            'contacts' => $this->countTableRows('subscribers', true),
            'lists' => $this->countMailPoetSegments(),
            'tags' => $this->countTableRows('tags', false),
            'custom_fields' => $this->countTableRows('custom_fields', true),
            'campaigns' => $this->countTableRows('newsletters', true),
            'templates' => $this->countTableRows('newsletter_templates', false),
            'forms' => $this->countTableRows('forms', true),
            'statistics' => $this->countStatistics(),
            default => 0,
        };
    }

    private function countSettings(): int
    {
        global $wpdb;

        $table = $this->sourceTable('settings');
        if (!$table || !$this->tableExists($table)) {
            return 0;
        }

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE name IN ('sender', 'reply_to', 'signup_confirmation', 'subscription', 'mta', 'mta_group')"
        );

        return $count > 0 ? 1 : 0;
    }

    private function countStatistics(): int
    {
        $tables = [
            'statistics_newsletters',
            'statistics_opens',
            'statistics_clicks',
            'statistics_bounces',
            'statistics_unsubscribes',
        ];

        $count = 0;
        foreach ($tables as $table) {
            $count += $this->countTableRows($table, false);
        }

        return $count;
    }

    private function countMailPoetSegments(): int
    {
        global $wpdb;

        $table = $this->sourceTable('segments');
        if (!$table || !$this->tableExists($table)) {
            return 0;
        }

        if (!$this->hasColumn($table, 'type')) {
            return $this->countTableRows('segments', true);
        }

        $typeWhere = $wpdb->prepare('(`type` <> %s OR `type` IS NULL OR `type` = \'\')', 'dynamic');
        $deletedWhere = $this->hasColumn($table, 'deleted_at') ? ' AND `deleted_at` IS NULL' : '';

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$typeWhere}{$deletedWhere}");
    }

    private function countRowsInRange(string $table, array $payload, bool $excludeDeleted): int
    {
        global $wpdb;

        $deletedWhere = $excludeDeleted && $this->hasColumn($table, 'deleted_at') ? ' AND `deleted_at` IS NULL' : '';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE id BETWEEN %d AND %d{$deletedWhere}",
                (int) ($payload['min_id'] ?? 0),
                (int) ($payload['max_id'] ?? 0)
            )
        );
    }

    private function countTableRows(string $suffix, bool $excludeDeleted): int
    {
        global $wpdb;

        $table = $this->sourceTable($suffix);
        if (!$table || !$this->tableExists($table)) {
            return 0;
        }

        $where = $excludeDeleted && $this->hasColumn($table, 'deleted_at') ? 'WHERE `deleted_at` IS NULL' : '';

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
    }

    private function mapSubscriberStatus(string $status): string
    {
        return match ($status) {
            'subscribed' => 'subscribed',
            'unconfirmed' => 'pending',
            'unsubscribed', 'bounced' => 'unsubscribed',
            'inactive' => 'inactive',
            default => 'pending',
        };
    }

    private function mapCustomFieldType(string $type): string
    {
        return match ($type) {
            'date' => 'date',
            'checkbox' => 'checkbox',
            'select', 'radio' => 'select',
            default => 'text',
        };
    }

    private function extractCustomFieldOptions(mixed $params): array
    {
        if (!is_array($params)) {
            return [];
        }

        $rawOptions = $params['values'] ?? $params['options'] ?? [];
        if (!is_array($rawOptions)) {
            return [];
        }

        $options = [];
        foreach ($rawOptions as $option) {
            if (is_array($option)) {
                $value = (string) ($option['value'] ?? $option['name'] ?? $option['label'] ?? '');
            } else {
                $value = (string) $option;
            }

            $value = trim($value);
            if ($value !== '') {
                $options[] = $value;
            }
        }

        return array_values(array_unique($options));
    }

    private function mailPoetFieldKey(string $name, int $sourceId): string
    {
        $base = sanitize_key((string) preg_replace('/[^a-z0-9]+/i', '_', remove_accents(strtolower($name))));
        $base = trim($base, '_');

        if ($base === '') {
            $base = 'field_' . $sourceId;
        }

        return 'mailpoet_' . $base;
    }

    private function decodeValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $unserialized = maybe_unserialize($value);
        if ($unserialized !== $value) {
            return $unserialized;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
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

    private function sourceTable(string $suffix): ?string
    {
        $prefix = $this->getSourcePrefix();
        if (!$prefix) {
            return null;
        }

        $table = $prefix . $suffix;

        return preg_match('/^[A-Za-z0-9_]+$/', $table) ? $table : null;
    }

    private function getSourcePrefix(): ?string
    {
        if ($this->sourcePrefix !== null) {
            return $this->sourcePrefix;
        }

        global $wpdb;

        $envClass = '\MailPoet\Config\Env';
        if (class_exists($envClass) && property_exists($envClass, 'dbPrefix')) {
            $prefix = (string) $envClass::$dbPrefix;
            if ($this->isValidPrefix($prefix) && $this->tableExists($prefix . 'subscribers')) {
                $this->sourcePrefix = $prefix;
                return $this->sourcePrefix;
            }
        }

        $pluginPrefix = (string) apply_filters('mailpoet_db_prefix', 'mailpoet_');
        $prefix = str_starts_with($pluginPrefix, $wpdb->prefix)
            ? $pluginPrefix
            : $wpdb->prefix . $pluginPrefix;

        if ($this->isValidPrefix($prefix) && $this->tableExists($prefix . 'subscribers')) {
            $this->sourcePrefix = $prefix;
            return $this->sourcePrefix;
        }

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', '%' . $wpdb->esc_like('mailpoet_subscribers'))
        );

        if (is_string($found) && str_ends_with($found, 'subscribers')) {
            $prefix = substr($found, 0, -strlen('subscribers'));
            if ($this->isValidPrefix($prefix)) {
                $this->sourcePrefix = $prefix;
                return $this->sourcePrefix;
            }
        }

        $this->sourcePrefix = '';

        return null;
    }

    private function isValidPrefix(string $prefix): bool
    {
        return $prefix !== '' && (bool) preg_match('/^[A-Za-z0-9_]+$/', $prefix);
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private function isMailPoetActive(): bool
    {
        $plugin = 'mailpoet/mailpoet.php';
        $activePlugins = (array) get_option('active_plugins', []);
        $networkPlugins = (array) get_site_option('active_sitewide_plugins', []);

        return in_array($plugin, $activePlugins, true) || array_key_exists($plugin, $networkPlugins);
    }

    private function getMailPoetVersion(): ?string
    {
        $envClass = '\MailPoet\Config\Env';
        if (class_exists($envClass) && property_exists($envClass, 'version')) {
            $version = (string) $envClass::$version;
            return $version !== '' ? $version : null;
        }

        return null;
    }
}
