<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Blocks\PatternsCategories;
use MailerPress\Blocks\TemplatesCategories;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Services\TemplateDirectoryParser;

require_once __DIR__ . '/Helpers/Helpers.php';

/**
 * @throws DependencyException
 * @throws NotFoundException
 * @throws Exception
 */
function mailerpress_register_pattern_category(array $category): void
{
    Kernel::getContainer()->get(PatternsCategories::class)->registerCategory($category);
}

/**
 * @throws DependencyException
 * @throws NotFoundException
 * @throws Exception
 */
function mailerpress_register_templates_category(array $category): void
{
    Kernel::getContainer()->get(TemplatesCategories::class)->registerCategory($category);
}

/**
 * @throws DependencyException
 * @throws NotFoundException
 * @throws Exception
 */
function mailerpress_templates_importer(string $dir): void
{
    Kernel::getContainer()->get(TemplateDirectoryParser::class)->import($dir);
}

/**
 * @param mixed $data
 *
 * @throws DependencyException
 * @throws NotFoundException
 * @throws Exception
 */
function add_mailerpress_contact($data): array
{
    global $wpdb;

    // Validate and sanitize email
    if (empty($data['contactEmail']) && empty($data['email'])) {
        return [
            'success' => false,
            'error' => __('Missing contactEmail', 'mailerpress'),
        ];
    }

    $email = sanitize_email($data['contactEmail'] ?? $data['email']);
    if (!is_email($email)) {
        return [
            'success' => false,
            'error' => __('Invalid email format', 'mailerpress'),
        ];
    }

    // Ensure we use the sanitized email
    $data['contactEmail'] = $email;

    // If no lists provided, assign default list
    // Normalize lists format first
    if (!isset($data['lists']) || !is_array($data['lists']) || count($data['lists']) === 0) {
        $lists_table = Tables::get(Tables::MAILERPRESS_LIST);
        $default_list_id = $wpdb->get_var(
            "SELECT list_id FROM {$lists_table} WHERE is_default = 1 LIMIT 1"
        );
        if ($default_list_id) {
            $data['lists'] = [['id' => (int)$default_list_id]];
        }
    } else {
        // Normalize list format - ensure each list has 'id' key
        $lists_table = Tables::get(Tables::MAILERPRESS_LIST);
        $normalized_lists = [];

        foreach ($data['lists'] as $list) {
            $list_id = null;
            if (is_array($list) && isset($list['id'])) {
                $list_id = (int)$list['id'];
            } elseif (is_numeric($list)) {
                $list_id = (int)$list;
            }

            if ($list_id) {
                // Validate that the list exists in database
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT list_id FROM {$lists_table} WHERE list_id = %d",
                    $list_id
                ));

                if ($exists) {
                    $normalized_lists[] = ['id' => $list_id];
                }
            }
        }

        // If no valid lists after validation, use default list
        if (empty($normalized_lists)) {
            $default_list_id = $wpdb->get_var(
                "SELECT list_id FROM {$lists_table} WHERE is_default = 1 LIMIT 1"
            );
            if ($default_list_id) {
                $normalized_lists = [['id' => (int)$default_list_id]];
            }
        }

        $data['lists'] = $normalized_lists;
    }

    // Auto-detect language for WPML/Polylang if not provided
    if (empty($data['lang'])) {
        $currentLang = apply_filters('wpml_current_language', null);
        if ($currentLang) {
            $data['lang'] = $currentLang;
        }
    }

    $contactModel = Kernel::getContainer()->get(\MailerPress\Models\Contacts::class);
    $existingContact = $contactModel->getContactByEmail($email);

    $hasStatus = !empty($data['subscription_status']) || !empty($data['contactStatus']) || !empty($data['subscriptionStatus']);
    if (!$existingContact && !$hasStatus) {
        $signupConfirmation = mailerpress_get_signup_confirmation_option();
        $source = $data['opt_in_source'] ?? 'custom_form';
        $data['subscription_status'] = ($source !== 'manual' && !empty($signupConfirmation) && true === $signupConfirmation['enableSignupConfirmation'])
            ? 'pending'
            : 'subscribed';
    }

    $result = (new \MailerPress\Services\ContactUpsertService())->upsert(array_merge($data, [
        'email' => $email,
        'update_existing' => true,
        'assign_default_list' => true,
        'auto_map_custom_fields' => true,
        'opt_in_source' => $data['opt_in_source'] ?? ($existingContact ? ($existingContact->opt_in_source ?? 'custom_form') : 'custom_form'),
    ]));

    if (empty($result['success'])) {
        return [
            'success' => false,
            'error' => $result['error'] ?? __('Unknown error.', 'mailerpress'),
        ];
    }

    return [
        'success' => true,
        'contact_id' => $result['contact_id'] ?? null,
        'data' => $result,
    ];
}

