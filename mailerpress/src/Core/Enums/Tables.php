<?php

declare(strict_types=1);

namespace MailerPress\Core\Enums;

\defined('ABSPATH') || exit;

class Tables
{
    public const MAILERPRESS_EMAIL_TRACKING = 'mailerpress_email_tracking';
    public const MAILERPRESS_CAMPAIGNS = 'mailerpress_campaigns';
    public const MAILERPRESS_CAMPAIGN_REVISIONS = 'mailerpress_campaigns_revisions';
    public const MAILERPRESS_CONTACT = 'mailerpress_contact';
    public const MAILERPRESS_CONTACT_NOTE = 'mailerpress_contact_note';
    public const CONTACT_TAGS = 'mailerpress_contact_tags';
    public const MAILERPRESS_EMAIL_BATCHES = 'mailerpress_email_batches';
    public const MAILERPRESS_EMAIL_CHUNKS = 'mailerpress_email_chunks';
    public const MAILERPRESS_TAGS = 'mailerpress_tags';
    public const MAILERPRESS_CONTACT_CUSTOM_FIELDS = 'mailerpress_contact_custom_fields';
    public const MAILERPRESS_EMAIL_QUEUE = 'mailerpress_email_queue';
    public const MAILERPRESS_CONTACT_BATCHES = 'mailerpress_contact_batches';
    public const MAILERPRESS_IMPORT_CONTACT_QUEUE = 'mailerpress_import_contact_queue';
    public const MAILERPRESS_IMPORT_CHUNKS = 'mailerpress_import_chunks';
    public const MAILERPRESS_LIST = 'mailerpress_lists';
    public const MAILERPRESS_CONTACT_LIST = 'mailerpress_contact_lists';
    public const MAILERPRESS_TEMPLATES = 'mailerpress_templates';
    public const MAILERPRESS_QUEUE_JOB = 'mailerpress_queue_job';
    public const MAILERPRESS_QUEUE_JOB_FAILURE = 'mailerpress_queue_job_failure';
    public const MAILERPRESS_AUTOMATIONS = 'mailerpress_automations';
    public const MAILERPRESS_AUTOMATIONS_STEPS = 'mailerpress_automations_steps';
    public const MAILERPRESS_AUTOMATIONS_STEP_BRANCHES = 'mailerpress_automations_branches';
    public const MAILERPRESS_AUTOMATIONS_JOBS = 'mailerpress_automations_jobs';
    public const MAILERPRESS_AUTOMATIONS_LOG = 'mailerpress_automations_log';
    public const MAILERPRESS_AUTOMATIONS_META = 'mailerpress_automations_meta';
    public const MAILERPRESS_TRACK_CART = 'mailerpress_track_cart';
    public const MAILERPRESS_PROVIDER_ACCOUNTS = 'mailerpress_provider_accounts';
    public const MAILERPRESS_PROVIDER_CONTACTS = 'mailerpress_provider_contacts';
    public const MAILERPRESS_PROVIDER_LISTS = 'mailerpress_provider_lists';
    public const MAILERPRESS_CAMPAIGN_STATS = 'mailerpress_campaign_stats';
    public const MAILERPRESS_CONTACT_STATS = 'mailerpress_contact_stats';
    public const MAILERPRESS_CLICK_TRACKING = 'mailerpress_click_tracking';
    public const MAILERPRESS_CATEGORIES = 'mailerpress_categories';
    public const MAILERPRESS_SEGMENTS = 'mailerpress_segments';
    public const MAILERPRESS_CUSTOM_FIELD_DEFINITIONS = 'mailerpress_cpt_definitions';
    public const MAILERPRESS_EMAIL_LOGS = 'mailerpress_email_logs';
    public const MAILERPRESS_EMBED_API_KEYS = 'mailerpress_embed_api_keys';
    public const MAILERPRESS_EMBED_RATE_LIMIT = 'mailerpress_embed_rate_limit';
    public const MAILERPRESS_AB_TESTS = 'mailerpress_ab_tests';
    public const MAILERPRESS_AB_TEST_PARTICIPANTS = 'mailerpress_ab_test_participants';
    public const MAILERPRESS_MIGRATIONS = 'mailerpress_migrations';

