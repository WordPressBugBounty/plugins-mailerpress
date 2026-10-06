<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class InactiveContactManager
{
    public const STATUS_INACTIVE = 'inactive';
    public const OPTION_NAME = 'mailerpress_inactive_subscribers';
    public const REENGAGEMENT_ENABLED_AT_OPTION = 'mailerpress_reengagement_enabled_at';

    private const DEFAULT_INACTIVE_AFTER_DAYS = 365;
    private const DEFAULT_INACTIVE_PERIOD_UNIT = 'years';
    private const DEFAULT_INACTIVE_PERIOD_VALUE = 1;
    private const DEFAULT_BATCH_SIZE = 1000;
    private const DEFAULT_MIN_EMAILS_SENT = 3;
    private const PERIOD_UNITS = [
        'days' => [
            'multiplier' => 1,
            'min' => 1,
            'max' => 3650,
        ],
        'weeks' => [
            'multiplier' => 7,
            'min' => 1,
            'max' => 520,
        ],
        'months' => [
            'multiplier' => 30,
            'min' => 1,
            'max' => 120,
        ],
        'years' => [
            'multiplier' => 365,
            'min' => 1,
            'max' => 10,
        ],
    ];

    private static array $columnCache = [];

    private static function getDefaultReengagementSubject(): string
    {
        return __('Do you still want to hear from [site:title]?', 'mailerpress');
    }

    private static function getDefaultReengagementContent(): string
    {
        return __("Hello [contact:firstName],\n\nWe noticed you have not engaged with our emails recently. If you still want to receive updates from [site:title], please confirm below:\n\n[reengagement_link]Yes, keep me subscribed[/reengagement_link]\n\nIf you do not confirm, we will keep you inactive and stop sending regular emails.\n\nThank you,\n[site:title]\n\n[unsubscribe_link]Unsubscribe[/unsubscribe_link]", 'mailerpress');
    }

    public function getSettings(): array
    {
        $settings = get_option(self::OPTION_NAME, []);
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : [];
            if (!empty($settings)) {
                update_option(self::OPTION_NAME, $settings);
            }
        }
        $hasPeriodSettings = is_array($settings)
            && (array_key_exists('inactive_period_unit', $settings) || array_key_exists('inactive_period_value', $settings));

        $settings = array_merge([
            'enabled' => false,
            'inactive_period_unit' => self::DEFAULT_INACTIVE_PERIOD_UNIT,
            'inactive_period_value' => self::DEFAULT_INACTIVE_PERIOD_VALUE,
            'inactive_after_days' => self::DEFAULT_INACTIVE_AFTER_DAYS,
            'batch_size' => self::DEFAULT_BATCH_SIZE,
            'min_emails_sent' => self::DEFAULT_MIN_EMAILS_SENT,
            'reengagement_enabled' => false,
            'reengagement_subject' => self::getDefaultReengagementSubject(),
            'reengagement_content' => self::getDefaultReengagementContent(),
            'campaign_id' => null,
            'useDesignedEmail' => false,
        ], is_array($settings) ? $settings : []);

        $settings['enabled'] = (bool) $settings['enabled'];
        $settings['reengagement_enabled'] = (bool) $settings['reengagement_enabled'];
        $settings['useDesignedEmail'] = (bool) $settings['useDesignedEmail'];
        $period = $hasPeriodSettings
            ? $this->normalizePeriod($settings['inactive_period_unit'], $settings['inactive_period_value'])
            : $this->inferPeriodFromDays((int) $settings['inactive_after_days']);
        $settings['inactive_period_unit'] = $period['unit'];
        $settings['inactive_period_value'] = $period['value'];
        $settings['inactive_after_days'] = $this->periodToDays($period['unit'], $period['value']);
        $settings['batch_size'] = max(1, min(5000, (int) $settings['batch_size']));
        $settings['min_emails_sent'] = max(1, min(100, (int) $settings['min_emails_sent']));
        $settings['reengagement_subject'] = sanitize_text_field((string) $settings['reengagement_subject']);
        $settings['reengagement_content'] = wp_kses_post((string) $settings['reengagement_content']);
        if ($settings['reengagement_subject'] === '') {
            $settings['reengagement_subject'] = self::getDefaultReengagementSubject();
        }
        if ($settings['reengagement_content'] === '') {
            $settings['reengagement_content'] = self::getDefaultReengagementContent();
        }
        if (function_exists('mailerpress_restore_newlines_from_original')) {
            $settings['reengagement_content'] = mailerpress_restore_newlines_from_original(
                $settings['reengagement_content'],
                self::OPTION_NAME,
                'reengagement_content'
            );
        }
        $settings['campaign_id'] = !empty($settings['campaign_id'])
            ? absint($settings['campaign_id'])
            : null;

        return (array) apply_filters('mailerpress_inactive_contact_settings', $settings);
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->getSettings()['enabled'] ?? false);
    }

    public function isReengagementEnabled(): bool
    {
        return (bool) ($this->getSettings()['reengagement_enabled'] ?? false);
    }

    public function markEmailsSent(array $contactIds, ?string $sentAt = null): void
    {
        global $wpdb;

        if (!$this->contactTableHasColumns(['email_count', 'last_sending_at'])) {
            return;
        }

        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn($id) => $id > 0)));
        if (empty($contactIds)) {
            return;
        }

        $sentAt = $this->normalizeDateTime($sentAt) ?? current_time('mysql');
        $placeholders = implode(',', array_fill(0, count($contactIds), '%d'));
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$contactTable}
             SET email_count = COALESCE(email_count, 0) + 1,
                 last_sending_at = IF(last_sending_at IS NULL OR last_sending_at < %s, %s, last_sending_at)
             WHERE contact_id IN ({$placeholders})",
            $sentAt,
            $sentAt,
            ...$contactIds
        ));
    }

    public function markOpened(int $contactId, ?string $openedAt = null): void
    {
        $this->markEngaged($contactId, 'last_open_at', $openedAt);
    }

    public function markClicked(int $contactId, ?string $clickedAt = null): void
    {
        $this->markEngaged($contactId, 'last_click_at', $clickedAt);
    }

    public function markSubscribed(int $contactId, ?string $subscribedAt = null): void
    {
        global $wpdb;

        if (!$this->contactTableHasColumns(['last_subscribed_at', 'inactivated_at', 'inactivation_reason'])) {
            return;
        }

        $contactId = absint($contactId);
        if ($contactId <= 0) {
            return;
        }

        $subscribedAt = $this->normalizeDateTime($subscribedAt) ?? current_time('mysql');
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$contactTable}
             SET last_subscribed_at = %s,
                 inactivated_at = NULL,
                 inactivation_reason = NULL
             WHERE contact_id = %d",
            $subscribedAt,
            $contactId
        ));
    }

    public function deactivateInactiveContacts(): array
    {
        global $wpdb;

        if (!$this->isEnabled() || !$this->contactTableHasColumns([
            'email_count',
            'last_sending_at',
            'last_engagement_at',
            'last_click_at',
            'last_subscribed_at',
            'inactivated_at',
            'inactivation_reason',
        ])) {
            return [
                'deactivated' => 0,
                'reactivated' => 0,
                'enabled' => false,
            ];
        }

        $settings = $this->getSettings();
        $thresholdDate = $this->daysAgo((int) $settings['inactive_after_days']);
        $batchSize = (int) $settings['batch_size'];

        $reactivated = $this->reactivateRecentlyEngagedContacts($thresholdDate, $batchSize);

        $contactIds = $this->findInactiveCandidates(
            $thresholdDate,
            $batchSize,
            (int) $settings['min_emails_sent']
        );

        if (empty($contactIds)) {
            return [
                'deactivated' => 0,
                'reactivated' => $reactivated,
                'enabled' => true,
            ];
        }

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $placeholders = implode(',', array_fill(0, count($contactIds), '%d'));
        $now = current_time('mysql');

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$contactTable}
             SET subscription_status = %s,
                 inactivated_at = %s,
                 inactivation_reason = %s
             WHERE subscription_status = 'subscribed'
               AND contact_id IN ({$placeholders})",
            self::STATUS_INACTIVE,
            $now,
            'no_engagement_after_inactivity_threshold',
            ...$contactIds
        ));

        if ($updated) {
            do_action('mailerpress_contacts_marked_inactive', $contactIds, [
                'threshold_date' => $thresholdDate,
                'inactive_after_days' => (int) $settings['inactive_after_days'],
            ]);
        }

        return [
            'deactivated' => max(0, (int) $updated),
            'reactivated' => $reactivated,
            'enabled' => true,
        ];
    }

    public function findReengagementCandidates(int $batchSize): array
    {
        global $wpdb;

        if (!$this->isReengagementEnabled() || !$this->contactTableHasColumns([
            'inactivated_at',
            'inactivation_reason',
            'reengagement_sent_at',
            'reengagement_confirmed_at',
        ])) {
            return [];
        }

        $enabledAt = get_option(self::REENGAGEMENT_ENABLED_AT_OPTION);
        if (!is_string($enabledAt) || $enabledAt === '') {
            return [];
        }

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $batchSize = max(1, min(5000, $batchSize));

        $contacts = $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$contactTable}
             WHERE subscription_status = %s
               AND inactivated_at > %s
               AND inactivation_reason = %s
               AND reengagement_sent_at IS NULL
               AND reengagement_confirmed_at IS NULL
             ORDER BY contact_id ASC
             LIMIT %d",
            self::STATUS_INACTIVE,
            $enabledAt,
            'no_engagement_after_inactivity_threshold',
            $batchSize
        ));

        return is_array($contacts) ? $contacts : [];
    }

    public function findReengagementCandidate(int $contactId): ?object
    {
        global $wpdb;

        $enabledAt = get_option(self::REENGAGEMENT_ENABLED_AT_OPTION);
        if (!$this->isReengagementEnabled() || !is_string($enabledAt) || $enabledAt === '') {
            return null;
        }

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$contactTable}
             WHERE contact_id = %d
               AND subscription_status = %s
               AND inactivated_at > %s
               AND inactivation_reason = %s
               AND reengagement_sent_at IS NULL
               AND reengagement_confirmed_at IS NULL",
            absint($contactId),
            self::STATUS_INACTIVE,
            $enabledAt,
            'no_engagement_after_inactivity_threshold'
        ));
    }

    public function markReengagementSent(int $contactId, ?string $sentAt = null): void
    {
        global $wpdb;

        if (!$this->contactTableHasColumns(['reengagement_sent_at'])) {
            return;
        }

        $contactId = absint($contactId);
        if ($contactId <= 0) {
            return;
        }

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$contactTable}
             SET reengagement_sent_at = %s
             WHERE contact_id = %d
               AND reengagement_sent_at IS NULL",
            $this->normalizeDateTime($sentAt) ?? current_time('mysql'),
            $contactId
        ));
    }

    public function buildReengagementConfirmationUrl(object $contact): string
    {
        $accessToken = (string) ($contact->access_token ?? '');
        if ($accessToken === '') {
            return '';
        }

        return add_query_arg(
            [
                'action' => 'reengagement_confirm',
                'cid' => $accessToken,
                'token' => $this->generateReengagementToken($contact),
            ],
            mailerpress_get_page('manage_page')
        );
    }

    public function confirmReengagement(string $accessToken, string $token): ?object
    {
        global $wpdb;

        if (!$this->contactTableHasColumns([
            'last_subscribed_at',
            'inactivated_at',
            'inactivation_reason',
            'reengagement_sent_at',
            'reengagement_confirmed_at',
        ])) {
            return null;
        }

        $accessToken = sanitize_text_field($accessToken);
        $token = sanitize_text_field($token);
        if ($accessToken === '' || $token === '') {
            return null;
        }

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contact = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$contactTable} WHERE access_token = %s LIMIT 1",
            $accessToken
        ));

        if (!$contact || !hash_equals($this->generateReengagementToken($contact), $token)) {
            return null;
        }

        if ((string) ($contact->subscription_status ?? '') === 'subscribed') {
            return $contact;
        }

        if (
            (string) ($contact->subscription_status ?? '') !== self::STATUS_INACTIVE
            || empty($contact->reengagement_sent_at)
        ) {
            return null;
        }

        $now = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$contactTable}
             SET subscription_status = 'subscribed',
                 last_subscribed_at = %s,
                 inactivated_at = NULL,
                 inactivation_reason = NULL,
                 reengagement_confirmed_at = %s,
                 unsubscribe_token = %s
             WHERE contact_id = %d",
            $now,
            $now,
            wp_generate_uuid4(),
            (int) $contact->contact_id
        ));

        if (false === $updated) {
            return null;
        }

        $contactId = (int) $contact->contact_id;
        do_action('mailerpress_subscription_confirmed', $contactId);
        do_action('mailerpress_contact_updated', $contactId);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$contactTable} WHERE contact_id = %d",
            $contactId
        ));
    }

    public function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function markEngaged(int $contactId, string $column, ?string $engagedAt = null): void
    {
        global $wpdb;

        if (!in_array($column, ['last_open_at', 'last_click_at'], true)) {
            return;
        }

        if (!$this->contactTableHasColumns([$column, 'last_engagement_at', 'inactivated_at', 'inactivation_reason'])) {
            return;
        }

        $contactId = absint($contactId);
        if ($contactId <= 0) {
            return;
        }

        $engagedAt = $this->normalizeDateTime($engagedAt) ?? current_time('mysql');
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$contactTable}
             SET {$column} = IF({$column} IS NULL OR {$column} < %s, %s, {$column}),
                 last_engagement_at = IF(last_engagement_at IS NULL OR last_engagement_at < %s, %s, last_engagement_at)
             WHERE contact_id = %d",
            $engagedAt,
            $engagedAt,
            $engagedAt,
            $engagedAt,
            $contactId
        ));

        if ($column === 'last_click_at') {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$contactTable}
                 SET subscription_status = 'subscribed',
                     inactivated_at = NULL,
                     inactivation_reason = NULL
                 WHERE contact_id = %d
                   AND subscription_status = %s
                   AND inactivated_at < %s",
                $contactId,
                self::STATUS_INACTIVE,
                $engagedAt
            ));
        }
    }

    private function reactivateRecentlyEngagedContacts(string $thresholdDate, int $batchSize): int
    {
        global $wpdb;

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $now = current_time('mysql');

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$contactTable}
             SET subscription_status = 'subscribed',
                 inactivated_at = NULL,
                 inactivation_reason = NULL
             WHERE subscription_status = %s
               AND last_click_at IS NOT NULL
               AND last_click_at > COALESCE(inactivated_at, %s)
             ORDER BY contact_id ASC
             LIMIT %d",
            self::STATUS_INACTIVE,
            $thresholdDate,
            $batchSize
        ));

        if ($updated) {
            do_action('mailerpress_inactive_contacts_reactivated', (int) $updated, [
                'threshold_date' => $thresholdDate,
                'reactivated_at' => $now,
            ]);
        }

        return max(0, (int) $updated);
    }

    private function findInactiveCandidates(
        string $thresholdDate,
        int $batchSize,
        int $minEmailsSent
    ): array {
        global $wpdb;

        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT c.contact_id
             FROM {$contactTable} c
             WHERE c.subscription_status = 'subscribed'
               AND COALESCE(c.email_count, 0) >= %d
               AND c.last_sending_at >= %s
               AND COALESCE(c.last_subscribed_at, c.created_at) < %s
               AND GREATEST(
                    COALESCE(c.last_engagement_at, '1000-01-01 00:00:00'),
                    COALESCE(c.last_subscribed_at, '1000-01-01 00:00:00'),
                    COALESCE(c.created_at, '1000-01-01 00:00:00')
               ) < %s
             ORDER BY c.contact_id ASC
             LIMIT %d",
            $minEmailsSent,
            $thresholdDate,
            $thresholdDate,
            $thresholdDate,
            $batchSize
        ));

        return array_values(array_map('intval', $ids ?: []));
    }

    private function generateReengagementToken(object $contact): string
    {
        $secret = wp_salt('auth');
        $payload = implode('|', [
            (string) ($contact->contact_id ?? ''),
            (string) ($contact->email ?? ''),
            (string) ($contact->access_token ?? ''),
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
    }

    private function normalizePeriod(mixed $unit, mixed $value): array
    {
        $unit = is_string($unit) ? sanitize_key($unit) : self::DEFAULT_INACTIVE_PERIOD_UNIT;
        $unit = array_key_exists($unit, self::PERIOD_UNITS) ? $unit : self::DEFAULT_INACTIVE_PERIOD_UNIT;
        $periodUnit = self::PERIOD_UNITS[$unit];

        return [
            'unit' => $unit,
            'value' => max($periodUnit['min'], min($periodUnit['max'], (int) $value)),
        ];
    }

    private function inferPeriodFromDays(int $days): array
    {
        $days = max(1, min(3650, $days));

        foreach (['years', 'months', 'weeks', 'days'] as $unit) {
            $periodUnit = self::PERIOD_UNITS[$unit];
            $value = $days / $periodUnit['multiplier'];
            if (floor($value) === $value && $value >= $periodUnit['min'] && $value <= $periodUnit['max']) {
                return [
                    'unit' => $unit,
                    'value' => (int) $value,
                ];
            }
        }

        $closest = [
            'unit' => self::DEFAULT_INACTIVE_PERIOD_UNIT,
            'value' => self::DEFAULT_INACTIVE_PERIOD_VALUE,
            'diff' => PHP_INT_MAX,
        ];

        foreach (['years', 'months', 'weeks', 'days'] as $unit) {
            $periodUnit = self::PERIOD_UNITS[$unit];
            $value = max($periodUnit['min'], min($periodUnit['max'], (int) round($days / $periodUnit['multiplier'])));
            $diff = abs($this->periodToDays($unit, $value) - $days);
            if ($diff < $closest['diff']) {
                $closest = [
                    'unit' => $unit,
                    'value' => $value,
                    'diff' => $diff,
                ];
            }
        }

        unset($closest['diff']);

        return $closest;
    }

    private function periodToDays(string $unit, int $value): int
    {
        return $value * self::PERIOD_UNITS[$unit]['multiplier'];
    }

    private function contactTableHasColumns(array $columns): bool
    {
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        if (!$this->tableExists($contactTable)) {
            return false;
        }

        if (!isset(self::$columnCache[$contactTable])) {
            global $wpdb;
            self::$columnCache[$contactTable] = $wpdb->get_col("SHOW COLUMNS FROM {$contactTable}", 0) ?: [];
        }

        foreach ($columns as $column) {
            if (!in_array($column, self::$columnCache[$contactTable], true)) {
                return false;
            }
        }

        return true;
    }

    private function tableExists(string $tableName): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($tableName))) === $tableName;
    }
}