function updateContact($existingContact, array $data): array
{
    global $wpdb;

    $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);
    $tags_table = Tables::get(Tables::CONTACT_TAGS);
    $lists_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
    $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);

    $id = $existingContact->contact_id;
    $tags = $data['tags'] ?? [];
    $lists = $data['lists'] ?? [];
    $customFields = $data['custom_fields'] ?? [];
    $newStatus = $data['subscription_status'] ?? null;
    $firstName = $data['contactFirstName'] ?? '';
    $lastName = $data['contactLastName'] ?? '';

    // Normalize lists format first
    if (!is_array($lists)) {
        $lists = [];
    }

    // Normalize list format - ensure each list has 'id' key and validate existence
    $lists_table_name = Tables::get(Tables::MAILERPRESS_LIST);
    $normalized_lists = [];

    foreach ($lists as $list) {
        $list_id = null;
        if (is_array($list) && isset($list['id'])) {
            $list_id = (int)$list['id'];
        } elseif (is_numeric($list)) {
            $list_id = (int)$list;
        }

        if ($list_id) {
            // Validate that the list exists in database
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT list_id FROM {$lists_table_name} WHERE list_id = %d",
                $list_id
            ));

            if ($exists) {
                $normalized_lists[] = ['id' => $list_id];
            }
        }
    }
    $lists = $normalized_lists;

    // If no lists provided, check if contact has any lists and assign default if needed
    if (empty($lists)) {
        // Check if contact already has any lists
        $existing_lists_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$lists_table} WHERE contact_id = %d",
            $id
        ));

        // Only assign default list if contact has no lists at all
        if ($existing_lists_count == 0) {
            $default_list_id = $wpdb->get_var(
                "SELECT list_id FROM {$lists_table_name} WHERE is_default = 1 LIMIT 1"
            );
            if ($default_list_id) {
                $lists = [['id' => (int)$default_list_id]];
            }
        }
    }

    if (empty($id)) {
        return [
            'update' => true,
            'success' => false,
            'message' => __('Missing contact ID.', 'mailerpress'),
        ];
    }

    // Update first name, last name, and subscription status if provided
    $updateData = [];
    $updateFormat = [];
    if (!empty($firstName)) {
        $updateData['first_name'] = $firstName;
        $updateFormat[] = '%s';
    }
    if (!empty($lastName)) {
        $updateData['last_name'] = $lastName;
        $updateFormat[] = '%s';
    }
    if (!empty($newStatus)) {
        $updateData['subscription_status'] = esc_html($newStatus);
        $updateFormat[] = '%s';
    }

    if (!empty($updateData)) {
        $wpdb->update(
            $table_name,
            $updateData,
            ['contact_id' => $id],
            $updateFormat,
            ['%d']
        );
    }

    // Add or update tags
    foreach ($tags as $tag) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $tags_table WHERE contact_id = %d AND tag_id = %d",
            $id,
            $tag['id']
        ));

        if (!$exists) {
            $wpdb->insert(
                $tags_table,
                [
                    'contact_id' => $id,
                    'tag_id' => $tag['id'],
                ],
                ['%d', '%d']
            );
            do_action('mailerpress_contact_tag_added', $id, $tag['id']);
        }
    }

    // Add or update lists
    foreach ($lists as $list) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $lists_table WHERE contact_id = %d AND list_id = %d",
            $id,
            $list['id']
        ));

        if (!$exists) {
            $wpdb->insert(
                $lists_table,
                [
                    'contact_id' => $id,
                    'list_id' => $list['id'],
                ],
                ['%d', '%d']
            );
            do_action('mailerpress_contact_list_added', $id, $list['id']);
        }
    }

    // Add or update custom fields
    foreach ($customFields as $field_key => $field_value) {
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$customFieldsTable} WHERE contact_id = %d AND field_key = %s",
                $id,
                $field_key
            )
        );

        if ($existing) {
            $wpdb->update(
                $customFieldsTable,
                ['field_value' => $field_value],
                ['contact_id' => $id, 'field_key' => $field_key],
                ['%s'],
                ['%d', '%s']
            );
            do_action('mailerpress_contact_custom_field_updated', $id, $field_key, $field_value);
        } else {
            $wpdb->insert(
                $customFieldsTable,
                [
                    'contact_id' => $id,
                    'field_key' => $field_key,
                    'field_value' => $field_value
                ],
                ['%d', '%s', '%s']
            );
            do_action('mailerpress_contact_custom_field_added', $id, $field_key, $field_value);
        }
    }

    return [
        'update' => true,
        'success' => true,
        'message' => __('Contact updated successfully.', 'mailerpress'),
        'contact_id' => $id,
    ];
}

function mailerpress_get_page(string $context): string
{
    $setting = get_option('mailerpress_default_settings');


    if (is_string($setting)) {
        $setting = json_decode($setting, true);
    }


    switch ($context) {
        case 'unsub_page':
            $unsub_page_id = (int) ( $setting['unsubpage']['pageId'] ?? 0 );
            if ( empty($setting) || !isset($setting['unsubpage']) || ( $setting['unsubpage']['useDefault'] ?? true ) || $unsub_page_id <= 0 ) {
                return home_url('?mailpress-pages=mailerpress&action=confirm_unsubscribe');
            } else {
                $permalink = get_the_permalink( $unsub_page_id );
                if ( empty( $permalink ) || false === $permalink ) {
                    return home_url('?mailpress-pages=mailerpress&action=confirm_unsubscribe');
                }
                return sprintf( '%s?action=confirm_unsubscribe', $permalink );
            }
        case 'manage_page':
            $manage_page_id = (int) ( $setting['subpage']['pageId'] ?? 0 );
            if ( empty($setting) || !isset($setting['subpage']) || ( $setting['subpage']['useDefault'] ?? true ) || $manage_page_id <= 0 ) {
                return home_url('?mailpress-pages=mailerpress&action=manage');
            } else {
                $permalink = get_the_permalink( $manage_page_id );
                if ( empty( $permalink ) || false === $permalink ) {
                    return home_url('?mailpress-pages=mailerpress&action=manage');
                }
                return sprintf( '%s?action=manage', $permalink );
            }
    }

    return '';
}

function mailerpress_get_lists()
{
    $model = Kernel::getContainer()->get(\MailerPress\Models\Lists::class);
    return $model->getLists();
}

