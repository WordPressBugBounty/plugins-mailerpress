<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use ActionScheduler_Store;
use DateTime;
use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Capabilities;
use MailerPress\Core\EmailManager\EmailLogger;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\HtmlParser;
use MailerPress\Core\Interfaces\ContactFetcherInterface;
use MailerPress\Core\Kernel;
use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Models\Batch;
use MailerPress\Models\Contacts;
use MailerPress\Services\ClassicContactFetcher;
use MailerPress\Services\SegmentContactFetcher;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Campaigns
{
    #[Endpoint(
        'batch-opened-contacts',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function batchOpenedContacts(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $batch_id = absint($request->get_param('batch_id'));
        if (!$batch_id) {
            return new \WP_REST_Response(['error' => __('No batch_id provided', 'mailerpress')], 400);
        }

        $paged = max(1, (int)$request->get_param('paged') ?? 1);
        $per_page = max(1, (int)($request->get_param('perPage') ?? 10));
        $search = $request->get_param('search');

        $tracking_table = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $contact_table = Tables::get(Tables::MAILERPRESS_CONTACT);

        $offset = ($paged - 1) * $per_page;

        // Count total opens including anonymous
        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        $total_opens_query = "
            SELECT
                COUNT(DISTINCT CASE WHEN contact_id > 0 THEN contact_id END) +
                COUNT(DISTINCT CASE WHEN contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END)
            FROM {$tracking_table}
            WHERE batch_id = %d AND opened_at IS NOT NULL
        ";
        $total_opens = (int)$wpdb->get_var($wpdb->prepare($total_opens_query, $batch_id));

        // Count anonymous opens (distinct anonymous_key)
        $anonymous_opens_query = $wpdb->prepare(
            "SELECT COUNT(DISTINCT anonymous_key) FROM {$tracking_table} WHERE batch_id = %d AND opened_at IS NOT NULL AND contact_id = 0 AND anonymous_key IS NOT NULL",
            $batch_id
        );
        $anonymous_opens = (int)$wpdb->get_var($anonymous_opens_query);

        $query_params = [$batch_id];

        // Base WHERE clause - exclude anonymous contacts (contact_id = 0) for the list
        $where = "WHERE t.batch_id = %d AND t.opened_at IS NOT NULL AND t.contact_id > 0";

        if (!empty($search)) {
            $where .= " AND (c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)";
            $like_search = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = $like_search;
            $query_params[] = $like_search;
            $query_params[] = $like_search;
        }

        // Count total rows (non-anonymous contacts only for the list)
        $count_sql = "
        SELECT COUNT(*)
        FROM {$tracking_table} AS t
        INNER JOIN {$contact_table} AS c ON t.contact_id = c.contact_id
        {$where}
    ";
        $total_rows = (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$query_params));
        $total_pages = ceil($total_rows / $per_page);

        // Main SELECT query
        $query_sql = "
        SELECT
            t.contact_id,
            t.opened_at,
            t.clicks,
            c.email,
            c.first_name,
            c.last_name
        FROM {$tracking_table} AS t
        INNER JOIN {$contact_table} AS c ON t.contact_id = c.contact_id
        {$where}
        ORDER BY t.opened_at DESC
        LIMIT %d OFFSET %d
    ";

        // Append LIMIT and OFFSET
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($query_sql, ...$query_params), ARRAY_A);

        return new \WP_REST_Response([
            'posts' => $results,
            'count' => $total_opens, // Return total opens including anonymous
            'identified_count' => $total_rows, // Non-anonymous contacts count
            'anonymous_count' => $anonymous_opens, // Anonymous opens count
            'pages' => $total_pages,
            'current_page' => $paged,
        ], 200);
    }

    #[Endpoint(
        'batch-clicked-contacts',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function batchClickedContacts(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $batch_id = absint($request->get_param('batch_id'));
        if (!$batch_id) {
            return new \WP_REST_Response(['error' => __('No batch_id provided', 'mailerpress')], 400);
        }

        $paged = max(1, (int)$request->get_param('paged') ?? 1);
        $per_page = max(1, (int)($request->get_param('perPage') ?? 10));
        $search = $request->get_param('search');

        // Get campaign_id from batch_id
        $batches_table = $wpdb->prefix . 'mailerpress_email_batches';
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT campaign_id FROM {$batches_table} WHERE id = %d",
                $batch_id
            ),
            ARRAY_A
        );

        if (!$batch || !$batch['campaign_id']) {
            return new \WP_REST_Response([
                'posts' => [],
                'count' => 0,
                'pages' => 1,
                'current_page' => $paged,
            ], 200);
        }

        $campaign_id = (int)$batch['campaign_id'];
        $click_tracking_table = Tables::get(Tables::MAILERPRESS_CLICK_TRACKING);
        $contact_table = Tables::get(Tables::MAILERPRESS_CONTACT);
        $tracking_table = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);

        $offset = ($paged - 1) * $per_page;

        // Count total clicks including anonymous
        // For anonymous users, count distinct anonymous_key; for identified users, count distinct contact_id
        $total_clicks_query = "
            SELECT
                COUNT(DISTINCT CASE WHEN contact_id > 0 THEN contact_id END) +
                COUNT(DISTINCT CASE WHEN contact_id = 0 AND anonymous_key IS NOT NULL THEN anonymous_key END)
            FROM {$click_tracking_table}
            WHERE campaign_id = %d
        ";
        $total_clicks = (int)$wpdb->get_var($wpdb->prepare($total_clicks_query, $campaign_id));

        // Count anonymous clicks (distinct anonymous_key)
        $anonymous_clicks_query = $wpdb->prepare(
            "SELECT COUNT(DISTINCT anonymous_key) FROM {$click_tracking_table} WHERE campaign_id = %d AND contact_id = 0 AND anonymous_key IS NOT NULL",
            $campaign_id
        );
        $anonymous_clicks = (int)$wpdb->get_var($anonymous_clicks_query);

        // Count total distinct contacts who clicked in this campaign and batch (excluding anonymous)
        $count_sql = "
        SELECT COUNT(DISTINCT ct.contact_id)
        FROM {$click_tracking_table} AS ct
        INNER JOIN {$contact_table} AS c ON ct.contact_id = c.contact_id
        WHERE ct.campaign_id = %d AND ct.contact_id > 0
    ";

        $count_params = [$campaign_id];
        if (!empty($search)) {
            $count_sql .= " AND (c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)";
            $like_search = '%' . $wpdb->esc_like($search) . '%';
            $count_params[] = $like_search;
            $count_params[] = $like_search;
            $count_params[] = $like_search;
        }

        $total_rows = (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$count_params));
        $total_pages = ceil($total_rows / $per_page);

        // Main SELECT query - get distinct contacts with their click count (excluding anonymous)
        $query_sql = "
        SELECT
            ct.contact_id,
            COUNT(DISTINCT ct.url) AS clicks,
            MIN(ct.created_at) AS first_clicked_at,
            MAX(ct.created_at) AS last_clicked_at,
            c.email,
            c.first_name,
            c.last_name,
            MAX(t.opened_at) AS opened_at
        FROM {$click_tracking_table} AS ct
        INNER JOIN {$contact_table} AS c ON ct.contact_id = c.contact_id
        LEFT JOIN {$tracking_table} AS t ON ct.contact_id = t.contact_id AND t.batch_id = %d
        WHERE ct.campaign_id = %d AND ct.contact_id > 0
    ";

        $query_params = [$batch_id, $campaign_id];
        if (!empty($search)) {
            $query_sql .= " AND (c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)";
            $like_search = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = $like_search;
            $query_params[] = $like_search;
            $query_params[] = $like_search;
        }

        $query_sql .= "
        GROUP BY ct.contact_id, c.email, c.first_name, c.last_name
        ORDER BY first_clicked_at DESC
        LIMIT %d OFFSET %d
    ";

        $query_params[] = $per_page;
        $query_params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($query_sql, ...$query_params), ARRAY_A);

        return new \WP_REST_Response([
            'posts' => $results,
            'count' => $total_clicks, // Return total clicks including anonymous
            'identified_count' => $total_rows, // Non-anonymous contacts count
            'anonymous_count' => $anonymous_clicks, // Anonymous clicks count
            'pages' => $total_pages,
            'current_page' => $paged,
        ], 200);
    }

    #[Endpoint(
        'batch-unsubscribed-contacts',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function batchUnsubscribedContacts(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $batch_id = absint($request->get_param('batch_id'));
        if (!$batch_id) {
            return new \WP_REST_Response(['error' => __('No batch_id provided', 'mailerpress')], 400);
        }

        $paged = max(1, (int)$request->get_param('paged') ?? 1);
        $per_page = max(1, (int)($request->get_param('perPage') ?? 10));
        $search = $request->get_param('search');

        $tracking_table = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $contact_table = Tables::get(Tables::MAILERPRESS_CONTACT);

        $offset = ($paged - 1) * $per_page;

        $query_params = [$batch_id];

        // Base WHERE clause - contacts who unsubscribed
        $where = "WHERE t.batch_id = %d AND t.unsubscribed_at IS NOT NULL";

        if (!empty($search)) {
            $where .= " AND (c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)";
            $like_search = '%' . $wpdb->esc_like($search) . '%';
            $query_params[] = $like_search;
            $query_params[] = $like_search;
            $query_params[] = $like_search;
        }

        // Count total rows
        $count_sql = "
        SELECT COUNT(*)
        FROM {$tracking_table} AS t
        INNER JOIN {$contact_table} AS c ON t.contact_id = c.contact_id
        {$where}
    ";
        $total_rows = (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$query_params));
        $total_pages = ceil($total_rows / $per_page);

        // Main SELECT query
        $query_sql = "
        SELECT
            t.contact_id,
            t.unsubscribed_at,
            t.opened_at,
            t.clicks,
            c.email,
            c.first_name,
            c.last_name
        FROM {$tracking_table} AS t
        INNER JOIN {$contact_table} AS c ON t.contact_id = c.contact_id
        {$where}
        ORDER BY t.unsubscribed_at DESC
        LIMIT %d OFFSET %d
    ";

        // Append LIMIT and OFFSET
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($query_sql, ...$query_params), ARRAY_A);

        return new \WP_REST_Response([
            'posts' => $results,
            'count' => $total_rows,
            'pages' => $total_pages,
            'current_page' => $paged,
        ], 200);
    }

    #[Endpoint(
        'campaign-status',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function campaignStatus(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $ids = $request->get_param('ids');
        if (!$ids) {
            return new WP_REST_Response(['error' => __('No IDs provided', 'mailerpress')], 400);
        }

        $ids = array_map('absint', explode(',', $ids));
        if (empty($ids)) {
            return new WP_REST_Response(['error' => __('Invalid IDs provided', 'mailerpress')], 400);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $batches_table = $wpdb->prefix . 'mailerpress_email_batches';

        $query = $wpdb->prepare("
        SELECT
            c.campaign_id,
            c.name AS title,
            c.subject,
            c.status,
            c.batch_id AS batch,
            c.updated_at,
            c.content_html,
            c.config
        FROM {$table} AS c
        LEFT JOIN {$batches_table} AS b ON c.batch_id = b.id
        WHERE c.campaign_id IN ($placeholders)
    ", ...$ids);

        $results = $wpdb->get_results($query);

        // ✅ Optimisation: Précharger tous les batches et statistiques
        $batch_ids = array_filter(array_map(fn($r) => (int)$r->batch, $results));
        $batches_data = [];
        $statistics_data = [];

        if (!empty($batch_ids)) {
            $batch_placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));
            $batches_table = $wpdb->prefix . 'mailerpress_email_batches';
            $chunks_table = $wpdb->prefix . 'mailerpress_email_chunks';

            // Récupérer tous les batches
            $batches = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$batches_table} WHERE id IN ($batch_placeholders)",
                    ...$batch_ids
                ),
                ARRAY_A
            );
            foreach ($batches as $batch) {
                $batches_data[$batch['id']] = $batch;
            }

            // Récupérer le prochain chunk pour chaque batch en cours
            // Priority: 1) processing chunks, 2) pending chunks with earliest scheduled_at
            $next_chunks = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        batch_id,
                        scheduled_at as next_chunk_time,
                        status as next_chunk_status,
                        started_at as next_chunk_started_at
                     FROM (
                         SELECT
                             batch_id,
                             scheduled_at,
                             status,
                             started_at,
                             CASE
                                 WHEN status = 'processing' THEN 1
                                 WHEN status = 'pending' THEN 2
                                 ELSE 3
                             END as priority,
                             ROW_NUMBER() OVER (PARTITION BY batch_id ORDER BY
                                 CASE
                                     WHEN status = 'processing' THEN 1
                                     WHEN status = 'pending' THEN 2
                                     ELSE 3
                                 END,
                                 scheduled_at ASC
                             ) as rn
                         FROM {$chunks_table}
                         WHERE batch_id IN ($batch_placeholders)
                         AND status IN ('processing', 'pending')
                     ) ranked
                     WHERE rn = 1",
                    ...$batch_ids
                ),
                ARRAY_A
            );
            foreach ($next_chunks as $chunk) {
                if (isset($batches_data[$chunk['batch_id']])) {
                    $batches_data[$chunk['batch_id']]['next_chunk_time'] = $chunk['next_chunk_time'];
                    $batches_data[$chunk['batch_id']]['next_chunk_status'] = $chunk['next_chunk_status'];
                    $batches_data[$chunk['batch_id']]['next_chunk_started_at'] = $chunk['next_chunk_started_at'];
                }
            }

            // Récupérer toutes les statistiques
            $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
            $campaignStatsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGN_STATS);

            // Récupérer les campaign_ids pour chaque batch
            $batch_campaigns = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, campaign_id FROM {$batches_table} WHERE id IN ($batch_placeholders)",
                    ...$batch_ids
                ),
                ARRAY_A
            );
            $batch_to_campaign = [];
            $campaign_ids_for_stats = [];
            foreach ($batch_campaigns as $bc) {
                $batch_to_campaign[$bc['id']] = (int)$bc['campaign_id'];
                if ($bc['campaign_id']) {
                    $campaign_ids_for_stats[] = (int)$bc['campaign_id'];
                }
            }

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

            foreach ($batch_ids as $batch_id) {
                $batch_id = (int)$batch_id;
                $campaign_id = $batch_to_campaign[$batch_id] ?? null;
                $ts = $tracking_stats_map[$batch_id] ?? null;
                $total_clicks = $campaign_id && isset($click_stats_map[$campaign_id])
                    ? $click_stats_map[$campaign_id]
                    : 0;
                $statistics_data[$batch_id] = array_merge(
                    [
                        'total_opens' => $ts ? (int)$ts['total_opens'] : 0,
                        'total_clicks' => $total_clicks,
                        'total_unsubscribes' => $ts ? (int)$ts['total_unsubscribes'] : 0,
                    ],
                    $campaign_id && isset($campaign_stats_map[$campaign_id]) ? $campaign_stats_map[$campaign_id] : []
                );
            }
        }

        foreach ($results as &$result) {
            $result->content_html = !empty($result->content_html) ? json_decode($result->content_html, true) : null;
            $result->config = !empty($result->config) ? json_decode($result->config, true) : null;

            // Utiliser les données préchargées
            if (!empty($result->batch) && isset($batches_data[$result->batch])) {
                $batch_data = $batches_data[$result->batch];
                if (isset($statistics_data[$result->batch])) {
                    $batch_data = array_merge($batch_data, $statistics_data[$result->batch]);
                }
                $result->batch = $batch_data;
            } else {
                $result->batch = null;
            }

            $statistics = !empty($result->batch['id']) && isset($statistics_data[$result->batch['id']])
                ? $statistics_data[$result->batch['id']]
                : null;

            // Format revenue in statistics if present (only if it's numeric, not already formatted)
            if ($statistics && isset($statistics['campaign_total_revenue'])) {
                if (is_numeric($statistics['campaign_total_revenue'])) {
                    $statistics['campaign_total_revenue'] = $this->formatRevenue((float)$statistics['campaign_total_revenue']);
                }
            }

            $result->statistics = $statistics;
        }

        return new WP_REST_Response($results, 200);
    }

    #[Endpoint(
        'campaign-status-lock',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function campaignStatusLock(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $ids = $request->get_param('ids');
        if (!$ids) {
            return new WP_REST_Response(['error' => __('No IDs provided', 'mailerpress')], 400);
        }

        $ids = array_map('absint', explode(',', $ids));
        if (empty($ids)) {
            return new WP_REST_Response(['error' => __('Invalid IDs provided', 'mailerpress')], 400);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $table = $wpdb->prefix . 'mailerpress_campaigns';

        $query = $wpdb->prepare("
        SELECT
            campaign_id,
            editing_user_id,
            editing_started_at
        FROM {$table}
        WHERE campaign_id IN ($placeholders)
    ", ...$ids);

        $results = $wpdb->get_results($query);

        $now = current_time('timestamp'); // Unix timestamp in site’s timezone

        $LOCK_TIMEOUT = 2 * 60; // 5 minutes

        foreach ($results as &$campaign) {
            $campaign->locked = false;
            $campaign->locked_by = null;

            if (!empty($campaign->editing_started_at) && $campaign->editing_user_id) {
                $editing_time = strtotime($campaign->editing_started_at);
                $elapsed = $now - $editing_time;
                if ($elapsed > $LOCK_TIMEOUT) {
                    // Only unlock if lock is stale
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$table}
                             SET editing_user_id = NULL, editing_started_at = NULL
                             WHERE campaign_id = %d",
                            $campaign->campaign_id
                        )
                    );
                    $campaign->editing_user_id = null;
                    $campaign->editing_started_at = null;
                } else {
                    // Lock is valid
                    $campaign->locked = true;
                    $user = get_userdata($campaign->editing_user_id);
                    $campaign->locked_by = $user ? $user->display_name : null;
                }
            }
        }

        return new WP_REST_Response($results, 200);
    }

    #[Endpoint(
        'batch/(?P<batch_id>\d+)/chunks',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getBatchChunks(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $batch_id = (int)$request->get_param('batch_id');
        if (!$batch_id) {
            return new \WP_Error('invalid_batch', __('Invalid batch ID', 'mailerpress'), ['status' => 400]);
        }

        $paged = max((int)($request->get_param('page') ?? 1), 1);
        $per_page = max((int)($request->get_param('per_page') ?? 50), 1);
        $offset = ($paged - 1) * $per_page;

        $status = $request->get_param('status');
        $orderby = $request->get_param('orderby') ?? 'chunk_index';
        $order = strtoupper($request->get_param('order') ?? 'ASC');

        // Validate orderby and order to prevent SQL injection
        $allowed_orderby = ['chunk_index', 'scheduled_at', 'status', 'created_at'];
        $allowed_order = ['ASC', 'DESC'];

        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'chunk_index';
        }
        if (!in_array($order, $allowed_order, true)) {
            $order = 'ASC';
        }

        $chunks_table = $wpdb->prefix . 'mailerpress_email_chunks';

        // Build WHERE clause
        $where = $wpdb->prepare("WHERE batch_id = %d", $batch_id);
        if (!empty($status)) {
            $where .= $wpdb->prepare(" AND status = %s", $status);
        }

        // Count total
        $total_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$chunks_table} {$where}");
        $total_pages = (int)ceil($total_count / $per_page);

        // Get chunks
        $chunks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$chunks_table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return new \WP_REST_Response([
            'chunks' => $chunks,
            'count' => $total_count,
            'pages' => $total_pages,
            'current_page' => $paged,
        ], 200);
    }

    #[Endpoint(
        'campaign/batches',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getBatchesForCampaign(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaign_id = (int)$request->get_param('id');
        $paged = max((int)($request->get_param('paged') ?? 1), 1);
        $per_page = max((int)($request->get_param('perPage') ?? 10), 1);
        $offset = ($paged - 1) * $per_page;

        if (!$campaign_id) {
            return new \WP_Error('invalid_campaign', __('Invalid campaign ID', 'mailerpress'), ['status' => 400]);
        }

        $table_campaigns = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $table_batches = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

        $campaign = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_campaigns} WHERE campaign_id = %d", $campaign_id)
        );

        if (!$campaign) {
            return new \WP_Error('campaign_not_found', __('Campaign not found', 'mailerpress'), ['status' => 404]);
        }

        $total_rows = (int)$wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table_batches} WHERE campaign_id = %d", $campaign_id)
        );

        $total_pages = (int)ceil($total_rows / $per_page);

        $batches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table_batches} WHERE campaign_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $campaign_id,
                $per_page,
                $offset
            )
        );

        // ✅ Optimisation: Précharger tous les batches et statistiques en une seule fois
        $batch_ids = array_map(fn($b) => (int)$b->id, $batches);

        if (empty($batch_ids)) {
            return new \WP_REST_Response([
                'posts' => [],
                'pages' => $total_pages,
                'count' => $total_rows,
                'current_page' => $paged,
                'per_page' => $per_page,
            ], 200);
        }

        $batch_placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));

        // Récupérer tous les batches
        $batches_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_batches} WHERE id IN ($batch_placeholders)",
                ...$batch_ids
            ),
            ARRAY_A
        );
        $batches_map = [];
        foreach ($batches_data as $bd) {
            $batches_map[$bd['id']] = $bd;
        }

        // Récupérer toutes les statistiques
        $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
        $campaignStatsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGN_STATS);

        // Récupérer les campaign_ids pour chaque batch
        $batch_campaigns = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, campaign_id FROM {$table_batches} WHERE id IN ($batch_placeholders)",
                ...$batch_ids
            ),
            ARRAY_A
        );
        $batch_to_campaign = [];
        $campaign_ids_for_stats = [];
        foreach ($batch_campaigns as $bc) {
            $batch_to_campaign[$bc['id']] = (int)$bc['campaign_id'];
            if ($bc['campaign_id']) {
                $campaign_ids_for_stats[] = (int)$bc['campaign_id'];
            }
        }

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
            $merged_stats = array_merge(
                [
                    'total_opens' => $ts ? (int)$ts['total_opens'] : 0,
                    'total_clicks' => $total_clicks,
                    'total_unsubscribes' => $ts ? (int)$ts['total_unsubscribes'] : 0,
                ],
                $campaign_id && isset($campaign_stats_map[$campaign_id]) ? $campaign_stats_map[$campaign_id] : []
            );
            // Format revenue if present
            if (isset($merged_stats['campaign_total_revenue'])) {
                $merged_stats['campaign_total_revenue'] = $this->formatRevenue((float)$merged_stats['campaign_total_revenue']);
            }
            $statistics_map[$batch_id] = $merged_stats;
        }

        // Construire les résultats
        $results = [];
        foreach ($batches as $batch) {
            $batch_id = (int)$batch->id;
            $batch_data = $batches_map[$batch_id] ?? null;
            if (!$batch_data) {
                continue;
            }

            // Ajouter les statistiques au batch
            if (isset($statistics_map[$batch_id])) {
                $batch_data = array_merge($batch_data, $statistics_map[$batch_id]);
            }

            $results[] = [
                'batch' => $batch_data,
                'created_at' => get_date_from_gmt($batch_data['created_at'], 'c'),
                'statistics' => $statistics_map[$batch_id] ?? null,
            ];
        }

        return new \WP_REST_Response([
            'posts' => $results,
            'pages' => $total_pages,
            'count' => $total_rows,
            'current_page' => $paged,
            'per_page' => $per_page,
        ], 200);
    }


    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'campaigns',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function response(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $paged = $request->get_param('paged') ?? 1;
        $posts_per_page = $request->get_param('perPages') ?? 10;
        $search = $request->get_param('search');
        $statusParam = $request->get_param('status');

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $statuses = [
            'draft',
            'mine',
            'sent',
            'in_progress',
            'scheduled',
            'pending',
            'error',
            'active',
            'inactive',
        ];


        if (!empty($statusParam)) {
            if (is_string($statusParam)) {
                $statuses = array_map('trim', explode(',', $statusParam));
            } elseif (is_array($statusParam)) {
                $statuses = array_map('trim', $statusParam);
            }
        }


        // Initialize query params early
        $query_params = [];

        // Base query - Include content_html and config to avoid N+1 queries
        $query = "
        SELECT c.campaign_id AS id,
               c.user_id,
               c.name AS title,
               c.subject,
               c.status,
               c.batch_id AS batch,
               c.updated_at,
               c.created_at,
               c.campaign_type,
                c.editing_user_id,
               c.editing_started_at,
               c.content_html,
               c.config
        FROM {$table_name} AS c
        LEFT JOIN {$wpdb->prefix}mailerpress_email_batches AS b
            ON c.batch_id = b.id
        WHERE 1=1
    ";

        $countQuery = "
        SELECT COUNT(c.campaign_id)
        FROM {$table_name} AS c
        LEFT JOIN {$wpdb->prefix}mailerpress_email_batches AS b
            ON c.batch_id = b.id
        WHERE 1=1
    ";

        // Search filter
        if (!empty($search)) {
            $query .= ' AND c.name LIKE %s';
            $countQuery .= ' AND c.name LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Status filter
        // Status filter
        if (!empty($statuses)) {
            $hasDraft = in_array('draft', $statuses, true);
            $hasMine = in_array('mine', $statuses, true);
            $filteredStatuses = array_filter($statuses, fn($status) => !in_array($status, ['draft', 'mine'], true));

            $query .= ' AND (';
            $countQuery .= ' AND (';

            $statusParts = [];

            if ($hasDraft) {
                $statusParts[] = "(c.status = 'draft' AND b.status IS NULL)";
            }

            if ($hasMine) {
                $currentUserId = get_current_user_id();
                $statusParts[] = $wpdb->prepare("(c.user_id = %d AND c.status != 'trash')", $currentUserId);
            }

            if (!empty($filteredStatuses)) {
                $placeholders = implode(',', array_fill(0, count($filteredStatuses), '%s'));
                $statusParts[] = "c.status IN ($placeholders)";
                $query_params = array_merge($query_params, $filteredStatuses);
            }

            // Combine all conditions with OR
            $query .= implode(' OR ', $statusParts) . ')';
            $countQuery .= implode(' OR ', $statusParts) . ')';
        }

        // Campaign type filter
        $campaignTypesRaw = $request->get_param('campaign_type');
        $campaignTypes = [];

        if (is_array($campaignTypesRaw)) {
            foreach ($campaignTypesRaw as $entry) {
                if (isset($entry['id']) && is_string($entry['id'])) {
                    $campaignTypes[] = sanitize_text_field($entry['id']);
                }
            }
        }

        if (!empty($campaignTypes)) {
            $placeholders = implode(',', array_fill(0, count($campaignTypes), '%s'));
            $query .= " AND c.campaign_type IN ($placeholders)";
            $countQuery .= " AND c.campaign_type IN ($placeholders)";
            $query_params = array_merge($query_params, $campaignTypes);
        }

        // Exclude automation campaigns
        $query .= " AND c.campaign_type != 'automation'";
        $countQuery .= " AND c.campaign_type != 'automation'";

        // Ordering - Use whitelist to prevent SQL injection
        $allowed_orderby = ['id', 'name', 'status', 'created_at', 'updated_at', 'user_id', 'campaign_type'];
        $allowed_order = ['ASC', 'DESC'];
        $orderby_param = $request->get_param('orderby');
        $order_param = strtoupper($request->get_param('order') ?? 'DESC');
        $orderby = in_array($orderby_param, $allowed_orderby, true) ? $orderby_param : 'updated_at';
        $order = in_array($order_param, $allowed_order, true) ? $order_param : 'DESC';
        $query .= sprintf(' ORDER BY c.%s %s', esc_sql($orderby), esc_sql($order));

        // Pagination - Use prepare to prevent SQL injection
        $offset = ($paged - 1) * $posts_per_page;
        $query .= " LIMIT %d, %d";
        $query_params[] = $offset;
        $query_params[] = $posts_per_page;

        // Final execution
        $results = $wpdb->get_results($wpdb->prepare($query, ...$query_params));

        // ✅ Optimisation: Précharger toutes les données nécessaires en une seule fois
        $campaign_ids = array_map(fn($r) => (int)$r->id, $results);
        $batch_ids = array_filter(array_map(fn($r) => (int)$r->batch, $results));
        $user_ids = array_unique(array_filter(array_merge(
            array_map(fn($r) => (int)$r->user_id, $results),
            array_map(fn($r) => (int)$r->editing_user_id, $results)
        )));

        // Précharger tous les batches en une seule requête
        $batches_data = [];
        if (!empty($batch_ids)) {
            $batch_placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));
            $batches_table = $wpdb->prefix . 'mailerpress_email_batches';
            $chunks_table = $wpdb->prefix . 'mailerpress_email_chunks';

            $batches = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$batches_table} WHERE id IN ($batch_placeholders)",
                    ...$batch_ids
                ),
                ARRAY_A
            );
            foreach ($batches as $batch) {
                $batches_data[$batch['id']] = $batch;
            }

            // Récupérer le prochain chunk pour chaque batch en cours
            // Priority: 1) processing chunks, 2) pending chunks with earliest scheduled_at
            $next_chunks = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        batch_id,
                        scheduled_at as next_chunk_time,
                        status as next_chunk_status,
                        started_at as next_chunk_started_at
                     FROM (
                         SELECT
                             batch_id,
                             scheduled_at,
                             status,
                             started_at,
                             CASE
                                 WHEN status = 'processing' THEN 1
                                 WHEN status = 'pending' THEN 2
                                 ELSE 3
                             END as priority,
                             ROW_NUMBER() OVER (PARTITION BY batch_id ORDER BY
                                 CASE
                                     WHEN status = 'processing' THEN 1
                                     WHEN status = 'pending' THEN 2
                                     ELSE 3
                                 END,
                                 scheduled_at ASC
                             ) as rn
                         FROM {$chunks_table}
                         WHERE batch_id IN ($batch_placeholders)
                         AND status IN ('processing', 'pending')
                     ) ranked
                     WHERE rn = 1",
                    ...$batch_ids
                ),
                ARRAY_A
            );
            foreach ($next_chunks as $chunk) {
                if (isset($batches_data[$chunk['batch_id']])) {
                    $batches_data[$chunk['batch_id']]['next_chunk_time'] = $chunk['next_chunk_time'];
                    $batches_data[$chunk['batch_id']]['next_chunk_status'] = $chunk['next_chunk_status'];
                    $batches_data[$chunk['batch_id']]['next_chunk_started_at'] = $chunk['next_chunk_started_at'];
                }
            }
        }

        // Précharger toutes les statistiques en une seule requête
        $statistics_data = [];
        if (!empty($batch_ids)) {
            $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);
            $clickTrackingTable = Tables::get(Tables::MAILERPRESS_CLICK_TRACKING);
            $campaignStatsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGN_STATS);
            $batch_placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));

            // Récupérer les campaign_ids pour chaque batch
            $batches_table = $wpdb->prefix . 'mailerpress_email_batches';
            $batch_campaigns = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, campaign_id FROM {$batches_table} WHERE id IN ($batch_placeholders)",
                    ...$batch_ids
                ),
                ARRAY_A
            );
            $batch_to_campaign = [];
            $campaign_ids_for_stats = [];
            foreach ($batch_campaigns as $bc) {
                $batch_to_campaign[$bc['id']] = (int)$bc['campaign_id'];
                if ($bc['campaign_id']) {
                    $campaign_ids_for_stats[] = (int)$bc['campaign_id'];
                }
            }

            // Statistiques de tracking par batch (opens et unsubscribes)
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

            // Statistiques de clics depuis mailerpress_click_tracking par campagne
            // Compter les clics uniques par contact et par URL (même lien cliqué plusieurs fois = 1 clic)
            // For anonymous users, count distinct anonymous_key|url; for identified users, count distinct contact_id|url
            $click_stats = [];
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
            }
            $click_stats_map = [];
            foreach ($click_stats as $cs) {
                $click_stats_map[$cs['campaign_id']] = (int)$cs['total_clicks'];
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

            foreach ($batch_ids as $batch_id) {
                $batch_id = (int)$batch_id;
                $campaign_id = $batch_to_campaign[$batch_id] ?? null;
                $ts = $tracking_stats_map[$batch_id] ?? null;

                // Récupérer les clics depuis click_tracking pour cette campagne
                $total_clicks = $campaign_id && isset($click_stats_map[$campaign_id])
                    ? $click_stats_map[$campaign_id]
                    : 0;

                $merged_stats = array_merge(
                    [
                        'total_opens' => $ts ? (int)$ts['total_opens'] : 0,
                        'total_clicks' => $total_clicks,
                        'total_unsubscribes' => $ts ? (int)$ts['total_unsubscribes'] : 0,
                    ],
                    $campaign_id && isset($campaign_stats_map[$campaign_id]) ? $campaign_stats_map[$campaign_id] : []
                );
                // Format revenue if present (only if it's numeric, not already formatted)
                if (isset($merged_stats['campaign_total_revenue']) && is_numeric($merged_stats['campaign_total_revenue'])) {
                    $merged_stats['campaign_total_revenue'] = $this->formatRevenue((float)$merged_stats['campaign_total_revenue']);
                }
                $statistics_data[$batch_id] = $merged_stats;
            }
        }

        // Précharger tous les utilisateurs
        $users_data = [];
        if (!empty($user_ids)) {
            $users = get_users(['include' => $user_ids]);
            foreach ($users as $user) {
                $users_data[$user->ID] = [
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'avatar' => get_avatar_url($user->ID, ['size' => 256, 'default' => 'mystery'])
                ];
            }
        }

        // ✅ Appliquer les données préchargées
        foreach ($results as &$result) {
            // Décoder content_html et config (déjà dans la requête)
            $result->content_html = !empty($result->content_html) ? json_decode($result->content_html, true) : null;
            $result->config = !empty($result->config) ? json_decode($result->config, true) : null;

            // Batch avec stats (préchargé)
            if (!empty($result->batch) && isset($batches_data[$result->batch])) {
                $batch_data = $batches_data[$result->batch];
                if (isset($statistics_data[$result->batch])) {
                    $batch_data = array_merge($batch_data, $statistics_data[$result->batch]);
                }
                $result->batch = $batch_data;
            } else {
                $result->batch = null;
            }

            $statistics = !empty($result->batch['id']) && isset($statistics_data[$result->batch['id']])
                ? $statistics_data[$result->batch['id']]
                : null;

            // Format revenue in statistics if present (only if it's numeric, not already formatted)
            if ($statistics && isset($statistics['campaign_total_revenue'])) {
                if (is_numeric($statistics['campaign_total_revenue'])) {
                    $statistics['campaign_total_revenue'] = $this->formatRevenue((float)$statistics['campaign_total_revenue']);
                }
            }

            $result->statistics = $statistics;

            // Author (préchargé)
            if (!empty($result->user_id) && isset($users_data[$result->user_id])) {
                $result->author = $users_data[$result->user_id];
            } else {
                $result->author = null;
            }

            // ✅ Lock info
            $canEdit = false;
            if ((int)$result->user_id === get_current_user_id()) {
                $canEdit = current_user_can(Capabilities::MANAGE_CAMPAIGNS);
            } else {
                $canEdit = current_user_can(Capabilities::EDIT_OTHERS_CAMPAIGNS);
            }
            $result->canEdit = $canEdit;

            $result->locked = !empty($result->editing_user_id);
            if ($result->editing_user_id) {
                $editing_user = get_userdata($result->editing_user_id);
                $result->locked_by = $editing_user ? $editing_user->display_name : null;
                $result->locked_since = $result->editing_started_at;
                $result->locked_avatar = $editing_user
                    ? get_avatar_url($editing_user->ID, ['size' => 256, 'default' => 'mystery'])
                    : get_avatar_url(0, ['size' => 256, 'default' => 'mystery']);
            } else {
                $result->locked_by = null;
                $result->locked_since = null;
                $result->locked_avatar = get_avatar_url(0, ['size' => 256, 'default' => 'mystery']);
            }
        }

        $total_rows = $wpdb->get_var($wpdb->prepare($countQuery, ...$query_params));
        $total_pages = ceil($total_rows / $posts_per_page);

        return new \WP_REST_Response([
            'posts' => $results,
            'pages' => $total_pages,
            'count' => $total_rows,
            'current_page' => $paged,
        ], 200);
    }

    #[Endpoint(
        'campaign/(?P<id>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getCampaignById(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        // Récupérer l'ID de la campagne depuis les paramètres de la requête
        $campaign_id = (int)$request->get_param('id');

        // Nom de la table des campagnes
        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Vérifier si la campagne existe
        $campaign = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE campaign_id = %d", $campaign_id),
            ARRAY_A
        );

        if (!$campaign) {
            return new \WP_Error('not_found', __('Campaign not found.', 'mailerpress'), ['status' => 404]);
        }

        // Décoder les champs JSON pour les rendre utilisables
        $campaign['content_html'] = !empty($campaign['content_html']) ? json_decode(
            $campaign['content_html'],
            true
        ) : null;
        $campaign['config'] = !empty($campaign['config']) ? json_decode($campaign['config'], true) : null;

        // Récupérer les informations de l'automation si elle existe
        $automation_id = !empty($campaign['automation_id']) ? (int)$campaign['automation_id'] : null;
        $step_id = !empty($campaign['step_id']) ? $campaign['step_id'] : null;
        $automation_name = null;

        if ($automation_id) {
            try {
                $automationRepo = new AutomationRepository();
                $automation = $automationRepo->find($automation_id);
                if ($automation) {
                    $automation_name = $automation->getName();
                }
            } catch (\Exception $e) {
                // Si l'automation n'existe pas, on continue sans erreur
                $automation_id = null;
            }
        }

        // Retourner la campagne en réponse
        return new \WP_REST_Response(
            [
                'title' => $campaign['name'],
                'status' => $campaign['status'],
                'json' => $campaign['content_html'],
                'config' => $campaign['config'],
                'type' => $campaign['campaign_type'],
                'campaign_type' => $campaign['campaign_type'], // Also include campaign_type for compatibility
                'batch' => '',
                'automation_id' => $automation_id,
                'automation_name' => $automation_name,
                'step_id' => $step_id, // Include step_id for automation emails
            ],
            200
        );
    }

    #[Endpoint(
        'campaigns',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function post(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $name = sanitize_text_field($request->get_param('title'));
        $meta = $request->get_param('meta') ?? [];
        $campaign_type = sanitize_text_field($request->get_param('campaign_type'));
        $automation_id = $request->get_param('automation_id');
        $step_id = $request->get_param('stepId') ?? $request->get_param('step_id');

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Prepare data for insertion
        $data = [
            'user_id' => get_current_user_id(),
            'name' => $name,
            'subject' => $meta['emailConfig']['campaignSubject'] ?? '',
            'status' => 'draft',
            'email_type' => $meta['emailConfig']['email_type'] ?? 'html',
            'content_html' => (!empty($meta) && isset($meta['json']) && $meta['json']) ? wp_json_encode($meta['json']) : null,
            'config' => !empty($meta['emailConfig']) ? wp_json_encode($meta['emailConfig']) : null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        // Add campaign_type if provided
        if (!empty($campaign_type) && in_array($campaign_type, ['newsletter', 'automated', 'automation'], true)) {
            $data['campaign_type'] = $campaign_type;
        }

        // Check if automation_id and step_id columns exist (they were re-added in later migrations)
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        $hasAutomationId = in_array('automation_id', $columns, true);
        $hasStepId = in_array('step_id', $columns, true);

        // Add automation_id if provided and column exists
        if ($hasAutomationId && !empty($automation_id) && is_numeric($automation_id)) {
            $automation_id = (int) $automation_id;
            // Vérifier que l'automation existe
            $automations_table = Tables::get(Tables::MAILERPRESS_AUTOMATIONS);
            $automation_exists = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$automations_table} WHERE id = %d", $automation_id)
            );
            if ($automation_exists > 0) {
                $data['automation_id'] = $automation_id;
            }
        }

        // Add step_id if provided and column exists
        if ($hasStepId && !empty($step_id) && is_string($step_id)) {
            $data['step_id'] = sanitize_text_field($step_id);
        }

        // Insert data into the database
        $inserted = $wpdb->insert($table_name, $data);

        if (false === $inserted) {
            return new \WP_Error('db_insert_error', __('Failed to create campaign.', 'mailerpress'), ['status' => 500]);
        }

        do_action('mailerpress_campaign_created', $wpdb->insert_id);

        // Return success response
        return new \WP_REST_Response($wpdb->insert_id, 201);
    }

    #[Endpoint(
        'campaign',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canDeleteCampaigns'],
    )]
    public function delete(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        if (!current_user_can(Capabilities::DELETE_EMAIL_CAMPAIGNS)) {
            return new \WP_Error(
                'forbidden',
                __('You do not have permission to delete campaigns.', 'mailerpress'),
                ['status' => 403]
            );
        }

        // Récupérer les IDs de campagnes depuis la requête
        $campaign_ids = $request->get_param('ids'); // Attendez un tableau d'IDs, exemple : [1, 2, 3]

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

        // Vérifier que le tableau d'IDs n'est pas vide
        if (empty($campaign_ids)) {
            return new \WP_Error(
                'no_ids_provided',
                __('No campaign IDs provided.', 'mailerpress'),
                ['status' => 400]
            );
        }

        $placeholders = implode(',', array_fill(0, \count($campaign_ids), '%d'));

        $query = $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE campaign_id IN ({$placeholders})",
            ...$campaign_ids
        );

        $deleted = $wpdb->query($query);

        if (false === $deleted) {
            return new \WP_Error(
                'db_delete_error',
                __('Failed to delete the campaigns.', 'mailerpress'),
                ['status' => 500]
            );
        }

        $batch_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$batchTable} WHERE campaign_id IN ({$placeholders})",
                ...$campaign_ids
            )
        );

        if (false === $batch_deleted) {
            return new \WP_Error(
                'db_delete_error',
                __('Failed to delete the batches.', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response(
            [
                'message' => __('Campaigns successfully deleted.', 'mailerpress'),
                'ids' => $campaign_ids,
            ],
            200
        );
    }

    #[Endpoint(
        'campaign/all',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canDeleteCampaigns'],
    )]
    public function deleteAll(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {

        if (!current_user_can(Capabilities::DELETE_EMAIL_CAMPAIGNS)) {
            return new \WP_Error(
                'forbidden',
                __('You do not have permission to delete campaigns.', 'mailerpress'),
                ['status' => 403]
            );
        }

        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $tableBatch = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

        $campaign_types = $request->get_param('campaign_type'); // array of ['id'=>..., 'name'=>...]

        if (empty($campaign_types)) {
            $campaign_types = [
                ['id' => 'newsletter', 'name' => 'Newsletter'],
                ['id' => 'automated', 'name' => 'Automated'],
            ];
        } elseif (!is_array($campaign_types)) {
            return new \WP_REST_Response([
                'message' => 'campaign_type parameter must be an array.'
            ], 400);
        }

        $campaign_type_ids = array_map(fn($ct) => $ct['id'], $campaign_types);

        if (empty($campaign_type_ids)) {
            return new \WP_REST_Response(['message' => 'No campaign_type IDs found.'], 400);
        }

        $placeholders = implode(',', array_fill(0, count($campaign_type_ids), '%s'));

        // Delete batches linked to campaigns of these types AND with trash status
        $delete_batches_query = "
        DELETE FROM {$tableBatch}
        WHERE campaign_id IN (
            SELECT id FROM {$table_name}
            WHERE campaign_type IN ($placeholders) AND status = 'trash'
        )
    ";
        $delete_batches_query_prepared = $wpdb->prepare($delete_batches_query, ...$campaign_type_ids);
        $deleted_batches = $wpdb->query($delete_batches_query_prepared);

        // Delete campaigns of these types AND with trash status
        $delete_campaigns_query = "
        DELETE FROM {$table_name}
        WHERE campaign_type IN ($placeholders) AND status = 'trash'
    ";
        $delete_campaigns_query_prepared = $wpdb->prepare($delete_campaigns_query, ...$campaign_type_ids);
        $deleted_campaigns = $wpdb->query($delete_campaigns_query_prepared);

        if ($deleted_batches === false || $deleted_campaigns === false) {
            return new \WP_REST_Response(['message' => 'Failed to delete campaigns or batches.'], 500);
        }

        return new \WP_REST_Response(
            [
                'message' => "Deleted campaigns and related batches for campaign_type IDs (trash only): " . implode(
                    ', ',
                    $campaign_type_ids
                ),
                'deleted_campaigns' => $deleted_campaigns,
                'deleted_batches' => $deleted_batches,
            ],
            200
        );
    }

    #[Endpoint(
        'campaign/status',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function update_status(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $ids = $request->get_param('id');
        $status = sanitize_text_field($request->get_param('status'));
        $campaign_type = sanitize_text_field($request->get_param('campaign_type'));

        // Validate status
        $allowed_statuses = ['draft', 'scheduled', 'sending', 'sent', 'paused', 'cancelled', 'trash'];
        if (empty($status) || !in_array($status, $allowed_statuses, true)) {
            return new \WP_Error(
                'invalid_status',
                __('Invalid or empty campaign status.', 'mailerpress'),
                ['status' => 400]
            );
        }

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Prepare extra SQL for trash
        $extra_set = $status === 'trash' ? ', batch_id = NULL' : '';

        // Handle "all" case
        if ($ids === 'all') {
            $where_parts = [];
            $params = [$status, current_time('mysql')];

            // Filter by campaign type
            if (!empty($campaign_type)) {
                $where_parts[] = 'type = %s';
                $params[] = $campaign_type;
            }

            // Filter by status (for trash filtering, etc.)
            $filter_status = sanitize_text_field($request->get_param('filter_status'));
            if (!empty($filter_status)) {
                $where_parts[] = 'status = %s';
                $params[] = $filter_status;
            }

            // Filter by search query
            $search = sanitize_text_field($request->get_param('search'));
            if (!empty($search)) {
                $where_parts[] = 'post_title LIKE %s';
                $params[] = '%' . $wpdb->esc_like($search) . '%';
            }

            $where = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table_name} SET status = %s, updated_at = %s{$extra_set} {$where}",
                    $params
                )
            );

            if (false === $updated) {
                return new \WP_Error(
                    'db_update_error',
                    __('Failed to update campaign statuses.', 'mailerpress'),
                    ['status' => 500]
                );
            }

            return new \WP_REST_Response(
                [
                    'success' => true,
                    'message' => sprintf(
                        /* translators: %d number of campaigns */
                        __('%d campaign(s) status updated successfully.', 'mailerpress'),
                        $updated
                    ),
                    'updated_ids' => 'all',
                    'new_status' => $status,
                    'campaign_type' => $campaign_type,
                ],
                200
            );
        }

        // Otherwise normalize IDs: ensure array
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            return new \WP_Error('missing_id', __('No campaign ID(s) provided.', 'mailerpress'), ['status' => 400]);
        }

        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Check existence
        $existing_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT campaign_id FROM {$table_name} WHERE campaign_id IN ($placeholders)",
                $ids
            )
        );

        if (empty($existing_ids)) {
            return new \WP_Error('not_found', __('No matching campaign(s) found.', 'mailerpress'), ['status' => 404]);
        }

        // Update all in one query
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name} SET status = %s, updated_at = %s{$extra_set} WHERE campaign_id IN ($placeholders)",
                array_merge([$status, current_time('mysql')], $ids)
            )
        );

        if (false === $updated) {
            return new \WP_Error(
                'db_update_error',
                __('Failed to update campaign status.', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response(
            [
                'success' => true,
                'message' => sprintf(
                    /* translators: %d number of campaigns */
                    __('%d campaign(s) status updated successfully.', 'mailerpress'),
                    $updated
                ),
                'updated_ids' => $existing_ids,
                'new_status' => $status,
            ],
            200
        );
    }

    #[Endpoint(
        'campaign/delete',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canDeleteCampaigns'],
    )]
    public function delete_campaign(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        if (!current_user_can(Capabilities::DELETE_EMAIL_CAMPAIGNS)) {
            return new \WP_Error(
                'forbidden',
                __('You do not have permission to delete campaigns.', 'mailerpress'),
                ['status' => 403]
            );
        }

        global $wpdb;

        $ids = $request->get_param('id'); // "all" or array/single ID
        $campaign_type = sanitize_text_field($request->get_param('campaign_type'));
        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // --- HANDLE "ALL" ---
        // Only delete all if explicitly requested with 'all' AND no specific IDs are provided
        if ($ids === 'all') {
            $tableBatch = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

            // Build WHERE conditions with filters
            $where_parts = ['status = %s'];
            $params_select = ['trash'];

            if (!empty($campaign_type)) {
                $where_parts[] = 'campaign_type = %s';
                $params_select[] = $campaign_type;
            }

            // Add filter_status if provided
            $filter_status = sanitize_text_field($request->get_param('filter_status'));
            if (!empty($filter_status) && $filter_status !== 'all') {
                $where_parts[] = 'status = %s';
                $params_select[] = $filter_status;
            }

            // Add search filter if provided
            $search = sanitize_text_field($request->get_param('search'));
            if (!empty($search)) {
                $where_parts[] = 'post_title LIKE %s';
                $params_select[] = '%' . $wpdb->esc_like($search) . '%';
            }

            // First, get campaign IDs that will be deleted
            $sql_select = "SELECT campaign_id FROM {$table_name} WHERE " . implode(' AND ', $where_parts);
            $campaign_ids_to_delete = $wpdb->get_col($wpdb->prepare($sql_select, ...$params_select));

            // Delete batches for these campaigns
            $deleted_batches = 0;
            if (!empty($campaign_ids_to_delete)) {
                $placeholders_batches = implode(',', array_fill(0, count($campaign_ids_to_delete), '%d'));
                $sql_delete_batches = "DELETE FROM {$tableBatch} WHERE campaign_id IN ($placeholders_batches)";
                $deleted_batches = $wpdb->query($wpdb->prepare($sql_delete_batches, ...$campaign_ids_to_delete));
            }

            // Then delete campaigns using same WHERE conditions
            $sql = "DELETE FROM {$table_name} WHERE " . implode(' AND ', $where_parts);
            $deleted = $wpdb->query($wpdb->prepare($sql, ...$params_select));

            if (false === $deleted) {
                return new \WP_Error(
                    'db_delete_error',
                    __('Failed to delete campaigns.', 'mailerpress'),
                    ['status' => 500]
                );
            }

            return new \WP_REST_Response([
                'success' => true,
                'message' => sprintf(__('All (%d) campaign(s) permanently deleted.', 'mailerpress'), $deleted),
                'deleted_ids' => 'all',
                'deleted_batches' => $deleted_batches !== false ? $deleted_batches : 0,
            ], 200);
        }

        // --- HANDLE SPECIFIC IDS ---
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            return new \WP_Error('missing_id', __('No campaign ID(s) provided.', 'mailerpress'), ['status' => 400]);
        }

        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Only select campaigns that are in trash
        $sql = "SELECT campaign_id FROM {$table_name} WHERE campaign_id IN ($placeholders) AND status = %s";
        $prepare_params = array_merge($ids, ['trash']); // merge before unpacking
        $existing_ids = $wpdb->get_col($wpdb->prepare($sql, ...$prepare_params));

        if (empty($existing_ids)) {
            return new \WP_Error('not_found', __('No campaign(s) in trash found.', 'mailerpress'), ['status' => 404]);
        }

        // Delete associated batches first
        $tableBatch = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $placeholders_existing = implode(',', array_fill(0, count($existing_ids), '%d'));
        $sql_delete_batches = "DELETE FROM {$tableBatch} WHERE campaign_id IN ($placeholders_existing)";
        $deleted_batches = $wpdb->query($wpdb->prepare($sql_delete_batches, ...$existing_ids));

        // Delete the selected campaigns
        $sql_delete = "DELETE FROM {$table_name} WHERE campaign_id IN ($placeholders_existing)";
        $deleted = $wpdb->query($wpdb->prepare($sql_delete, ...$existing_ids));

        if (false === $deleted) {
            return new \WP_Error(
                'db_delete_error',
                __('Failed to delete campaign(s).', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => sprintf(__('Campaign(s) permanently deleted: %d', 'mailerpress'), $deleted),
            'deleted_ids' => $existing_ids,
            'deleted_batches' => $deleted_batches !== false ? $deleted_batches : 0,
        ], 200);
    }


    #[Endpoint(
        'campaign/(?P<id>\d+)/rename',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function rename(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaign_id = (int)$request->get_param('id');
        $title = sanitize_text_field($request->get_param('title'));

        if (empty($title)) {
            return new \WP_Error('invalid_title', __('Title cannot be empty.', 'mailerpress'), ['status' => 400]);
        }

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT campaign_id FROM {$table_name} WHERE campaign_id = %d",
            $campaign_id
        ));

        if (!$campaign) {
            return new \WP_Error('not_found', __('Campaign not found.', 'mailerpress'), ['status' => 404]);
        }

        $updated = $wpdb->update(
            $table_name,
            [
                'name' => $title,
                'updated_at' => current_time('mysql'),
            ],
            [
                'campaign_id' => $campaign_id,
            ]
        );

        if (false === $updated) {
            return new \WP_Error('db_update_error', __('Failed to rename campaign.', 'mailerpress'), ['status' => 500]);
        }

        return new \WP_REST_Response(
            [
                'success' => true,
                'message' => __('Campaign renamed successfully.', 'mailerpress'),
                'campaign_id' => $campaign_id,
                'new_title' => $title,
            ],
            200
        );
    }


    #[Endpoint(
        'campaign/(?P<id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEdit'],
        args: [
            'id' => [
                'required' => true,
                'validate_callback' => [ArgsValidator::class, 'validateId'],
            ],
        ]
    )]
    public function edit(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaign_id = (int)$request->get_param('id');
        $name = sanitize_text_field($request->get_param('title'));
        $meta = $request->get_param('meta');

        // Vérifiez si la campagne existe
        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE campaign_id = %d", $campaign_id));

        if (!$campaign) {
            return new \WP_Error('not_found', __('Campaign not found.', 'mailerpress'), ['status' => 404]);
        }

        $current_user_id = get_current_user_id();


        // Préparer les données pour la mise à jour
        $data = [
            'name' => $name ?: $campaign->name, // Si "title" est vide, garder l'ancien
            'subject' => !empty($meta['emailConfig']['campaignSubject']) ? $meta['emailConfig']['campaignSubject'] : $campaign->subject,
            'status' => !empty($meta['status']) ? esc_attr($meta['status']) : $campaign->status,
            'email_type' => !empty($meta['emailConfig']['email_type']) ? esc_attr($meta['emailConfig']['email_type']) : $campaign->email_type,
            'content_html' => !empty($meta['json']) ? wp_json_encode($meta['json']) : wp_json_encode($campaign->content_html),
            'config' => !empty($meta['emailConfig']) ? wp_json_encode($meta['emailConfig']) : $campaign->config,
            'updated_at' => current_time('mysql'),
        ];

        if (empty($campaign->editing_user_id) || (int)$campaign->editing_user_id === $current_user_id) {
            $data['editing_user_id'] = $current_user_id;
            $data['editing_started_at'] = current_time('mysql');
        }

        // Mettre à jour les données dans la base de données
        $updated = $wpdb->update($table_name, $data, ['campaign_id' => $campaign_id]);

        if (false === $updated) {
            return new \WP_Error('db_update_error', __('Failed to update campaign.', 'mailerpress'), ['status' => 500]);
        }

        // Retourner une réponse de succès
        return new \WP_REST_Response(
            [
                'success' => true,
                'message' => __('Campaign updated successfully.', 'mailerpress'),
                'campaign_id' => $campaign_id,
                'updated_data' => $data,
            ],
            200
        );
    }

    #[Endpoint(
        'campaign/(?P<id>\d+)/settings',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEdit'],
        args: [
            'id' => [
                'required' => true,
                'validate_callback' => [ArgsValidator::class, 'validateId'],
            ],
        ]
    )]
    public function updateSettings(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaign_id = (int)$request->get_param('id');
        $table_name  = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE campaign_id = %d",
            $campaign_id
        ));

        if (!$campaign) {
            return new \WP_Error('not_found', __('Campaign not found.', 'mailerpress'), ['status' => 404]);
        }

        $email_config = $request->get_param('emailConfig');

        $existing_config = !empty($campaign->config)
            ? (array) json_decode($campaign->config, true)
            : [];

        $merged_config = array_merge($existing_config, (array) $email_config);

        $data = [
            'subject'             => !empty($email_config['campaignSubject']) ? sanitize_text_field($email_config['campaignSubject']) : $campaign->subject,
            'config'              => wp_json_encode($merged_config),
            'updated_at'          => current_time('mysql'),
            'editing_user_id'     => null,
            'editing_started_at'  => null,
        ];

        $updated = $wpdb->update($table_name, $data, ['campaign_id' => $campaign_id]);

        if (false === $updated) {
            return new \WP_Error('db_update_error', __('Failed to update campaign settings.', 'mailerpress'), ['status' => 500]);
        }

        return new \WP_REST_Response(['success' => true, 'campaign_id' => $campaign_id], 200);
    }

    #[Endpoint(
        'campaign/save-content/(?P<id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canEdit'],
        args: [
            'id' => [
                'required' => true,
                'validate_callback' => [ArgsValidator::class, 'validateId'],
            ],
        ]
    )]
    public function saveCampaignContent(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaign_id = (int)$request->get_param('id');
        $current_user = get_current_user_id();
        $content = $request->get_param('content');
        $html = $request->get_param('html');

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE campaign_id = %d",
            $campaign_id
        ));

        if (!$campaign) {
            return new \WP_Error('not_found', __('Campaign not found.', 'mailerpress'), ['status' => 404]);
        }

        // ✅ Check if campaign is locked by someone else
        if ($campaign->editing_user_id && (int)$campaign->editing_user_id !== (int)$current_user) {
            return new \WP_Error(
                'locked',
                __('This campaign is currently locked by another user.', 'mailerpress'),
                ['status' => 423] // 423 Locked
            );
        }

        // Prepare data for update
        $data = [
            'content_html' => wp_json_encode($content),
        ];

        $updated = $wpdb->update($table_name, $data, ['campaign_id' => $campaign_id]);

        if (false === $updated) {
            return new \WP_Error('db_update_error', __('Failed to update campaign.', 'mailerpress'), ['status' => 500]);
        }

        // Si le HTML est fourni (notamment pour les campagnes automation en draft), le stocker
        if (!empty($html)) {
            $optionKey = 'mailerpress_batch_' . $campaign_id . '_html';
            update_option($optionKey, $html, false);
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Campaign updated successfully.', 'mailerpress'),
            'campaign_id' => $campaign_id,
            'updated_data' => $data,
        ], 200);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'campaign/html',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function formatHTML(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $html = $request->get_param('html');

        return new \WP_REST_Response(
            Kernel::getContainer()->get(HtmlParser::class)->init(
                $html,
                [
                    'UNSUB_LINK' => home_url('/unsubsribe'),
                    'TRACK_CLICK' => home_url('/'), // base of your redirect handler

                ]
            )->replaceVariables(),
            200
        );
    }

    #[Endpoint(
        'campaign/contact/preview/',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function previewEmailByContact(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $contactId = esc_html($request->get_param('contact'));
        $html = $request->get_param('html');

        if (!empty($contactId && !empty($html))) {
            global $wpdb;
            $contactEntity = Kernel::getContainer()->get(Contacts::class)->get((int)$contactId);

            // Build base variables
            $variables = [
                'TRACK_CLICK' => home_url('/'), // your redirect endpoint
                'CONTACT_ID'  => (int) $contactEntity->contact_id,
                'CAMPAIGN_ID' => 297,
                'UNSUB_LINK' => wp_unslash(
                    \sprintf(
                        '%s&data=%s&cid=%s&batchId=%s',
                        mailerpress_get_page('unsub_page'),
                        esc_attr($contactEntity->unsubscribe_token),
                        esc_attr($contactEntity->access_token),
                        ''
                    )
                ),
                'MANAGE_SUB_LINK' => wp_unslash(
                    \sprintf(
                        '%s&cid=%s',
                        mailerpress_get_page('manage_page'),
                        esc_attr($contactEntity->access_token)
                    )
                ),
                'CONTACT_NAME' => esc_html($contactEntity->first_name) . ' ' . esc_html($contactEntity->last_name),
                'TRACK_OPEN' => get_rest_url(
                    null,
                    \sprintf('mailerpress/v1/campaign/track-open?contactId=%s&batchId=%s', $contactId, '')
                ),
                'contact_name' => \sprintf(
                    '%s %s',
                    esc_html($contactEntity->first_name),
                    esc_html($contactEntity->last_name)
                ),
                'contact_email' => \sprintf('%s', esc_html($contactEntity->email)),
                'contact_first_name' => \sprintf('%s', esc_html($contactEntity->first_name)),
                'contact_last_name' => \sprintf('%s', esc_html($contactEntity->last_name)),
            ];

            // Add custom fields to variables
            $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
            $customFields = $wpdb->get_results($wpdb->prepare(
                "SELECT field_key, field_value FROM {$customFieldsTable} WHERE contact_id = %d",
                (int) $contactEntity->contact_id
            ));

            if ($customFields) {
                foreach ($customFields as $customField) {
                    // Add custom field to variables using field_key as the key
                    $variables[$customField->field_key] = esc_html($customField->field_value ?? '');
                }
            }

            // Générer l'HTML personnalisé pour ce contact
            $parsed_html = Kernel::getContainer()->get(HtmlParser::class)->init(
                $html,
                $variables
            )->replaceVariables();

            return new \WP_REST_Response($parsed_html);
        }

        return new \WP_REST_Response(
            'error',
            400
        );
    }

    #[Endpoint(
        'campaign/create_batch',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canPublishCampaign'],
    )]
    public function createBatch(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $contacts = $request->get_param('contacts');
        $sendType = $request->get_param('sendType');
        $post = $request->get_param('post');
        $html = $request->get_param('htmlContent');
        $config = $request->get_param('config');
        $scheduledAt = $request->get_param('scheduledAt');

        // Fallback to global sender settings if fromName/fromTo are missing in config
        if (empty($config['fromTo']) || empty($config['fromName'])) {
            $globalSender = get_option('mailerpress_default_settings');
            if ($globalSender) {
                if (is_string($globalSender)) {
                    $globalSender = json_decode($globalSender, true);
                }
                if (is_array($globalSender)) {
                    if (empty($config['fromTo'])) {
                        $config['fromTo'] = $globalSender['fromAddress'] ?? '';
                    }
                    if (empty($config['fromName'])) {
                        $config['fromName'] = $globalSender['fromName'] ?? '';
                    }
                }
            }
        }

        $status = ('future' === $sendType) ? 'scheduled' : 'pending';

        // Convert scheduledAt from WP local time to UTC for storage.
        // This ensures the campaign fires at the correct absolute moment
        // even if the WP timezone setting is changed after scheduling.
        $utcScheduledAt = $scheduledAt;
        if (!empty($scheduledAt)) {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(wp_timezone_string());
            $dtForStorage = \DateTime::createFromFormat('Y-m-d H:i:s', $scheduledAt, $tz);
            if ($dtForStorage) {
                $utcScheduledAt = gmdate('Y-m-d H:i:s', $dtForStorage->getTimestamp());
            }
        }

        $wpdb->insert(
            Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES),
            [
                'status' => $status,
                'total_emails' => count($contacts),
                'sender_name' => $config['fromName'],
                'sender_to' => $config['fromTo'],
                'subject' => $config['subject'],
                'scheduled_at' => $utcScheduledAt,
                'campaign_id' => $post,
            ]
        );

        $batch_id = $wpdb->insert_id;

        if (!$batch_id || is_wp_error($batch_id)) {
            return new \WP_REST_Response(null, 400);
        }

        // Get frequency sending option with default fallback
        $frequencySending = get_option('mailerpress_frequency_sending', [
            "webHost" => "",
            "frequency" => "recommended",
            "settings" => [
                "numberEmail" => 25,
                "config" => [
                    "value" => 5,
                    "unit" => "minutes",
                ],
            ],
        ]);

        if (is_string($frequencySending)) {
            $decoded = json_decode($frequencySending, true);
            if (is_array($decoded)) {
                $frequencySending = $decoded;
            } else {
                // fallback to default if decode fails
                $frequencySending = [
                    "webHost" => "",
                    "frequency" => "recommended",
                    "settings" => [
                        "numberEmail" => 25,
                        "config" => [
                            "value" => 5,
                            "unit" => "minutes",
                        ],
                    ],
                ];
            }
        }

        // Extract numberEmail and config properly from settings
        $numberEmail = $frequencySending['settings']['numberEmail'] ?? 25;
        $frequencyConfig = $frequencySending['settings']['config'] ?? ['value' => 5, 'unit' => 'minutes'];

        $contact_chunks = array_chunk($contacts, $numberEmail);

        $now = time();

        $unit_multipliers = [
            'seconds' => 1,
            'minutes' => MINUTE_IN_SECONDS,
            'hours' => HOUR_IN_SECONDS,
        ];

        $interval_seconds = ($frequencyConfig['value'] ?? 5) * ($unit_multipliers[$frequencyConfig['unit']] ?? MINUTE_IN_SECONDS);

        foreach ($contact_chunks as $chunk_index => $contact_chunk) {

            $hook_name = 'mailerpress_process_contact_chunk';

            // Generate a unique transient key for this chunk
            $transient_key = 'mailerpress_chunk_' . $batch_id . '_' . $chunk_index;

            $datetime = new DateTime($scheduledAt, wp_timezone());
            $scheduledAt = $datetime->format('Y-m-d H:i:s');

            set_transient($transient_key, [
                'html' => $html,
                'subject' => $config['subject'],
                'contacts' => $contact_chunk,
                'scheduled_at' => $datetime,
                'webhook_url' => get_rest_url(null, 'mailerpress/v1/webhook/notify'),
                'sendType' => $sendType,
            ], 12 * HOUR_IN_SECONDS);

            $scheduled_time = $now + ($chunk_index * $interval_seconds);

            as_schedule_single_action(
                $scheduled_time,
                $hook_name,
                [$batch_id, $transient_key],
                'mailerpress'
            );
        }

        do_action('mailerpress_batch_event', $status, $post, $batch_id);

        return new \WP_REST_Response($batch_id, 200);
    }

    #[Endpoint(
        'campaign/create_batch_V2',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canPublishCampaign'],
    )]
    public function createBatchV2(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        if (!current_user_can(Capabilities::PUBLISH_CAMPAIGNS)) {
            return new WP_Error(
                'mailerpress_no_permission',
                __('You do not have permission to create a campaign batch.', 'mailerpress'),
                ['status' => 403]
            );
        }

        $sendType = $request->get_param('sendType');
        $post = $request->get_param('postEdit');
        $html = $request->get_param('html');
        $config = $request->get_param('config');
        $scheduledAt = $request->get_param('scheduledAt');

        // Fallback to global sender settings if fromName/fromTo are missing in config
        if (empty($config['fromTo']) || empty($config['fromName'])) {
            $globalSender = get_option('mailerpress_default_settings');
            if ($globalSender) {
                if (is_string($globalSender)) {
                    $globalSender = json_decode($globalSender, true);
                }
                if (is_array($globalSender)) {
                    if (empty($config['fromTo'])) {
                        $config['fromTo'] = $globalSender['fromAddress'] ?? '';
                    }
                    if (empty($config['fromName'])) {
                        $config['fromName'] = $globalSender['fromName'] ?? '';
                    }
                }
            }
        }
        $recipientTargeting = $request->get_param('recipientTargeting') ?? null;
        $lists = $request->get_param('lists') ?? [];
        $tags = $request->get_param('tags') ?? [];
        $segment = $request->get_param('segment') ?? [];
        $openTracking = $request->get_param('openTracking') ?? 'yes';
        $clickTracking = $request->get_param('clickTracking') ?? 'yes';

        update_option('mailerpress_batch_' . $post . '_html', $html, false);

        // Get subject from config or fallback to campaign title
        $subject = $config['subject'] ?? '';
        if (empty($subject) && !empty($post)) {
            $campaign = get_post($post);
            $subject = $campaign ? $campaign->post_title : '';
        }

        // Calculate total number of contacts before creating the batch
        $total_emails = 0;
        try {
            $fetcher = $this->getContactFetcher($recipientTargeting, $lists, $tags, $segment);
            if ($fetcher) {
                // Fetch contacts in chunks to count them
                $chunk_size = 1000;
                $offset = 0;
                do {
                    $contacts = $fetcher->fetch($chunk_size, $offset);
                    $total_emails += count($contacts);
                    $offset += $chunk_size;
                } while (count($contacts) === $chunk_size);
            }
        } catch (\Exception $e) {
            // If counting fails, use 0 (will be updated later in MailerPressEmailBatch)
        }

        // Create batch immediately so it can be displayed in the UI
        // Convert scheduledAt (WP-timezone naive string) to UTC for consistent storage.
        // The chunks table also stores scheduled_at in UTC; dateI18n on the frontend
        // expects UTC and converts to WP timezone for display.
        $status = ('future' === $sendType) ? 'scheduled' : 'pending';
        $utcScheduledAt = null;
        if (!empty($scheduledAt)) {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(wp_timezone_string());
            $dtForStorage = \DateTime::createFromFormat('Y-m-d H:i:s', $scheduledAt, $tz);
            if ($dtForStorage) {
                $utcScheduledAt = gmdate('Y-m-d H:i:s', $dtForStorage->getTimestamp());
            }
        }
        $wpdb->insert(
            Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES),
            [
                'status' => $status,
                'total_emails' => $total_emails,
                'sender_name' => $config['fromName'] ?? '',
                'sender_to' => $config['fromTo'] ?? '',
                'subject' => $subject,
                'scheduled_at' => $utcScheduledAt,
                'campaign_id' => $post,
            ]
        );

        $batch_id = $wpdb->insert_id;
        if (!$batch_id) {
            return new \WP_Error(
                'mailerpress_batch_creation_failed',
                __('Failed to create batch', 'mailerpress'),
                ['status' => 500]
            );
        }

        // Calculate the scheduled time for the action
        $scheduled_time = time() + 5; // Default: 5 seconds from now for immediate sending
        if ('future' === $sendType && !empty($scheduledAt)) {
            // Convert scheduledAt to timestamp
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(wp_timezone_string());
            try {
                // Handle different date formats
                // If it's an ISO string with timezone, parse it directly
                // If it's a date string without timezone, assume it's in WordPress timezone
                if (is_string($scheduledAt)) {
                    // Try to parse as ISO 8601 first
                    $dt = \DateTime::createFromFormat(\DateTime::ISO8601, $scheduledAt);
                    if (!$dt) {
                        // Try WordPress date format (Y-m-d H:i:s)
                        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $scheduledAt, $tz);
                    }
                    if (!$dt) {
                        // Try parsing with DateTime constructor (will use provided timezone)
                        $dt = new \DateTime($scheduledAt, $tz);
                    }
                } else {
                    // If it's not a string, try to convert it
                    $dt = new \DateTime($scheduledAt, $tz);
                }

                if ($dt) {
                    $scheduled_timestamp = $dt->getTimestamp();
                    if ($scheduled_timestamp > time()) {
                        $scheduled_time = $scheduled_timestamp;
                    } else {
                        // Scheduled date is in the past — reject the request
                        return new \WP_Error(
                            'mailerpress_scheduled_date_past',
                            __('The scheduled date is in the past. Please select a future date and time.', 'mailerpress'),
                            ['status' => 422]
                        );
                    }
                }
            } catch (\Exception $e) {
                \MailerPress\Services\Logger::error('Failed to parse scheduledAt', [
                    'message' => $e->getMessage(),
                    'scheduledAt' => $scheduledAt,
                ]);
                return new \WP_Error(
                    'mailerpress_scheduled_date_invalid',
                    __('Invalid scheduled date format.', 'mailerpress'),
                    ['status' => 422]
                );
            }
        }

        as_schedule_single_action(
            $scheduled_time,
            'mailerpress_batch_email',
            [
                $sendType,
                $post,
                $config,
                $utcScheduledAt ?? $scheduledAt, // Pass UTC so timezone changes after scheduling don't affect execution
                $recipientTargeting,
                $lists,
                $tags,
                $segment,
                $openTracking,
                $clickTracking,
            ],
            'mailerpress'
        );

        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Définir le statut correct selon le type d'envoi
        // Si sendType est 'future', la campagne est programmée, sinon elle est en attente
        $campaign_status = ('future' === $sendType) ? 'scheduled' : 'pending';

        $wpdb->update(
            $table_name,
            [
                'status' => $campaign_status,
                'batch_id' => $batch_id,
                'updated_at' => current_time('mysql'), // Set to the current timestamp
            ],
            ['campaign_id' => intval($post)], // Where condition
            ['%s', '%d', '%s'], // Data format: string for status, integer for batch_id, string for timestamp
            ['%d']        // Where condition format: integer for campaign_id
        );

        $wpdb->update(
            $table_name,
            [
                'editing_user_id' => null,
                'editing_started_at' => null
            ],
            ['campaign_id' => $post,]
        );

        // Remove all pending unlock requests
        delete_transient("campaign_{$post}_unlock_requests");

        // Trigger webhook if campaign is scheduled
        if ('future' === $sendType) {
            do_action('mailerpress_campaign_scheduled', intval($post), [
                'batch_id' => $batch_id,
                'scheduled_time' => $scheduled_time,
                'total_emails' => $total_emails,
            ]);
        }

        return new \WP_REST_Response(['pending'], 200);
    }


    #[Endpoint(
        'campaign/update_automated_campaign',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function updateAutomatedCampaign(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaignId = (int)$request->get_param('campaignId');
        $html = $request->get_param('html');
        $data = $request->get_param('data');

        // Validate inputs
        if (!$campaignId || empty($html)) {
            return new \WP_Error(
                'invalid_parameters',
                'Missing or invalid campaignId or html',
                ['status' => 400]
            );
        }

        $table = $wpdb->prefix . 'mailerpress_campaigns';

        // Check if campaign exists
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE campaign_id = %d", $campaignId)
        );

        if (!$exists) {
            return new \WP_Error(
                'campaign_not_found',
                'Campaign not found',
                ['status' => 404]
            );
        }

        // Update the content_html in the database
        $updated = $wpdb->update(
            $table,
            ['content_html' => json_encode($data)],
            ['campaign_id' => $campaignId],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return new \WP_Error(
                'update_failed',
                'Failed to update campaign HTML content',
                ['status' => 500]
            );
        }

        // Update the HTML version in the WordPress options
        // Stocker le HTML même si l'option n'existe pas encore (pour les campagnes automation en draft)
        $optionKey = 'mailerpress_batch_' . $campaignId . '_html';
        update_option($optionKey, $html, false);

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Campaign HTML content and option updated successfully',
        ]);
    }


    /**
     * @throws \Exception
     */
    #[Endpoint(
        'campaign/create_automated_campaign',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function createAutomatedCampaign(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $sendType = $request->get_param('sendType');
        $post = intval($request->get_param('postEdit'));
        $html = $request->get_param('html');
        $config = $request->get_param('config');
        $scheduledAt = $request->get_param('scheduledAt');
        $recipientTargeting = $request->get_param('recipientTargeting') ?? null;
        $lists = $request->get_param('lists') ?? [];
        $tags = $request->get_param('tags') ?? [];
        $segment = $request->get_param('segment') ?? [];
        $automateSettings = $request->get_param('automateSettings') ?? null;

        // Store HTML separately
        update_option('mailerpress_batch_' . $post . '_html', $html, false);

        // Get existing config from DB
        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT config FROM {$table_name} WHERE campaign_id = %d", $post));

        $currentConfig = [];
        if ($existing && $existing->config) {
            $currentConfig = json_decode($existing->config, true) ?? [];
        }

        // Merge automateSettings into config
        if ($automateSettings) {
            $currentConfig['automateSettings'] = $automateSettings;
        }

        // Update the campaign
        $wpdb->update(
            $table_name,
            [
                'status' => 'active',
                'campaign_type' => 'automated',
                'updated_at' => current_time('mysql'),
                'config' => wp_json_encode($currentConfig),
            ],
            ['campaign_id' => $post],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        // Schedule the first run of the automation
        mailerpress_schedule_automated_campaign(
            $post,
            $sendType,
            $config,
            $scheduledAt,
            $recipientTargeting,
            $lists,
            $tags,
            $segment,
        );

        return new \WP_REST_Response(['pending'], 200);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'campaign/send_test',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canPublishCampaign'],
    )]
    public function sendTest(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $contacts = $request->get_param('contacts');
        $body = $request->get_param('htmlContent');
        $subject = sanitize_text_field($request->get_param('subject'));

        // 🔧 Sanitize HTML to remove/replace merge tags for test emails
        // This prevents ESP parsing errors when merge tags like {{variable}} remain in the HTML
        // Test emails don't have contact context, so merge tags are replaced with:
        // - Their default values (if specified as {{var default="value"}})
        // - Empty strings (if no default)
        $body = \MailerPress\Core\HtmlParser::sanitizeTestEmail($body);

        $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
        $config = $mailer->getConfig();

        if (
            empty($config['conf']['default_email'])
            || empty($config['conf']['default_name'])
        ) {
            $globalSender = get_option('mailerpress_default_settings');

            if ($globalSender) {
                if (is_string($globalSender)) {
                    $globalSender = json_decode($globalSender, true);
                }

                if (is_array($globalSender)) {
                    $config['conf']['default_email'] = $globalSender['fromAddress'] ?? '';
                    $config['conf']['default_name'] = $globalSender['fromName'] ?? '';
                }
            }
        }

        $success = [];
        $errors = [];

        foreach ($contacts as $contact) {
            try {
                $mailer->sendEmail([
                    'to' => $contact,
                    'html' => true,
                    'body' => $body,
                    'subject' => /* translators: %s is the subject of the email */ sprintf(__(
                        '[MailerPress TEST] - %s',
                        'mailerpress'
                    ), $subject),
                    'sender_name' => $config['conf']['default_name'],
                    'sender_to' => $config['conf']['default_email'],
                    'apiKey' => $config['conf']['api_key'] ?? '',
                ]);
                $success[] = $contact;
            } catch (\Exception $e) {
                $errors[] = [
                    'contact' => $contact,
                    'message' => $e->getMessage()
                ];
            }
        }

        return new \WP_REST_Response([
            'status' => empty($errors) ? 'success' : 'partial',
            'sent' => $success,
            'errors' => $errors,
        ], empty($errors) ? 200 : 207); // 207: Multi-Status (partial success)
    }


    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'campaign/pause_batch',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function mailerpress_cancel_batch_actions(\WP_REST_Request $request)
    {
        global $wpdb;

        $batch_id = (int)$request->get_param('batchId');
        $campaign_id = (int)$request->get_param('campaignId');

        if (!$batch_id) {
            return new \WP_REST_Response(['error' => __('Missing batchId', 'mailerpress')], 400);
        }

        // Get all actions for this batch (you can pass a reduced status list if preferred)
        $asActions = $this->mailerpress_get_chunk_actions_for_batch($batch_id);

        $store = \ActionScheduler_Store::instance();

        foreach ($asActions as $action_id => $action) {
            $args = $action->get_args();

            // Arg[1] is our transient key: 'mailerpress_chunk_{batch_id}_{chunk_index}'
            if (isset($args[1])) {
                delete_transient($args[1]);
            }

            // Cancel first (safe; marks it as canceled and prevents execution)
            try {
                $store->cancel_action($action_id);
            } catch (\Exception $e) {
            }

            // Hard delete (optional). Comment out if you want history.
            try {
                $store->delete_action($action_id);
            } catch (\Exception $e) {
            }
        }

        // NEW: Delete chunks from mailerpress_email_chunks table
        $chunks_deleted = 0;
        if ($batch_id) {
            $chunks_table = Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS);

            // Count chunks before deletion
            $chunks_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$chunks_table} WHERE batch_id = %d",
                $batch_id
            ));

            // Delete all chunks for this batch
            $wpdb->delete(
                $chunks_table,
                ['batch_id' => $batch_id],
                ['%d']
            );

            $chunks_deleted = (int) $chunks_count;
        }

        // CRITICAL: Cancel mailerpress_batch_email action for scheduled campaigns
        // This prevents the campaign from executing at its scheduled time
        $actions_cancelled = 0;
        if ($campaign_id && function_exists('as_get_scheduled_actions')) {
            $batch_email_actions = as_get_scheduled_actions([
                'hook' => 'mailerpress_batch_email',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 500,
                'group' => 'mailerpress',
            ]);

            foreach ($batch_email_actions as $action_id => $action) {
                $args = $action->get_args();
                // args[1] contains the campaign_id
                if (!empty($args[1]) && (int)$args[1] === $campaign_id) {
                    try {
                        $store->cancel_action($action_id);
                        $store->delete_action($action_id);
                        $actions_cancelled++;

                    } catch (\Exception $e) {
                        error_log(sprintf(
                            '[pause_batch] Failed to cancel mailerpress_batch_email action #%d: %s',
                            $action_id,
                            $e->getMessage()
                        ));
                    }
                }
            }
        }

        // Set campaign as draft and remove the batch_id
        if ($campaign_id) {
            $wpdb->update(
                Tables::get(Tables::MAILERPRESS_CAMPAIGNS),
                [
                    'status' => 'draft',
                    'batch_id' => null
                ],
                ['campaign_id' => $campaign_id],
                ['%s', 'NULL'],
                ['%d']
            );
        }

        // Delete the batch record
        $wpdb->delete(
            Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES),
            ['id' => $batch_id],
            ['%d']
        );


        return new \WP_REST_Response([
            'batchId' => $batch_id,
            'campaignId' => $campaign_id,
            'canceled' => array_keys($asActions),
            'chunks_deleted' => $chunks_deleted,
            'batch_email_actions_cancelled' => $actions_cancelled,
            'status' => 'draft',
        ], 200);
    }


    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'campaign/(?P<id>\d+)/deactivate',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function mailerpress_deactivate_automated_campaign(\WP_REST_Request $request)
    {
        global $wpdb;

        $campaign_id = (int)$request->get_param('id');

        if (!$campaign_id) {
            return new \WP_REST_Response(['error' => __('Missing campaign ID', 'mailerpress')], 400);
        }

        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $campaign = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT campaign_id, campaign_type, status FROM $table WHERE campaign_id = %d",
                $campaign_id
            ),
            ARRAY_A
        );

        if (!$campaign) {
            return new \WP_REST_Response(['error' => __('Campaign not found', 'mailerpress')], 404);
        }

        if ($campaign['campaign_type'] !== 'automated') {
            return new \WP_REST_Response(['error' => __('Only automated campaigns can be deactivated', 'mailerpress')], 400);
        }

        $wpdb->update(
            $table,
            ['status' => 'inactive'],
            ['campaign_id' => $campaign_id],
            ['%s'],
            ['%d']
        );

        return new \WP_REST_Response([
            'campaignId' => $campaign_id,
            'status' => 'inactive',
            'message' => 'Campaign deactivated successfully',
        ], 200);
    }


    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'campaign/(?P<id>\d+)/activate',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function mailerpress_activate_automated_campaign(\WP_REST_Request $request)
    {
        global $wpdb;

        $campaign_id = (int)$request->get_param('id');

        if (!$campaign_id) {
            return new \WP_REST_Response(['error' => __('Missing campaign ID', 'mailerpress')], 400);
        }

        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $campaign = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT campaign_id, campaign_type, status FROM $table WHERE campaign_id = %d",
                $campaign_id
            ),
            ARRAY_A
        );

        if (!$campaign) {
            return new \WP_REST_Response(['error' => __('Campaign not found', 'mailerpress')], 404);
        }

        if ($campaign['campaign_type'] !== 'automated') {
            return new \WP_REST_Response(['error' => __('Only automated campaigns can be activated', 'mailerpress')], 400);
        }

        // Optional: Only allow activation if not already active
        if ($campaign['status'] === 'scheduled') {
            return new \WP_REST_Response(['message' => 'Campaign is already active'], 200);
        }

        $wpdb->update(
            $table,
            ['status' => 'active'],
            ['campaign_id' => $campaign_id],
            ['%s'],
            ['%d']
        );

        return new \WP_REST_Response([
            'campaignId' => $campaign_id,
            'status' => 'active',
            'message' => 'Campaign activated successfully',
        ], 200);
    }


    /**
     * Return all AS actions for the given MailerPress batch (chunk sends).
     *
     * @param int $batch_id
     * @param array|null $statuses Optional list of AS statuses to include.
     * @return array [ action_id => ActionScheduler_Action ]
     */
    private function mailerpress_get_chunk_actions_for_batch($batch_id, $statuses = null)
    {
        if (null === $statuses) {
            $statuses = [
                ActionScheduler_Store::STATUS_PENDING,
                ActionScheduler_Store::STATUS_COMPLETE,
                ActionScheduler_Store::STATUS_RUNNING,   // include in-progress
                ActionScheduler_Store::STATUS_FAILED,    // include failures
                ActionScheduler_Store::STATUS_CANCELED,  // include canceled
            ];
        }

        $store = ActionScheduler_Store::instance();
        $found = [];
        $limit = 100; // page size; tune as needed

        foreach ($statuses as $status) {
            $offset = 0;

            do {
                $ids = $store->query_actions([
                    'hook' => 'mailerpress_process_contact_chunk',
                    'group' => 'mailerpress',
                    'status' => $status,
                    'per_page' => $limit,
                    'offset' => $offset,
                ]);

                if (empty($ids)) {
                    break;
                }

                foreach ($ids as $id) {
                    $action = $store->fetch_action($id);
                    if (!$action) {
                        continue;
                    }

                    $args = $action->get_args();
                    // Our scheduled actions use [ $batch_id, $transient_key ]
                    if (isset($args[0]) && (int)$args[0] === (int)$batch_id) {
                        $found[$id] = $action;
                    }
                }

                $offset += $limit;
            } while (count($ids) === $limit);
        }

        return $found;
    }


    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'campaign/resume_batch',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function resumeBatch(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $batch_id = $request->get_param('batchId');

        $wpdb->update(
            "{$wpdb->prefix}mailerpress_email_batches",
            ['status' => 'pending'],
            ['id' => $batch_id],
            ['%s'],    // Format de la valeur du champ 'status' (NULL est traité comme une chaîne vide)
            ['%d']     // Format de la condition (id)
        );

        return new \WP_REST_Response([], 200);
    }


    /**
     * Track email opens for both campaign emails (with batch) and transactional/workflow emails (without batch)
     *
     * For transactional emails (no batch_id):
     * - Only triggers workflow re-evaluation
     * - Does NOT update contact_stats table
     *
     * For campaign emails (with batch_id):
     * - Updates email_tracking table
     * - Updates contact_stats table
     * - Triggers workflow re-evaluation
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint('campaign/track-open', methods: 'GET')]
    public function trackOpen(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        // Get token from query string (new unified system)
        $query_params = $request->get_query_params();
        $token = $request->get_param('token') ?? ($query_params['token'] ?? null);

        // Decode token to get tracking information
        if (empty($token)) {
            return new \WP_Error('invalid_input', 'Token is required.', ['status' => 400]);
        }

        $data = \MailerPress\Core\HtmlParser::decodeTrackingToken($token);

        // Note: cid can be 0 for anonymous tracking, so we use isset() instead of empty()
        if (!$data || !isset($data['cid']) || empty($data['cmp'])) {
            return new \WP_Error('invalid_token', 'Invalid or corrupted tracking token.', ['status' => 400]);
        }

        // Extract data from token
        $contact_id = (int) ($data['cid'] ?? 0);
        $campaign_id = (int) ($data['cmp'] ?? 0);
        $batch_id = isset($data['batch']) ? (int) $data['batch'] : null;
        $job_id = isset($data['job']) ? (int) $data['job'] : null;
        $step_id = isset($data['step']) ? (string) $data['step'] : null;
        $anonymous_key = isset($data['ank']) ? sanitize_text_field($data['ank']) : null;

        // Normalize empty values to null
        if ($batch_id !== null && $batch_id <= 0) {
            $batch_id = null;
        }
        if ($campaign_id <= 0) {
            return new \WP_Error('invalid_token', 'Invalid campaign ID in token.', ['status' => 400]);
        }

        // Determine if this is anonymous tracking (contact_id = 0)
        $isAnonymousTracking = ($contact_id === 0);

        // For transactional emails, if contact_id is 0 or invalid, get it from job
        // But only if it's NOT anonymous tracking for a campaign email
        if ($contact_id <= 0 && !empty($job_id) && empty($batch_id)) {
            $job = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->prefix}mailerpress_automations_jobs WHERE id = %d",
                    (int) $job_id
                )
            );
            if ($job && !empty($job->user_id)) {
                $contact_id = (int) $job->user_id; // Use user_id as contact_id for non-subscribers
                $isAnonymousTracking = false;
            }
        }

        // For transactional emails without job_id, we need a valid contact_id
        // For campaign emails (with batch_id), anonymous tracking is allowed
        if ($contact_id <= 0 && empty($batch_id)) {
            return new \WP_Error('invalid_token', 'Could not determine contact ID from token.', ['status' => 400]);
        }

        // Determine if this is a transactional email (workflow) or campaign email
        $isTransactional = empty($batch_id);

        if ($isTransactional) {
            // ============================================
            // TRANSACTIONAL EMAIL (WORKFLOW) - No batch
            // ============================================

            // For transactional emails, campaign_id is required
            if (empty($campaign_id)) {
                return new \WP_Error('invalid_input', 'Campaign ID is required for transactional emails.', ['status' => 400]);
            }

            // For transactional emails, we need to:
            // 1. Update contact_stats so conditions can check if email was opened
            // 2. Trigger workflow re-evaluation
            // We get user_id directly from the workflow job using jobId

            $userId = null;

            // Get user_id directly from the workflow job table
            if (!empty($job_id)) {
                $job = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->prefix}mailerpress_automations_jobs WHERE id = %d",
                        (int) $job_id
                    )
                );

                if ($job && !empty($job->user_id)) {
                    $userId = (int) $job->user_id;
                }
            }

            // Update contact_stats table for transactional emails
            // This is CRITICAL so conditions can verify if email was opened after it was sent
            // For non-subscribers, contact_id is user_id - we still track it
            if ($campaign_id) {
                // If contact_id equals user_id, it means user is not a MailerPress subscriber
                // We use user_id as contact_id to track them
                if (!empty($userId) && $contact_id === $userId) {
                    // contact_id already equals user_id, which is correct for non-subscribers
                } elseif (!empty($userId) && $contact_id <= 0) {
                    // If contact_id is 0 or negative, use user_id instead
                    $contact_id = $userId;
                }
                $contactStatsTable = $wpdb->prefix . 'mailerpress_contact_stats';
                $openedAt = current_time('mysql');

                $contactStats = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT opened, clicked, click_count FROM {$contactStatsTable} WHERE contact_id = %d AND campaign_id = %d",
                        $contact_id,
                        $campaign_id
                    )
                );

                if ($contactStats) {
                    // Increment opened count
                    $newOpened = (int) $contactStats->opened + 1;

                    $wpdb->update(
                        $contactStatsTable,
                        [
                            'opened'     => $newOpened,
                            'updated_at' => $openedAt,  // CRITICAL: Update this so conditions can check if email was opened
                        ],
                        [
                            'contact_id'  => $contact_id,
                            'campaign_id' => $campaign_id,
                        ],
                        ['%d', '%s'],
                        ['%d', '%d']
                    );
                } else {
                    // Insert new row
                    $wpdb->insert(
                        $contactStatsTable,
                        [
                            'contact_id'  => $contact_id,
                            'campaign_id' => $campaign_id,
                            'opened'      => 1,
                            'clicked'     => 0,
                            'click_count' => 0,
                            'status'      => 'neutral',
                            'created_at'  => $openedAt,
                            'updated_at'  => $openedAt,  // CRITICAL: Set this so conditions can check if email was opened
                        ],
                        ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
                    );
                }
            }

            // Update A/B Test participant if this is an A/B test email
            if ($campaign_id && $userId) {
                \MailerPress\Actions\Workflows\MailerPress\Actions\ABTestStepHandler::updateParticipantOpen($campaign_id, $userId);
            }

            if ($userId) {
                // Trigger workflow re-evaluation
                $workflowSystem = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
                $executor = $workflowSystem->getManager()->getExecutor();
                $reevaluated = $executor->reevaluateWaitingJobs($userId, $campaign_id, 'mp_email_opened');
            }
        } else {
            // ============================================
            // CAMPAIGN EMAIL (WITH BATCH)
            // ============================================

            // Get campaign_id from batch if not provided
            if (empty($campaign_id)) {
                $campaign_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT campaign_id FROM {$wpdb->prefix}mailerpress_email_batches WHERE id = %d",
                        (int) $batch_id
                    )
                );
            }

            if (empty($campaign_id)) {
                return new \WP_Error('invalid_input', 'Campaign ID could not be determined from batch.', ['status' => 400]);
            }

            // Update email_tracking table
            $table = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);

            // For anonymous tracking, use anonymous_key to avoid duplicates
            // For identified contacts, check if record exists and update or insert
            if ($isAnonymousTracking) {
                // Check if record already exists for this anonymous user
                $existing = null;
                if (!empty($anonymous_key)) {
                    $existing = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$table} WHERE batch_id = %d AND anonymous_key = %s",
                            $batch_id,
                            $anonymous_key
                        )
                    );
                }

                if (empty($existing)) {
                    // Insert new record for anonymous tracking
                    $data = [
                        'batch_id' => $batch_id,
                        'contact_id' => 0,
                        'anonymous_key' => $anonymous_key,
                        'opened_at' => current_time('mysql'),
                        'clicks' => 0,
                        'unsubscribed_at' => null,
                    ];
                    $format = ['%d', '%d', '%s', '%s', '%d', '%s'];
                    $wpdb->insert($table, $data, $format);
                }
                // If record exists, don't create duplicate (statistics are already correct)
            } else {
                $existing = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE contact_id = %d AND batch_id = %d",
                        $contact_id,
                        $batch_id
                    )
                );

                if (empty($existing)) {
                    $data = [
                        'batch_id' => $batch_id,
                        'contact_id' => $contact_id,
                        'opened_at' => current_time('mysql'),
                        'clicks' => 0,
                        'unsubscribed_at' => null,
                    ];

                    $format = ['%d', '%d', '%s', '%d', '%s'];

                    $row_exists = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$table} WHERE batch_id = %d AND contact_id = %d",
                            $batch_id,
                            $contact_id
                        )
                    );

                    if ($row_exists) {
                        $wpdb->update(
                            $table,
                            [
                                'opened_at' => $data['opened_at'],
                                'clicks' => $data['clicks'],
                                'unsubscribed_at' => $data['unsubscribed_at'],
                            ],
                            [
                                'batch_id' => $batch_id,
                                'contact_id' => $contact_id,
                            ],
                            ['%s', '%d', '%s'],
                            ['%d', '%d']
                        );
                    } else {
                        $wpdb->insert($table, $data, $format);
                    }
                }
            }

            // Update contact_stats table (skip for anonymous tracking)
            if (!$isAnonymousTracking && $contact_id > 0) {
                $contactStatsTable = $wpdb->prefix . 'mailerpress_contact_stats';

                $contactStats = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT opened, clicked, click_count FROM {$contactStatsTable} WHERE contact_id = %d AND campaign_id = %d",
                        $contact_id,
                        $campaign_id
                    )
                );

                $openedAt = current_time('mysql');

                if ($contactStats) {
                    // Increment opened count
                    $newOpened = (int) $contactStats->opened + 1;

                    $wpdb->update(
                        $contactStatsTable,
                        [
                            'opened'     => $newOpened,
                            'updated_at' => $openedAt,
                        ],
                        [
                            'contact_id'  => $contact_id,
                            'campaign_id' => $campaign_id,
                        ],
                        ['%d', '%s'],
                        ['%d', '%d']
                    );
                } else {
                    // Insert new row
                    $wpdb->insert(
                        $contactStatsTable,
                        [
                            'contact_id'  => $contact_id,
                            'campaign_id' => $campaign_id,
                            'opened'      => 1,
                            'clicked'     => 0,
                            'click_count' => 0,
                            'status'      => 'neutral',
                            'created_at'  => $openedAt,
                            'updated_at'  => $openedAt,
                        ],
                        ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
                    );
                }
            }

            // Update status based on interaction (skip for anonymous tracking)
            if (!$isAnonymousTracking && $contact_id > 0 && isset($contactStats)) {
                $status = 'neutral';
                if (!empty($contactStats->clicked) || (!empty($contactStats->click_count) && $contactStats->click_count > 0)) {
                    $status = 'good';
                } elseif (!empty($contactStats->opened) || (isset($newOpened) && $newOpened > 0)) {
                    $status = 'neutral';
                } else {
                    $status = 'bad';
                }

                $wpdb->update(
                    $contactStatsTable,
                    ['status' => $status],
                    [
                        'contact_id'  => $contact_id,
                        'campaign_id' => $campaign_id,
                    ],
                    ['%s'],
                    ['%d', '%d']
                );

                // Re-evaluate waiting workflows for campaign emails too
                $contact = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT email FROM {$wpdb->prefix}mailerpress_contact WHERE contact_id = %d",
                        $contact_id
                    )
                );

                $userId = null;

                if ($contact && !empty($contact->email)) {
                    // Find user by email from WordPress users table
                    $user = \get_user_by('email', $contact->email);
                    if ($user) {
                        $userId = (int) $user->ID;
                    }
                }

                // Update A/B Test participant if this is an A/B test email
                // Use contact_id as user_id if no WordPress user found (for non-subscribers)
                $abTestUserId = $userId ?: $contact_id;
                if ($campaign_id && $abTestUserId) {
                    \MailerPress\Actions\Workflows\MailerPress\Actions\ABTestStepHandler::updateParticipantOpen($campaign_id, $abTestUserId);
                }

                if ($userId) {
                    $workflowSystem = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
                    $executor = $workflowSystem->getManager()->getExecutor();
                    $reevaluated = $executor->reevaluateWaitingJobs($userId, $campaign_id, 'mp_email_opened');
                }
            }
        }

        // Fire webhook for email opened (non-anonymous only)
        if (!$isAnonymousTracking && $contact_id > 0) {
            do_action('mailerpress_email_opened', $contact_id, $campaign_id, $batch_id);
        }

        // Send a transparent 1x1 pixel image
        header('Content-Type: image/png');
        $base64_image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
        echo base64_decode($base64_image);
        exit;
    }


    #[Endpoint(
        'campaign/(?P<id>\d+)/lock',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function mailerpress_lock_campaign(WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $campaign_id = intval($request['id']);
        $user_id = get_current_user_id();
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        global $wpdb;

        $lock = $wpdb->get_row($wpdb->prepare(
            "SELECT editing_user_id, editing_started_at FROM $table WHERE campaign_id = %d",
            $campaign_id
        ));

        $lock_timeout = strtotime('-5 minutes');

        if ($lock && $lock->editing_user_id && $lock->editing_user_id != $user_id) {
            if (strtotime($lock->editing_started_at) > $lock_timeout) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Cette campagne est en cours d’édition par un autre utilisateur.'
                ], 423); // 423 Locked
            }
        }

        $wpdb->update(
            $table,
            [
                'editing_user_id' => $user_id,
                'editing_started_at' => current_time('mysql')
            ],
            ['campaign_id' => $campaign_id]
        );

        return new WP_REST_Response(['success' => true]);
    }


    #[Endpoint(
        'campaign/(?P<id>\d+)/unlock-requests',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function mailerpress_unlock_requests_campaign(
        WP_REST_Request $request
    ): \WP_Error|\WP_HTTP_Response|\WP_REST_Response {
        $campaign_id = intval($request['id']);
        $requests = get_transient("campaign_{$campaign_id}_unlock_requests") ?: [];
        return new WP_REST_Response(['requests' => $requests]);
    }

    #[Endpoint(
        'campaign/(?P<id>\d+)/add-unlock-request',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function mailerpress_add_unlock_request_campaign(
        WP_REST_Request $request
    ): \WP_Error|\WP_HTTP_Response|\WP_REST_Response {
        $campaign_id = intval($request['id']);
        $user_id = get_current_user_id();
        add_unlock_request($campaign_id, $user_id);
        return new WP_REST_Response(['success' => true]);
    }

    #[Endpoint(
        'campaign/(?P<id>\d+)/deny-unlock-request',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function mailerpress_deny_unlock_request_campaign(
        WP_REST_Request $request
    ): \WP_Error|\WP_HTTP_Response|\WP_REST_Response {
        $campaign_id = intval($request['id']);
        $user_id = intval($request['new_user_id']);
        remove_unlock_request($campaign_id, $user_id);
        return new WP_REST_Response(['success' => true]);
    }


    #[Endpoint(
        'campaign/(?P<id>\d+)/unlock',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function mailerpress_unlock_campaign(WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $campaign_id = intval($request['id']);
        $current_user_id = get_current_user_id();
        $new_user_id = $request->get_param('new_user_id'); // optional new locker
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        global $wpdb;

        // Reset the current lock
        $wpdb->update(
            $table,
            [
                'editing_user_id' => null,
                'editing_started_at' => null
            ],
            ['campaign_id' => $campaign_id, 'editing_user_id' => $current_user_id]
        );

        // Remove all pending unlock requests
        delete_transient("campaign_{$campaign_id}_unlock_requests");

        // Optionally assign new locker
        if (!empty($new_user_id) && is_numeric($new_user_id)) {
            $wpdb->update(
                $table,
                [
                    'editing_user_id' => intval($new_user_id),
                    'editing_started_at' => current_time('mysql')
                ],
                ['campaign_id' => $campaign_id]
            );
        }

        return new WP_REST_Response(['success' => true]);
    }


    #[Endpoint(
        'campaign/(?P<id>\d+)/refresh-lock',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function mailerpress_refresh_lock(WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaign_id = intval($request['id']);
        $user_id = get_current_user_id();
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $wpdb->update(
            $table,
            ['editing_started_at' => current_time('mysql')],
            ['campaign_id' => $campaign_id, 'editing_user_id' => $user_id]
        );

        return new WP_REST_Response(['success' => true]);
    }

    #[Endpoint(
        'campaign/(?P<id>\d+)/status',
        permissionCallback: [Permissions::class, 'canManageCampaign']
    )]
    public function campaingStatusLock(WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $campaign_id = intval($request['id']);
        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $lock = $wpdb->get_row($wpdb->prepare(
            "SELECT editing_user_id, editing_started_at FROM $table WHERE campaign_id = %d",
            $campaign_id
        ));

        if (!$lock || !$lock->editing_user_id) {
            return new WP_REST_Response(['locked' => false]);
        }

        $user = get_userdata($lock->editing_user_id);
        return new WP_REST_Response([
            'locked' => true,
            'user_id' => $lock->editing_user_id,
            'user_name' => $user ? $user->display_name : '',
            'locked_avatar' => get_avatar_url($lock->editing_user_id, ['size' => 256, 'default' => 'mystery']),
            'timestamp' => $lock->editing_started_at
        ]);
    }

    #[Endpoint(
        'video-preview',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function generateVideoPreview(\WP_REST_Request $request): \WP_REST_Response
    {
        $videoUrl = esc_url_raw($request->get_param('url'));
        if (empty($videoUrl)) {
            return new \WP_REST_Response(['error' => __('Missing video url', 'mailerpress')], 400);
        }

        $parsed = $this->parseVideoUrl($videoUrl);
        if (!$parsed || empty($parsed['thumbnail'])) {
            return new \WP_REST_Response(['error' => __('Unsupported video url', 'mailerpress')], 400);
        }

        // 🔹 If Dailymotion, fetch high-res thumbnail
        if ($parsed['type'] === 'dailymotion') {
            $videoId = $parsed['id'];
            $parsed['thumbnail'] = "https://www.dailymotion.com/thumbnail/video/$videoId?size=1280";
            $oEmbedUrl = "https://www.dailymotion.com/services/oembed?url=https://www.dailymotion.com/video/$videoId";
            $response = wp_remote_get($oEmbedUrl);
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($data['thumbnail_url'])) {
                    $parsed['thumbnail'] = $data['thumbnail_url'];
                }
            }
        }

        $thumbnailUrl = $parsed['thumbnail'];

        $uploadDir = wp_upload_dir();
        $previewDir = $uploadDir['basedir'] . '/mailerpress-previews/';
        $previewUrlBase = $uploadDir['baseurl'] . '/mailerpress-previews/';

        if (!file_exists($previewDir)) {
            wp_mkdir_p($previewDir);
        }

        $filename = 'preview-' . $parsed['type'] . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $parsed['id']) . '.jpg';
        $outputPath = $previewDir . $filename;
        $previewUrl = $previewUrlBase . $filename;

        // Return cached version if it exists
        if (file_exists($outputPath)) {
            return new \WP_REST_Response([
                'url' => $previewUrl,
                'type' => $parsed['type'],
                'id' => $parsed['id'],
            ]);
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmpFile = download_url($thumbnailUrl);

        // If maxresdefault fails for YouTube, fallback to hqdefault
        if (is_wp_error($tmpFile) && $parsed['type'] === 'youtube') {
            $fallbackUrl = "https://img.youtube.com/vi/{$parsed['id']}/hqdefault.jpg";
            $tmpFile = download_url($fallbackUrl);
        }

        if (is_wp_error($tmpFile)) {
            return new \WP_REST_Response(['error' => __('Failed to fetch thumbnail', 'mailerpress')], 400);
        }

        // Try Imagick first, then GD, then raw copy
        if (extension_loaded('imagick')) {
            try {
                $image = new \Imagick($tmpFile);
                @unlink($tmpFile);

                $width = $image->getImageWidth();
                $height = $image->getImageHeight();

                $overlay = new \Imagick();
                $overlay->newImage($width, $height, new \ImagickPixel('rgba(0,0,0,0.3)'));
                $overlay->setImageFormat('png');
                $image->compositeImage($overlay, \Imagick::COMPOSITE_OVER, 0, 0);
                $overlay->destroy();

                $draw = new \ImagickDraw();
                $draw->setStrokeAntialias(true);

                $centerX = $width / 2;
                $centerY = $height / 2;
                $circleRadius = min($width, $height) * 0.08;

                $draw->setFillColor(new \ImagickPixel('rgba(255,255,255,0.85)'));
                $draw->circle($centerX, $centerY, $centerX + $circleRadius, $centerY);

                $triangleSize = $circleRadius * 0.8;
                $triangle = [
                    ['x' => $centerX - $triangleSize / 2, 'y' => $centerY - $triangleSize / 1.8],
                    ['x' => $centerX - $triangleSize / 2, 'y' => $centerY + $triangleSize / 1.8],
                    ['x' => $centerX + $triangleSize / 1.5, 'y' => $centerY]
                ];

                $draw->setFillColor(new \ImagickPixel('black'));
                $draw->polygon($triangle);

                $image->setImageMatte(true);
                $image->drawImage($draw);

                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality(90);
                $image->writeImage($outputPath);
                $image->destroy();

                return new \WP_REST_Response([
                    'url' => $previewUrl,
                    'type' => $parsed['type'],
                    'id' => $parsed['id'],
                ]);
            } catch (\Exception $e) {
                // Imagick failed, fall through to GD
            }
        }

        // GD fallback: draw play button overlay
        if (extension_loaded('gd')) {
            try {
                $srcImage = @imagecreatefromjpeg($tmpFile);
                if (!$srcImage) {
                    $srcImage = @imagecreatefrompng($tmpFile);
                }
                if (!$srcImage) {
                    $srcImage = @imagecreatefromwebp($tmpFile);
                }

                if ($srcImage) {
                    @unlink($tmpFile);
                    $width = imagesx($srcImage);
                    $height = imagesy($srcImage);

                    // Dark overlay
                    $overlayColor = imagecolorallocatealpha($srcImage, 0, 0, 0, 90);
                    imagefilledrectangle($srcImage, 0, 0, $width, $height, $overlayColor);

                    // Play button circle
                    $centerX = (int) ($width / 2);
                    $centerY = (int) ($height / 2);
                    $circleRadius = (int) (min($width, $height) * 0.08);
                    $white = imagecolorallocatealpha($srcImage, 255, 255, 255, 19);
                    imagefilledellipse($srcImage, $centerX, $centerY, $circleRadius * 2, $circleRadius * 2, $white);

                    // Play triangle
                    $triangleSize = $circleRadius * 0.8;
                    $black = imagecolorallocate($srcImage, 0, 0, 0);
                    $points = [
                        (int) ($centerX - $triangleSize / 2), (int) ($centerY - $triangleSize / 1.8),
                        (int) ($centerX - $triangleSize / 2), (int) ($centerY + $triangleSize / 1.8),
                        (int) ($centerX + $triangleSize / 1.5), $centerY,
                    ];
                    imagefilledpolygon($srcImage, $points, $black);

                    imagejpeg($srcImage, $outputPath, 90);
                    imagedestroy($srcImage);

                    return new \WP_REST_Response([
                        'url' => $previewUrl,
                        'type' => $parsed['type'],
                        'id' => $parsed['id'],
                    ]);
                }
            } catch (\Exception $e) {
                // GD failed, fall through to raw copy
            }
        }

        // Ultimate fallback: just copy the thumbnail as-is (no play button overlay)
        @copy($tmpFile, $outputPath);
        @unlink($tmpFile);

        if (file_exists($outputPath)) {
            return new \WP_REST_Response([
                'url' => $previewUrl,
                'type' => $parsed['type'],
                'id' => $parsed['id'],
            ]);
        }

        return new \WP_REST_Response(['error' => __('Failed to process thumbnail. Neither Imagick nor GD extension is available.', 'mailerpress')], 500);
    }

    private function parseVideoUrl(string $url): ?array
    {
        // YouTube
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $m)) {
            return [
                'type' => 'youtube',
                'id' => $m[1],
                // Use maxresdefault if available, else fallback to hqdefault
                'thumbnail' => "https://img.youtube.com/vi/{$m[1]}/maxresdefault.jpg",
            ];
        }

        // Vimeo
        if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
            // Vimeo doesn't have a direct URL pattern for high-res thumbnails,
            // need to fetch via API for best quality
            $vimeoId = $m[1];
            $thumbnail = "https://vumbnail.com/{$vimeoId}.jpg"; // default
            // Optional: fetch JSON for better resolution
            $json = @file_get_contents("https://vimeo.com/api/v2/video/{$vimeoId}.json");
            if ($json) {
                $data = json_decode($json, true);
                if (!empty($data[0]['thumbnail_large'])) {
                    $thumbnail = $data[0]['thumbnail_large'];
                }
            }
            return [
                'type' => 'vimeo',
                'id' => $vimeoId,
                'thumbnail' => $thumbnail,
            ];
        }

        // Dailymotion
        if (preg_match('/dailymotion\.com\/video\/([a-zA-Z0-9]+)/', $url, $m)) {
            return [
                'type' => 'dailymotion',
                'id' => $m[1],
                'thumbnail' => "https://www.dailymotion.com/thumbnail/video/{$m[1]}",
                // Dailymotion only provides small size by default; for higher-res you’d need API
            ];
        }

        return null;
    }

    #[Endpoint(
        'campaigns/(?P<id>\d+)/logs',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getCampaignLogs(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $campaignId = (int) $request->get_param('id');

        // Check if campaign exists
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT campaign_id FROM {$campaignsTable} WHERE campaign_id = %d",
            $campaignId
        ));

        if (!$campaign) {
            return new \WP_Error(
                'not_found',
                'Campaign not found',
                ['status' => 404]
            );
        }

        $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contactStatsTable = Tables::get(Tables::MAILERPRESS_CONTACT_STATS);
        $trackingTable = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);

        // Debug: Check batches for this campaign
        $batchesCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$batchesTable} WHERE campaign_id = %d",
            $campaignId
        ));

        // Debug: Check if there are any contact_stats for this campaign
        $contactStatsCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$contactStatsTable} WHERE campaign_id = %d",
            $campaignId
        ));

        // Pagination
        $per_page = (int) ($request->get_param('per_page') ?? 50);
        $page = (int) ($request->get_param('page') ?? 1);
        $offset = ($page - 1) * $per_page;

        // Status filter - we'll use contact_stats to determine sent status
        // Since contact_stats is created when email is sent, presence = sent
        $status = $request->get_param('status');

        // Build status condition based on contact_stats
        // We can't really determine "failed" from contact_stats alone
        // So we'll show all sent emails (those with contact_stats entries)
        $statusCondition = "";
        if ($status === 'sent') {
            // Only show sent (those with contact_stats)
            $statusCondition = "";
        } else {
            // Default: show all sent emails
            $statusCondition = "";
        }

        // Get batch IDs for this campaign first
        $batchIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$batchesTable} WHERE campaign_id = %d",
            $campaignId
        ));

        $batchIdsPlaceholder = '';
        if (!empty($batchIds)) {
            $batchIdsPlaceholder = implode(',', array_map('intval', $batchIds));
        }

        // Use contact_stats as the only source for logs
        // contact_stats is created when emails are sent and contains individual send records
        $logsQuery = "SELECT
                    cs.contact_id,
                    cs.campaign_id,
                    cs.opened,
                    cs.clicked,
                    cs.click_count,
                    cs.created_at as sent_at,
                    cs.updated_at,
                    COALESCE(c.email, CONCAT('Contact #', cs.contact_id)) as email,
                    COALESCE(c.first_name, '') as first_name,
                    COALESCE(c.last_name, '') as last_name";

        if (!empty($batchIdsPlaceholder)) {
            $logsQuery .= ",
                    t.opened_at,
                    t.clicks as tracking_clicks,
                    t.unsubscribed_at
                 FROM {$contactStatsTable} cs
                 LEFT JOIN {$contactTable} c ON cs.contact_id = c.contact_id
                 LEFT JOIN {$trackingTable} t ON cs.contact_id = t.contact_id
                     AND t.batch_id IN ({$batchIdsPlaceholder})";
        } else {
            $logsQuery .= ",
                    NULL as opened_at,
                    NULL as tracking_clicks,
                    NULL as unsubscribed_at
                 FROM {$contactStatsTable} cs
                 LEFT JOIN {$contactTable} c ON cs.contact_id = c.contact_id";
        }

        $logsQuery .= $wpdb->prepare(
            " WHERE cs.campaign_id = %d
             ORDER BY cs.created_at DESC
             LIMIT %d OFFSET %d",
            $campaignId,
            $per_page,
            $offset
        );

        $logs = $wpdb->get_results($logsQuery, ARRAY_A);

        // Filter logs to ensure they belong to the correct campaign (safety check)
        $logs = array_filter($logs, function ($log) use ($campaignId) {
            return isset($log['campaign_id']) && (int)$log['campaign_id'] === (int)$campaignId;
        });

        // Re-index array after filtering
        $logs = array_values($logs);

        // Get total count
        $totalQuery = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$contactStatsTable} cs
             WHERE cs.campaign_id = %d",
            $campaignId
        );
        $total_count = (int) $wpdb->get_var($totalQuery);

        // Debug: Get batch information
        $sampleBatches = $wpdb->get_results($wpdb->prepare(
            "SELECT b.id as batch_id, b.campaign_id, b.sent_emails, b.error_emails, b.status as batch_status
             FROM {$batchesTable} b
             WHERE b.campaign_id = %d
             ORDER BY b.created_at DESC
             LIMIT 5",
            $campaignId
        ), ARRAY_A);

        // Format logs - all from contact_stats are considered sent (COMPLETED)
        // contact_stats is only created when emails are successfully sent
        $formattedLogs = array_map(function ($log) {
            $displayStatus = 'COMPLETED'; // All have contact_stats, so they were sent

            // Build data object
            $data = [];
            $opened = isset($log['opened']) ? (int) $log['opened'] : 0;
            $clicked = isset($log['clicked']) ? (int) $log['clicked'] : 0;
            $clickCount = isset($log['click_count']) ? (int) $log['click_count'] : 0;

            if ($opened > 0) {
                $data['emails_opened'] = $opened;
            }
            if ($clicked > 0 || $clickCount > 0) {
                $data['emails_clicked'] = $clicked ?: $clickCount;
            }
            if (!empty($log['unsubscribed_at'])) {
                $data['unsubscribed'] = true;
                $data['unsubscribed_at'] = $log['unsubscribed_at'];
            }
            if (!empty($log['opened_at'])) {
                $data['opened_at'] = $log['opened_at'];
            }

            return [
                'id' => (int) ($log['contact_id'] ?? 0),
                'contact_id' => (int) ($log['contact_id'] ?? 0),
                'email' => $log['email'] ?? sprintf(__('Contact #%d', 'mailerpress'), $log['contact_id'] ?? 0),
                'status' => $displayStatus,
                'data' => $data,
                'created_at' => $log['sent_at'] ?? date('Y-m-d H:i:s'),
                'first_name' => $log['first_name'] ?? '',
                'last_name' => $log['last_name'] ?? '',
            ];
        }, $logs);

        $total_pages = ceil($total_count / $per_page);

        return new \WP_REST_Response([
            'logs' => $formattedLogs,
            'count' => $total_count,
            'pages' => $total_pages,
            'page' => $page,
            'per_page' => $per_page,
            'debug' => [
                'batches_count' => $batchesCount,
                'contact_stats_count' => $contactStatsCount,
                'sample_batches' => $sampleBatches,
                'campaign_id' => $campaignId,
                'status_filter' => $status,
                'table_names' => [
                    'contact_stats' => $contactStatsTable,
                    'batches' => $batchesTable,
                    'contacts' => $contactTable,
                    'tracking' => $trackingTable,
                ],
                'raw_logs_count' => count($logs),
                'total_count' => $total_count,
            ],
        ], 200);
    }

    #[Endpoint(
        'campaigns/(?P<id>\d+)/email-logs',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView']
    )]
    public function getCampaignEmailLogs(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $campaignId = (int) $request->get_param('id');

        // Check if campaign exists
        global $wpdb;
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT campaign_id FROM {$campaignsTable} WHERE campaign_id = %d",
            $campaignId
        ));

        if (!$campaign) {
            return new \WP_Error(
                'not_found',
                __('Campaign not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Get EmailLogger instance
        $logger = Kernel::getContainer()->get(EmailLogger::class);

        // Pagination
        $per_page = (int) ($request->get_param('per_page') ?? 50);
        $page = (int) ($request->get_param('page') ?? 1);
        $offset = ($page - 1) * $per_page;

        // Status filter
        $status = $request->get_param('status');
        $statusFilter = null;
        if ($status === 'success' || $status === 'error' || $status === 'pending') {
            $statusFilter = $status;
        }

        // Get logs
        $args = [
            'campaign_id' => $campaignId,
            'status' => $statusFilter,
            'limit' => $per_page,
            'offset' => $offset,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $logs = $logger->getLogs($args);
        $total_count = $logger->getLogCount([
            'campaign_id' => $campaignId,
            'status' => $statusFilter,
        ]);

        $total_pages = ceil($total_count / $per_page);

        // Format logs for frontend
        $formattedLogs = array_map(function ($log) {
            return [
                'id' => (int) $log['id'],
                'to_email' => $log['to_email'] ?? '',
                'subject' => $log['subject'] ?? '',
                'from_email' => $log['from_email'] ?? '',
                'from_name' => $log['from_name'] ?? '',
                'service' => $log['service'] ?? 'php',
                'status' => $log['status'] ?? 'pending',
                'error_message' => $log['error_message'] ?? null,
                'created_at' => $log['created_at'] ?? '',
                'sent_at' => $log['sent_at'] ?? null,
                'is_html' => (bool) ($log['is_html'] ?? true),
                'wp_mail_result' => $log['wp_mail_result'] ?? null,
            ];
        }, $logs);

        return new \WP_REST_Response([
            'logs' => $formattedLogs,
            'count' => $total_count,
            'pages' => $total_pages,
            'page' => $page,
            'per_page' => $per_page,
        ], 200);
    }

    /**
     * Format revenue using WooCommerce settings
     *
     * @param float $revenue The revenue amount to format
     * @return string Formatted revenue with currency symbol
     */
    private function formatRevenue(float $revenue): string
    {
        if (!function_exists('WC')) {
            // Fallback if WooCommerce not installed
            return number_format($revenue, 2);
        }

        $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
        $formatted_number = number_format(
            $revenue,
            wc_get_price_decimals(),
            wc_get_price_decimal_separator(),
            wc_get_price_thousand_separator()
        );

        switch (get_option('woocommerce_currency_pos', 'left')) {
            case 'right':
                return $formatted_number . ' ' . $currency_symbol;
            case 'left_space':
                return $currency_symbol . ' ' . $formatted_number;
            case 'right_space':
                return $formatted_number . ' ' . $currency_symbol;
            case 'left': // default
            default:
                return $currency_symbol . $formatted_number;
        }
    }

    /**
     * Get contact fetcher based on recipient targeting type
     *
     * @param string|null $recipientTargeting
     * @param array $lists
     * @param array $tags
     * @param array $segment
     * @return ContactFetcherInterface|null
     */
    private function getContactFetcher(
        ?string $recipientTargeting,
        array $lists,
        array $tags,
        array $segment
    ): ?ContactFetcherInterface {
        $type = $recipientTargeting ?? 'classic';

        return match ($type) {
            'classic' => new ClassicContactFetcher($lists, $tags),
            'segment' => new SegmentContactFetcher(is_array($segment) ? $segment[0] : $segment),
            default => null
        };
    }

    /**
     * Get sent campaigns for the archive block (public endpoint)
     */
    #[Endpoint(
        'campaigns/sent',
        methods: 'GET',
        permissionCallback: '__return_true'
    )]
    public function getSentCampaigns(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $campaigns = $wpdb->get_results(
            "SELECT campaign_id, name, created_at, updated_at
             FROM {$table}
             WHERE status = 'sent'
             ORDER BY created_at DESC",
            ARRAY_A
        );

        return new \WP_REST_Response($campaigns ?: [], 200);
    }
}