    public static function getAll(): array
    {
        return [
            self::get(self::MAILERPRESS_EMAIL_TRACKING),
            self::get(self::MAILERPRESS_CAMPAIGNS),
            self::get(self::MAILERPRESS_CAMPAIGN_REVISIONS),
            self::get(self::MAILERPRESS_CONTACT),
            self::get(self::MAILERPRESS_CONTACT_NOTE),
            self::get(self::CONTACT_TAGS),
            self::get(self::MAILERPRESS_EMAIL_BATCHES),
            self::get(self::MAILERPRESS_EMAIL_CHUNKS),
            self::get(self::MAILERPRESS_TAGS),
            self::get(self::MAILERPRESS_CONTACT_CUSTOM_FIELDS),
            self::get(self::MAILERPRESS_EMAIL_QUEUE),
            self::get(self::MAILERPRESS_CONTACT_BATCHES),
            self::get(self::MAILERPRESS_IMPORT_CONTACT_QUEUE),
            self::get(self::MAILERPRESS_IMPORT_CHUNKS),
            self::get(self::MAILERPRESS_LIST),
            self::get(self::MAILERPRESS_CONTACT_LIST),
            self::get(self::MAILERPRESS_TEMPLATES),
            self::get(self::MAILERPRESS_QUEUE_JOB),
            self::get(self::MAILERPRESS_QUEUE_JOB_FAILURE),
            self::get(self::MAILERPRESS_AUTOMATIONS),
            self::get(self::MAILERPRESS_AUTOMATIONS_STEPS),
            self::get(self::MAILERPRESS_AUTOMATIONS_STEP_BRANCHES),
            self::get(self::MAILERPRESS_AUTOMATIONS_JOBS),
            self::get(self::MAILERPRESS_AUTOMATIONS_LOG),
            self::get(self::MAILERPRESS_AUTOMATIONS_META),
            self::get(self::MAILERPRESS_TRACK_CART),
            self::get(self::MAILERPRESS_PROVIDER_ACCOUNTS),
            self::get(self::MAILERPRESS_PROVIDER_CONTACTS),
            self::get(self::MAILERPRESS_PROVIDER_LISTS),
            self::get(self::MAILERPRESS_CAMPAIGN_STATS),
            self::get(self::MAILERPRESS_CONTACT_STATS),
            self::get(self::MAILERPRESS_CLICK_TRACKING),
            self::get(self::MAILERPRESS_CATEGORIES),
            self::get(self::MAILERPRESS_SEGMENTS),
            self::get(self::MAILERPRESS_CUSTOM_FIELD_DEFINITIONS),
            self::get(self::MAILERPRESS_EMAIL_LOGS),
            self::get(self::MAILERPRESS_EMBED_API_KEYS),
            self::get(self::MAILERPRESS_EMBED_RATE_LIMIT),
            self::get(self::MAILERPRESS_AB_TESTS),
            self::get(self::MAILERPRESS_AB_TEST_PARTICIPANTS),
            self::get(self::MAILERPRESS_MIGRATIONS),
        ];
    }

    /**
     * In-memory cache for table existence checks
     * @var array<string, bool>
     */
    private static array $tableExistsCache = [];

    /**
     * @param mixed $value
     *
     * @return string|void
     */
    public static function get($value): string
    {
        global $wpdb;

        if (!empty($value)) {
            return \sprintf('%s%s', $wpdb->prefix, $value);
        }

        return '';
    }

    /**
     * Check if a table exists with in-memory caching.
     * Avoids repeated SHOW TABLES LIKE queries within the same request.
     */
    public static function exists(string $tableName): bool
    {
        if (isset(self::$tableExistsCache[$tableName])) {
            return self::$tableExistsCache[$tableName];
        }

        global $wpdb;
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$tableName}'") === $tableName;
        self::$tableExistsCache[$tableName] = $result;

        return $result;
    }
}