function mailerpress_get_tags()
{
    $model = Kernel::getContainer()->get(\MailerPress\Models\Tags::class);
    return $model->getAll();
}

function mailerpress_get_provider_class()
{
    return Kernel::getContainer()->get(\MailerPress\Core\EmailManager\EmailServiceManager::class);
}

function mailerpress_option_array(string $optionName, array $default = []): array
{
    $value = get_option($optionName, $default);

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    return is_array($value) ? $value : $default;
}

function mailerpress_normalize_sender_identity(array $sender): array
{
    $fromName = sanitize_text_field((string) (
        $sender['fromName']
        ?? $sender['from_name']
        ?? $sender['default_name']
        ?? ''
    ));
    $fromTo = sanitize_email((string) (
        $sender['fromTo']
        ?? $sender['fromAddress']
        ?? $sender['from_to']
        ?? $sender['default_email']
        ?? ''
    ));

    return array_filter([
        'fromName' => $fromName,
        'fromTo' => $fromTo,
        'senderId' => isset($sender['id']) ? (string) $sender['id'] : null,
    ], static fn($value) => null !== $value && '' !== $value);
}

function mailerpress_get_stored_email_senders(): array
{
    $senders = mailerpress_option_array('mailerpress_email_senders');
    return array_values(array_filter($senders, 'is_array'));
}

function mailerpress_find_email_sender_by_id(string $senderId): array
{
    foreach (mailerpress_get_stored_email_senders() as $sender) {
        if (isset($sender['id']) && (string) $sender['id'] === $senderId) {
            return mailerpress_normalize_sender_identity($sender);
        }
    }

    return [];
}

function mailerpress_get_default_email_sender_identity(): array
{
    $senders = mailerpress_get_stored_email_senders();

    foreach ($senders as $sender) {
        if (!empty($sender['isDefault'])) {
            return mailerpress_normalize_sender_identity($sender);
        }
    }

    return isset($senders[0]) ? mailerpress_normalize_sender_identity($senders[0]) : [];
}

function mailerpress_get_active_service_sender_identity(): array
{
    $servicesData = mailerpress_option_array('mailerpress_email_services');
    $defaultService = $servicesData['default_service'] ?? '';
    $serviceConfig = is_string($defaultService) && isset($servicesData['services'][$defaultService]['conf'])
        ? (array) $servicesData['services'][$defaultService]['conf']
        : [];

    return mailerpress_normalize_sender_identity($serviceConfig);
}

function mailerpress_get_global_sender_identity(): array
{
    $defaultSettings = mailerpress_option_array('mailerpress_default_settings');
    $sender = mailerpress_normalize_sender_identity($defaultSettings);

    if (!empty($sender['fromName']) && !empty($sender['fromTo'])) {
        return $sender;
    }

    return mailerpress_normalize_sender_identity(
        mailerpress_option_array('mailerpress_global_email_senders')
    );
}

function mailerpress_apply_sender_identity_to_config(array $config, array $sender, bool $keepDefaultSenderId = false): array
{
    if (empty($sender['fromName']) || empty($sender['fromTo'])) {
        return $config;
    }

    $config['fromName'] = $sender['fromName'];
    $config['fromTo'] = $sender['fromTo'];

    if (!$keepDefaultSenderId && !empty($sender['senderId'])) {
        $config['senderId'] = $sender['senderId'];
    }

    return $config;
}

function mailerpress_resolve_sender_config(array $config, bool $forceCurrentDefault = false): array
{
    $senderId = isset($config['senderId']) ? (string) $config['senderId'] : '';

    if ('' !== $senderId && 'default' !== $senderId) {
        $sender = mailerpress_find_email_sender_by_id($senderId);
        if (!empty($sender)) {
            return mailerpress_apply_sender_identity_to_config($config, $sender);
        }
    }

    if ('default' === $senderId) {
        $sender = mailerpress_get_default_email_sender_identity();
        if (!empty($sender)) {
            return mailerpress_apply_sender_identity_to_config($config, $sender, true);
        }
    }

    if (!$forceCurrentDefault && !empty($config['fromName']) && !empty($config['fromTo'])) {
        return $config;
    }

    $sender = mailerpress_get_active_service_sender_identity();

    if (empty($sender['fromName']) || empty($sender['fromTo'])) {
        $sender = mailerpress_get_global_sender_identity();
    }

    if (empty($sender['fromName']) || empty($sender['fromTo'])) {
        $sender = mailerpress_get_default_email_sender_identity();
    }

    return mailerpress_apply_sender_identity_to_config($config, $sender, 'default' === $senderId);
}

