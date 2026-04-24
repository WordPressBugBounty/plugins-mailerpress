<?php

declare(strict_types=1);

namespace MailerPress\Core\EmailManager;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

/**
 * Service for logging all email sending attempts (success and errors)
 */
class EmailLogger
{
    /**
     * Log an email sending attempt
     *
     * @param array $data Email data
     * @param string $status 'success', 'error', or 'pending'
     * @param mixed $result Result from wp_mail() or service
     * @return int|false Log ID or false on failure
     */
    public function log(array $data, string $status = 'pending', $result = null): int|false
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMAIL_LOGS);

        // Extract email data
        $toEmail = is_array($data['to'] ?? null) ? (reset($data['to']) ?? '') : ($data['to'] ?? '');
        $subject = $data['subject'] ?? '';
        $fromEmail = $data['sender_to'] ?? $data['from'] ?? '';
        $fromName = $data['sender_name'] ?? '';
        $replyTo = $data['reply_to_address'] ?? $data['reply_to'] ?? '';
        $service = $data['service'] ?? 'php';
        $isHtml = $data['html'] ?? true;

        // Extract related IDs
        $campaignId = $data['campaign_id'] ?? null;
        $contactId = $data['contact_id'] ?? null;
        $batchId = $data['batch_id'] ?? null;
        $jobId = $data['job_id'] ?? null;

        // Process result
        $wpMailResult = null;
        $errorMessage = null;
        $wpError = null;

        // Check if a custom error message was provided
        $customErrorMessage = $data['error_message'] ?? null;

        if ($result instanceof \WP_Error) {
            $status = 'error';
            $wpMailResult = false;
            // Use custom error message if provided, otherwise use WP_Error message
            $errorMessage = $customErrorMessage ?? $result->get_error_message();
            $wpError = json_encode([
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'data' => $result->get_error_data(),
            ]);
        } elseif ($result === false) {
            $status = 'error';
            $wpMailResult = false;
            // Use custom error message if provided, otherwise use default
            $errorMessage = $customErrorMessage ?? __('Email sending returned false', 'mailerpress');
        } elseif ($result === true) {
            $status = 'success';
            $wpMailResult = true;
        }

        // Get headers as JSON
        $headers = $data['headers'] ?? [];
        $headersJson = !empty($headers) ? json_encode($headers) : null;

        // Get body preview (first 500 chars)
        $body = $data['body'] ?? '';
        $bodyPreview = !empty($body) ? mb_substr(strip_tags($body), 0, 500) : null;

        // Insert log
        $inserted = $wpdb->insert(
            $table,
            [
                'to_email' => $toEmail,
                'subject' => $subject,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'reply_to' => $replyTo,
                'service' => $service,
                'status' => $status,
                'error_message' => $errorMessage,
                'campaign_id' => $campaignId,
                'contact_id' => $contactId,
                'batch_id' => $batchId,
                'job_id' => $jobId,
                'headers' => $headersJson,
                'body_preview' => $bodyPreview,
                'is_html' => $isHtml ? 1 : 0,
                'wp_mail_result' => $wpMailResult,
                'wp_error' => $wpError,
                'sent_at' => $status === 'success' ? \current_time('mysql') : null,
            ],
            [
                '%s', // to_email
                '%s', // subject
                '%s', // from_email
                '%s', // from_name
                '%s', // reply_to
                '%s', // service
                '%s', // status
                '%s', // error_message
                '%d', // campaign_id
                '%d', // contact_id
                '%d', // batch_id
                '%d', // job_id
                '%s', // headers
                '%s', // body_preview
                '%d', // is_html
                '%d', // wp_mail_result
                '%s', // wp_error
                '%s', // sent_at
            ]
        );

        if ($inserted === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Get email logs with filters
     *
     * @param array $args Query arguments
     * @return array
     */
    public function getLogs(array $args = []): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMAIL_LOGS);

        $defaults = [
            'status' => null,
            'service' => null,
            'campaign_id' => null,
            'contact_id' => null,
            'to_email' => null,
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = \wp_parse_args($args, $defaults);

        $where = [];
        $whereValues = [];

        if ($args['status']) {
            $where[] = 'status = %s';
            $whereValues[] = $args['status'];
        }

        if ($args['service']) {
            $where[] = 'service = %s';
            $whereValues[] = $args['service'];
        }

        if ($args['campaign_id']) {
            $where[] = 'campaign_id = %d';
            $whereValues[] = (int) $args['campaign_id'];
        }

        if ($args['contact_id']) {
            $where[] = 'contact_id = %d';
            $whereValues[] = (int) $args['contact_id'];
        }

        if ($args['to_email']) {
            $where[] = 'to_email = %s';
            $whereValues[] = $args['to_email'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $orderby = \sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'created_at DESC';
        }

        $limit = (int) $args['limit'];
        $offset = (int) $args['offset'];

        $query = "SELECT * FROM {$table} {$whereClause} ORDER BY {$orderby} LIMIT %d OFFSET %d";

        if (!empty($whereValues)) {
            $query = $wpdb->prepare($query, array_merge($whereValues, [$limit, $offset]));
        } else {
            $query = $wpdb->prepare($query, $limit, $offset);
        }

        return $wpdb->get_results($query, ARRAY_A) ?: [];
    }

    /**
     * Get log count
     *
     * @param array $args Query arguments
     * @return int
     */
    public function getLogCount(array $args = []): int
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_EMAIL_LOGS);

        $where = [];
        $whereValues = [];

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $whereValues[] = $args['status'];
        }

        if (!empty($args['service'])) {
            $where[] = 'service = %s';
            $whereValues[] = $args['service'];
        }

        if (!empty($args['campaign_id'])) {
            $where[] = 'campaign_id = %d';
            $whereValues[] = $args['campaign_id'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT COUNT(*) FROM {$table} {$whereClause}";

        if (!empty($whereValues)) {
            $query = $wpdb->prepare($query, $whereValues);
        }

        return (int) $wpdb->get_var($query);
    }
}
