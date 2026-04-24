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
    if (empty($data['contactEmail'])) {
        return [
            'success' => false,
            'error' => __('Missing contactEmail', 'mailerpress'),
        ];
    }

    $email = sanitize_email($data['contactEmail']);
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

    $contactModel = Kernel::getContainer()->get(\MailerPress\Models\Contacts::class);
    $existingContact = $contactModel->getContactByEmail($email);

    if ($existingContact) {
        return updateContact($existingContact, $data);
    } else {
        $response = wp_remote_post(
            home_url('/wp-json/mailerpress/v1/contact'),
            [
                'method' => 'POST',
                'timeout' => 60,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($data),
            ]
        );
    }


    if (is_wp_error($response)) {
        return [
            'success' => false,
            'error' => $response->get_error_message(),
        ];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['success']) && $body['success']) {
        $contact_id = $body['data']['contact_id'] ?? $body['contact_id'] ?? null;

        return [
            'success' => true,
            'contact_id' => $contact_id,
            'data' => $body['data'] ?? [],
        ];
    }

    $error_message = $body['message'] ?? __('Unknown error.', 'mailerpress');

    return [
        'success' => false,
        'error' => $error_message,
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

    $settings = json_decode($campaign->config, true)['automateSettings'] ?? null;

    if (!$settings) {
        return;
    }

    $nextRun = mailerpress_calculate_next_run($settings);
    if (!$nextRun) {
        return;
    }

    // Avoid duplicate
    as_unschedule_all_actions('mailerpress_run_campaign_once', [
        $post,
        $sendType,
        $campaign->campaign_id,
        $config,
        $scheduledAt,
        $recipientTargeting,
        $lists,
        $tags,
        $segment,
    ], 'mailerpress');

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

function user_has_gravatar($email): bool
{
    $hash = md5(strtolower(trim($email)));
    $uri  = 'https://www.gravatar.com/avatar/' . $hash . '?d=404';

    $response = wp_remote_head($uri);

    return !is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response);
}