function mailerpress_refresh_active_automated_campaign_sender_configs(): int
{
    global $wpdb;

    $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
    $campaigns = $wpdb->get_results(
        "SELECT campaign_id, config FROM {$campaignsTable} WHERE campaign_type = 'automated' AND status = 'active'"
    );

    if (empty($campaigns)) {
        return 0;
    }

    $updated = 0;

    foreach ($campaigns as $campaign) {
        $config = json_decode((string) $campaign->config, true);

        if (!is_array($config)) {
            continue;
        }

        $scheduleConfig = is_array($config['automatedCampaignSchedule']['config'] ?? null)
            ? $config['automatedCampaignSchedule']['config']
            : [
                'fromName' => $config['fromName'] ?? '',
                'fromTo' => $config['fromTo'] ?? '',
                'subject' => $config['campaignSubject'] ?? $config['subject'] ?? get_the_title((int) $campaign->campaign_id),
                'previewText' => $config['previewText'] ?? '',
            ];

        $resolvedConfig = mailerpress_resolve_sender_config($scheduleConfig, true);

        if (($scheduleConfig['fromName'] ?? '') === ($resolvedConfig['fromName'] ?? '')
            && ($scheduleConfig['fromTo'] ?? '') === ($resolvedConfig['fromTo'] ?? '')
        ) {
            continue;
        }

        if (!isset($config['automatedCampaignSchedule']) || !is_array($config['automatedCampaignSchedule'])) {
            $config['automatedCampaignSchedule'] = [];
        }

        $config['automatedCampaignSchedule']['config'] = $resolvedConfig;
        $config['fromName'] = $resolvedConfig['fromName'] ?? ($config['fromName'] ?? '');
        $config['fromTo'] = $resolvedConfig['fromTo'] ?? ($config['fromTo'] ?? '');

        $result = $wpdb->update(
            $campaignsTable,
            [
                'config' => wp_json_encode($config),
                'updated_at' => current_time('mysql'),
            ],
            ['campaign_id' => (int) $campaign->campaign_id],
            ['%s', '%s'],
            ['%d']
        );

        if (false !== $result) {
            $updated++;
        }
    }

    return $updated;
}

function mailerpress_normalize_query_block_signature(array $value): array
{
    ksort($value);

    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = mailerpress_normalize_query_block_signature($item);
        }
    }

    return $value;
}

function mailerpress_get_query_block_signatures(string $html): array
{
    preg_match_all(
        '/<!-- START query block:\s*(\{.*?\})\s*-->/is',
        $html,
        $matches
    );

    $signatures = [];

    foreach (($matches[1] ?? []) as $queryJson) {
        $decoded = json_decode($queryJson, true);

        if (is_array($decoded)) {
            $signatures[] = wp_json_encode(mailerpress_normalize_query_block_signature($decoded));
            continue;
        }

        $signatures[] = trim((string) $queryJson);
    }

    return array_values(array_filter($signatures));
}

function mailerpress_query_blocks_changed(string $previousHtml, string $nextHtml): bool
{
    return mailerpress_get_query_block_signatures($previousHtml) !== mailerpress_get_query_block_signatures($nextHtml);
}

function mailerpress_reset_automated_campaign_query_tracking(int $campaignId): void
{
    if ($campaignId <= 0) {
        return;
    }

    delete_option("mailerpress_processed_post_ids_{$campaignId}");
    delete_option("mailerpress_query_baseline_at_{$campaignId}");
}

/**
 * @throws Exception
 */
function mailerpress_schedule_automated_campaign(
    $post,
    $sendType,
    $config,
    $scheduledAt,
    $recipientTargeting,
    $lists,
    $tags,
    $segment,
): void {
    $campaign = Kernel::getContainer()->get(\MailerPress\Models\Campaigns::class)->find($post);
    if (!$campaign || $campaign->campaign_type !== 'automated') {
        return;
    }

    $config = mailerpress_resolve_sender_config(is_array($config) ? $config : [], true);

    $settings = json_decode($campaign->config, true)['automateSettings'] ?? null;

    if (!$settings) {
        return;
    }

    $nextRun = mailerpress_calculate_next_run($settings);
    if (!$nextRun) {
        return;
    }

    // Avoid duplicate pending runs for this campaign, regardless of payload changes.
    mailerpress_cancel_scheduled_automated_campaign_actions((int) $post);

    $campaignConfig = json_decode($campaign->config, true) ?: [];
    $campaignConfig['automateSettings'] = $settings;
    $campaignConfig['automateSettings']['next_run'] = $nextRun->format('Y-m-d H:i:s');
    $campaignConfig['automatedCampaignSchedule'] = [
        'sendType' => $sendType,
        'config' => $config,
        'scheduledAt' => $scheduledAt,
        'recipientTargeting' => $recipientTargeting,
        'lists' => $lists,
        'tags' => $tags,
        'segment' => $segment,
    ];

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'mailerpress_campaigns',
        ['config' => wp_json_encode($campaignConfig)],
        ['campaign_id' => $post],
        ['%s'],
        ['%d']
    );

    as_schedule_single_action(
        $nextRun->getTimestamp(),
        'mailerpress_run_campaign_once',
        [
            $post,
            $sendType,
            $config,
            $scheduledAt,
            $recipientTargeting,
            $lists,
            $tags,
            $segment,
        ],
        'mailerpress'
    );
}

function mailerpress_cancel_scheduled_automated_campaign_actions(int $campaignId): int
{
    if (
        $campaignId <= 0
        || !function_exists('as_get_scheduled_actions')
        || !class_exists('\ActionScheduler_Store')
    ) {
        return 0;
    }

    $store = \ActionScheduler_Store::instance();
    $actions = as_get_scheduled_actions([
        'hook' => 'mailerpress_run_campaign_once',
        'group' => 'mailerpress',
        'status' => \ActionScheduler_Store::STATUS_PENDING,
        'per_page' => 1000,
    ]);

    $cancelled = 0;

    foreach ($actions as $actionId => $action) {
        $args = $action->get_args();
        if (!isset($args[0]) || (int) $args[0] !== $campaignId) {
            continue;
        }

        try {
            $store->cancel_action($actionId);
        } catch (\Exception $e) {
            // Continue so a cancel failure does not block deleting other stale actions.
        }

        try {
            $store->delete_action($actionId);
            $cancelled++;
        } catch (\Exception $e) {
            // Keep processing remaining actions even if Action Scheduler refuses deletion.
        }
    }

    return $cancelled;
}

