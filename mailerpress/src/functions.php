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

    // Auto-detect language for WPML/Polylang if not provided
    if (empty($data['lang'])) {
        $currentLang = apply_filters('wpml_current_language', null);
        if ($currentLang) {
            $data['lang'] = $currentLang;
        }
    }

    $contactModel = Kernel::getContainer()->get(\MailerPress\Models\Contacts::class);
    $existingContact = $contactModel->getContactByEmail($email);

    if ($existingContact) {
        return updateContact($existingContact, $data);
    }

    // New contact — use rest_do_request() (in-process, no HTTP round-trip, no auth issues)
    $GLOBALS['mailerpress_internal_php_call'] = true;
    try {
        $wp_request = new \WP_REST_Request('POST', '/mailerpress/v1/contact');
        $wp_request->set_body_params($data);
        $rest_response = rest_do_request($wp_request);
    } finally {
        unset($GLOBALS['mailerpress_internal_php_call']);
    }

    if ($rest_response->is_error()) {
        return [
            'success' => false,
            'error' => $rest_response->as_error()->get_error_message(),
        ];
    }

    $response_data = $rest_response->get_data();

    if (isset($response_data['success']) && $response_data['success']) {
        $contact_id = $response_data['data']['contact_id'] ?? $response_data['contact_id'] ?? null;
        return [
            'success' => true,
            'contact_id' => $contact_id,
            'data' => $response_data['data'] ?? [],
        ];
    }

    return [
        'success' => false,
        'error' => $response_data['message'] ?? __('Unknown error.', 'mailerpress'),
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
