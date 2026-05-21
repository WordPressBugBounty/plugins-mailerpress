<?php

namespace MailerPress\Actions\Frontend;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;

class Frontend
{
    #[Action('wp_enqueue_scripts')]
    public function enqueue()
    {
        if (is_singular() && in_the_loop()) {
            return; // Avoid enqueueing too early
        }

        if (is_singular()) {
            global $post;

            if (has_shortcode($post->post_content, 'mailerpress_pages')) {
                wp_enqueue_style(
                    'mailerpress-shortcode-css',
                    Kernel::$config['rootUrl'] . '/build/public/shortcode.css',
                    [],
                    '1.0'
                );
            }
        }
    }

    #[Action('init', priority: 1)]
    public function handleOpenTracking()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $request_path = strtok($request_uri, '?#') ?: '';

        if (!preg_match('|/?mp/o/([^/?#]+)|', $request_path, $matches)) {
            return;
        }

        $rawToken = rawurldecode($matches[1]);
        $token = sanitize_text_field($rawToken);

        $this->sendTrackingGif();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            if (ob_get_level()) {
                ob_end_flush();
            }
            flush();
        }

        if (!empty($token)) {
            $this->processOpenTracking($token);
        }

        exit;
    }

    private function sendTrackingGif(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        // 1x1 transparent GIF — 43 bytes, industry standard (Brevo, Mailchimp, SendGrid)
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($gif));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('X-Content-Type-Options: nosniff');

        echo $gif;
    }

    private function processOpenTracking(string $token): void
    {
        global $wpdb;

        $data = \MailerPress\Core\HtmlParser::decodeTrackingToken($token);

        if (!$data || !isset($data['cid']) || empty($data['cmp'])) {
            return;
        }

        $contact_id = (int) ($data['cid'] ?? 0);
        $campaign_id = (int) ($data['cmp'] ?? 0);
        $batch_id = isset($data['batch']) ? (int) $data['batch'] : null;
        $job_id = isset($data['job']) ? (int) $data['job'] : null;
        $step_id = isset($data['step']) ? (string) $data['step'] : null;
        $anonymous_key = isset($data['ank']) ? sanitize_text_field($data['ank']) : null;

        if ($batch_id !== null && $batch_id <= 0) {
            $batch_id = null;
        }
        if ($campaign_id <= 0) {
            return;
        }

        $isAnonymousTracking = ($contact_id === 0);

        if ($contact_id <= 0 && !empty($job_id) && empty($batch_id)) {
            $job = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->prefix}mailerpress_automations_jobs WHERE id = %d",
                    (int) $job_id
                )
            );
            if ($job && !empty($job->user_id)) {
                $contact_id = (int) $job->user_id;
                $isAnonymousTracking = false;
            }
        }

        if ($contact_id <= 0 && empty($batch_id)) {
            return;
        }

        $isTransactional = empty($batch_id);
        $contactStatsTable = $wpdb->prefix . 'mailerpress_contact_stats';
        $openedAt = current_time('mysql');

        if ($isTransactional) {
            if (empty($campaign_id)) {
                return;
            }

            $userId = null;
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

            if (!empty($userId) && $contact_id <= 0) {
                $contact_id = $userId;
            }

            $this->upsertContactStats($contactStatsTable, $contact_id, $campaign_id, $openedAt);

            if ($campaign_id && $userId) {
                \MailerPress\Actions\Workflows\MailerPress\Actions\ABTestStepHandler::updateParticipantOpen($campaign_id, $userId);
            }

            if ($userId) {
                $workflowSystem = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
                $executor = $workflowSystem->getManager()->getExecutor();
                $executor->reevaluateWaitingJobs($userId, $campaign_id, 'mp_email_opened');
            }
        } else {
            if (empty($campaign_id)) {
                $campaign_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT campaign_id FROM {$wpdb->prefix}mailerpress_email_batches WHERE id = %d",
                        (int) $batch_id
                    )
                );
            }

            if (empty($campaign_id)) {
                return;
            }

            $table = Tables::get(Tables::MAILERPRESS_EMAIL_TRACKING);

            if ($isAnonymousTracking) {
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
                    $wpdb->insert($table, [
                        'batch_id' => $batch_id,
                        'contact_id' => 0,
                        'anonymous_key' => $anonymous_key,
                        'opened_at' => $openedAt,
                        'clicks' => 0,
                        'unsubscribed_at' => null,
                    ], ['%d', '%d', '%s', '%s', '%d', '%s']);
                }
            } else {
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
                        ['opened_at' => $openedAt],
                        ['batch_id' => $batch_id, 'contact_id' => $contact_id],
                        ['%s'],
                        ['%d', '%d']
                    );
                } else {
                    $wpdb->insert($table, [
                        'batch_id' => $batch_id,
                        'contact_id' => $contact_id,
                        'opened_at' => $openedAt,
                        'clicks' => 0,
                        'unsubscribed_at' => null,
                    ], ['%d', '%d', '%s', '%d', '%s']);
                }
            }

            if (!$isAnonymousTracking && $contact_id > 0) {
                $contactStats = $this->upsertContactStats($contactStatsTable, $contact_id, $campaign_id, $openedAt);

                $contact = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT email FROM {$wpdb->prefix}mailerpress_contact WHERE contact_id = %d",
                        $contact_id
                    )
                );

                $userId = null;
                if ($contact && !empty($contact->email)) {
                    $user = \get_user_by('email', $contact->email);
                    if ($user) {
                        $userId = (int) $user->ID;
                    }
                }

                $abTestUserId = $userId ?: $contact_id;
                if ($campaign_id && $abTestUserId) {
                    \MailerPress\Actions\Workflows\MailerPress\Actions\ABTestStepHandler::updateParticipantOpen($campaign_id, $abTestUserId);
                }

                if ($userId) {
                    $workflowSystem = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
                    $executor = $workflowSystem->getManager()->getExecutor();
                    $executor->reevaluateWaitingJobs($userId, $campaign_id, 'mp_email_opened');
                }
            }
        }

        if (!$isAnonymousTracking && $contact_id > 0) {
            do_action('mailerpress_email_opened', $contact_id, $campaign_id, $batch_id);
        }
    }

    private function upsertContactStats(string $table, int $contactId, int $campaignId, string $openedAt): ?object
    {
        global $wpdb;

        $contactStats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT opened, clicked, click_count FROM {$table} WHERE contact_id = %d AND campaign_id = %d",
                $contactId,
                $campaignId
            )
        );

        if ($contactStats) {
            $newOpened = (int) $contactStats->opened + 1;
            $wpdb->update(
                $table,
                ['opened' => $newOpened, 'updated_at' => $openedAt],
                ['contact_id' => $contactId, 'campaign_id' => $campaignId],
                ['%d', '%s'],
                ['%d', '%d']
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'contact_id' => $contactId,
                    'campaign_id' => $campaignId,
                    'opened' => 1,
                    'clicked' => 0,
                    'click_count' => 0,
                    'status' => 'neutral',
                    'created_at' => $openedAt,
                    'updated_at' => $openedAt,
                ],
                ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
            );
        }

        return $contactStats;
    }

    #[Action('template_redirect')]
    public function handleClickTracking()
    {
        // Support both old format (mp_utm query param) and new format (tracking-link/{token})
        $token = null;

        // Check for new format: /tracking-link/{token}
        // Try get_query_var first (for rewrite rules)
        $trackingToken = get_query_var('mailerpress_tracking_token');

        if (!empty($trackingToken)) {
            $token = sanitize_text_field($trackingToken);
        }

        // If query var didn't work, try parsing the URL directly from REQUEST_URI
        if (empty($token)) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            // Remove query string and fragment for matching
            $request_path = strtok($request_uri, '?#');
            // Match /tracking-link/{token} pattern
            // Handle both with and without leading slash, and handle subdirectory installs
            // Use | as delimiter to avoid issues with # in the pattern
            if (preg_match('|/?tracking-link/([^/?#]+)|', $request_path, $matches)) {
                // Decode the token (it was rawurlencoded in the link)
                $rawToken = rawurldecode($matches[1]);
                $token = sanitize_text_field($rawToken);
            }
        }
        // Fallback to old format: ?mp_utm=token
        if (empty($token) && isset($_GET['mp_utm'])) {
            $token = sanitize_text_field($_GET['mp_utm']);
        }

        if (empty($token)) {
            return;
        }

        $data = $this->decodeTrackingToken($token);


        if (!$data || empty($data['url'])) {
            // Log for debugging (remove in production if needed)
            wp_die('Invalid tracking link', 'MailerPress', ['response' => 400]);
        }

        $contactId = (int)($data['cid'] ?? 0);
        $campaignId = (int)($data['cmp'] ?? 0);
        $originalUrl = esc_url_raw($data['url']);
        $anonymousKey = isset($data['ank']) ? sanitize_text_field($data['ank']) : null;

        // Validate that we have required IDs
        if ($campaignId <= 0) {
            wp_die('Invalid tracking link', 'MailerPress', ['response' => 400]);
        }

        // Check if this is anonymous tracking (contact_id = 0)
        $isAnonymousTracking = ($contactId === 0);

        // Check if this is an automation email (workflow) by looking for jobId and stepId in token
        $jobId = isset($data['job']) ? (int) $data['job'] : null;
        $stepId = isset($data['step']) ? (string) $data['step'] : null;
        $isAutomationEmail = ($jobId !== null && $jobId > 0);

        global $wpdb;

        $clickTable = Tables::get(Tables::MAILERPRESS_CLICK_TRACKING);
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT_STATS);
        $campaignTable = Tables::get(Tables::MAILERPRESS_CAMPAIGN_STATS);

        $now = current_time('mysql', 1);

        // 1️⃣ Insert click record
        // Sanitize IP address and user agent to prevent injection
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null;
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : null;

        // For anonymous tracking, check if click already exists for this anonymous_key and campaign
        $shouldInsert = true;
        if ($isAnonymousTracking && !empty($anonymousKey)) {
            $existingClick = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$clickTable} WHERE campaign_id = %d AND anonymous_key = %s AND url = %s LIMIT 1",
                    $campaignId,
                    $anonymousKey,
                    $originalUrl
                )
            );
            if ($existingClick) {
                $shouldInsert = false; // Click already tracked for this anonymous user
            }
        }

        if ($shouldInsert) {
            $insertData = [
                'contact_id' => $contactId,
                'campaign_id' => $campaignId,
                'url' => $originalUrl,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'created_at' => $now,
            ];
            $insertFormat = ['%d', '%d', '%s', '%s', '%s', '%s'];

            // Add anonymous_key for anonymous tracking
            if ($isAnonymousTracking && !empty($anonymousKey)) {
                $insertData['anonymous_key'] = $anonymousKey;
                $insertFormat[] = '%s';
            }

            $insertResult = $wpdb->insert($clickTable, $insertData, $insertFormat);
        }

        // 2️⃣ Update contact stats (skip for anonymous tracking)
        if (!$isAnonymousTracking && $contactId > 0) {
            $contactStats = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT click_count FROM {$contactTable} WHERE contact_id = %d AND campaign_id = %d",
                    $contactId,
                    $campaignId
                )
            );

            if ($contactStats) {
                $updateResult = $wpdb->update(
                    $contactTable,
                    [
                        'clicked' => 1,
                        'click_count' => $contactStats->click_count + 1,
                        'last_click_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['contact_id' => $contactId, 'campaign_id' => $campaignId],
                    ['%d', '%d', '%s', '%s'],
                    ['%d', '%d']
                );
            } else {
                $insertResult = $wpdb->insert(
                    $contactTable,
                    [
                        'contact_id' => $contactId,
                        'campaign_id' => $campaignId,
                        'opened' => 0,
                        'clicked' => 1,
                        'click_count' => 1,
                        'last_click_at' => $now,
                        'status' => 'good',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
                );
            }
        }

        // 3️⃣ Update campaign stats
        $campaignStats = $wpdb->get_row(
            $wpdb->prepare("SELECT total_click FROM {$campaignTable} WHERE campaign_id = %d", $campaignId)
        );

        if ($campaignStats) {
            $wpdb->update(
                $campaignTable,
                [
                    'total_click' => $campaignStats->total_click + 1,
                    'updated_at' => $now,
                ],
                ['campaign_id' => $campaignId],
                ['%d', '%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $campaignTable,
                [
                    'campaign_id' => $campaignId,
                    'total_click' => 1,
                    'total_sent' => 0,
                    'total_open' => 0,
                    'total_unsubscribe' => 0,
                    'total_bounce' => 0,
                    'total_revenue' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s']
            );
        }

        // 4️⃣ If WooCommerce product → set cookie for checkout tracking
        if (!empty($_GET['mp_track_product']) && !empty($_GET['mp_product_id'])) {
            $product_id = (int)$_GET['mp_product_id'];

            if (function_exists('WC') && WC()->session) {
                WC()->session->set('mailerpress_product_click', [
                    'campaign_id' => $campaignId,
                    'product_id' => $product_id,
                    'timestamp' => time(),
                ]);
            }
        }

        // 4️⃣ Update A/B Test participant if this is an A/B test email (skip for anonymous tracking)
        if ($campaignId && $contactId > 0 && !$isAnonymousTracking) {
            // Find the correct user_id for this contact
            // First, try to find WordPress user by email
            $userId = null;
            $contact = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT email FROM {$wpdb->prefix}mailerpress_contact WHERE contact_id = %d",
                    $contactId
                )
            );

            if ($contact && !empty($contact->email)) {
                $user = get_user_by('email', $contact->email);
                if ($user) {
                    $userId = $user->ID;
                }
            }

            // If no user found, use contact_id as user_id (for non-subscribers)
            if (!$userId) {
                $userId = $contactId;
            }
            \MailerPress\Actions\Workflows\MailerPress\Actions\ABTestStepHandler::updateParticipantClick($campaignId, $userId);
        }

        // 5️⃣ Re-evaluate waiting workflows for this contact and campaign (skip for anonymous tracking)
        if ($campaignId && $contactId > 0 && !$isAnonymousTracking) {
            $userId = null;

            // For automation emails, get user_id directly from the job
            if ($isAutomationEmail && $jobId) {
                $job = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->prefix}mailerpress_automations_jobs WHERE id = %d",
                        (int) $jobId
                    )
                );

                if ($job && !empty($job->user_id)) {
                    $userId = (int) $job->user_id;
                }
            }

            // For newsletter emails or if job lookup failed, find user by contact email
            if (!$userId) {
                $contact = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT email FROM {$wpdb->prefix}mailerpress_contact WHERE contact_id = %d",
                        $contactId
                    )
                );

                if ($contact && !empty($contact->email)) {
                    $user = get_user_by('email', $contact->email);
                    if ($user) {
                        $userId = $user->ID;
                    }
                }

                // If no user found, use contact_id as user_id (for non-subscribers)
                if (!$userId) {
                    $userId = $contactId;
                }
            }

            if ($userId) {
                // Trigger workflow re-evaluation
                // For automation emails, this will re-evaluate the specific job
                // For newsletter emails, this will re-evaluate any waiting workflows
                $workflowSystem = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
                $executor = $workflowSystem->getManager()->getExecutor();
                $executor->reevaluateWaitingJobs($userId, $campaignId, 'mp_email_clicked');
            }
        }


        // 6️⃣ Fire webhook for email clicked (non-anonymous only)
        if (!$isAnonymousTracking && $contactId > 0) {
            do_action('mailerpress_email_clicked', $contactId, $campaignId, $originalUrl);
        }

        // 7️⃣ Redirect to original URL (token is HMAC-signed, esc_url for defense-in-depth)
        wp_redirect(esc_url_raw($originalUrl));
        exit;
    }


    private function decodeTrackingToken(string $token): ?array
    {
        $remainder = strlen($token) % 4;
        if ($remainder) {
            $token .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'));
        if (!$decoded) {
            return null;
        }

        [$payloadJson, $signature] = explode('::', $decoded, 2) + [null, null];
        if (!$payloadJson || !$signature) {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $payloadJson, wp_salt('auth'));
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return json_decode($payloadJson, true);
    }
}