function mailerpress_get_scheduled_automated_campaign_action_payload(int $campaignId): ?array
{
    if (
        $campaignId <= 0
        || !function_exists('as_get_scheduled_actions')
        || !class_exists('\ActionScheduler_Store')
    ) {
        return null;
    }

    $actions = as_get_scheduled_actions([
        'hook' => 'mailerpress_run_campaign_once',
        'group' => 'mailerpress',
        'status' => \ActionScheduler_Store::STATUS_PENDING,
        'per_page' => 1000,
    ]);

    foreach ($actions as $action) {
        $args = $action->get_args();
        if (!isset($args[0]) || (int) $args[0] !== $campaignId) {
            continue;
        }

        return [
            'sendType' => $args[1] ?? 'now',
            'config' => $args[2] ?? [],
            'scheduledAt' => $args[3] ?? current_time('mysql'),
            'recipientTargeting' => $args[4] ?? 'classic',
            'lists' => $args[5] ?? [],
            'tags' => $args[6] ?? [],
            'segment' => $args[7] ?? [],
        ];
    }

    return null;
}

/**
 * @throws Exception
 */
function mailerpress_calculate_next_run(array $settings, ?DateTime $lastRun = null): ?DateTime
{
    $now = new DateTime('now', wp_timezone());
    $type = $settings['type'] ?? '';
    $time = $settings['time'] ?? null;
    if (!$time) {
        return null;
    }

    [$hour, $minute] = explode(':', $time);

    // Date de base pour le calcul : soit le "lastRun + 1 seconde", soit "now"
    $base = $lastRun ? (clone $lastRun)->modify('+1 second') : $now;

    // Positionner la date de départ à l'heure donnée, même jour
    $next = clone $base;
    $next->setTime((int)$hour, (int)$minute, 0);

    if ($lastRun) {
        $lastRun->setTimezone(wp_timezone());
    }

    // Si l'heure est déjà passée dans la journée de base, on passe au jour suivant
    if ($next <= $base) {
        $next->modify('+1 day');
        $next->setTime((int)$hour, (int)$minute, 0);
    }

    switch ($type) {
        case 'daily':
            return $next;

        case 'weekly':
            $days = $settings['daysOfWeek'] ?? [];
            if (empty($days)) {
                return null;
            }

            // Chercher dans les 7 prochains jours à partir de $next
            for ($i = 0; $i < 7; $i++) {
                $candidate = (clone $next)->modify("+$i day");
                if (in_array((int)$candidate->format('N'), $days)) {
                    $candidate->setTime((int)$hour, (int)$minute, 0);
                    // Si candidat <= base, on continue
                    if ($candidate > $base) {
                        return $candidate;
                    }
                }
            }

            // Si aucun jour trouvé dans les 7 prochains jours, chercher dans la semaine suivante
            // Cela peut arriver si on est déjà passé tous les jours de la semaine
            $nextWeek = (clone $next)->modify('+7 days');
            for ($i = 0; $i < 7; $i++) {
                $candidate = (clone $nextWeek)->modify("+$i day");
                if (in_array((int)$candidate->format('N'), $days)) {
                    $candidate->setTime((int)$hour, (int)$minute, 0);
                    if ($candidate > $base) {
                        return $candidate;
                    }
                }
            }

            return null;

        case 'monthly':
            $days = $settings['daysOfMonth'] ?? [];
            if (empty($days)) {
                return null;
            }
            sort($days); // Sort ascending for easier check

            $candidate = clone $base;

            // We check candidate months for next 12 months max to avoid infinite loops
            for ($m = 0; $m < 12; $m++) {
                $monthStart = (clone $candidate)->modify("+$m month")->modify('first day of this month');
                foreach ($days as $day) {
                    // Check if day exists in this month
                    $daysInMonth = (int)$monthStart->format('t');
                    if ($day > $daysInMonth) {
                        continue;
                    }
                    $nextRun = (clone $monthStart)->setDate(
                        (int)$monthStart->format('Y'),
                        (int)$monthStart->format('m'),
                        $day
                    )->setTime((int)$hour, (int)$minute, 0);

                    if ($nextRun > $base) {
                        return $nextRun;
                    }
                }
            }

            return null;

        default:
            return null;
    }
}

function containsStartQueryBlock(string $html): bool
{
    return (bool)preg_match('/<!--\s*START query block:\s*\{.*?\}\s*-->/', $html);
}

function remove_unlock_request($campaign_id, $user_id): void
{
    $requests = get_transient("campaign_{$campaign_id}_unlock_requests") ?: [];

    if (isset($requests[$user_id])) {
        unset($requests[$user_id]);
        set_transient("campaign_{$campaign_id}_unlock_requests", $requests, 5 * MINUTE_IN_SECONDS);
    }
}

function add_unlock_request($campaign_id, $user_id): void
{
    $requests = get_transient("campaign_{$campaign_id}_unlock_requests") ?: [];

    // Add the user to the list of unlock requests with timestamp
    $requests[$user_id] = [
        'timestamp' => current_time('mysql'),
        'user_name' => wp_get_current_user()->user_login,
        'user_id' => intval($user_id),
    ];

    // Save back to transient (5 minutes expiration for example)
    set_transient("campaign_{$campaign_id}_unlock_requests", $requests, 5 * MINUTE_IN_SECONDS);
}

/**
 * Register a notification message
 *
 * @param string $id Unique identifier for the message
 * @param string $class Fully qualified class name implementing NotificationMessageInterface
 * @throws Exception
 */
