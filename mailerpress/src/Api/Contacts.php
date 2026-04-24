<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Models\CustomFields;
use MailerPress\Services\RateLimiter;
use MailerPress\Services\RateLimitConfig;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Contacts
{

    #[Endpoint(
        'contact/(?P<contact_id>\d+)/activity',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAudience']
    )]
    public static function getContactActivity(WP_REST_Request $request)
    {
        global $wpdb;

        $contact_id = (int)$request['contact_id'];
        $page = max(1, (int)$request->get_param('page'));
        $per_page = 4;
        $offset = ($page - 1) * $per_page;

        if (empty($contact_id)) {
            return rest_ensure_response(['error' => __('Invalid contact ID.', 'mailerpress')]);
        }

        $contactStatsTable = $wpdb->prefix . Tables::MAILERPRESS_CONTACT_STATS;
        $clickTrackingTable = $wpdb->prefix . Tables::MAILERPRESS_CLICK_TRACKING;
        $campaignsTable = $wpdb->prefix . Tables::MAILERPRESS_CAMPAIGNS;

        // Get campaigns with last activity
        $campaigns = $wpdb->get_results($wpdb->prepare("
        SELECT c.*, MAX(cs.updated_at) AS last_activity
        FROM {$campaignsTable} c
        INNER JOIN {$contactStatsTable} cs ON cs.campaign_id = c.campaign_id
        WHERE cs.contact_id = %d
        GROUP BY c.campaign_id
        ORDER BY last_activity DESC
    ", $contact_id));

        $total_campaigns = count($campaigns);
        $paginated_campaigns = array_slice($campaigns, $offset, $per_page);

        // ✅ Optimisation: Précharger toutes les données en une seule fois
        $campaign_ids = array_map(fn($c) => (int)$c->campaign_id, $paginated_campaigns);

        $stats_by_campaign = [];
        $clicks_by_campaign = [];

        if (!empty($campaign_ids)) {
            $campaign_placeholders = implode(',', array_fill(0, count($campaign_ids), '%d'));

            // Récupérer toutes les stats en une seule requête
            $all_stats = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$contactStatsTable}
                WHERE contact_id = %d AND campaign_id IN ({$campaign_placeholders})
                ORDER BY campaign_id, id ASC
            ", $contact_id, ...$campaign_ids));

            // Grouper les stats par campagne
            foreach ($all_stats as $stats) {
                $stats_by_campaign[(int)$stats->campaign_id][] = $stats;
            }

            // Récupérer tous les clics en une seule requête
            $all_clicks = $wpdb->get_results($wpdb->prepare("
                SELECT campaign_id, url, COUNT(*) AS click_count, MAX(created_at) AS last_clicked
                FROM {$clickTrackingTable}
                WHERE contact_id = %d AND campaign_id IN ({$campaign_placeholders})
                GROUP BY campaign_id, url
            ", $contact_id, ...$campaign_ids));

            // Grouper les clics par campagne
            foreach ($all_clicks as $click) {
                $clicks_by_campaign[(int)$click->campaign_id][] = $click;
            }
        }

        $grouped = [];

        foreach ($paginated_campaigns as $campaign) {
            $activities = [];
            $campaignScore = 0;

            // Utiliser les stats préchargées
            $statsRows = $stats_by_campaign[(int)$campaign->campaign_id] ?? [];

            foreach ($statsRows as $stats) {
                // Sent
                $activities[] = [
                    'type' => 'sent',
                    'timestamp' => $stats->created_at,
                    'details' => sprintf(__('Sent campaign "%s"', 'mailerpress'), $campaign->name),
                ];

                // Opened
                if (!empty($stats->opened)) {
                    $activities[] = [
                        'type' => 'opened',
                        'timestamp' => $stats->updated_at ?: $stats->created_at,
                        'details' => sprintf(__('Opened campaign "%s"', 'mailerpress'), $campaign->name),
                    ];
                    $campaignScore = max($campaignScore, 80);
                }

                // Unsubscribed
                if (!empty($stats->unsubscribed)) {
                    $activities[] = [
                        'type' => 'unsubscribed',
                        'timestamp' => $stats->updated_at ?: $stats->created_at,
                        'details' => sprintf(__('Unsubscribed from campaign "%s"', 'mailerpress'), $campaign->name),
                    ];
                    $campaignScore = 0;
                }

                // Revenue
                if (!empty($stats->revenue) && (float)$stats->revenue > 0) {
                    if (function_exists('get_woocommerce_currency_symbol') && function_exists('wc_get_price_decimals')) {
                        $revenue = (float)$stats->revenue;
                        $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
                        $formatted_price = number_format(
                            $revenue,
                            wc_get_price_decimals(),
                            wc_get_price_decimal_separator(),
                            wc_get_price_thousand_separator()
                        );

                        $activities[] = [
                            'type' => 'revenue',
                            'timestamp' => $stats->updated_at ?: $stats->created_at,
                            'details' => sprintf(
                                __('Generated %s %s in revenue from campaign "%s"', 'mailerpress'),
                                $formatted_price,
                                $currency_symbol,
                                $campaign->name
                            ),
                        ];
                    }
                }
            }

            // Utiliser les clics préchargés
            $clicks = $clicks_by_campaign[(int)$campaign->campaign_id] ?? [];

            foreach ($clicks as $click) {
                $click_count = (int)$click->click_count;
                $details = sprintf(
                    __('Clicked link in campaign "%s": %s', 'mailerpress'),
                    $campaign->name,
                    esc_url($click->url)
                );

                if ($click_count > 1) {
                    $details .= sprintf(__(' — clicked %d times', 'mailerpress'), $click_count);
                }

                $activities[] = [
                    'type' => 'clicked',
                    'timestamp' => $click->last_clicked,
                    'details' => $details,
                ];
                $campaignScore = max($campaignScore, 100);
            }

            usort($activities, function ($a, $b) {
                $timeA = strtotime($a['timestamp']);
                $timeB = strtotime($b['timestamp']);

                // Primary sort: descending timestamp
                if ($timeA !== $timeB) {
                    return $timeB <=> $timeA; // latest first
                }

                // Secondary sort: priority for same timestamp
                $priority = [
                    'opened' => 1,
                    'clicked' => 2,
                    'revenue' => 3,
                    'sent' => 4,
                    'unsubscribed' => 5,
                ];

                $a_priority = $priority[$a['type']] ?? 99;
                $b_priority = $priority[$b['type']] ?? 99;

                return $a_priority <=> $b_priority;
            });





            $grouped[] = [
                'campaign_id' => $campaign->campaign_id,
                'campaign_name' => $campaign->name,
                'engagement_score' => $campaignScore,
                'activities' => $activities,
            ];
        }

        return rest_ensure_response([
            'activities' => $grouped,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total_campaigns,
                'total_pages' => ceil($total_campaigns / $per_page),
            ],
        ]);
    }

    #[Endpoint(
        'contact/(?P<contact_id>\d+)/campaigns',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAudience']
    )]
    public static function getContactCampaigns(WP_REST_Request $request)
    {
        global $wpdb;

        $contact_id = (int)$request['contact_id'];

        $campaigns = $wpdb->get_results($wpdb->prepare(
            "
        SELECT DISTINCT c.campaign_id, c.name
        FROM {$wpdb->prefix}mailerpress_campaigns c
        INNER JOIN {$wpdb->prefix}mailerpress_contact_stats cs
            ON cs.campaign_id = c.campaign_id
        WHERE cs.contact_id = %d
        ORDER BY c.created_at DESC
        ",
            $contact_id
        ), ARRAY_A);

        return rest_ensure_response($campaigns);
    }

    #[Endpoint(
        'stats/(?P<contact_id>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAudience']
    )]
    public static function contactStats(WP_REST_Request $request)
    {
        global $wpdb;

        $contact_id = (int)$request['contact_id'];
        $campaign_id = $request->get_param('campaign_id'); // optional

        $contactStatsTable = $wpdb->prefix . 'mailerpress_contact_stats';
        $conditions = ["contact_id = %d"];
        $params = [$contact_id];

        if (!empty($campaign_id)) {
            $conditions[] = "campaign_id = %d";
            $params[] = (int)$campaign_id;
        }

        $whereClause = "WHERE " . implode(" AND ", $conditions);

        $stats = [
            'total_opened' => (int)$wpdb->get_var($wpdb->prepare(
                "SELECT SUM(opened) FROM {$contactStatsTable} {$whereClause}",
                ...$params
            )),
            'total_clicked' => (int)$wpdb->get_var($wpdb->prepare(
                "SELECT SUM(click_count) FROM {$contactStatsTable} {$whereClause}",
                ...$params
            )),
            'total_unsubscribed' => (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$contactStatsTable} {$whereClause} AND status = 'bad'",
                ...$params
            )),
            'total_revenue' => (float)$wpdb->get_var($wpdb->prepare(
                "SELECT SUM(revenue) FROM {$contactStatsTable} {$whereClause}",
                ...$params
            )),
            'last_activity' => $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(updated_at) FROM {$contactStatsTable} {$whereClause}",
                ...$params
            )),
        ];

        // Normalize nulls to 0
        foreach ($stats as $key => $value) {
            if ($value === null) {
                $stats[$key] = 0;
            }
        }

        return rest_ensure_response($stats);
    }

    #[Endpoint(
        'export/(?P<export_id>[a-zA-Z0-9-]+)',
        methods: 'GET',
    )]
    public static function handleExportDownload(WP_REST_Request $request)
    {
        $export_id = sanitize_text_field($request->get_param('export_id'));
        $token = sanitize_text_field($request->get_param('token'));

        $export_data = get_option("mailerpress_export_{$export_id}");
        if (!$export_data) {
            return new \WP_REST_Response(['message' => __('Export not found.', 'mailerpress')], 404);
        }

        if ($token !== $export_data['token']) {
            return new \WP_REST_Response(['message' => __('Invalid token.', 'mailerpress')], 403);
        }

        if (time() > $export_data['expires']) {
            return new \WP_REST_Response(['message' => __('Link expired.', 'mailerpress')], 410);
        }

        $zip_path = $export_data['zip_path'];
        if (!file_exists($zip_path)) {
            return new \WP_REST_Response(['message' => __('File not found.', 'mailerpress')], 404);
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zip_path) . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        exit;
    }

    #[Endpoint(
        'contact-note/(?P<contact_id>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function getContactNote(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $contact_id = intval($request['contact_id']);
        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT_NOTE);

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT note_id, content, created_at, updated_at
             FROM $table_name
             WHERE contact_id = %d
             ORDER BY created_at DESC",
                $contact_id
            ),
            ARRAY_A
        );

        return new WP_REST_Response([
            'success' => true,
            'notes' => $results
        ]);
    }

    #[Endpoint(
        'contact/note',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function addContactNote(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $contact_id = intval($request->get_param('contact_id'));
        $content = sanitize_textarea_field($request->get_param('content'));

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT_NOTE);

        $result = $wpdb->insert(
            $table_name,
            [
                'contact_id' => $contact_id,
                'content' => $content,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($result === false) {
            return new WP_Error('insert_failed', __('Failed to insert contact note.', 'mailerpress'), ['status' => 500]);
        }

        $note_id = $wpdb->insert_id;

        // Fetch the newly inserted row
        $new_note = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT note_id, contact_id, content, created_at, updated_at FROM $table_name WHERE note_id = %d",
                $note_id
            ),
            ARRAY_A
        );

        return new WP_REST_Response([
            'success' => true,
            'note' => $new_note,
        ]);
    }

    #[Endpoint(
        'contact/note/(?P<note_id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function updateContactNote(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $note_id = intval($request['note_id']);
        $content = sanitize_textarea_field($request->get_param('content'));

        if (empty($content)) {
            return new WP_Error('empty_content', __('Note content cannot be empty.', 'mailerpress'), ['status' => 400]);
        }

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT_NOTE);

        // Check if note exists
        $existing_note = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT note_id FROM $table_name WHERE note_id = %d",
                $note_id
            ),
            ARRAY_A
        );

        if (!$existing_note) {
            return new WP_Error('note_not_found', __('Note not found.', 'mailerpress'), ['status' => 404]);
        }

        $result = $wpdb->update(
            $table_name,
            [
                'content' => $content,
                'updated_at' => current_time('mysql'),
            ],
            [
                'note_id' => $note_id,
            ],
            [
                '%s',
                '%s',
            ],
            [
                '%d',
            ]
        );

        if ($result === false) {
            return new WP_Error('update_failed', __('Failed to update contact note.', 'mailerpress'), ['status' => 500]);
        }

        // Fetch the updated row
        $updated_note = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT note_id, contact_id, content, created_at, updated_at FROM $table_name WHERE note_id = %d",
                $note_id
            ),
            ARRAY_A
        );

        return new WP_REST_Response([
            'success' => true,
            'note' => $updated_note,
        ]);
    }

    #[Endpoint(
        'contact/note/(?P<note_id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function deleteContactNote(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $note_id = intval($request['note_id']);
        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT_NOTE);

        // Check if note exists
        $existing_note = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT note_id FROM $table_name WHERE note_id = %d",
                $note_id
            ),
            ARRAY_A
        );

        if (!$existing_note) {
            return new WP_Error('note_not_found', __('Note not found.', 'mailerpress'), ['status' => 404]);
        }

        $result = $wpdb->delete(
            $table_name,
            [
                'note_id' => $note_id,
            ],
            [
                '%d',
            ]
        );

        if ($result === false) {
            return new WP_Error('delete_failed', __('Failed to delete contact note.', 'mailerpress'), ['status' => 500]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Note deleted successfully.', 'mailerpress'),
        ]);
    }

    #[Endpoint(
        'contacts/all',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function all(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $contact_table = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contact_tags_table = Tables::get(Tables::CONTACT_TAGS);
        $tags_table = Tables::get(Tables::MAILERPRESS_TAGS);
        $lists_table = Tables::get(Tables::MAILERPRESS_LIST);
        $contact_lists_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        $custom_fields_table = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
        $field_definitions_table = Tables::get(Tables::MAILERPRESS_CUSTOM_FIELD_DEFINITIONS);

        $per_page = isset($_GET['perPages']) ? (int)(wp_unslash($_GET['perPages'])) : 20;
        $page = isset($_GET['paged']) ? (int)(wp_unslash($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        $search = $request->get_param('search');

        $where = '1=1';
        $params = [];
        $joins = '';

        // Search filter
        if (!empty($search)) {
            // Si la recherche est un nombre, chercher aussi par contact_id
            if (is_numeric($search)) {
                $where .= ' AND (c.email LIKE %s OR c.contact_id = %d)';
                $params[] = '%' . $wpdb->esc_like($search) . '%';
                $params[] = (int)$search;
            } else {
                $where .= ' AND c.email LIKE %s';
                $params[] = '%' . $wpdb->esc_like($search) . '%';
            }
        }

        // Subscription status filter
        if (!empty($_GET['subscription_status'])) {
            $where .= ' AND c.subscription_status = %s';
            $params[] = sanitize_text_field(wp_unslash($_GET['subscription_status']));
        }

        // Filter by lists
        $listParam = $request->get_param('list');
        $listIds = [];

        if (!empty($listParam) && is_array($listParam)) {
            foreach ($listParam as $list) {
                if (isset($list['id'])) {
                    $listIds[] = (int)$list['id'];
                }
            }
        }

        if (!empty($listIds)) {
            $joins .= " INNER JOIN {$contact_lists_table} cl ON cl.contact_id = c.contact_id ";
            $placeholders = implode(',', array_fill(0, count($listIds), '%d'));
            $where .= " AND cl.list_id IN ($placeholders)";
            $params = array_merge($params, $listIds);
        }

        // Filter by tags
        $tagParam = $request->get_param('tag');
        $tagIds = [];

        if (!empty($tagParam) && is_array($tagParam)) {
            foreach ($tagParam as $tag) {
                if (isset($tag['id'])) {
                    $tagIds[] = (int)$tag['id'];
                }
            }
        }

        if (!empty($tagIds)) {
            $joins .= " INNER JOIN {$contact_tags_table} ct_filter ON ct_filter.contact_id = c.contact_id ";
            $placeholders = implode(',', array_fill(0, count($tagIds), '%d'));
            $where .= " AND ct_filter.tag_id IN ($placeholders)";
            $params = array_merge($params, $tagIds);
        }

        // Order - Use whitelist to prevent SQL injection
        $allowed_orderby = ['contact_id', 'email', 'first_name', 'last_name', 'created_at', 'updated_at', 'subscription_status', 'opt_in_source'];
        $allowed_order = ['ASC', 'DESC'];
        $orderby_param = $request->get_param('orderby');
        $order_param = strtoupper($request->get_param('order') ?? 'DESC');
        $orderby = in_array($orderby_param, $allowed_orderby, true) ? $orderby_param : 'contact_id';
        $order = in_array($order_param, $allowed_order, true) ? $order_param : 'DESC';
        $orderBy = sprintf('c.%s %s', esc_sql($orderby), esc_sql($order));

        $hasJoins = !empty($listIds) || !empty($tagIds);

        // Run COUNT query first — use COUNT(*) when no JOINs (faster than COUNT(DISTINCT))
        $countSelect = $hasJoins ? 'COUNT(DISTINCT c.contact_id)' : 'COUNT(*)';
        $total_count = (int) $wpdb->get_var($wpdb->prepare("
        SELECT {$countSelect}
        FROM {$contact_table} c
        {$joins}
        WHERE {$where}
    ", $params));

        $total_pages = ceil($total_count / $per_page);

        // Early return if no results
        if ($total_count === 0) {
            return new \WP_REST_Response([
                'posts' => [],
                'pages' => 0,
                'count' => 0,
            ], 200);
        }

        // Fetch contacts — only select needed columns, skip large TEXT fields
        $selectDistinct = $hasJoins ? 'DISTINCT' : '';
        $contacts = $wpdb->get_results($wpdb->prepare("
        SELECT {$selectDistinct} c.contact_id, c.contact_id as id, c.email, c.first_name, c.last_name,
               c.subscription_status, c.opt_in_source, c.created_at, c.updated_at, c.unsubscribe_token
        FROM {$contact_table} c
        {$joins}
        WHERE {$where}
        ORDER BY {$orderBy}
        LIMIT %d OFFSET %d
    ", [...$params, $per_page, $offset]));

        $contact_ids = array_map(static fn($c) => $c->contact_id, $contacts);

        if (empty($contact_ids)) {
            return new \WP_REST_Response([
                'posts' => [],
                'pages' => $total_pages,
                'count' => $total_count,
            ], 200);
        }

        $placeholders = implode(',', array_fill(0, count($contact_ids), '%d'));

        // Fetch tags
        $tags_results = $wpdb->get_results($wpdb->prepare("
        SELECT ct.contact_id, t.tag_id, t.name
        FROM {$contact_tags_table} ct
        INNER JOIN {$tags_table} t ON ct.tag_id = t.tag_id
        WHERE ct.contact_id IN ({$placeholders})
    ", ...$contact_ids));

        $tags_by_contact = [];
        foreach ($tags_results as $tag) {
            $tags_by_contact[$tag->contact_id][] = [
                'tag_id' => $tag->tag_id,
                'tag_name' => $tag->name,
            ];
        }

        // Fetch lists
        $lists_results = $wpdb->get_results($wpdb->prepare("
        SELECT cl.contact_id, cl.list_id, l.name as list_name
        FROM {$contact_lists_table} cl
        INNER JOIN {$lists_table} l ON cl.list_id = l.list_id
        WHERE cl.contact_id IN ({$placeholders})
    ", ...$contact_ids));

        $lists_by_contact = [];
        foreach ($lists_results as $list) {
            $lists_by_contact[$list->contact_id][] = [
                'list_id' => $list->list_id,
                'list_name' => $list->list_name,
            ];
        }

        // Fetch custom field definitions — cached per request
        $cache_key = 'mailerpress_field_definitions';
        $field_definitions = wp_cache_get($cache_key);
        if ($field_definitions === false) {
            $field_definitions = $wpdb->get_results("SELECT field_key, label, type, required, options FROM {$field_definitions_table}");
            foreach ($field_definitions as $def) {
                $def->options = is_serialized($def->options)
                    ? unserialize($def->options, ['allowed_classes' => false])
                    : $def->options;
            }
            wp_cache_set($cache_key, $field_definitions);
        }

        // Fetch contact custom field values
        $custom_field_results = $wpdb->get_results($wpdb->prepare("
        SELECT contact_id, field_key, field_value
        FROM {$custom_fields_table}
        WHERE contact_id IN ({$placeholders})
    ", ...$contact_ids));

        $custom_fields_by_contact = [];
        foreach ($custom_field_results as $field) {
            $custom_fields_by_contact[$field->contact_id][$field->field_key] = is_serialized($field->field_value)
                ? unserialize($field->field_value, ['allowed_classes' => false])
                : $field->field_value;
        }

        // Merge data
        foreach ($contacts as &$contact) {
            $contact->tags = $tags_by_contact[$contact->contact_id] ?? [];
            $contact->contact_lists = $lists_by_contact[$contact->contact_id] ?? [];

            $contact->custom_fields = [];
            foreach ($field_definitions as $def) {
                $contact->custom_fields[] = [
                    'field_key' => $def->field_key,
                    'label' => $def->label,
                    'type' => $def->type,
                    'required' => (bool)$def->required,
                    'options' => $def->options,
                    'value' => $custom_fields_by_contact[$contact->contact_id][$def->field_key] ?? '',
                ];
            }
        }

        return new \WP_REST_Response([
            'posts' => $contacts,
            'pages' => $total_pages,
            'count' => $total_count,
        ], 200);
    }

    #[Endpoint(
        'contact',
        methods: 'POST'
    )]
    public function add(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        // --- RATE LIMITING CHECK ---
        // Check if rate limiting is enabled
        if (RateLimitConfig::isEnabled()) {
            $ipAddress = $this->getClientIp();
            $limit = RateLimitConfig::getLimit();
            $window = RateLimitConfig::getWindow();

            if (!RateLimiter::checkLimit(
                RateLimiter::CONTACT_FORM_IDENTIFIER,
                $ipAddress,
                $limit,
                $window
            )) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => __('Too many submission attempts. Please try again later.', 'mailerpress'),
                    'retry_after' => $window,
                ], 429);
            }
        }
        // --- END RATE LIMITING CHECK ---

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);

        // Récupérer et sécuriser les données
        $email = sanitize_email($request->get_param('contactEmail'));
        $first_name = sanitize_text_field($request->get_param('contactFirstName'));
        $last_name = sanitize_text_field($request->get_param('contactLastName'));
        $subscription_status = sanitize_text_field($request->get_param('contactStatus'));
        $contactTags = $request->get_param('tags') ?? [];
        $contactLists = $request->get_param('lists') ?? [];
        $optinSource = $request->get_param('opt_in_source') ?? 'unknown';
        $optinDetails = $request->get_param('optin_details') ?? '';
        $customFields = $request->get_param('custom_fields') ?? [];

        // Honeypot protection: if honeypot is enabled and field is filled, reject silently
        if (\MailerPress\Services\RateLimitConfig::isHoneypotEnabled()) {
            $honeypot = sanitize_text_field($request->get_param('website') ?? '');
            if (!empty($honeypot)) {
                // Bot detected - return success to fool the bot, but don't add contact
                return rest_ensure_response([
                    'message' => __('Contact added successfully.', 'mailerpress'),
                    'success' => true
                ]);
            }
        }

        // Vérifier si le contact existe déjà

        $existing_contact = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE email = %s", $email)
        );

        $isNewContact = false;

        if ($existing_contact) {
            $contactId = $existing_contact->contact_id;

            // Only allow updating contact fields (name, etc.) if the request is authenticated
            // Unauthenticated requests (optin forms) can only add to lists/tags, not modify contact data
            if (is_user_logged_in() && current_user_can('edit_posts')) {
                $wpdb->update(
                    $table_name,
                    [
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'updated_at' => current_time('mysql'),
                    ],
                    ['contact_id' => $existing_contact->contact_id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );

                do_action('mailerpress_contact_updated', $contactId);
            }

            $message = __('Contact updated successfully.', 'mailerpress');
        } else {
            $isNewContact = true;
            // Nouveau contact
            $unsubscribe_token = wp_generate_uuid4();
            $singUpConfirmation = get_option('mailerpress_signup_confirmation', wp_json_encode([
                'enableSignupConfirmation' => true
            ]));

            if (is_string($singUpConfirmation)) {
                $singUpConfirmation = json_decode($singUpConfirmation, true);
            }

            // Déterminer le statut d'abonnement final
            // Si un statut explicite est fourni (et n'est pas vide), l'utiliser
            // Sinon, appliquer les paramètres globaux de double opt-in
            $finalSubscriptionStatus = $subscription_status;

            if (empty($subscription_status)) {
                // Pas de statut fourni : appliquer les paramètres globaux de double opt-in
                // Exception : si la source est 'manual', ne pas appliquer le double opt-in
                if ($optinSource !== 'manual' && !empty($singUpConfirmation) && true === $singUpConfirmation['enableSignupConfirmation']) {
                    $finalSubscriptionStatus = 'pending';
                } else {
                    $finalSubscriptionStatus = 'subscribed';
                }
            }

            $wpdb->insert(
                $table_name,
                [
                    'email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'subscription_status' => $finalSubscriptionStatus,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'unsubscribe_token' => $unsubscribe_token,
                    'opt_in_source' => $optinSource,
                    'opt_in_details' => $optinDetails,
                    'access_token' => bin2hex(random_bytes(32)),
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            $contactId = $wpdb->insert_id;

            $message = __('Contact added successfully.', 'mailerpress');
        }

        // --- Tags ---
        if (!empty($contactTags)) {
            $tagsTable = Tables::get(Tables::CONTACT_TAGS);
            foreach ($contactTags as $tag) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$tagsTable} WHERE contact_id = %d AND tag_id = %d",
                    $contactId,
                    (int) $tag['id']
                ));

                if (!$exists) {
                    $wpdb->insert(
                        $tagsTable,
                        [
                            'contact_id' => $contactId,
                            'tag_id' => $tag['id'],
                        ],
                        ['%d', '%d']
                    );
                    do_action('mailerpress_contact_tag_added', $contactId, $tag['id']);
                }
            }
        }

        // --- Lists ---
        $listsTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        $table_lists = Tables::get(Tables::MAILERPRESS_LIST);

        if (!empty($contactLists)) {
            $valid_lists = [];

            // First, validate that all lists exist
            foreach ($contactLists as $list) {
                $list_id = (int) ($list['id'] ?? $list);

                // Check if list exists in database
                $list_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT list_id FROM {$table_lists} WHERE list_id = %d",
                    $list_id
                ));

                if ($list_exists) {
                    $valid_lists[] = ['id' => $list_id];
                }
            }

            // If no valid lists after validation, use default list
            if (empty($valid_lists)) {
                $default_list_id = $wpdb->get_var("SELECT list_id FROM {$table_lists} WHERE is_default = 1 LIMIT 1");
                if ($default_list_id) {
                    $valid_lists = [['id' => (int)$default_list_id]];
                }
            }

            // Now insert valid lists
            foreach ($valid_lists as $list) {
                // Vérifier si l'association existe déjà
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$listsTable} WHERE contact_id = %d AND list_id = %d",
                    $contactId,
                    $list['id']
                ));

                if (!$exists) {
                    $wpdb->insert(
                        $listsTable,
                        [
                            'contact_id' => $contactId,
                            'list_id' => $list['id'],
                        ],
                        ['%d', '%d']
                    );
                    do_action('mailerpress_contact_list_added', $contactId, $list['id']);
                }
            }
        } else {
            // If no lists provided, assign the default list
            $default_list_id = $wpdb->get_var("SELECT list_id FROM {$table_lists} WHERE is_default = 1 LIMIT 1");

            if ($default_list_id) {
                $wpdb->insert(
                    $listsTable,
                    [
                        'contact_id' => $contactId,
                        'list_id' => $default_list_id,
                    ],
                    ['%d', '%d']
                );
                do_action('mailerpress_contact_list_added', $contactId, $default_list_id);
            }
        }

        // Trigger contact_created hook after lists and tags are added
        // This ensures the workflow context includes complete contact data
        // Only fire for genuinely new contacts, not updates to existing ones
        if (isset($contactId) && $isNewContact) {
            do_action('mailerpress_contact_created', $contactId);
        }

        if (!empty($customFields)) {
            $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);

            foreach ($customFields as $field_key => $field_value) {
                // Vérifier si le champ existe déjà
                $existing = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$customFieldsTable} WHERE contact_id = %d AND field_key = %s",
                        $contactId,
                        $field_key
                    )
                );

                if ($existing) {
                    $wpdb->update(
                        $customFieldsTable,
                        ['field_value' => $field_value, 'updated_at' => current_time('mysql')],
                        ['contact_id' => $contactId, 'field_key' => $field_key],
                        ['%s', '%s'],
                        ['%d', '%s']
                    );
                    do_action('mailerpress_contact_custom_field_updated', $contactId, $field_key, $field_value);
                } else {
                    $wpdb->insert(
                        $customFieldsTable,
                        [
                            'contact_id' => $contactId,
                            'field_key' => $field_key,
                            'field_value' => $field_value,
                        ],
                        ['%d', '%s', '%s']
                    );
                    // Déclencher l'action pour notifier que le champ a été ajouté
                    do_action('mailerpress_contact_custom_field_added', $contactId, $field_key, $field_value);
                }
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => $message,
        ]);
    }

    #[Endpoint(
        'contacts',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function edit(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);
        $tags_table = Tables::get(Tables::CONTACT_TAGS);
        $lists_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);

        $newStatus = $request->get_param('newStatus');
        $ids = $request->get_param('ids');
        $tags = $request->get_param('tags') ?? [];
        $lists = $request->get_param('lists') ?? [];
        $removeTags = $request->get_param('removeTags') ?? [];
        $removeLists = $request->get_param('removeLists') ?? [];
        $customFields = $request->get_param('custom_fields') ?? [];

        $firstName = $request->get_param('first_name');
        $lastName = $request->get_param('last_name');
        $email = $request->get_param('email');

        // ===============================
        // 🟢 BULK UPDATE (All contacts)
        // ===============================
        if (null === $ids) {
            $updateClauses = [];
            $params = [];

            if (!empty($newStatus)) {
                $updateClauses[] = 'subscription_status = %s';
                $params[] = esc_html($newStatus);
            }
            if (!empty($firstName)) {
                $updateClauses[] = 'first_name = %s';
                $params[] = sanitize_text_field($firstName);
            }
            if (!empty($lastName)) {
                $updateClauses[] = 'last_name = %s';
                $params[] = sanitize_text_field($lastName);
            }
            if (!empty($email)) {
                $updateClauses[] = 'email = %s';
                $params[] = sanitize_email($email);
            }

            if (!empty($updateClauses)) {
                $query = "UPDATE {$table_name} SET " . implode(', ', $updateClauses);
                $wpdb->query($wpdb->prepare($query, ...$params));
            }

            // Add tags to all contacts
            foreach ($tags as $tag) {
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$tags_table} (contact_id, tag_id)
                 SELECT contact_id, %d FROM {$table_name}",
                    $tag['id']
                ));
                do_action('mailerpress_contact_tag_added_bulk', $tag['id']);
            }

            // Add lists to all contacts
            foreach ($lists as $list) {
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$lists_table} (contact_id, list_id)
                 SELECT contact_id, %d FROM {$table_name}",
                    $list['id']
                ));
                do_action('mailerpress_contact_list_added_bulk', $list['id']);
            }

            do_action('mailerpress_all_contacts_updated');
        }

        // ===============================
        // 🟠 SPECIFIC CONTACT IDS UPDATE
        // ===============================
        else {
            foreach ($ids as $id) {
                $id = (int) $id;

                $updateData = [];
                $updateFormat = [];

                if (!empty($newStatus)) {
                    $updateData['subscription_status'] = esc_html($newStatus);
                    $updateFormat[] = '%s';
                }
                if ($firstName !== null) {
                    $updateData['first_name'] = sanitize_text_field($firstName);
                    $updateFormat[] = '%s';
                }
                if ($lastName !== null) {
                    $updateData['last_name'] = sanitize_text_field($lastName);
                    $updateFormat[] = '%s';
                }
                if ($email !== null) {
                    $sanitizedEmail = sanitize_email($email);

                    // Check if email already exists for another contact
                    $existingContact = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT contact_id FROM {$table_name} WHERE email = %s AND contact_id != %d",
                            $sanitizedEmail,
                            $id
                        )
                    );

                    if ($existingContact) {
                        return new \WP_Error(
                            'email_exists',
                            __('This email address is already in use by another contact.', 'mailerpress'),
                            ['status' => 400]
                        );
                    }

                    $updateData['email'] = $sanitizedEmail;
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

                // Add new tags (only if tag doesn't already exist)
                foreach ($tags as $tag) {
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT 1 FROM {$tags_table} WHERE contact_id = %d AND tag_id = %d",
                        $id,
                        (int) $tag['id']
                    ));

                    if (!$exists) {
                        $wpdb->insert(
                            $tags_table,
                            ['contact_id' => $id, 'tag_id' => (int) $tag['id']],
                            ['%d', '%d']
                        );
                        do_action('mailerpress_contact_tag_added', $id, $tag['id']);
                    }
                }

                // Remove tags
                foreach ($removeTags as $tag) {
                    $deleted = $wpdb->delete(
                        $tags_table,
                        ['contact_id' => $id, 'tag_id' => (int) $tag['id']],
                        ['%d', '%d']
                    );
                    // Déclencher le hook seulement si la suppression a réussi
                    if ($deleted !== false && $deleted > 0) {
                        do_action('mailerpress_contact_tag_removed', $id, $tag['id']);
                    }
                }

                // Add new lists
                foreach ($lists as $list) {
                    // Vérifier si l'association existe déjà
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT 1 FROM {$lists_table} WHERE contact_id = %d AND list_id = %d",
                        $id,
                        (int) $list['id']
                    ));

                    if (!$exists) {
                        $wpdb->insert(
                            $lists_table,
                            ['contact_id' => $id, 'list_id' => (int) $list['id']],
                            ['%d', '%d']
                        );
                        do_action('mailerpress_contact_list_added', $id, $list['id']);
                    }
                }

                // Remove lists
                foreach ($removeLists as $list) {
                    $wpdb->delete(
                        $lists_table,
                        ['contact_id' => $id, 'list_id' => (int) $list['id']],
                        ['%d', '%d']
                    );
                    do_action('mailerpress_contact_list_removed', $id, $list['id']);
                }

                // Update custom fields
                foreach ($customFields as $field) {
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT field_id FROM {$customFieldsTable} WHERE contact_id = %d AND field_key = %s",
                        $id,
                        $field['field_key']
                    ));

                    if ($existing) {
                        $wpdb->update(
                            $customFieldsTable,
                            ['field_value' => $field['field_value']],
                            ['field_id' => $existing],
                            ['%s'],
                            ['%d']
                        );
                        do_action('mailerpress_contact_custom_field_updated', $id, $field['field_key'], $field['field_value']);
                    } else {
                        $wpdb->insert(
                            $customFieldsTable,
                            [
                                'contact_id' => $id,
                                'field_key' => $field['field_key'],
                                'field_value' => $field['field_value']
                            ],
                            ['%d', '%s', '%s']
                        );
                        do_action('mailerpress_contact_custom_field_added', $id, $field['field_key'], $field['field_value']);
                    }
                }
                do_action('mailerpress_contact_updated', $id);
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Contacts updated successfully.', 'mailerpress'),
        ], 200);
    }

    #[Endpoint(
        'contact/(?P<id>\d+)',
        methods: 'PUT',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function editSingle(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);
        $tags_table = Tables::get(Tables::CONTACT_TAGS);
        $lists_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        $id = (int)$request->get_param('id');
        $newStatus = $request->get_param('newStatus');
        $tags = $request->get_param('tags') ?? [];
        $lists = $request->get_param('lists') ?? [];
        $removeTags = $request->get_param('removeTags') ?? [];
        $removeLists = $request->get_param('removeLists') ?? [];

        // Nouveaux paramètres pour mettre à jour les informations du contact
        $email = $request->get_param('email');
        $firstName = $request->get_param('first_name');
        $lastName = $request->get_param('last_name');
        $customFields = $request->get_param('custom_fields') ?? [];

        if (empty($id)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Missing contact ID.', 'mailerpress'),
            ], 400);
        }

        $contactUpdated = false;

        // Préparer les données à mettre à jour dans le contact principal
        $updateData = ['updated_at' => current_time('mysql')];
        $updateFormat = ['%s'];

        if (!empty($newStatus)) {
            $updateData['subscription_status'] = esc_html($newStatus);
            $updateFormat[] = '%s';
            $contactUpdated = true;
        }

        if (isset($email) && !empty($email)) {
            $updateData['email'] = sanitize_email($email);
            $updateFormat[] = '%s';
            $contactUpdated = true;
        }

        if (isset($firstName)) {
            $updateData['first_name'] = sanitize_text_field($firstName);
            $updateFormat[] = '%s';
            $contactUpdated = true;
        }

        if (isset($lastName)) {
            $updateData['last_name'] = sanitize_text_field($lastName);
            $updateFormat[] = '%s';
            $contactUpdated = true;
        }

        // Mettre à jour le contact si nécessaire
        if ($contactUpdated) {
            $wpdb->update(
                $table_name,
                $updateData,
                ['contact_id' => $id],
                $updateFormat,
                ['%d']
            );
        }

        // Add tags (only if tag doesn't already exist)
        foreach ($tags as $tag) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$tags_table} WHERE contact_id = %d AND tag_id = %d",
                $id,
                (int) $tag['id']
            ));

            if (!$exists) {
                $wpdb->insert(
                    $tags_table,
                    [
                        'contact_id' => $id,
                        'tag_id' => $tag['id']
                    ],
                    ['%d', '%d']
                );
                do_action('mailerpress_contact_tag_added', $id, $tag['id']);
            }
        }

        // Remove tags
        foreach ($removeTags as $tag) {
            $deleted = $wpdb->delete(
                $tags_table,
                [
                    'contact_id' => $id,
                    'tag_id' => $tag['id']
                ],
                ['%d', '%d']
            );
            // Déclencher le hook seulement si la suppression a réussi
            if ($deleted !== false && $deleted > 0) {
                do_action('mailerpress_contact_tag_removed', $id, $tag['id']);
            }
        }

        // Add lists
        foreach ($lists as $list) {
            // Vérifier si l'association existe déjà
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$lists_table} WHERE contact_id = %d AND list_id = %d",
                $id,
                (int) $list['id']
            ));

            if (!$exists) {
                $wpdb->insert(
                    $lists_table,
                    [
                        'contact_id' => $id,
                        'list_id' => $list['id']
                    ],
                    ['%d', '%d']
                );
                do_action('mailerpress_contact_list_added', $id, $list['id']);
            }
        }

        // Remove lists
        foreach ($removeLists as $list) {
            $wpdb->delete(
                $lists_table,
                [
                    'contact_id' => $id,
                    'list_id' => $list['id']
                ],
                ['%d', '%d']
            );
            do_action('mailerpress_contact_list_removed', $id, $list['id']);
        }

        // Gérer les custom fields
        if (!empty($customFields) && is_array($customFields)) {
            $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);

            foreach ($customFields as $field) {
                if (!isset($field['field_key'])) {
                    continue;
                }

                $fieldKey = sanitize_text_field($field['field_key']);
                $fieldValue = isset($field['value']) ? $field['value'] : '';

                // Sanitize value according to field type (if available)
                if (class_exists('MailerPress\\Models\\CustomFields')) {
                    $sanitizedValue = \MailerPress\Models\CustomFields::sanitizeValue($fieldKey, $fieldValue);
                } else {
                    $sanitizedValue = $fieldValue;
                }

                // Skip null values
                if ($sanitizedValue === null) {
                    continue;
                }

                // Convert to string for database storage
                $dbValue = is_numeric($sanitizedValue)
                    ? (string) $sanitizedValue
                    : sanitize_text_field((string) $sanitizedValue);

                // Vérifier si le champ existe déjà
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$customFieldsTable} WHERE contact_id = %d AND field_key = %s",
                    $id,
                    $fieldKey
                ));

                if ($existing) {
                    // Mettre à jour
                    $wpdb->update(
                        $customFieldsTable,
                        ['field_value' => $dbValue, 'updated_at' => current_time('mysql')],
                        ['contact_id' => $id, 'field_key' => $fieldKey],
                        ['%s', '%s'],
                        ['%d', '%s']
                    );
                    do_action('mailerpress_contact_custom_field_updated', $id, $fieldKey, $sanitizedValue);
                } else {
                    // Insérer
                    $wpdb->insert(
                        $customFieldsTable,
                        [
                            'contact_id' => $id,
                            'field_key' => $fieldKey,
                            'field_value' => $dbValue,
                        ],
                        ['%d', '%s', '%s']
                    );
                    do_action('mailerpress_contact_custom_field_added', $id, $fieldKey, $sanitizedValue);
                }

                $contactUpdated = true;
            }
        }

        if ($contactUpdated || !empty($tags) || !empty($lists) || !empty($removeTags) || !empty($removeLists)) {
            do_action('mailerpress_contact_updated', $id);
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Contact updated successfully.', 'mailerpress'),
        ], 200);
    }

    #[Endpoint(
        '/contact',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function delete(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        // Get the contact IDs from the request (expects an array)
        $contact_ids = $request->get_param('ids'); // Assuming 'ids' is an array of contact IDs

        // Validate that the input is an array
        if (!\is_array($contact_ids) || empty($contact_ids)) {
            return new \WP_Error(
                'invalid_input',
                __('Contact IDs must be an array and cannot be empty', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Verify if the contacts exist (fetch email/name for webhook before deletion)
        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);
        $placeholders = implode(',', array_fill(0, \count($contact_ids), '%d'));
        $existing_contacts = $wpdb->get_results($wpdb->prepare(
            "SELECT contact_id, email, first_name, last_name FROM {$table_name} WHERE contact_id IN ({$placeholders})",
            ...$contact_ids
        ));

        // If any contact does not exist, return an error
        if (\count($existing_contacts) !== \count($contact_ids)) {
            return new \WP_Error(
                'contact_not_found',
                __('One or more contacts were not found.', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Delete the contacts
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table_name} WHERE contact_id IN ({$placeholders})", ...$contact_ids));

        if ($deleted) {
            // Fire webhook for each deleted contact
            foreach ($existing_contacts as $contact) {
                do_action('mailerpress_contact_deleted', (int) $contact->contact_id, $contact->email ?? '', $contact->first_name ?? '', $contact->last_name ?? '');
            }

            return new \WP_REST_Response([
                'success' => true,
                'message' => __('Contacts deleted successfully', 'mailerpress'),
                'deleted_contacts' => $contact_ids,
            ], 200);
        }

        return new \WP_Error(
            'delete_failed',
            __('Failed to delete the contacts.', 'mailerpress'),
            ['status' => 500]
        );
    }


    #[Endpoint(
        '/contact/list/(?P<id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function deleteContactList(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        // Route param: contact ID from URL
        $contact_id = (int)$request->get_param('id');
        // Query param: list ID from ?listId=123
        $list_id = (int)$request->get_param('listId');

        if (!$contact_id || !$list_id) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Missing contact_id or list_id.', 'mailerpress'),
            ], 400);
        }

        $table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        $deleted = $wpdb->delete(
            $table,
            [
                'contact_id' => $contact_id,
                'list_id' => $list_id,
            ],
            [
                '%d',
                '%d',
            ]
        );

        if ($deleted === false) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Database error occurred.', 'mailerpress'),
            ], 500);
        }

        if ($deleted === 0) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('No matching record found.', 'mailerpress'),
            ], 404);
        }

        // Déclencher le hook seulement si la suppression a réussi
        if ($deleted > 0) {
            do_action('mailerpress_contact_list_removed', $contact_id, $list_id);
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('List removed from contact.', 'mailerpress'),
        ], 200);
    }


    #[Endpoint(
        '/contact/tag/(?P<id>\d+)',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageLists'],
    )]
    public function deleteContactTag(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        // Route param: contact ID from URL
        $contact_id = (int)$request->get_param('id');

        // Pour les requêtes DELETE, récupérer tagId depuis le body JSON ou les query params
        $body_params = $request->get_json_params();
        $tag_id = 0;

        if (!empty($body_params['tagId'])) {
            $tag_id = (int)$body_params['tagId'];
        } elseif ($request->get_param('tagId')) {
            // Fallback sur query param si disponible
            $tag_id = (int)$request->get_param('tagId');
        }

        if (!$tag_id || !$contact_id) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Missing contact_id or tag_id.', 'mailerpress'),
            ], 400);
        }

        $table = Tables::get(Tables::CONTACT_TAGS);

        $deleted = $wpdb->delete(
            $table,
            [
                'contact_id' => $contact_id,
                'tag_id' => $tag_id,
            ],
            [
                '%d',
                '%d',
            ]
        );

        if ($deleted === false) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Database error occurred.', 'mailerpress'),
            ], 500);
        }

        if ($deleted === 0) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('No matching record found.', 'mailerpress'),
            ], 404);
        }

        // Déclencher le hook seulement si la suppression a réussi
        if ($deleted > 0) {
            do_action('mailerpress_contact_tag_removed', $contact_id, $tag_id);
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Tag removed from contact.', 'mailerpress'),
        ], 200);
    }


    #[Endpoint(
        '/contact/all',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function deleteAll(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);
        $batchTable = Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES);
        $importChunksTable = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);

        // Get total contact count
        $total_contacts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        // Threshold for batch processing (20K contacts)
        $batch_threshold = apply_filters('mailerpress_delete_batch_threshold', 20000);
        // Batch size (5000 contacts per chunk) - only filterable if > threshold
        $batch_size = $total_contacts > $batch_threshold
            ? apply_filters('mailerpress_delete_batch_size', 5000)
            : 5000;

        // If less than threshold, delete directly
        if ($total_contacts < $batch_threshold) {
            $result = $wpdb->query("DELETE FROM {$table_name}");

            if (false === $result) {
                return new \WP_REST_Response(['message' => __('Failed to delete contacts.', 'mailerpress')], 500);
            }

            return new \WP_REST_Response(
                [
                    'message' => __('All contacts have been deleted successfully.', 'mailerpress'),
                    'deleted' => $result,
                    'batch_id' => null,
                ],
                200
            );
        }

        // For large deletions, use batch processing
        // Create a batch record for tracking
        $wpdb->insert($batchTable, [
            'tags' => null,
            'lists' => null,
            'subscription_status' => 'pending',
            'count' => $total_contacts,
            'processed_count' => 0,
            'status' => 'pending',
        ]);

        $batch_id = $wpdb->insert_id;

        if (!$batch_id) {
            return new \WP_REST_Response(['message' => __('Failed to create delete batch.', 'mailerpress')], 500);
        }

        // Get all contact IDs in chunks
        $offset = 0;
        $chunk_ids = [];

        while (true) {
            $contact_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT contact_id FROM {$table_name} ORDER BY contact_id ASC LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ));

            if (empty($contact_ids)) {
                break;
            }

            // Save chunk to the chunks table
            $wpdb->insert($importChunksTable, [
                'batch_id' => $batch_id,
                'chunk_data' => wp_json_encode($contact_ids),
                'processed' => 0, // 0 = pending
            ]);

            $chunk_id = $wpdb->insert_id;
            if ($chunk_id) {
                $chunk_ids[] = $chunk_id;
            }

            $offset += $batch_size;

            // Safety check to prevent infinite loop
            if ($offset > $total_contacts) {
                break;
            }
        }

        // Schedule first chunks immediately for faster processing
        if (!empty($chunk_ids) && function_exists('as_schedule_single_action')) {
            $chunks_to_schedule = array_slice($chunk_ids, 0, 10);
            foreach ($chunks_to_schedule as $index => $chunk_id) {
                as_schedule_single_action(
                    time() + $index,
                    'process_delete_chunk',
                    [$chunk_id],
                    'mailerpress'
                );
            }
        }

        return new \WP_REST_Response(
            [
                'message' => __('Delete batch created successfully. Deletion in progress...', 'mailerpress'),
                'batch_id' => $batch_id,
                'total_contacts' => $total_contacts,
            ],
            200
        );
    }

    #[Endpoint(
        'contacts/bactches/pending',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function contactBatchImport(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES);

        // Query all batches with status "pending" (actively importing)
        // Exclude delete batches (those where tags and lists are both null)
        $results = $wpdb->get_results(
            "SELECT * FROM `{$table_name}`
            WHERE `status` = 'pending'
            AND NOT (tags IS NULL AND lists IS NULL)
            ORDER BY created_at DESC",
            ARRAY_A
        );

        // Always return an array (empty if no pending batches)
        // This ensures frontend can safely check response.length
        return new \WP_REST_Response(
            is_array($results) ? $results : [],
            200
        );
    }

    #[Endpoint(
        'contacts/delete/batches/pending',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function contactBatchDelete(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES);

        // Query all batches with status "pending" (actively deleting)
        // We identify delete batches by checking if tags and lists are both null (delete batches don't have these)
        $results = $wpdb->get_results(
            "SELECT * FROM `{$table_name}`
            WHERE `status` = 'pending'
            AND tags IS NULL
            AND lists IS NULL
            ORDER BY created_at DESC",
            ARRAY_A
        );

        // Always return an array (empty if no pending batches)
        return new \WP_REST_Response(
            is_array($results) ? $results : [],
            200
        );
    }

    #[Endpoint(
        'contacts/delete/reset',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function resetBatchDelete(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $batch_id = (int)$request->get_param('batch_id');

        if (!$batch_id) {
            return new \WP_Error(
                'invalid_params',
                __('Batch ID is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Verify batch exists and is a delete batch
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES) . " WHERE batch_id = %d AND tags IS NULL AND lists IS NULL",
            $batch_id
        ));

        if (!$batch) {
            return new \WP_Error(
                'batch_not_found',
                __('Delete batch not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Get all chunks for this batch
        $chunks = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM " . Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS) . " WHERE batch_id = %d",
            $batch_id
        ));

        // Cancel/delete all scheduled actions for these chunks
        if (function_exists('as_unschedule_action') && !empty($chunks)) {
            foreach ($chunks as $chunk) {
                as_unschedule_action('process_delete_chunk', [$chunk->id]);
            }
        }

        // Delete all chunks for this batch
        $wpdb->delete(
            Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS),
            ['batch_id' => $batch_id],
            ['%d']
        );

        // Delete the batch itself
        $wpdb->delete(
            Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES),
            ['batch_id' => $batch_id],
            ['%d']
        );

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Delete batch and all related data have been cleaned up', 'mailerpress'),
        ], 200);
    }

    #[Endpoint(
        'contacts/import/progress/(?P<batch_id>\d+)',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function getBatchProgress(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $batch_id = (int)$request['batch_id'];

        if (!$batch_id) {
            return new \WP_Error(
                'invalid_batch_id',
                __('Invalid batch ID', 'mailerpress'),
                ['status' => 400]
            );
        }

        $batchTable = Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES);
        $chunksTable = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);

        // Get batch info
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$batchTable} WHERE batch_id = %d",
            $batch_id
        ), ARRAY_A);

        if (!$batch) {
            return new \WP_Error(
                'batch_not_found',
                __('Batch not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Get chunk statistics
        $chunk_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_chunks,
                SUM(CASE WHEN processed = 0 THEN 1 ELSE 0 END) as pending_chunks,
                SUM(CASE WHEN processed = 1 THEN 1 ELSE 0 END) as completed_chunks,
                SUM(CASE WHEN processed = 2 THEN 1 ELSE 0 END) as processing_chunks,
                SUM(CASE WHEN processed = 3 THEN 1 ELSE 0 END) as failed_chunks
            FROM {$chunksTable}
            WHERE batch_id = %d
        ", $batch_id), ARRAY_A);

        // Calculate progress percentage
        $total_count = (int)$batch['count'];
        $processed_count = (int)$batch['processed_count'];
        $progress_percentage = $total_count > 0 ? round(($processed_count / $total_count) * 100, 2) : 0;

        return new \WP_REST_Response([
            'batch_id' => $batch_id,
            'status' => $batch['status'],
            'total_contacts' => $total_count,
            'processed_contacts' => $processed_count,
            'progress_percentage' => $progress_percentage,
            'chunks' => [
                'total' => (int)$chunk_stats['total_chunks'],
                'pending' => (int)$chunk_stats['pending_chunks'],
                'processing' => (int)$chunk_stats['processing_chunks'],
                'completed' => (int)$chunk_stats['completed_chunks'],
                'failed' => (int)$chunk_stats['failed_chunks'],
            ],
            'created_at' => $batch['created_at'],
            'updated_at' => $batch['updated_at'],
        ], 200);
    }

    #[Endpoint(
        'contacts/import/retry/(?P<batch_id>\d+)',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function retryFailedChunks(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $batch_id = (int)$request['batch_id'];

        if (!$batch_id) {
            return new \WP_Error(
                'invalid_batch_id',
                __('Invalid batch ID', 'mailerpress'),
                ['status' => 400]
            );
        }

        $chunksTable = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);

        // Get all failed chunks for this batch
        $failed_chunks = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$chunksTable}
            WHERE batch_id = %d AND processed = 3
            ORDER BY id ASC
        ", $batch_id));

        if (empty($failed_chunks)) {
            return new \WP_REST_Response([
                'success' => true,
                'message' => __('No failed chunks to retry', 'mailerpress'),
                'retried_count' => 0,
            ], 200);
        }

        $retried_count = 0;

        foreach ($failed_chunks as $chunk) {
            // Reset chunk status to pending
            $wpdb->update(
                $chunksTable,
                ['processed' => 0],
                ['id' => $chunk->id],
                ['%d'],
                ['%d']
            );

            // Schedule first 10 failed chunks
            if ($retried_count < 10 && function_exists('as_schedule_single_action')) {
                as_schedule_single_action(
                    time() + $retried_count,
                    'process_import_chunk',
                    [$chunk->id, false]
                );
            }

            $retried_count++;
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => sprintf(__('Retrying %d failed chunks', 'mailerpress'), $retried_count),
            'retried_count' => $retried_count,
        ], 200);
    }

    #[Endpoint(
        'contacts/import/kickstart/(?P<batch_id>\d+)',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function kickstartStuckImport(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $batch_id = (int)$request['batch_id'];

        if (!$batch_id) {
            return new \WP_Error(
                'invalid_batch_id',
                __('Invalid batch ID', 'mailerpress'),
                ['status' => 400]
            );
        }

        $chunksTable = Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS);

        // Reset any chunks stuck in "processing" state (2) back to pending (0)
        // These are likely stuck due to timeouts or failures
        $wpdb->query($wpdb->prepare("
            UPDATE {$chunksTable}
            SET processed = 0
            WHERE batch_id = %d AND processed = 2
        ", $batch_id));

        $reset_count = $wpdb->rows_affected;

        // Get pending chunks
        $pending_chunks = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$chunksTable}
            WHERE batch_id = %d AND processed = 0
            ORDER BY id ASC
            LIMIT 10
        ", $batch_id));

        $scheduled_count = 0;

        if (!empty($pending_chunks)) {
            foreach ($pending_chunks as $chunk) {
                if (function_exists('as_schedule_single_action')) {
                    // Check if already scheduled
                    $already_scheduled = false;
                    if (function_exists('as_has_scheduled_action')) {
                        $already_scheduled = as_has_scheduled_action('process_import_chunk', [$chunk->id]);
                    }

                    if (!$already_scheduled) {
                        as_schedule_single_action(
                            time() + $scheduled_count,
                            'process_import_chunk',
                            [$chunk->id, false]
                        );
                        $scheduled_count++;
                    }
                }
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => sprintf(
                __('Reset %d stuck chunks and scheduled %d chunks for processing', 'mailerpress'),
                $reset_count,
                $scheduled_count
            ),
            'reset_count' => $reset_count,
            'scheduled_count' => $scheduled_count,
        ], 200);
    }

    #[Endpoint(
        'contacts',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
        args: [
            'tags' => [
                'required' => false,
                'type' => 'array',
            ],
        ],
    )]
    public function response(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $tags = $request->get_param('tags') ?? '';
        $lists = $request->get_param('lists') ?? '';

        return new \WP_REST_Response(
            Kernel::getContainer()->get(\MailerPress\Models\Contacts::class)->getContactsWithTagsAndLists(
                $lists,
                $tags
            ),
            200
        );
    }

    #[Endpoint(
        'contact/export',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function export(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contact_ids = $request->get_param('contact_ids');
        $email = sanitize_email($request->get_param('email'));
        $export_id = uniqid(); // utilisé pour stocker les fichiers temporairement
        $batch_size = 200;
        $i = 0;

        if (is_array($contact_ids) && !empty($contact_ids)) {
            // Mode ciblé : on divise les contact_ids en batchs
            $chunks = array_chunk($contact_ids, $batch_size);

            foreach ($chunks as $index => $chunk) {
                as_schedule_single_action(
                    time() + $index,
                    'mailerpress_export_contact_batch',
                    [
                        'export_id' => $export_id,
                        'status' => null,
                        'offset' => null,
                        'contact_ids' => $chunk,
                    ],
                    'mailerpress'
                );
            }

            $total_batches = count($chunks);
        } else {
            // Mode complet : on récupère tous les contacts par status
            $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name");

            if ($count === 0) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => __('No contacts to export.', 'mailerpress'),
                ], 200);
            }

            $statuses = ['subscribed', 'unsubscribed'];
            $total_batches = 0;

            foreach ($statuses as $status) {
                $status_count = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE subscription_status = %s",
                    $status
                ));

                if ($status_count === 0) {
                    continue;
                }

                $offsets = [];
                for ($offset = 0; $offset < $status_count; $offset += $batch_size) {
                    $offsets[] = $offset;
                }

                foreach ($offsets as $offset) {
                    as_schedule_single_action(
                        time() + $i,
                        'mailerpress_export_contact_batch',
                        [
                            'export_id' => $export_id,
                            'status' => $status,
                            'offset' => $offset,
                        ],
                        'mailerpress'
                    );
                    $i++;
                }

                $total_batches += count($offsets);
            }
        }

        // Planifie la finalisation une fois tous les batchs théoriquement terminés
        as_schedule_single_action(
            time() + ($total_batches * 2),
            'mailerpress_finalize_export_contact_zip',
            ['export_id' => $export_id, 'email' => $email],
            'mailerpress'
        );

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Export started', 'mailerpress'),
            'export_id' => $export_id,
        ]);
    }


    /**
     * Legacy batch import - schedules only first chunk, rest are staggered
     * @deprecated Use initBatchImport + addChunkToBatch for better memory management
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'contacts/import',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function batchImport(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $data = $request->get_param('data') ?? [];

        $batch_data = [
            'tags' => wp_json_encode($data['tags'] ?? []),
            'lists' => wp_json_encode($data['lists'] ?? []),
            'count' => \count($data['mapping']),
            'subscription_status' => $data['status'],
        ];

        $wpdb->insert(
            Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES),
            $batch_data
        );

        $batch_id = $wpdb->insert_id;

        if ($batch_id) {
            // Split contacts into chunks (50 per chunk for optimal processing)
            $chunks = array_chunk($data['mapping'], 50);
            $chunk_ids = [];

            foreach ($chunks as $chunk) {
                // Save chunk to the temporary table
                $wpdb->insert(Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS), [
                    'batch_id' => $batch_id,
                    'chunk_data' => wp_json_encode($chunk),
                    'processed' => 0, // 0 = pending
                ]);

                $chunk_id = $wpdb->insert_id;
                $chunk_ids[] = $chunk_id;
            }

            // Schedule first 10 chunks immediately for faster processing
            if (!empty($chunk_ids) && function_exists('as_schedule_single_action')) {
                $chunks_to_schedule = array_slice($chunk_ids, 0, 10);
                foreach ($chunks_to_schedule as $index => $chunk_id) {
                    as_schedule_single_action(
                        time() + $index,
                        'process_import_chunk',
                        [$chunk_id, $data['forceUpdate'] ?? false]
                    );
                }
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'batch_id' => $batch_id,
        ], 200);
    }

    /**
     * Initialize a batch import without loading all data in memory
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'contacts/import/init',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function initBatchImport(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $data = $request->get_param('data') ?? [];

        // Initialize count to 0 - it will be updated as chunks are added
        $batch_data = [
            'tags' => wp_json_encode($data['tags'] ?? []),
            'lists' => wp_json_encode($data['lists'] ?? []),
            'count' => 0, // Start at 0, will be incremented as chunks are added
            'subscription_status' => $data['status'] ?? 'pending',
        ];

        $wpdb->insert(
            Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES),
            $batch_data
        );

        $batch_id = $wpdb->insert_id;

        if (!$batch_id) {
            return new \WP_Error(
                'batch_creation_failed',
                __('Failed to create import batch', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response([
            'success' => true,
            'batch_id' => $batch_id,
        ], 200);
    }

    /**
     * Add a chunk to an existing batch import
     * Automatically splits large chunks into optimal sizes for processing
     * Schedules first 20 chunks immediately for faster start
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'contacts/import/chunk',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function addChunkToBatch(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $batch_id = (int)$request->get_param('batch_id');
        $chunk = $request->get_param('chunk') ?? [];
        $forceUpdate = (bool)$request->get_param('forceUpdate');

        if (!$batch_id || empty($chunk)) {
            return new \WP_Error(
                'invalid_params',
                __('Batch ID and chunk data are required', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Verify batch exists
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES) . " WHERE batch_id = %d",
            $batch_id
        ));

        if (!$batch) {
            return new \WP_Error(
                'batch_not_found',
                __('Import batch not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Split large chunks into smaller ones for better processing
        // Default: 500 contacts per chunk (filterable for performance tuning)
        $optimal_chunk_size = apply_filters('mailerpress_import_chunk_size', 500);

        // Ensure minimum of 50 and maximum of 2000 for safety
        $optimal_chunk_size = max(50, min(2000, $optimal_chunk_size));

        $sub_chunks = array_chunk($chunk, $optimal_chunk_size);
        $chunk_ids = [];
        $total_contacts = 0;

        foreach ($sub_chunks as $sub_chunk) {
            // Count contacts in this sub-chunk
            $sub_chunk_count = count($sub_chunk);
            $total_contacts += $sub_chunk_count;

            // Save sub-chunk to the temporary table with pending status
            $wpdb->insert(Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS), [
                'batch_id' => $batch_id,
                'chunk_data' => wp_json_encode($sub_chunk),
                'processed' => 0, // 0 = pending, 1 = done, 2 = processing, 3 = failed
                'retry_count' => 0,
            ]);

            $chunk_id = $wpdb->insert_id;
            if ($chunk_id) {
                $chunk_ids[] = $chunk_id;
            }
        }

        if (!empty($chunk_ids) && $total_contacts > 0) {
            // Update the batch count by adding contacts from all sub-chunks
            $wpdb->query($wpdb->prepare(
                "UPDATE " . Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES) . "
                SET count = count + %d
                WHERE batch_id = %d",
                $total_contacts,
                $batch_id
            ));

            // Get count of existing chunks for this batch
            $existing_chunks_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS) . "
                WHERE batch_id = %d",
                $batch_id
            ));

            // Schedule initial chunks immediately for faster start
            // Default: 50 chunks (filterable for performance tuning)
            $initial_chunks_to_schedule = apply_filters('mailerpress_import_initial_chunks_schedule', 50);
            $initial_chunks_to_schedule = max(10, min(200, $initial_chunks_to_schedule));

            // This provides better parallelism and faster initial processing
            if ($existing_chunks_count <= $initial_chunks_to_schedule && function_exists('as_schedule_single_action')) {
                $schedule_count = 0;

                // Stagger delay (in seconds) between scheduled chunks - filterable
                $stagger_delay = apply_filters('mailerpress_import_chunk_stagger_delay', 0.5);

                foreach ($chunk_ids as $chunk_id) {
                    // Check if action is already scheduled to avoid duplicates
                    $already_scheduled = function_exists('as_has_scheduled_action')
                        ? as_has_scheduled_action('process_import_chunk', [$chunk_id])
                        : false;

                    if (!$already_scheduled) {
                        // Stagger chunks to avoid overwhelming the server
                        as_schedule_single_action(
                            time() + (int)($schedule_count * $stagger_delay),
                            'process_import_chunk',
                            [$chunk_id, $forceUpdate]
                        );
                        $schedule_count++;
                    }
                }
            }
            // After initial batch, chunks will be scheduled automatically by the processor
        }

        return new \WP_REST_Response([
            'success' => true,
            'chunk_ids' => $chunk_ids,
            'chunks_created' => count($chunk_ids),
            'contacts_count' => $total_contacts,
        ], 200);
    }

    /**
     * Reset/Cancel a batch import and clean up all related data
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'contacts/import/reset',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function resetBatchImport(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $batch_id = (int)$request->get_param('batch_id');

        if (!$batch_id) {
            return new \WP_Error(
                'invalid_params',
                __('Batch ID is required', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Verify batch exists
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES) . " WHERE batch_id = %d",
            $batch_id
        ));

        if (!$batch) {
            return new \WP_Error(
                'batch_not_found',
                __('Import batch not found', 'mailerpress'),
                ['status' => 404]
            );
        }

        // Get all chunks for this batch
        $chunks = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM " . Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS) . " WHERE batch_id = %d",
            $batch_id
        ));

        // Cancel/delete all scheduled actions for these chunks
        if (function_exists('as_unschedule_action') && !empty($chunks)) {
            foreach ($chunks as $chunk) {
                // Try to unschedule with different possible argument combinations
                as_unschedule_action('process_import_chunk', [$chunk->id, false]);
                as_unschedule_action('process_import_chunk', [$chunk->id, true]);
                as_unschedule_action('process_import_chunk', [$chunk->id]);
            }
        }

        // Also cancel any pending actions using ActionScheduler store if available
        if (class_exists('\ActionScheduler_Store') && !empty($chunks)) {
            try {
                $store = \ActionScheduler_Store::instance();
                foreach ($chunks as $chunk) {
                    // Get all actions for this chunk
                    $actions = as_get_scheduled_actions([
                        'hook' => 'process_import_chunk',
                        'args' => [$chunk->id],
                        'status' => \ActionScheduler_Store::STATUS_PENDING,
                    ], 'ids');

                    if (!empty($actions)) {
                        foreach ($actions as $action_id) {
                            try {
                                $action = $store->fetch_action($action_id);
                                if ($action) {
                                    $store->cancel_action($action_id);
                                    $store->delete_action($action_id);
                                }
                            } catch (\Exception $e) {
                                // Continue even if cancellation fails
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Continue even if ActionScheduler operations fail
            }
        }

        // Delete all chunks for this batch
        $wpdb->delete(
            Tables::get(Tables::MAILERPRESS_IMPORT_CHUNKS),
            ['batch_id' => $batch_id],
            ['%d']
        );

        // Delete the batch itself
        $wpdb->delete(
            Tables::get(Tables::MAILERPRESS_CONTACT_BATCHES),
            ['batch_id' => $batch_id],
            ['%d']
        );

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Import batch and all related data have been cleaned up', 'mailerpress'),
        ], 200);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'contact/import',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function importContact(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;
        $contact = $request->get_param('item');
        $status = sanitize_text_field($request->get_param('status')) ?? 'pending';
        $contactTags = $request->get_param('tags');
        $contactLists = $request->get_param('lists');
        $customFields = $contact['custom_fields'] ?? []; // <-- get custom_fields from $contact
        $forceUpdate = $request->get_param('forceUpdate');
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contactCustomFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS); // NEW

        $contact_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT contact_id FROM {$contactTable} WHERE email = %s LIMIT 1",
                trim($contact['email'], '"')
            )
        );

        if (null === $contact_id) {
            // Extract and clean first_name and last_name
            // Handle both array_key_exists and isset to catch empty strings
            $first_name = '';
            $last_name = '';

            if (array_key_exists('first_name', $contact)) {
                $first_name = is_string($contact['first_name']) ? trim($contact['first_name'], ' "\'') : '';
            }

            if (array_key_exists('last_name', $contact)) {
                $last_name = is_string($contact['last_name']) ? trim($contact['last_name'], ' "\'') : '';
            }

            $contact_data = [
                'email' => sanitize_email($contact['email'] ?? ''),
                'first_name' => sanitize_text_field($first_name),
                'last_name' => sanitize_text_field($last_name),
                'subscription_status' => sanitize_text_field($status),
                'unsubscribe_token' => wp_generate_uuid4(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'opt_in_source' => 'batch_import_file',
                'access_token' => bin2hex(random_bytes(32))
            ];

            $result = $wpdb->insert($contactTable, $contact_data);

            if (false !== $result) {
                $contactId = $wpdb->insert_id;

                // Tags (only if tag doesn't already exist)
                foreach ($contactTags ?? [] as $tag) {
                    $tagsTable = Tables::get(Tables::CONTACT_TAGS);
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT 1 FROM {$tagsTable} WHERE contact_id = %d AND tag_id = %d",
                        $contactId,
                        (int) $tag['id']
                    ));

                    if (!$exists) {
                        $wpdb->insert($tagsTable, [
                            'contact_id' => $contactId,
                            'tag_id' => $tag['id'],
                        ]);
                        do_action('mailerpress_contact_tag_added', $contactId, $tag['id']);
                    }
                }

                // Lists
                foreach ($contactLists ?? [] as $list) {
                    $listsTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
                    // Vérifier si l'association existe déjà
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT 1 FROM {$listsTable} WHERE contact_id = %d AND list_id = %d",
                        $contactId,
                        (int) $list['id']
                    ));

                    if (!$exists) {
                        $wpdb->insert($listsTable, [
                            'contact_id' => $contactId,
                            'list_id' => $list['id'],
                        ]);
                        do_action('mailerpress_contact_list_added', $contactId, $list['id']);
                    }
                }

                // Trigger contact_created hook after lists and tags are added
                // This ensures the workflow context includes complete contact data
                do_action('mailerpress_contact_created', $contactId);

                // Custom fields - skip standard fields that shouldn't be in custom_fields
                $standardFields = ['email', 'first_name', 'last_name', 'created_at', 'updated_at'];
                foreach ($customFields as $key => $value) {
                    // Skip if this is a standard field (shouldn't be in custom_fields)
                    if (in_array($key, $standardFields, true)) {
                        continue;
                    }

                    // Sanitize value according to field type
                    $sanitized_value = CustomFields::sanitizeValue($key, $value);

                    // Skip null values (empty or invalid)
                    if ($sanitized_value === null) {
                        continue;
                    }

                    // Convert to string for database storage (handles int, float, string, etc.)
                    $db_value = is_numeric($sanitized_value)
                        ? (string) $sanitized_value
                        : sanitize_text_field((string) $sanitized_value);

                    $insert_result = $wpdb->insert($contactCustomFieldsTable, [
                        'contact_id' => $contactId,
                        'field_key' => sanitize_text_field($key),
                        'field_value' => $db_value,
                    ]);

                    // Déclencher l'action pour notifier que le champ a été ajouté
                    do_action('mailerpress_contact_custom_field_added', $contactId, sanitize_text_field($key), $sanitized_value);
                }

                return new \WP_REST_Response($result, 200);
            }
        } else {
            if (true === $forceUpdate || '1' === $forceUpdate) {
                // Extract and clean first_name and last_name
                // Handle both array_key_exists and isset to catch empty strings
                $first_name = '';
                $last_name = '';

                if (array_key_exists('first_name', $contact)) {
                    $first_name = is_string($contact['first_name']) ? trim($contact['first_name'], ' "\'') : '';
                }

                if (array_key_exists('last_name', $contact)) {
                    $last_name = is_string($contact['last_name']) ? trim($contact['last_name'], ' "\'') : '';
                }

                $result = $wpdb->update(
                    $contactTable,
                    [
                        'subscription_status' => $status,
                        'updated_at' => current_time('mysql'),
                        'first_name' => sanitize_text_field($first_name),
                        'last_name' => sanitize_text_field($last_name),
                    ],
                    ['contact_id' => $contact_id]
                );

                if (false !== $result) {
                    // Tags (only if tag doesn't already exist)
                    foreach ($contactTags ?? [] as $tag) {
                        $tagsTable = Tables::get(Tables::CONTACT_TAGS);
                        $exists = $wpdb->get_var($wpdb->prepare(
                            "SELECT 1 FROM {$tagsTable} WHERE contact_id = %d AND tag_id = %d",
                            $contact_id,
                            (int) $tag['id']
                        ));

                        if (!$exists) {
                            $wpdb->insert($tagsTable, [
                                'contact_id' => $contact_id,
                                'tag_id' => $tag['id'],
                            ]);
                            do_action('mailerpress_contact_tag_added', $contact_id, $tag['id']);
                        }
                    }

                    // Lists
                    foreach ($contactLists ?? [] as $list) {
                        $listsTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
                        // Vérifier si l'association existe déjà
                        $exists = $wpdb->get_var($wpdb->prepare(
                            "SELECT 1 FROM {$listsTable} WHERE contact_id = %d AND list_id = %d",
                            $contact_id,
                            (int) $list['id']
                        ));

                        if (!$exists) {
                            $wpdb->insert($listsTable, [
                                'contact_id' => $contact_id,
                                'list_id' => $list['id'],
                            ]);
                            do_action('mailerpress_contact_list_added', $contact_id, $list['id']);
                        }
                    }

                    // Custom fields - skip standard fields that shouldn't be in custom_fields
                    $standardFields = ['email', 'first_name', 'last_name', 'created_at', 'updated_at'];
                    foreach ($customFields as $key => $value) {
                        // Skip if this is a standard field (shouldn't be in custom_fields)
                        if (in_array($key, $standardFields, true)) {
                            continue;
                        }

                        // Sanitize value according to field type
                        $sanitized_value = CustomFields::sanitizeValue($key, $value);

                        // Delete existing field first
                        $wpdb->delete($contactCustomFieldsTable, [
                            'contact_id' => $contact_id,
                            'field_key' => $key,
                        ]);

                        // Skip null values (empty or invalid)
                        if ($sanitized_value === null) {
                            continue;
                        }

                        // Convert to string for database storage (handles int, float, string, etc.)
                        $db_value = is_numeric($sanitized_value)
                            ? (string) $sanitized_value
                            : sanitize_text_field((string) $sanitized_value);

                        $wpdb->insert($contactCustomFieldsTable, [
                            'contact_id' => $contact_id,
                            'field_key' => sanitize_text_field($key),
                            'field_value' => $db_value,
                        ]);

                        // Déclencher l'action pour notifier que le champ a été mis à jour
                        do_action('mailerpress_contact_custom_field_updated', $contact_id, sanitize_text_field($key), $sanitized_value);
                    }

                    return new \WP_REST_Response($result, 200);
                }
            }
        }

        usleep(100000);

        return new \WP_REST_Response([], 400);
    }

    #[Endpoint(
        'contact/check-email',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function checkEmail(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $email = sanitize_email($request->get_param('email'));
        $excludeId = (int) $request->get_param('exclude_id');

        if (empty($email) || !is_email($email)) {
            return new \WP_Error(
                'invalid_email',
                __('Invalid email address.', 'mailerpress'),
                ['status' => 400]
            );
        }

        $table_name = Tables::get(Tables::MAILERPRESS_CONTACT);

        $query = "SELECT contact_id FROM {$table_name} WHERE email = %s";
        $params = [$email];

        if ($excludeId > 0) {
            $query .= " AND contact_id != %d";
            $params[] = $excludeId;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare($query, ...$params)
        );

        return new \WP_REST_Response([
            'exists' => !empty($existing),
        ]);
    }

    /**
     * Get all custom field definitions
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'custom-fields',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageAudience']
    )]
    public static function getCustomFields(WP_REST_Request $request): WP_REST_Response
    {
        $customFields = new CustomFields();
        $fields = $customFields->all();

        return new WP_REST_Response([
            'success' => true,
            'fields' => $fields,
        ]);
    }

    /**
     * Send confirmation reminder email to a pending contact
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Endpoint(
        'contact/(?P<id>\d+)/send-confirmation-reminder',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function sendConfirmationReminder(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        global $wpdb;

        $contactId = (int) $request->get_param('id');
        $contactsModel = Kernel::getContainer()->get(\MailerPress\Models\Contacts::class);
        $contactEntity = $contactsModel->get($contactId);

        if (!$contactEntity) {
            return new \WP_Error(
                'contact_not_found',
                __('Contact not found.', 'mailerpress'),
                ['status' => 404]
            );
        }

        if ('pending' !== $contactEntity->subscription_status) {
            return new \WP_Error(
                'invalid_status',
                __('This contact is not in pending status.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Get confirmation email settings
        $signupConfirmationOption = get_option('mailerpress_signup_confirmation', wp_json_encode([
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
            )
        ]));

        if (is_string($signupConfirmationOption)) {
            $signupConfirmationOption = json_decode($signupConfirmationOption, true);
        }

        $content = $signupConfirmationOption['emailContent'] ?? '';
        $subject = $signupConfirmationOption['emailSubject'] ?? __('Confirm your subscription to [site:title]', 'mailerpress');

        // Prepare contact data
        $contact = [
            'email' => $contactEntity->email,
            'first_name' => $contactEntity->first_name ?? '',
            'last_name' => $contactEntity->last_name ?? '',
            'activation_link' => wp_unslash(
                home_url(
                    \sprintf(
                        '?mailpress-pages=mailerpress&action=confirm&cid=%s&data=%s',
                        esc_attr($contactEntity->access_token),
                        esc_attr($contactEntity->unsubscribe_token),
                    )
                )
            ),
        ];

        $site = [
            'title' => get_bloginfo('name'),
            'home_url' => home_url('/'),
        ];

        // Replace dynamic variables
        $placeholders = [
            '[contact:email]' => $contact['email'],
            '[contact:firstName]' => $contact['first_name'],
            '[contact:lastName]' => $contact['last_name'],
            '[site:title]' => $site['title'],
            '[activation_link]' => '<a href="' . $contact['activation_link'] . '">',
            '[/activation_link]' => '</a>',
            '[site:homeURL]' => $site['home_url'],
        ];

        $body = str_replace(array_keys($placeholders), array_values($placeholders), $content);
        $body = nl2br($body);
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);

        // Get email service
        $mailer = Kernel::getContainer()->get(\MailerPress\Core\EmailManager\EmailServiceManager::class)->getActiveService();
        $config = $mailer->getConfig();

        if (
            empty($config['conf']['default_email'])
            || empty($config['conf']['default_name'])
        ) {
            $globalSender = get_option('mailerpress_global_email_senders');
            if (is_string($globalSender)) {
                $globalSender = json_decode($globalSender, true);
            }
            $config['conf']['default_email'] = $globalSender['fromAddress'] ?? '';
            $config['conf']['default_name'] = $globalSender['fromName'] ?? '';
        }

        // Get Reply to settings
        $defaultSettings = get_option('mailerpress_default_settings', []);
        if (is_string($defaultSettings)) {
            $defaultSettings = json_decode($defaultSettings, true) ?: [];
        }

        $replyToName = !empty($defaultSettings['replyToName'])
            ? $defaultSettings['replyToName']
            : ($config['conf']['default_name'] ?? '');
        $replyToAddress = !empty($defaultSettings['replyToAddress'])
            ? $defaultSettings['replyToAddress']
            : ($config['conf']['default_email'] ?? '');

        // Send email
        $result = $mailer->sendEmail([
            'to' => $contactEntity->email,
            'html' => true,
            'body' => $body,
            'subject' => $subject,
            'sender_name' => $config['conf']['default_name'],
            'sender_to' => $config['conf']['default_email'],
            'reply_to_name' => $replyToName,
            'reply_to_address' => $replyToAddress,
            'apiKey' => $config['conf']['api_key'] ?? '',
        ]);

        if (is_wp_error($result)) {
            return new \WP_Error(
                'send_email_failed',
                __('Failed to send confirmation reminder email.', 'mailerpress'),
                ['status' => 500]
            );
        }

        if ($result === false) {
            return new \WP_Error(
                'send_email_failed',
                __('Failed to send confirmation reminder email.', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Confirmation reminder email sent successfully.', 'mailerpress'),
        ], 200);
    }

    /**
     * Manually confirm a pending contact
     */
    #[Endpoint(
        'contact/(?P<id>\d+)/confirm',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canManageAudience'],
    )]
    public function confirmContact(\WP_REST_Request $request): \WP_Error|\WP_HTTP_Response|\WP_REST_Response
    {
        $contactId = (int) $request->get_param('id');
        $contactsModel = Kernel::getContainer()->get(\MailerPress\Models\Contacts::class);
        $contactEntity = $contactsModel->get($contactId);

        if (!$contactEntity) {
            return new \WP_Error(
                'contact_not_found',
                __('Contact not found.', 'mailerpress'),
                ['status' => 404]
            );
        }

        if ('pending' !== $contactEntity->subscription_status) {
            return new \WP_Error(
                'invalid_status',
                __('This contact is not in pending status.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Use the subscribe method to confirm the contact
        $result = $contactsModel->subscribe((string) $contactId);

        if (!$result) {
            return new \WP_Error(
                'confirm_failed',
                __('Failed to confirm contact.', 'mailerpress'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Contact confirmed successfully.', 'mailerpress'),
        ], 200);
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private function getClientIp(): string
    {
        // Use REMOTE_ADDR as the reliable, non-spoofable source
        // Forwarded headers (X-Forwarded-For, etc.) can be manipulated by clients
        // Only trust them if a trusted proxy list is configured via filter
        $trustedProxies = apply_filters('mailerpress_trusted_proxies', []);
        $remoteAddr = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');

        if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
            // Request comes from a trusted proxy, use forwarded header
            $forwarded = '';
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $forwarded = $_SERVER['HTTP_X_REAL_IP'];
            }

            if (!empty($forwarded)) {
                // Take the first (client) IP from the chain
                if (strpos($forwarded, ',') !== false) {
                    $ips = explode(',', $forwarded);
                    return sanitize_text_field(trim($ips[0]));
                }
                return sanitize_text_field(trim($forwarded));
            }
        }

        return $remoteAddr;
    }
}
