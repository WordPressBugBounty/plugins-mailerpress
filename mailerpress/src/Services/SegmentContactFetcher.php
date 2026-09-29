<?php

namespace MailerPress\Services;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Interfaces\ContactFetcherInterface;

class SegmentContactFetcher implements ContactFetcherInterface, \Countable
{
    private string $segmentName;

    /**
     * @param string $segmentName The human-readable segment "name" used to look up conditions.
     */
    public function __construct(string $segmentName)
    {
        $this->segmentName = $segmentName;
    }

    /**
     * Fetch contact IDs matching the segment conditions.
     *
     * Uses the Pro plugin's SegmentQueryBuilder directly (no REST call)
     * so it works in cron/ActionScheduler context without authentication.
     *
     * Falls back to REST internal dispatch if Pro classes are not available.
     *
     * @param int $limit Max number of contacts requested.
     * @param int $offset Starting offset (0-based).
     * @return array Array of contact IDs (int).
     */
    public function fetch(int $limit, int $offset): array
    {
        if ($limit <= 0) {
            return [];
        }

        // Try direct DB query first (works in cron context)
        $ids = $this->fetchDirect($limit, $offset);
        if ($ids !== null) {
            return $ids;
        }

        // Fallback: REST internal dispatch (only works with authenticated user)
        return $this->fetchViaRest($limit, $offset);
    }

    public function count(): int
    {
        global $wpdb;

        $query = $this->directQuery();
        if ($query !== null) {
            return $query === '' ? 0 : (int) $wpdb->get_var("SELECT COUNT(*) FROM ({$query}) audience");
        }

        $total = 0;
        do {
            $ids = $this->fetchViaRest(1000, $total);
            $total += count($ids);
        } while (count($ids) === 1000);

        return $total;
    }

    private function fetchDirect(int $limit, int $offset): ?array
    {
        global $wpdb;

        $query = $this->directQuery();
        if ($query === null) {
            return null;
        }
        if ($query === '') {
            return [];
        }

        $query .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
        return array_map('intval', $wpdb->get_col($query) ?: []);
    }

    /**
     * Direct DB query using Pro plugin's segmentation classes.
     * Returns null if Pro plugin classes are not available.
     */
    private function directQuery(): ?string
    {
        global $wpdb;

        // Check that Pro segmentation classes exist
        if (
            !class_exists('MailerPressPro\\Core\\Segmentation\\Segment')
            || !class_exists('MailerPressPro\\Core\\Segmentation\\ConditionFactory')
            || !class_exists('MailerPressPro\\Core\\Segmentation\\SegmentQueryBuilder')
        ) {
            return null;
        }

        $segmentTable = Tables::get(Tables::MAILERPRESS_SEGMENTS);

        // Fetch segment row by name
        $segment_row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$segmentTable} WHERE name = %s", $this->segmentName),
            ARRAY_A
        );

        if (!$segment_row || !isset($segment_row['conditions'])) {
            Logger::error('SegmentContactFetcher: Segment not found', [
                'segment_name' => $this->segmentName,
            ]);
            return '';
        }

        // Parse conditions (may be serialized or JSON)
        $raw_conditions = is_serialized($segment_row['conditions'])
            ? unserialize($segment_row['conditions'], ['allowed_classes' => false])
            : $segment_row['conditions'];
        $segment_conditions = is_string($raw_conditions) ? json_decode($raw_conditions, true) : $raw_conditions;

        if (!is_array($segment_conditions)) {
            Logger::error('SegmentContactFetcher: Invalid segment conditions', [
                'segment_name' => $this->segmentName,
            ]);
            return '';
        }

        // Build Segment object using Pro classes
        if (method_exists('MailerPressPro\\Core\\Segmentation\\Segment', 'fromArray')) {
            $segment = \MailerPressPro\Core\Segmentation\Segment::fromArray($segment_conditions);
        } else {
            if (!isset($segment_conditions['conditions'])) {
                Logger::error('SegmentContactFetcher: Invalid legacy segment conditions', [
                    'segment_name' => $this->segmentName,
                ]);
                return '';
            }

            $segment = new \MailerPressPro\Core\Segmentation\Segment($segment_conditions['operator'] ?? 'AND');
            foreach ($segment_conditions['conditions'] as $cond) {
                try {
                    $conditionObj = \MailerPressPro\Core\Segmentation\ConditionFactory::create($cond);
                    $segment->addCondition($conditionObj);
                } catch (\InvalidArgumentException $e) {
                    Logger::error('SegmentContactFetcher: Invalid condition', [
                        'segment_name' => $this->segmentName,
                        'condition' => $cond,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $hasConditions = method_exists($segment, 'hasConditions')
            ? $segment->hasConditions()
            : !empty($segment->getConditions());

        if (!$hasConditions) {
            Logger::error('SegmentContactFetcher: No valid conditions', [
                'segment_name' => $this->segmentName,
            ]);
            return '';
        }

        // Build and execute query
        $queryBuilder = new \MailerPressPro\Core\Segmentation\SegmentQueryBuilder($segment);
        $segmentSql = rtrim($queryBuilder->buildQuery(['fields' => 'c.contact_id']), " \t\n\r\0\x0B;");
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $sql = "SELECT segment_contacts.contact_id
                FROM ({$segmentSql}) segment_contacts
                INNER JOIN {$contactTable} c ON c.contact_id = segment_contacts.contact_id
                WHERE c.subscription_status = 'subscribed'";
        return $sql;
    }

    /**
     * Fallback: fetch via internal REST dispatch.
     * Only works when there is an authenticated user (not in cron context).
     */
    private function fetchViaRest(int $limit, int $offset): array
    {
        $page = (int) floor($offset / $limit) + 1;

        $request = new \WP_REST_Request('GET', '/mailerpress/v1/getContactSegment');
        $request->set_param('segmentName', $this->segmentName);
        $request->set_param('onlyIds', true);
        $request->set_param('page', $page);
        $request->set_param('per_page', $limit);
        $request->set_param('_internal_key', wp_hash('mailerpress-internal'));

        $response = rest_do_request($request);

        if ($response->is_error()) {
            Logger::error('SegmentContactFetcher: REST endpoint error', [
                'segment_name' => $this->segmentName,
                'status' => $response->get_status(),
                'error' => $response->as_error()->get_error_message(),
            ]);
            return [];
        }

        $status = $response->get_status();
        if ($status < 200 || $status >= 300) {
            return [];
        }

        $data = $response->get_data();
        if (empty($data)) {
            return [];
        }

        $ids = [];
        foreach ($data as $row) {
            if (is_object($row) && isset($row->contact_id)) {
                $ids[] = (int) $row->contact_id;
            } elseif (is_array($row) && isset($row['contact_id'])) {
                $ids[] = (int) $row['contact_id'];
            } elseif (is_scalar($row)) {
                $ids[] = (int) $row;
            }
        }

        $offsetWithinPage = $offset % $limit;
        if ($offsetWithinPage > 0 || count($ids) > $limit) {
            $ids = array_slice($ids, $offsetWithinPage, $limit);
        }

        return $this->filterSubscribedIds($ids);
    }

    private function filterSubscribedIds(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        $subscribedIds = $wpdb->get_col($wpdb->prepare(
            "SELECT contact_id
             FROM {$contactTable}
             WHERE subscription_status = 'subscribed'
               AND contact_id IN ({$placeholders})",
            ...$ids
        ));

        $subscribedSet = array_flip(array_map('intval', $subscribedIds ?: []));

        return array_values(array_filter($ids, static fn(int $id): bool => isset($subscribedSet[$id])));
    }
}