function mailerpress_register_notification_message(string $id, string $class): void
{
    try {
        $factory = Kernel::getContainer()->get(\MailerPress\Core\Notifications\NotificationMessageFactory::class);
        $factory->register($id, $class);
    } catch (DependencyException | NotFoundException $e) {
        throw new Exception('Failed to register notification message: ' . $e->getMessage());
    }
}

function user_has_gravatar($email): bool
{
    $hash = md5(strtolower(trim($email)));
    $uri  = 'https://www.gravatar.com/avatar/' . $hash . '?d=404';

    $response = wp_remote_head($uri);

    return !is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response);
}

/**
 * Get the signup confirmation option as a PHP array.
 * Handles backward compatibility with old JSON-encoded format.
 */
function mailerpress_get_signup_confirmation_option(): array
{
    $default = [
        'enableSignupConfirmation' => true,
        'emailSubject' => __('Confirm your subscription to [site:title]', 'mailerpress'),
        'emailContent' => __(
            'Hello [contact:firstName] [contact:lastName],

You have received this email regarding your subscription to [site:title]. Please confirm it to receive emails from us:

[activation_link]Click here to confirm your subscription[/activation_link]

If you received this email in error, simply delete it. You will no longer receive emails from us if you do not confirm your subscription using the link above.

Thank you,

<a target="_blank" href="[site:homeURL]">[site:title]</a>',
            'mailerpress'
        ),
        'confirmRedirectUrl' => '',
        'enableReminders' => false,
        'reminderIntervalDays' => 7,
        'campaign_id' => null,
    ];

    $option = get_option('mailerpress_signup_confirmation');

    if ($option === false) {
        return $default;
    }

    // Backward compatibility: old format was JSON string — migrate to native array
    // so WPML admin-texts can translate sub-keys
    if (is_string($option)) {
        $decoded = json_decode($option, true);
        if (is_array($decoded)) {
            // Persist as native PHP array for WPML compatibility
            update_option('mailerpress_signup_confirmation', $decoded);
            $option = $decoded;
        } else {
            return $default;
        }
    }

    if (!is_array($option)) {
        return $default;
    }

    $result = array_merge($default, $option);

    // WPML strips \n from translated admin-texts values.
    // Restore line breaks in emailContent by reading the original (untranslated) value
    // and applying its \n\n structure to the translated text.
    if (!empty($result['emailContent'])) {
        $before = $result['emailContent'];
        $result['emailContent'] = mailerpress_restore_newlines_from_original($result['emailContent']);
    }

    return $result;
}

/**
 * Restore \n\n line breaks in translated text by using the original (untranslated) value as reference.
 * WPML strips newlines from admin-texts translations, so we read the original value,
 * extract the \n\n structure, and re-inject it into the translated text.
 *
 * Strategy: split the original by \n\n to get segment ending patterns (last few chars).
 * For each segment ending in the original, find the corresponding ending in the translated text
 * (same trailing punctuation/tag pattern) and insert \n\n after it.
 */
function mailerpress_restore_newlines_from_original(string $translatedContent): string
{
    // If the translated content already has \n\n, it's fine — no restoration needed
    if (str_contains($translatedContent, "\n\n")) {
        return $translatedContent;
    }

    // Read the original (untranslated) option value by temporarily switching to default language
    $originalLang = apply_filters('wpml_current_language', null);
    $defaultLang = apply_filters('wpml_default_language', null);

    if (!$defaultLang || $originalLang === $defaultLang) {
        return $translatedContent;
    }

    // Read the raw option directly from the database, bypassing WPML filters
    // (WPML's get_option filter returns translated values even after wpml_switch_language)
    global $wpdb;
    $rawValue = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'mailerpress_signup_confirmation'
    ));

    if (!$rawValue) {
        return $translatedContent;
    }

    $originalOption = maybe_unserialize($rawValue);

    if (!is_array($originalOption) || empty($originalOption['emailContent'])) {
        return $translatedContent;
    }

    $originalContent = $originalOption['emailContent'];

    if (!str_contains($originalContent, "\n\n")) {
        return $translatedContent;
    }

    // Split the original by \n\n to get segment endings
    $originalSegments = preg_split('/\n\n+/', $originalContent);
    $segmentCount = count($originalSegments);

    if ($segmentCount <= 1) {
        return $translatedContent;
    }

    // Extract the trailing pattern of each segment (the last distinctive string).
    // We use the last punctuation or HTML tag ending as anchor to find the same boundary
    // in the translated text.
    $result = $translatedContent;
    $offset = 0;

    for ($i = 0; $i < $segmentCount - 1; $i++) {
        $origSegment = trim($originalSegments[$i]);
        if (empty($origSegment)) {
            continue;
        }

        // Get the ending anchor: for tags like [/activation_link] or </a>, use the tag.
        // For text, use the last punctuation + trailing chars.
        $anchor = null;

        // Check for shortcode ending
        if (preg_match('/(\[\/?[a-z_]+\])$/i', $origSegment, $m)) {
            $anchor = $m[1];
        }
        // Check for HTML tag ending
        elseif (preg_match('/(<\/[a-z]+>)$/i', $origSegment, $m)) {
            $anchor = $m[1];
        }
        // Use last punctuation (: , . ;) as anchor — look for it followed by a space
        else {
            $lastChar = substr(rtrim($origSegment), -1);
            if (in_array($lastChar, [':', ',', '.', ';'], true)) {
                $anchor = $lastChar;
            }
        }

        if ($anchor === null) {
            continue;
        }

        // Count how many times this anchor appears in the original segment
        // to find the Nth (last) occurrence in the translated text.
        // e.g. if original segment has 2 periods ("delete it. ... link above."),
        // we need the 2nd period in the translated text.
        $anchorCountInSegment = substr_count($origSegment, $anchor);

        // Find the Nth occurrence of this anchor in the translated text after current offset
        $pos = false;
        $searchPos = $offset;
        for ($n = 0; $n < $anchorCountInSegment; $n++) {
            $found = strpos($result, $anchor, $searchPos);
            if ($found === false) {
                break;
            }
            $pos = $found;
            $searchPos = $found + strlen($anchor);
        }

        if ($pos === false || $pos <= $offset) {
            continue;
        }

        $insertAt = $pos + strlen($anchor);

        // Skip trailing space if present
        if ($insertAt < strlen($result) && $result[$insertAt] === ' ') {
            $insertAt++;
        }

        // Don't insert at the very end
        if ($insertAt >= strlen($result)) {
            continue;
        }

        $result = substr($result, 0, $insertAt) . "\n\n" . substr($result, $insertAt);
        $offset = $insertAt + 2; // skip past the inserted \n\n
    }

    return $result;
}

