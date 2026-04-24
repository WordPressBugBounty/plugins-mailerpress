<?php

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Models\Batch;

class Dashboard
{
    #[Endpoint(
        'dashboard/campaigns',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function campaignByIntervalDate(\WP_REST_Request $request)
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $interval = (int) $request->get_param('interval') ?: 30;

        $query = $wpdb->prepare("
        SELECT *
        FROM $table
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
          AND (status IS NULL OR status != %s)
          AND (campaign_type IS NULL OR campaign_type = %s)
        ORDER BY created_at DESC
        LIMIT 5
    ", $interval, 'trash', 'newsletter');

        $results = $wpdb->get_results($query);

        // ✅ Optimisation: Précharger tous les batches et statistiques
        $batch_ids = array_filter(array_map(fn($r) => (int)$r->batch_id, $results));

        if (!empty($batch_ids)) {
            $batch_placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));
            $batches_table = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
            $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
            $campaignStatsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGN_STATS);

            // ✅ Optimisation: Récupérer tous les batches en une seule requête (campaign_id est déjà inclus)
            $batches = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$batches_table} WHERE id IN ($batch_placeholders)",
                    ...$batch_ids
                ),
                ARRAY_A
            );
            $batches_map = [];
            $batch_to_campaign = [];
            $campaign_ids_for_stats = [];

            // Extraire campaign_id directement des batches récupérés
            foreach ($batches as $batch) {
                $batch_id = (int)$batch['id'];
                $batches_map[$batch_id] = $batch;
                $campaign_id = !empty($batch['campaign_id']) ? (int)$batch['campaign_id'] : null;
                if ($campaign_id) {
                    $batch_to_campaign[$batch_id] = $campaign_id;
                    $campaign_ids_for_stats[] = $campaign_id;
                }
            }

            // Éliminer les doublons de campaign_ids
            $campaign_ids_for_stats = array_unique($campaign_ids_for_stats);

            // Statistiques de tracking
            // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
            $clickTrackingTable = Tables::get(Tables::MAILERPRESS_CLICK_TRACKING);
            $tracking_stats = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT batch_id,
                        COALESCE(COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND contact_id > 0 THEN contact_id END), 0) +
                        COALESCE(COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0) AS total_opens,
                        COALESCE(COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL AND contact_id > 0 THEN contact_id END), 0) +
                        COALESCE(COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0) AS total_unsubscribes
                     FROM {$trackingTable}
                     WHERE batch_id IN ($batch_placeholders)
                     GROUP BY batch_id",
                    ...$batch_ids
                ),
                ARRAY_A
            );

            // Click stats from click_tracking table (supports anonymous clicks)
            $click_stats_map = [];
            if (!empty($campaign_ids_for_stats)) {
                $campaign_placeholders = implode(',', array_fill(0, count($campaign_ids_for_stats), '%d'));
                $click_stats = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT campaign_id,
                            COALESCE(COUNT(DISTINCT CASE WHEN contact_id > 0 THEN CONCAT(contact_id, '|', url) END), 0) +
                            COALESCE(COUNT(DISTINCT CASE WHEN contact_id = 0 AND anonymous_key IS NOT NULL THEN CONCAT(anonymous_key, '|', url) END), 0) AS total_clicks
                         FROM {$clickTrackingTable}
                         WHERE campaign_id IN ($campaign_placeholders)
                         GROUP BY campaign_id",
                        ...$campaign_ids_for_stats
                    ),
                    ARRAY_A
                );
                foreach ($click_stats as $cs) {
                    $click_stats_map[$cs['campaign_id']] = (int)$cs['total_clicks'];
                }
            }

            // Statistiques de campagne
            $campaign_stats = [];
            if (!empty($campaign_ids_for_stats)) {
                $campaign_placeholders = implode(',', array_fill(0, count($campaign_ids_for_stats), '%d'));
                $campaign_stats = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT campaign_id, total_click AS campaign_total_click, total_revenue AS campaign_total_revenue
                         FROM {$campaignStatsTable}
                         WHERE campaign_id IN ($campaign_placeholders)",
                        ...$campaign_ids_for_stats
                    ),
                    ARRAY_A
                );
            }
            $campaign_stats_map = [];
            foreach ($campaign_stats as $cs) {
                $campaign_stats_map[$cs['campaign_id']] = $cs;
            }

            // Combiner les statistiques — itérer sur tous les batch_ids pour capturer
            // les cas où email_tracking n'a aucune ligne (ex: opens désactivés, clicks anonymes uniquement)
            $tracking_stats_map = [];
            foreach ($tracking_stats as $ts) {
                $tracking_stats_map[(int)$ts['batch_id']] = $ts;
            }

            $statistics_map = [];
            foreach ($batch_ids as $batch_id) {
                $batch_id = (int)$batch_id;
                $campaign_id = $batch_to_campaign[$batch_id] ?? null;
                $ts = $tracking_stats_map[$batch_id] ?? null;
                $total_clicks = $campaign_id && isset($click_stats_map[$campaign_id])
                    ? $click_stats_map[$campaign_id]
                    : 0;
                $statistics_map[$batch_id] = array_merge(
                    [
                        'total_opens' => $ts ? (int)$ts['total_opens'] : 0,
                        'total_clicks' => $total_clicks,
                        'total_unsubscribes' => $ts ? (int)$ts['total_unsubscribes'] : 0,
                    ],
                    $campaign_id && isset($campaign_stats_map[$campaign_id]) ? $campaign_stats_map[$campaign_id] : []
                );
            }

            // Appliquer les données préchargées
            foreach ($results as &$result) {
                if (!empty($result->batch_id) && isset($batches_map[$result->batch_id])) {
                    $batch_data = $batches_map[$result->batch_id];
                    if (isset($statistics_map[$result->batch_id])) {
                        $batch_data = array_merge($batch_data, $statistics_map[$result->batch_id]);
                    }
                    $result->batch = $batch_data;
                    $result->statistics = $statistics_map[$result->batch_id] ?? null;
                } else {
                    $result->batch = null;
                    $result->statistics = null;
                }
            }
        } else {
            foreach ($results as &$result) {
                $result->batch = null;
                $result->statistics = null;
            }
        }

        return rest_ensure_response($results);
    }


    #[Endpoint(
        'dashboard/email-batches-by-date',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function emailBatchesByDateRange(\WP_REST_Request $request)
    {
        global $wpdb;

        // Define the table for email batches
        $table = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $campaignTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Fetch start_date and end_date params from the request
        $startDateParam = $request->get_param('start_date');
        $endDateParam = $request->get_param('end_date');

        // Ensure the date format is compatible with MySQL
        try {
            // Convert to DateTime objects, handling both timestamp and date formats
            $startDate = is_numeric($startDateParam) ? new \DateTime('@' . (int)$startDateParam) : new \DateTime($startDateParam);
            $endDate = is_numeric($endDateParam) ? new \DateTime('@' . (int)$endDateParam) : new \DateTime($endDateParam);

            // Set the time for start date to 00:00:00 (beginning of the day)
            $startDate->setTime(0, 0, 0);

            // Set the time for end date to 23:59:59 (end of the day)
            $endDate->setTime(23, 59, 59);

            // Format the dates to MySQL-compatible format
            $startDateFormatted = $startDate->format('Y-m-d H:i:s');
            $endDateFormatted = $endDate->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return rest_ensure_response([
                'message' => 'Invalid date format provided.',
                'error' => $e->getMessage(),
            ]);
        }

        $query = $wpdb->prepare("
    SELECT
        b.id,
        b.campaign_id,
        b.created_at,
        b.updated_at,
        b.total_emails,
        b.total_open,
        b.sent_emails,
        b.error_emails,
        b.error_message,
        b.scheduled_at,
        b.sender_name,
        b.sender_to,
        b.subject,
        b.offset,
        c.content_html,
        c.name,
        c.subject AS campaign_subject,
        c.status
    FROM $table b
    LEFT JOIN $campaignTable c ON b.campaign_id = c.campaign_id
    WHERE b.scheduled_at >= %s
      AND b.scheduled_at <= %s
      AND c.status != 'draft'
      AND c.status != 'trash'
    ORDER BY b.scheduled_at DESC
", $startDateFormatted, $endDateFormatted);


        // Execute query and get results
        $results = $wpdb->get_results($query);

        // ✅ Optimisation: Précharger tous les batches et statistiques
        $batch_ids = array_filter(array_map(fn($r) => (int)$r->id, $results));

        if (!empty($batch_ids)) {
            $batch_placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));
            $batches_table = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
            $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
            $campaignStatsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGN_STATS);

            // ✅ Optimisation: Récupérer tous les batches en une seule requête (campaign_id est déjà inclus)
            $batches = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$batches_table} WHERE id IN ($batch_placeholders)",
                    ...$batch_ids
                ),
                ARRAY_A
            );
            $batches_map = [];
            $batch_to_campaign = [];
            $campaign_ids_for_stats = [];

            // Extraire campaign_id directement des batches récupérés
            foreach ($batches as $batch) {
                $batch_id = (int)$batch['id'];
                $batches_map[$batch_id] = $batch;
                $campaign_id = !empty($batch['campaign_id']) ? (int)$batch['campaign_id'] : null;
                if ($campaign_id) {
                    $batch_to_campaign[$batch_id] = $campaign_id;
                    $campaign_ids_for_stats[] = $campaign_id;
                }
            }

            // Éliminer les doublons de campaign_ids
            $campaign_ids_for_stats = array_unique($campaign_ids_for_stats);

            // Statistiques de tracking
            // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
            $clickTrackingTable = Tables::get(Tables::MAILERPRESS_CLICK_TRACKING);
            $tracking_stats = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT batch_id,
                        COALESCE(COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND contact_id > 0 THEN contact_id END), 0) +
                        COALESCE(COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0) AS total_opens,
                        COALESCE(COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL AND contact_id > 0 THEN contact_id END), 0) +
                        COALESCE(COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0) AS total_unsubscribes
                     FROM {$trackingTable}
                     WHERE batch_id IN ($batch_placeholders)
                     GROUP BY batch_id",
                    ...$batch_ids
                ),
                ARRAY_A
            );

            // Click stats from click_tracking table (supports anonymous clicks)
            $click_stats_map = [];
            if (!empty($campaign_ids_for_stats)) {
                $campaign_placeholders = implode(',', array_fill(0, count($campaign_ids_for_stats), '%d'));
                $click_stats = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT campaign_id,
                            COALESCE(COUNT(DISTINCT CASE WHEN contact_id > 0 THEN CONCAT(contact_id, '|', url) END), 0) +
                            COALESCE(COUNT(DISTINCT CASE WHEN contact_id = 0 AND anonymous_key IS NOT NULL THEN CONCAT(anonymous_key, '|', url) END), 0) AS total_clicks
                         FROM {$clickTrackingTable}
                         WHERE campaign_id IN ($campaign_placeholders)
                         GROUP BY campaign_id",
                        ...$campaign_ids_for_stats
                    ),
                    ARRAY_A
                );
                foreach ($click_stats as $cs) {
                    $click_stats_map[$cs['campaign_id']] = (int)$cs['total_clicks'];
                }
            }

            // Statistiques de campagne
            $campaign_stats = [];
            if (!empty($campaign_ids_for_stats)) {
                $campaign_placeholders = implode(',', array_fill(0, count($campaign_ids_for_stats), '%d'));
                $campaign_stats = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT campaign_id, total_click AS campaign_total_click, total_revenue AS campaign_total_revenue
                         FROM {$campaignStatsTable}
                         WHERE campaign_id IN ($campaign_placeholders)",
                        ...$campaign_ids_for_stats
                    ),
                    ARRAY_A
                );
            }
            $campaign_stats_map = [];
            foreach ($campaign_stats as $cs) {
                $campaign_stats_map[$cs['campaign_id']] = $cs;
            }

            // Combiner les statistiques — itérer sur tous les batch_ids pour capturer
            // les cas où email_tracking n'a aucune ligne (ex: opens désactivés, clicks anonymes uniquement)
            $tracking_stats_map = [];
            foreach ($tracking_stats as $ts) {
                $tracking_stats_map[(int)$ts['batch_id']] = $ts;
            }

            $statistics_map = [];
            foreach ($batch_ids as $batch_id) {
                $batch_id = (int)$batch_id;
                $campaign_id = $batch_to_campaign[$batch_id] ?? null;
                $ts = $tracking_stats_map[$batch_id] ?? null;
                $total_clicks = $campaign_id && isset($click_stats_map[$campaign_id])
                    ? $click_stats_map[$campaign_id]
                    : 0;
                $statistics_map[$batch_id] = array_merge(
                    [
                        'total_opens' => $ts ? (int)$ts['total_opens'] : 0,
                        'total_clicks' => $total_clicks,
                        'total_unsubscribes' => $ts ? (int)$ts['total_unsubscribes'] : 0,
                    ],
                    $campaign_id && isset($campaign_stats_map[$campaign_id]) ? $campaign_stats_map[$campaign_id] : []
                );
            }

            // Appliquer les données préchargées
            foreach ($results as &$result) {
                if (!empty($result->id) && isset($batches_map[$result->id])) {
                    $batch_data = $batches_map[$result->id];
                    if (isset($statistics_map[$result->id])) {
                        $batch_data = array_merge($batch_data, $statistics_map[$result->id]);
                    }
                    $result->batch = $batch_data;
                    $result->statistics = $statistics_map[$result->id] ?? null;
                } else {
                    $result->batch = null;
                    $result->statistics = null;
                }
            }
        } else {
            foreach ($results as &$result) {
                $result->batch = null;
                $result->statistics = null;
            }
        }

        return rest_ensure_response($results);
    }


    #[Endpoint(
        'dashboard/contacts',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function contactsByIntervalDate(\WP_REST_Request $request)
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CONTACT);
        $interval = (int)$request->get_param('interval') ?: 30;

        // 1. Total contacts (global)
        $total_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");

        // 2. Filtered count by updated_at
        $filtered_count_query = $wpdb->prepare("
        SELECT COUNT(*)
        FROM $table
        WHERE updated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
    ", $interval);
        $filtered_count = (int)$wpdb->get_var($filtered_count_query);

        // 3. Subscribed contacts count within interval
        $subscribed_count_query = $wpdb->prepare("
        SELECT COUNT(*)
        FROM $table
        WHERE subscription_status = 'subscribed'
          AND updated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
    ", $interval);
        $subscribed_count = (int)$wpdb->get_var($subscribed_count_query);

        // 4. Unsubscribed contacts count within interval
        $unsubscribed_count_query = $wpdb->prepare("
        SELECT COUNT(*)
        FROM $table
        WHERE subscription_status = 'unsubscribed'
          AND updated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
    ", $interval);
        $unsubscribed_count = (int)$wpdb->get_var($unsubscribed_count_query);

        // 5. Latest 5 contacts updated in interval
        $contacts_query = $wpdb->prepare("
        SELECT contact_id, email, first_name, last_name, subscription_status, updated_at
        FROM $table
        WHERE updated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
        ORDER BY updated_at DESC
        LIMIT 5
    ", $interval);
        $contacts = $wpdb->get_results($contacts_query);

        return rest_ensure_response([
            'total_count' => $total_count,
            'filtered_count' => $filtered_count,
            'subscribed_count' => $subscribed_count,
            'unsubscribed_count' => $unsubscribed_count,
            'contacts' => $contacts,
        ]);
    }

    #[Endpoint(
        'dashboard/click-rate',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function clickRate(\WP_REST_Request $request)
    {
        global $wpdb;

        $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $clickTrackingTable = Tables::get(Tables::MAILERPRESS_CLICK_TRACKING);
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Période actuelle : 30 derniers jours
        $currentInterval = 30;
        // Période précédente : 30 jours avant (jours 31-60)
        $previousInterval = 30;

        // Calcul pour la période actuelle (30 derniers jours)
        // Total d'emails envoyés
        $currentSent = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COALESCE(SUM(b.sent_emails), 0)
                FROM {$batchesTable} b
                INNER JOIN {$campaignsTable} c ON b.campaign_id = c.campaign_id
                WHERE b.scheduled_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND b.scheduled_at < NOW()
                  AND c.status != 'draft'
                  AND c.status != 'trash'
            ", $currentInterval)
        );

        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        $currentClicks = (int)$wpdb->get_var("
            SELECT
                COALESCE(COUNT(DISTINCT CASE WHEN contact_id > 0 THEN CONCAT(contact_id, '|', url) END), 0) +
                COALESCE(COUNT(DISTINCT CASE WHEN contact_id = 0 AND anonymous_key IS NOT NULL THEN CONCAT(anonymous_key, '|', url) END), 0)
            FROM {$clickTrackingTable}
        ");

        // Total de clics pour la période précédente (toujours 0 car on ne filtre plus par date)
        $previousClicks = 0;

        // Calcul du changement en nombre absolu
        $change = $currentClicks - $previousClicks;

        $response = [
            'rate' => $currentClicks, // Retourner le nombre total de clics au lieu d'un pourcentage
            'change' => $change, // Changement en nombre absolu
        ];

        return rest_ensure_response($response);
    }

    #[Endpoint(
        'dashboard/open-rate',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function openRate(\WP_REST_Request $request)
    {
        global $wpdb;

        $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Période actuelle : 30 derniers jours
        $currentInterval = 30;
        // Période précédente : 30 jours avant (jours 31-60)
        $previousInterval = 30;

        // Calcul pour la période actuelle (30 derniers jours)
        // Total d'emails envoyés (uniquement les campagnes avec sent_emails > 0)
        $currentSent = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COALESCE(SUM(b.sent_emails), 0)
                FROM {$batchesTable} b
                INNER JOIN {$campaignsTable} c ON b.campaign_id = c.campaign_id
                WHERE b.scheduled_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND b.scheduled_at < NOW()
                  AND b.sent_emails > 0
                  AND c.status != 'draft'
                  AND c.status != 'trash'
            ", $currentInterval)
        );

        // Total d'ouvertures (compter les contacts distincts qui ont ouvert)
        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        // Utiliser des sous-requêtes séparées car COUNT(DISTINCT CASE WHEN ...) peut ne pas fonctionner correctement
        $identifiedOpens = (int)$wpdb->get_var("
            SELECT COUNT(DISTINCT contact_id)
            FROM {$trackingTable}
            WHERE opened_at IS NOT NULL
              AND contact_id > 0
        ");

        $anonymousOpens = (int)$wpdb->get_var("
            SELECT COUNT(DISTINCT anonymous_key)
            FROM {$trackingTable}
            WHERE opened_at IS NOT NULL
              AND contact_id = 0
              AND anonymous_key IS NOT NULL
        ");

        $currentOpens = $identifiedOpens + $anonymousOpens;

        // Total d'ouvertures pour la période précédente (toujours 0 car on ne filtre plus par date)
        $previousOpens = 0;

        // Calcul du changement en nombre absolu
        $change = $currentOpens - $previousOpens;

        $response = [
            'rate' => $currentOpens, // Retourner le nombre total d'ouvertures au lieu d'un pourcentage
            'change' => $change, // Changement en nombre absolu
        ];

        return rest_ensure_response($response);
    }

    #[Endpoint(
        'dashboard/active-campaigns',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function activeCampaigns(\WP_REST_Request $request)
    {
        global $wpdb;

        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Période actuelle : 30 derniers jours
        $currentInterval = 30;
        // Période précédente : 30 jours avant (jours 31-60)
        $previousInterval = 30;

        // Compter les campagnes actives pour la période actuelle
        $currentCount = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COUNT(DISTINCT campaign_id)
                FROM {$campaignsTable}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND (status IS NULL OR (status != 'draft' AND status != 'trash'))
            ", $currentInterval)
        );

        // Compter les campagnes actives pour la période précédente
        $previousCount = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COUNT(DISTINCT campaign_id)
                FROM {$campaignsTable}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND (status IS NULL OR (status != 'draft' AND status != 'trash'))
            ", $currentInterval + $previousInterval, $currentInterval)
        );

        // Calcul du changement en pourcentage
        $change = 0;
        if ($previousCount > 0) {
            $change = (($currentCount - $previousCount) / $previousCount) * 100;
        } elseif ($currentCount > 0) {
            $change = 100; // Nouvelles campagnes, considéré comme +100%
        }

        return rest_ensure_response([
            'count' => $currentCount,
            'change' => round($change, 2),
        ]);
    }

    #[Endpoint(
        'dashboard/contacts-summary',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function contactsSummary(\WP_REST_Request $request)
    {
        global $wpdb;

        $contactsTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        // Période actuelle : 30 derniers jours
        $currentInterval = 30;
        // Période précédente : 30 jours avant (jours 31-60)
        $previousInterval = 30;

        // Total actuel (global)
        $currentTotal = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$contactsTable}");

        // Total pour la période précédente (il y a 30 jours)
        $previousTotal = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COUNT(*)
                FROM {$contactsTable}
                WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
            ", $currentInterval)
        );

        // Calcul du changement en pourcentage
        $change = 0;
        if ($previousTotal > 0) {
            $change = (($currentTotal - $previousTotal) / $previousTotal) * 100;
        } elseif ($currentTotal > 0) {
            $change = 100; // Nouveaux contacts, considéré comme +100%
        }

        // Total bounced contacts
        $bouncedTotal = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$contactsTable} WHERE subscription_status = 'bounced'");

        return rest_ensure_response([
            'total_count' => $currentTotal,
            'change' => round($change, 2),
            'bounced' => $bouncedTotal,
        ]);
    }

    #[Endpoint(
        'dashboard/email-performance',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function emailPerformance(\WP_REST_Request $request)
    {
        global $wpdb;

        $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Récupérer la période depuis les paramètres (par défaut 7 jours)
        $interval = (int)$request->get_param('interval') ?: 7;

        // Total d'emails envoyés dans la période
        $totalSent = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COALESCE(SUM(b.sent_emails), 0)
                FROM {$batchesTable} b
                INNER JOIN {$campaignsTable} c ON b.campaign_id = c.campaign_id
                WHERE b.scheduled_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND b.scheduled_at < NOW()
                  AND b.sent_emails > 0
                  AND c.status != 'draft'
                  AND c.status != 'trash'
            ", $interval)
        );

        // Total d'emails prévus (total_emails)
        $totalEmails = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COALESCE(SUM(b.total_emails), 0)
                FROM {$batchesTable} b
                INNER JOIN {$campaignsTable} c ON b.campaign_id = c.campaign_id
                WHERE b.scheduled_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND b.scheduled_at < NOW()
                  AND c.status != 'draft'
                  AND c.status != 'trash'
            ", $interval)
        );

        // Total d'ouvertures (contacts distincts qui ont ouvert)
        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        $totalOpens = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT
                    COALESCE(COUNT(DISTINCT CASE WHEN t.contact_id > 0 THEN t.contact_id END), 0) +
                    COALESCE(COUNT(DISTINCT CASE WHEN t.contact_id = 0 AND t.anonymous_key IS NOT NULL THEN t.anonymous_key END), 0)
                FROM {$trackingTable} t
                INNER JOIN {$batchesTable} b ON t.batch_id = b.id
                INNER JOIN {$campaignsTable} c ON b.campaign_id = c.campaign_id
                WHERE b.scheduled_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND b.scheduled_at < NOW()
                  AND t.opened_at IS NOT NULL
                  AND b.sent_emails > 0
                  AND c.status != 'draft'
                  AND c.status != 'trash'
            ", $interval)
        );

        // Total de désabonnements
        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        $totalUnsubscribes = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT
                    COALESCE(COUNT(DISTINCT CASE WHEN t.contact_id > 0 THEN t.contact_id END), 0) +
                    COALESCE(COUNT(DISTINCT CASE WHEN t.contact_id = 0 AND t.anonymous_key IS NOT NULL THEN t.anonymous_key END), 0)
                FROM {$trackingTable} t
                INNER JOIN {$batchesTable} b ON t.batch_id = b.id
                INNER JOIN {$campaignsTable} c ON b.campaign_id = c.campaign_id
                WHERE b.scheduled_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                  AND b.scheduled_at < NOW()
                  AND t.unsubscribed_at IS NOT NULL
                  AND b.sent_emails > 0
                  AND c.status != 'draft'
                  AND c.status != 'trash'
            ", $interval)
        );

        // Calcul des pourcentages
        $deliveredRate = $totalEmails > 0 ? ($totalSent / $totalEmails) * 100 : 0;
        $openedRate = $totalSent > 0 ? ($totalOpens / $totalSent) * 100 : 0;
        $unsubscribedRate = $totalSent > 0 ? ($totalUnsubscribes / $totalSent) * 100 : 0;

        return rest_ensure_response([
            'total_sent' => $totalSent,
            'total_emails' => $totalEmails,
            'total_opens' => $totalOpens,
            'total_unsubscribes' => $totalUnsubscribes,
            'delivered_rate' => round($deliveredRate, 1),
            'opened_rate' => round($openedRate, 1),
            'unsubscribed_rate' => round($unsubscribedRate, 1),
        ]);
    }

    #[Endpoint(
        'dashboard/planned-campaigns',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function plannedCampaigns(\WP_REST_Request $request)
    {
        global $wpdb;

        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $limit = (int)$request->get_param('limit') ?: 5;

        // Récupérer toutes les campagnes draft et scheduled
        // Pour scheduled: récupérer celles avec un batch et scheduled_at
        // Pour draft: récupérer toutes les drafts (avec ou sans batch)
        $query = $wpdb->prepare("
            SELECT
                c.campaign_id,
                c.name,
                c.status,
                c.created_at,
                b.scheduled_at,
                b.total_emails,
                b.sent_emails,
                b.id AS batch_id
            FROM {$campaignsTable} c
            LEFT JOIN {$batchesTable} b ON c.batch_id = b.id
            WHERE (
                (c.status = 'scheduled' AND b.scheduled_at IS NOT NULL)
                OR (c.status = 'draft')
            )
            AND c.status != 'trash'
            ORDER BY COALESCE(b.scheduled_at, c.created_at) ASC
            LIMIT %d
        ", $limit);

        $results = $wpdb->get_results($query);

        // Formater les résultats
        $campaigns = [];
        foreach ($results as $row) {
            $campaigns[] = [
                'campaign_id' => (int)$row->campaign_id,
                'name' => $row->name,
                'status' => $row->status,
                'scheduled_at' => $row->scheduled_at,
                'total_emails' => $row->total_emails ? (int)$row->total_emails : null,
                'sent_emails' => $row->sent_emails ? (int)$row->sent_emails : null,
                'batch' => [
                    'scheduled_at' => $row->scheduled_at,
                    'total_emails' => $row->total_emails ? (int)$row->total_emails : null,
                ],
            ];
        }

        return rest_ensure_response([
            'campaigns' => $campaigns,
        ]);
    }

    #[Endpoint(
        'dashboard/unsubscribe-rate',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function unsubscribeRate(\WP_REST_Request $request)
    {
        global $wpdb;

        $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Période actuelle : 30 derniers jours
        $currentInterval = 30;
        // Période précédente : 30 jours avant (jours 31-60)
        $previousInterval = 30;

        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        $currentUnsubscribes = (int)$wpdb->get_var("
            SELECT
                COALESCE(COUNT(DISTINCT CASE WHEN contact_id > 0 THEN contact_id END), 0) +
                COALESCE(COUNT(DISTINCT CASE WHEN contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0)
            FROM {$trackingTable}
            WHERE unsubscribed_at IS NOT NULL
        ");

        // Total de désabonnements pour la période précédente (toujours 0 car on ne filtre plus par date)
        $previousUnsubscribes = 0;

        // Calcul du changement en nombre absolu
        $change = $currentUnsubscribes - $previousUnsubscribes;

        $response = [
            'rate' => $currentUnsubscribes, // Retourner le nombre total de désabonnements au lieu d'un pourcentage
            'change' => $change, // Changement en nombre absolu
        ];

        return rest_ensure_response($response);
    }

    #[Endpoint(
        'dashboard/recent-campaigns',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function recentCampaigns(\WP_REST_Request $request)
    {
        global $wpdb;

        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $limit = (int)$request->get_param('limit') ?: 5;

        $query = $wpdb->prepare("
            SELECT
                c.campaign_id,
                c.name,
                c.status,
                c.created_at,
                b.id AS batch_id,
                b.scheduled_at,
                b.total_emails,
                b.sent_emails,
                b.created_at AS batch_created_at
            FROM {$campaignsTable} c
            INNER JOIN {$batchesTable} b ON c.batch_id = b.id
            WHERE c.status = 'sent'
            AND c.status != 'trash'
            AND b.sent_emails > 0
            ORDER BY b.created_at DESC
            LIMIT %d
        ", $limit);

        $results = $wpdb->get_results($query);

        if (empty($results)) {
            return rest_ensure_response([
                'campaigns' => [],
            ]);
        }

        // Récupérer les batch_ids pour les statistiques
        $batch_ids = array_filter(array_map(fn($r) => (int)$r->batch_id, $results));
        $batch_placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));

        // Récupérer les campaign_ids pour chaque batch
        $batch_campaigns = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, campaign_id FROM {$batchesTable} WHERE id IN ($batch_placeholders)",
                ...$batch_ids
            ),
            ARRAY_A
        );
        $batch_to_campaign = [];
        $campaign_ids_for_clicks = [];
        foreach ($batch_campaigns as $bc) {
            $batch_to_campaign[(int)$bc['id']] = (int)$bc['campaign_id'];
            if ($bc['campaign_id']) {
                $campaign_ids_for_clicks[] = (int)$bc['campaign_id'];
            }
        }

        // Récupérer les statistiques de tracking (opens et unsubscribes)
        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        $tracking_stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT batch_id,
                    COALESCE(COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND contact_id > 0 THEN contact_id END), 0) +
                    COALESCE(COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0) AS total_opens,
                    COALESCE(COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL AND contact_id > 0 THEN contact_id END), 0) +
                    COALESCE(COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0) AS total_unsubscribes
                 FROM {$trackingTable}
                 WHERE batch_id IN ($batch_placeholders)
                 GROUP BY batch_id",
                ...$batch_ids
            ),
            ARRAY_A
        );

        // Récupérer les statistiques de clics depuis mailerpress_click_tracking par campagne
        $clickTrackingTable = Tables::get(Tables::MAILERPRESS_CLICK_TRACKING);
        $click_stats = [];
        if (!empty($campaign_ids_for_clicks)) {
            $campaign_placeholders = implode(',', array_fill(0, count($campaign_ids_for_clicks), '%d'));
            // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
            $click_stats = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT campaign_id,
                        COALESCE(COUNT(DISTINCT CASE WHEN contact_id > 0 THEN CONCAT(contact_id, '|', url) END), 0) +
                        COALESCE(COUNT(DISTINCT CASE WHEN contact_id = 0 AND anonymous_key IS NOT NULL THEN CONCAT(anonymous_key, '|', url) END), 0) AS total_clicks
                     FROM {$clickTrackingTable}
                     WHERE campaign_id IN ($campaign_placeholders)
                     GROUP BY campaign_id",
                    ...$campaign_ids_for_clicks
                ),
                ARRAY_A
            );
        }
        $click_stats_map = [];
        foreach ($click_stats as $cs) {
            $click_stats_map[(int)$cs['campaign_id']] = (int)$cs['total_clicks'];
        }

        // Créer un map des statistiques par batch_id
        $statistics_map = [];
        foreach ($tracking_stats as $ts) {
            $batch_id = (int)$ts['batch_id'];
            $campaign_id = $batch_to_campaign[$batch_id] ?? null;

            // Récupérer les clics depuis click_tracking pour cette campagne
            $total_clicks = $campaign_id && isset($click_stats_map[$campaign_id])
                ? $click_stats_map[$campaign_id]
                : 0;

            $statistics_map[$batch_id] = [
                'total_opens' => (int)$ts['total_opens'],
                'total_clicks' => $total_clicks,
                'total_unsubscribes' => (int)$ts['total_unsubscribes'],
            ];
        }

        // Formater les résultats
        $campaigns = [];
        foreach ($results as $row) {
            $batch_id = (int)$row->batch_id;
            $campaigns[] = [
                'campaign_id' => (int)$row->campaign_id,
                'name' => $row->name,
                'status' => $row->status,
                'sent_at' => $row->batch_created_at,
                'created_at' => $row->created_at,
                'total_emails' => $row->total_emails ? (int)$row->total_emails : null,
                'sent_emails' => $row->sent_emails ? (int)$row->sent_emails : null,
                'statistics' => $statistics_map[$batch_id] ?? [
                    'total_opens' => 0,
                    'total_clicks' => 0,
                    'total_unsubscribes' => 0,
                ],
                'batch' => [
                    'scheduled_at' => $row->scheduled_at,
                    'total_emails' => $row->total_emails ? (int)$row->total_emails : null,
                ],
            ];
        }

        return rest_ensure_response([
            'campaigns' => $campaigns,
        ]);
    }

    #[Endpoint(
        'dashboard/contact-growth',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function contactGrowth(\WP_REST_Request $request)
    {
        global $wpdb;

        $contactsTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $interval = (int)$request->get_param('interval') ?: 30;

        // Calculer le total de croissance (nouveaux contacts dans la période)
        $totalGrowth = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COUNT(*)
                FROM {$contactsTable}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            ", $interval)
        );

        // Récupérer le total de contacts avant la période pour calculer le cumulé
        $totalBeforePeriod = (int)$wpdb->get_var(
            $wpdb->prepare("
                SELECT COUNT(*)
                FROM {$contactsTable}
                WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
            ", $interval)
        );

        // Récupérer les nouveaux contacts par jour
        // Utiliser DATE_FORMAT pour s'assurer d'un format de date cohérent
        // Exclure les contacts avec created_at NULL
        $query = $wpdb->prepare("
            SELECT
                DATE(created_at) as date,
                COUNT(DISTINCT contact_id) as new_contacts
            FROM {$contactsTable}
            WHERE created_at IS NOT NULL
              AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", $interval);

        $results = $wpdb->get_results($query);

        // Formater les résultats avec le total cumulé
        // S'assurer que chaque date n'apparaît qu'une seule fois
        $dataByDate = [];
        foreach ($results as $row) {
            $dateKey = $row->date;
            // S'assurer que la date est au format Y-m-d
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey)) {
                // Si la date existe déjà, additionner (ne devrait pas arriver avec GROUP BY, mais sécurité)
                if (isset($dataByDate[$dateKey])) {
                    $dataByDate[$dateKey] += (int)$row->new_contacts;
                } else {
                    $dataByDate[$dateKey] = (int)$row->new_contacts;
                }
            }
        }

        // Remplir tous les jours avec le total cumulé
        $filledData = [];
        $startDate = new \DateTime("-$interval days");
        $endDate = new \DateTime();
        $currentDate = clone $startDate;
        $cumulativeCount = $totalBeforePeriod;

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $newContacts = $dataByDate[$dateStr] ?? 0;
            $cumulativeCount += $newContacts;

            $filledData[] = [
                'date' => $dateStr,
                'count' => $cumulativeCount,
                'new_contacts' => $newContacts,
            ];

            $currentDate->modify('+1 day');
        }

        return rest_ensure_response([
            'data' => $filledData,
            'total_growth' => $totalGrowth,
        ]);
    }

    #[Endpoint(
        'dashboard/top-performing-campaigns',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function topPerformingCampaigns(\WP_REST_Request $request)
    {
        global $wpdb;

        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $limit = (int)$request->get_param('limit') ?: 5;

        $query = $wpdb->prepare("
            SELECT
                c.campaign_id,
                c.name,
                c.status,
                c.created_at,
                b.id AS batch_id,
                b.scheduled_at,
                b.total_emails,
                b.sent_emails,
                b.created_at AS batch_created_at
            FROM {$campaignsTable} c
            INNER JOIN {$batchesTable} b ON c.batch_id = b.id
            WHERE c.status = 'sent'
            AND c.status != 'trash'
            AND b.sent_emails > 0
            ORDER BY b.created_at DESC
        ");

        $results = $wpdb->get_results($query);

        if (empty($results)) {
            return rest_ensure_response([
                'campaigns' => [],
            ]);
        }

        // Récupérer les batch_ids pour les statistiques
        $batch_ids = array_filter(array_map(fn($r) => (int)$r->batch_id, $results));
        $batch_placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));

        // Récupérer les statistiques de tracking
        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        $tracking_stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT batch_id,
                    COALESCE(COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND contact_id > 0 THEN contact_id END), 0) +
                    COALESCE(COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0) AS total_opens,
                    SUM(clicks) AS total_clicks,
                    COALESCE(COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL AND contact_id > 0 THEN contact_id END), 0) +
                    COALESCE(COUNT(DISTINCT CASE WHEN unsubscribed_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END), 0) AS total_unsubscribes
                 FROM {$trackingTable}
                 WHERE batch_id IN ($batch_placeholders)
                 GROUP BY batch_id",
                ...$batch_ids
            ),
            ARRAY_A
        );

        // Créer un map des statistiques par batch_id
        $statistics_map = [];
        foreach ($tracking_stats as $ts) {
            $statistics_map[(int)$ts['batch_id']] = [
                'total_opens' => (int)$ts['total_opens'],
                'total_clicks' => (int)$ts['total_clicks'],
                'total_unsubscribes' => (int)$ts['total_unsubscribes'],
            ];
        }

        // Formater les résultats avec calcul des taux de performance
        $campaigns = [];
        foreach ($results as $row) {
            $batch_id = (int)$row->batch_id;
            $sent_emails = (int)$row->sent_emails;
            $stats = $statistics_map[$batch_id] ?? [
                'total_opens' => 0,
                'total_clicks' => 0,
                'total_unsubscribes' => 0,
            ];

            // Calculer les taux
            $open_rate = $sent_emails > 0 ? ($stats['total_opens'] / $sent_emails) * 100 : 0;
            $click_rate = $sent_emails > 0 ? ($stats['total_clicks'] / $sent_emails) * 100 : 0;

            // Score de performance (combinaison de taux d'ouverture et de clic)
            $performance_score = ($open_rate * 0.6) + ($click_rate * 0.4);

            $campaigns[] = [
                'campaign_id' => (int)$row->campaign_id,
                'name' => $row->name,
                'status' => $row->status,
                'sent_at' => $row->batch_created_at,
                'created_at' => $row->created_at,
                'total_emails' => $row->total_emails ? (int)$row->total_emails : null,
                'sent_emails' => $sent_emails,
                'statistics' => [
                    'total_opens' => $stats['total_opens'],
                    'total_clicks' => $stats['total_clicks'],
                    'total_unsubscribes' => $stats['total_unsubscribes'],
                    'open_rate' => round($open_rate, 2),
                    'click_rate' => round($click_rate, 2),
                ],
                'performance_score' => round($performance_score, 2),
                'batch' => [
                    'scheduled_at' => $row->scheduled_at,
                    'total_emails' => $row->total_emails ? (int)$row->total_emails : null,
                ],
            ];
        }

        // Trier par score de performance décroissant
        usort($campaigns, function ($a, $b) {
            return $b['performance_score'] <=> $a['performance_score'];
        });

        // Limiter le nombre de résultats
        $campaigns = array_slice($campaigns, 0, $limit);

        return rest_ensure_response([
            'campaigns' => $campaigns,
        ]);
    }

    #[Endpoint(
        'dashboard/active-workflows',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function activeWorkflows(\WP_REST_Request $request)
    {
        global $wpdb;

        $automationsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS);

        // Compter les workflows actifs (ENABLED)
        $activeWorkflows = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$automationsTable} WHERE status = 'ENABLED'"
        );

        // Compter les workflows totaux
        $totalWorkflows = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$automationsTable}"
        );

        // Calculer le changement (comparaison avec la période précédente - 30 jours)
        $previousActiveWorkflows = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$automationsTable}
                WHERE status = 'ENABLED'
                AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                30
            )
        );

        $change = 0;
        if ($previousActiveWorkflows > 0) {
            $change = (($activeWorkflows - $previousActiveWorkflows) / $previousActiveWorkflows) * 100;
        } elseif ($activeWorkflows > 0) {
            $change = 100; // Nouveau workflow, considéré comme +100%
        }

        return rest_ensure_response([
            'count' => $activeWorkflows,
            'total' => $totalWorkflows,
            'change' => round($change, 2),
        ]);
    }

    #[Endpoint(
        'dashboard/workflow-jobs',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function workflowJobs(\WP_REST_Request $request)
    {
        global $wpdb;

        $jobsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_JOBS);

        // Période actuelle : 30 derniers jours
        $currentInterval = 30;
        // Période précédente : 30 jours avant (jours 31-60)
        $previousInterval = 30;

        // Calcul pour la période actuelle (30 derniers jours)
        $currentJobs = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$jobsTable}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                AND created_at < NOW()",
                $currentInterval
            )
        );

        // Calcul pour la période précédente (jours 31-60)
        $previousJobs = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$jobsTable}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $currentInterval + $previousInterval,
                $currentInterval
            )
        );

        // Jobs actifs (en cours)
        $activeJobs = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$jobsTable}
            WHERE status IN ('ACTIVE', 'PROCESSING', 'WAITING')"
        );

        // Calcul du changement en pourcentage
        $change = 0;
        if ($previousJobs > 0) {
            $change = (($currentJobs - $previousJobs) / $previousJobs) * 100;
        } elseif ($currentJobs > 0) {
            $change = 100; // Nouveau, considéré comme +100%
        }

        return rest_ensure_response([
            'total' => $currentJobs,
            'active' => $activeJobs,
            'change' => round($change, 2),
        ]);
    }

    #[Endpoint(
        'dashboard/recent-workflows',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function recentWorkflows(\WP_REST_Request $request)
    {
        global $wpdb;

        $automationsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS);
        $jobsTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_JOBS);
        $limit = (int)$request->get_param('limit') ?: 5;

        // Récupérer les workflows actifs et en brouillon avec leurs statistiques
        $query = $wpdb->prepare(
            "SELECT
                a.id,
                a.name,
                a.status,
                a.updated_at,
                COUNT(DISTINCT j.id) as total_jobs,
                SUM(CASE WHEN j.status = 'COMPLETED' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN j.status = 'FAILED' THEN 1 ELSE 0 END) as failed_jobs,
                COUNT(DISTINCT j.user_id) as unique_users,
                MAX(j.created_at) as last_execution
            FROM {$automationsTable} a
            LEFT JOIN {$jobsTable} j ON a.id = j.automation_id
            WHERE a.status IN ('ENABLED', 'DRAFT')
            GROUP BY a.id, a.name, a.status, a.updated_at
            ORDER BY a.updated_at DESC
            LIMIT %d",
            $limit
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        if (empty($results)) {
            return rest_ensure_response([
                'workflows' => [],
            ]);
        }

        $workflows = array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'status' => $row['status'],
                'total_jobs' => (int)$row['total_jobs'],
                'completed_jobs' => (int)$row['completed_jobs'],
                'failed_jobs' => (int)$row['failed_jobs'],
                'unique_users' => (int)$row['unique_users'],
                'last_execution' => $row['last_execution'],
                'updated_at' => $row['updated_at'],
            ];
        }, $results);

        return rest_ensure_response([
            'workflows' => $workflows,
        ]);
    }

    #[Endpoint(
        'dashboard/webhook-stats',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function webhookStats(\WP_REST_Request $request)
    {
        // Vérifier que Pro est actif
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $isProActive = function_exists('is_plugin_active')
            && is_plugin_active('mailerpress-pro/mailerpress-pro.php');

        if (!$isProActive) {
            return rest_ensure_response([
                'total_webhooks' => 0,
                'incoming_webhooks' => 0,
                'outgoing_webhooks' => 0,
                'enabled_outgoing_webhooks' => 0,
                'change' => 0,
            ]);
        }

        // Récupérer les webhooks entrants
        $incomingWebhooks = get_option('mailerpress_webhook_configs', []);
        $incomingCount = is_array($incomingWebhooks) ? count($incomingWebhooks) : 0;

        // Récupérer les webhooks sortants
        $outgoingWebhooks = get_option('mailerpress_outgoing_webhook_configs', []);
        if (is_string($outgoingWebhooks)) {
            $outgoingWebhooks = json_decode($outgoingWebhooks, true) ?: [];
        }
        $outgoingCount = is_array($outgoingWebhooks) ? count($outgoingWebhooks) : 0;

        // Compter les webhooks sortants activés
        $enabledOutgoingCount = 0;
        if (is_array($outgoingWebhooks)) {
            foreach ($outgoingWebhooks as $config) {
                if (isset($config['enabled']) && $config['enabled']) {
                    $enabledOutgoingCount++;
                }
            }
        }

        $totalWebhooks = $incomingCount + $outgoingCount;

        // Calculer le changement (comparaison avec la période précédente - 30 jours)
        // On ne peut pas vraiment calculer l'historique, donc on retourne 0 pour le changement
        $change = 0;

        return rest_ensure_response([
            'total_webhooks' => $totalWebhooks,
            'incoming_webhooks' => $incomingCount,
            'outgoing_webhooks' => $outgoingCount,
            'enabled_outgoing_webhooks' => $enabledOutgoingCount,
            'change' => $change,
        ]);
    }
}