/**
 * Build the HTML email wrapper for the confirmation email.
 * Wraps the textarea content in a responsive email template with header (logo) and footer.
 */
function mailerpress_build_confirmation_email_html(string $bodyContent, array $options = []): string
{
    $primaryColor = esc_attr($options['primaryColor'] ?? '#000000');
    $backgroundColor = esc_attr($options['backgroundColor'] ?? '#f5f5f5');
    $textColor = esc_attr($options['textColor'] ?? '#333333');
    $headerLogo = $options['headerLogo'] ?? '';
    $siteTitle = esc_html(get_bloginfo('name'));

    $logoHtml = '';
    if (!empty($headerLogo)) {
        $logoHtml = sprintf(
            '<tr><td align="center" style="padding: 25px 20px 15px 20px;"><img src="%s" alt="%s" style="max-width: 120px; height: auto; display: block;" /></td></tr>',
            esc_url($headerLogo),
            $siteTitle
        );
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>{$siteTitle}</title>
</head>
<body style="margin: 0; padding: 0; background-color: {$backgroundColor}; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: {$backgroundColor};">
<tr><td align="center" style="padding: 30px 10px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 8px; overflow: hidden;">
<!-- Header -->
<tr><td style="background-color: {$primaryColor}; padding: 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
{$logoHtml}
<tr><td style="height: 8px; font-size: 0; line-height: 0;">&nbsp;</td></tr>
</table>
</td></tr>
<!-- Body -->
<tr><td style="padding: 40px 30px; color: {$textColor}; font-size: 16px; line-height: 1.6;">
{$bodyContent}
</td></tr>
<!-- Footer -->
<tr><td align="center" style="padding: 20px 30px; background-color: {$backgroundColor}; color: #999999; font-size: 12px; line-height: 1.5;">
{$siteTitle}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Starter template for the confirm_email campaign type.
 * Contains a locked text block showing the textarea content (non-editable in the editor).
 * At send time, the content between MAILERPRESS_EMAIL_CONTENT markers is replaced
 * with the translated textarea content.
 */
function mailerpress_get_confirm_email_starter_template(string $emailContent = ''): array
{
    $uid = function () {
        return wp_generate_uuid4();
    };

    // Split content around [activation_link]Label[/activation_link]
    $buttonLabel = __('Confirm my subscription', 'mailerpress');
    $beforeText = $emailContent;
    $afterText = '';

    if (preg_match('/\[activation_link\](.*?)\[\/activation_link\]/s', $emailContent, $matches)) {
        $buttonLabel = trim($matches[1]);
        $parts = preg_split('/\[activation_link\].*?\[\/activation_link\]/s', $emailContent);
        $beforeText = trim($parts[0] ?? '');
        $afterText = trim($parts[1] ?? '');
    }

    return [
        'type' => 'page',
        'data' => [
            'attributes' => [
                'width' => '600px',
                'background-color' => '#ffffff',
            ],
            'globalAttributes' => [],
            'headAttributes' => [],
            'fonts' => [],
            'style' => '',
            'previewText' => '',
        ],
        'attributes' => [],
        'children' => [
            // Header section (design only — logo, spacer)
            [
                'type' => 'section',
                'data' => ['columnCount' => 1, 'border-style' => 'solid', 'size' => 'full'],
                'attributes' => [
                    'padding-left' => '20px',
                    'padding-right' => '20px',
                    'padding-bottom' => '0px',
                    'padding-top' => '10px',
                    'background-color' => '#2c2c2c',
                ],
                'children' => [
                    [
                        'type' => 'column',
                        'data' => ['border-style' => 'solid'],
                        'attributes' => ['vertical-align' => 'top', 'padding-top' => '0px', 'padding-bottom' => '0px', 'padding-right' => '0px', 'padding-left' => '0px'],
                        'children' => [
                            [
                                'type' => 'spacer',
                                'data' => [],
                                'attributes' => ['height' => '10px', 'padding-top' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px', 'padding-right' => '0px'],
                                'children' => [],
                                'clientId' => $uid(),
                            ],
                            [
                                'type' => 'image',
                                'data' => ['width' => 120, 'size' => 'full'],
                                'attributes' => ['width' => '120px', 'align' => 'center', 'src' => 'https://placehold.co/120x40/2c2c2c/ffffff?text=LOGO', 'href' => '', 'fluid-on-mobile' => false, 'padding-top' => '10px', 'padding-bottom' => '10px', 'padding-left' => '10px', 'padding-right' => '10px'],
                                'children' => [],
                                'clientId' => $uid(),
                            ],
                            [
                                'type' => 'spacer',
                                'data' => [],
                                'attributes' => ['height' => '10px', 'padding-top' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px', 'padding-right' => '0px'],
                                'children' => [],
                                'clientId' => $uid(),
                            ],
                        ],
                        'clientId' => $uid(),
                    ],
                ],
                'clientId' => $uid(),
            ],
            // Body section — email content placeholder + confirmation button (locked)
            [
                'type' => 'section',
                'data' => ['columnCount' => 1, 'border-style' => 'solid', 'size' => 'full', 'lock' => true],
                'attributes' => [
                    'padding-left' => '20px',
                    'padding-right' => '20px',
                    'padding-bottom' => '10px',
                    'padding-top' => '20px',
                ],
                'children' => [
                    [
                        'type' => 'column',
                        'data' => ['border-style' => 'solid', 'lock' => true],
                        'attributes' => ['vertical-align' => 'top', 'padding-top' => '0px', 'padding-bottom' => '0px', 'padding-right' => '0px', 'padding-left' => '0px'],
                        'children' => array_values(array_filter([
                            // Text before button
                            $beforeText ? [
                                'type' => 'text',
                                'data' => [
                                    'content' => '<!-- MAILERPRESS_EMAIL_CONTENT_BEFORE_START -->' . nl2br(esc_html($beforeText)) . '<!-- MAILERPRESS_EMAIL_CONTENT_BEFORE_END -->',
                                    'lock' => true,
                                ],
                                'attributes' => [
                                    'padding-top' => '10px',
                                    'padding-bottom' => '10px',
                                    'padding-left' => '25px',
                                    'padding-right' => '25px',
                                    'font-size' => '16px',
                                    'color' => '#333333',
                                    'line-height' => '1.6',
                                    'css-class' => 'lock-inline-editing',
                                ],
                                'children' => [],
                                'clientId' => $uid(),
                            ] : null,
                            // Confirmation button
                            [
                                'type' => 'button',
                                'data' => [
                                    'content' => $buttonLabel,
                                    'border-style' => 'solid',
                                    'lock' => true,
                                ],
                                'attributes' => [
                                    'align' => 'center',
                                    'background-color' => '#2c2c2c',
                                    'color' => '#ffffff',
                                    'font-size' => '16px',
                                    'font-weight' => 'bold',
                                    'border-radius' => '6px',
                                    'padding-top' => '15px',
                                    'padding-bottom' => '15px',
                                    'padding-left' => '10px',
                                    'padding-right' => '10px',
                                    'inner-padding' => '14px 30px',
                                    'href' => '{{activation_link}}',
                                    'css-class' => 'lock-inline-editing',
                                ],
                                'children' => [],
                                'clientId' => $uid(),
                            ],
                            // Text after button
                            $afterText ? [
                                'type' => 'text',
                                'data' => [
                                    'content' => '<!-- MAILERPRESS_EMAIL_CONTENT_AFTER_START -->' . nl2br(esc_html($afterText)) . '<!-- MAILERPRESS_EMAIL_CONTENT_AFTER_END -->',
                                    'lock' => true,
                                ],
                                'attributes' => [
                                    'padding-top' => '10px',
                                    'padding-bottom' => '10px',
                                    'padding-left' => '25px',
                                    'padding-right' => '25px',
                                    'font-size' => '16px',
                                    'color' => '#333333',
                                    'line-height' => '1.6',
                                    'css-class' => 'lock-inline-editing',
                                ],
                                'children' => [],
                                'clientId' => $uid(),
                            ] : null,
                            // Trailing spacer
                            [
                                'type' => 'spacer',
                                'data' => [],
                                'attributes' => ['height' => '15px', 'padding-top' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px', 'padding-right' => '0px'],
                                'children' => [],
                                'clientId' => $uid(),
                            ],
                        ])),
                        'clientId' => $uid(),
                    ],
                ],
                'clientId' => $uid(),
            ],
            // Footer section (design only — divider, spacer)
            [
                'type' => 'section',
                'data' => ['columnCount' => 1, 'border-style' => 'solid', 'size' => 'full'],
                'attributes' => [
                    'padding-left' => '20px',
                    'padding-right' => '20px',
                    'padding-bottom' => '20px',
                    'padding-top' => '10px',
                    'background-color' => '#f5f5f5',
                ],
                'children' => [
                    [
                        'type' => 'column',
                        'data' => ['border-style' => 'solid'],
                        'attributes' => ['vertical-align' => 'top', 'padding-top' => '0px', 'padding-bottom' => '0px', 'padding-right' => '0px', 'padding-left' => '0px'],
                        'children' => [
                            [
                                'type' => 'divider',
                                'data' => [],
                                'attributes' => [
                                    'border-color' => '#cccccc',
                                    'border-style' => 'solid',
                                    'border-width' => '1px',
                                    'padding-top' => '10px',
                                    'padding-bottom' => '10px',
                                    'padding-left' => '10px',
                                    'padding-right' => '10px',
                                ],
                                'children' => [],
                                'clientId' => $uid(),
                            ],
                            [
                                'type' => 'spacer',
                                'data' => [],
                                'attributes' => ['height' => '10px', 'padding-top' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px', 'padding-right' => '0px'],
                                'children' => [],
                                'clientId' => $uid(),
                            ],
                        ],
                        'clientId' => $uid(),
                    ],
                ],
                'clientId' => $uid(),
            ],
        ],
        'clientId' => $uid(),
    ];
}
