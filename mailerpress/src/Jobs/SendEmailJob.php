<?php

declare(strict_types=1);

namespace MailerPress\Jobs;

\defined('ABSPATH') || exit;

use MailerPress\Core\Abstract\BaseJob;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\HtmlParser;
use MailerPress\Core\Kernel;
use Throwable;
use WP_Error;

class SendEmailJob extends BaseJob
{
    /**
     * @param array{
     *   to: array<int, array{email:string,variables:array<string,mixed>}>,
     *   subject: string,
     *   sender_to: string,
     *   sender_name: string,
     *   body: string,
     *   webhook_url?: string,
     *   batch_id?: int|string,
     *   campaign_id?: int|string,
     *   transient_key?: string
     * } $data
     */
    public function handle(array $data): void
    {
        $data = $this->refreshAutomatedCampaignSender($data);

        $countSuccess = 0;
        $countError = 0;
        $errors = []; // collect per-recipient error detail (optional)

        // We'll want to know if the whole job failed catastrophically
        $jobException = null;

        try {
            $recipientBatches = $data['to']; // you said already chunked to <= 50 items
        

            // Acquire shared services once outside loop.
            $container = Kernel::getContainer();
            /** @var HtmlParser $parser */
            $parser = $container->get(HtmlParser::class);
            /** @var EmailServiceManager $manager */
            $manager = $container->get(EmailServiceManager::class);
            $mailer = $manager->getActiveService();

            $apiKey = '';

            $servicesData = get_option('mailerpress_email_services', []);

            if (
                !empty($servicesData['default_service'])
                && !empty($servicesData['services'][$servicesData['default_service']]['conf']['api_key'])
            ) {
                $defaultService = $servicesData['default_service'];
                $apiKey = $servicesData['services'][$defaultService]['conf']['api_key'];
            }

            // Récupérer les paramètres Reply to depuis les paramètres par défaut
            $defaultSettings = get_option('mailerpress_default_settings', []);
            if (is_string($defaultSettings)) {
                $defaultSettings = json_decode($defaultSettings, true) ?: [];
            }

            // Déterminer les valeurs Reply to (utiliser From si Reply to est vide)
            $replyToName = !empty($defaultSettings['replyToName'])
                ? $defaultSettings['replyToName']
                : ($data['sender_name'] ?? '');
            $replyToAddress = !empty($defaultSettings['replyToAddress'])
                ? $defaultSettings['replyToAddress']
                : ($data['sender_to'] ?? '');

            // Get rate limit configuration (emails per second)
            // Priority: 1. Job data (passed from chunk), 2. Option (backwards compatibility)
            $rateLimit = 10; // Default fallback

            if (isset($data['rate_limit'])) {
                // New way: rate_limit passed from ContactEmailChunk
                $rateLimit = (int)$data['rate_limit'];
            } else {
                // Backwards compatibility: read from option
                $frequencySending = get_option('mailerpress_frequency_sending', [
                    "settings" => [
                        "numberEmail" => 25,
                        "config" => ["value" => 5, "unit" => "minutes"],
                        "rate_limit" => 10,
                    ],
                ]);

                if (is_string($frequencySending)) {
                    $frequencySending = json_decode($frequencySending, true) ?: [];
                }

                // Support both new and old formats
                if (isset($frequencySending['effectiveConfig']['rate_limit'])) {
                    $rateLimit = (int)$frequencySending['effectiveConfig']['rate_limit'];
                } else {
                    $rateLimit = (int)($frequencySending['settings']['rate_limit'] ?? 10);
                }
            }

            $delayBetweenEmails = $rateLimit > 0 ? (1000000 / $rateLimit) : 0; // Convert to microseconds (1 second = 1,000,000 microseconds)

            $preprocessedBody = HtmlParser::preprocessBody($data['body']);

            $statsToInsert = [];

            $emailIndex = 0;
            foreach ($recipientBatches as $recipient) {
                try {
                    // Ensure tracking variables are present
                    $variables = $recipient['variables'] ?? [];

                    // Render per‑recipient body
                    // IMPORTANT: HtmlParser->init() injects tracking pixel BEFORE replaceVariables()
                    // This ensures tracking is always present even with third-party SMTP plugins
                    $clickTracking = $data['clickTracking'] ?? 'yes';
                    $body = $parser->init(
                        $preprocessedBody,
                        $variables
                    )->replaceVariables($clickTracking);

                    $result = $mailer->sendEmail([
                        'to' => $recipient['email'],
                        'html' => true,
                        'body' => $body,
                        'subject' => $data['subject'],
                        'sender_name' => $data['sender_name'] ?? '',
                        'sender_to' => $data['sender_to'] ?? '',
                        'reply_to_name' => $replyToName,
                        'reply_to_address' => $replyToAddress,
                        'apiKey' => $apiKey,
                        'campaign_id' => $recipient['campaign_id'] ?? null,
                        'contact_id' => $recipient['id'] ?? null,
                        'batch_id' => $data['batch_id'] ?? null,
                    ]);

                    // Collect stats for batch insert after the loop
                    if (!empty($recipient['id']) && !empty($recipient['campaign_id'])) {
                        $statsToInsert[] = [
                            'contact_id' => (int) $recipient['id'],
                            'campaign_id' => (int) $recipient['campaign_id'],
                        ];
                    }

                    if ($result === true) {
                        ++$countSuccess;

                        // Note: La mise à jour de sent_emails se fait maintenant à la fin du traitement pour éviter les problèmes de concurrence
                    } else {
                        ++$countError;

                        $errorMessage = 'Mailer returned false.';
                        if ($result instanceof \WP_Error) {
                            $errorMessage = $result->get_error_message();
                        }



                        $errors[] = [
                            'email' => $recipient['email'],
                            'message' => $errorMessage,
                        ];
                        // Note: La mise à jour de error_emails se fait maintenant à la fin du traitement pour éviter les problèmes de concurrence

                        // --- Log to file per batch ---
                        $batchId = $data['batch_id'] ?? 'unknown';
                        $logDir = WP_CONTENT_DIR . '/mailerpress-logs';
                        $logFile = $logDir . "/batch-{$batchId}.log";

                        if (!is_dir($logDir)) {
                            wp_mkdir_p($logDir);
                        }

                        $logEntry = sprintf(
                            "[%s] Email: %s | Error: %s\n",
                            current_time('mysql'),
                            $recipient['email'],
                            $errorMessage
                        );

                        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
                    }

                    // Apply rate limiting: wait between emails to respect the rate limit
                    // Skip delay for the last email
                    if ($delayBetweenEmails > 0 && $emailIndex < count($recipientBatches) - 1) {
                        usleep((int)$delayBetweenEmails);
                    }

                    // Free cyclic references from DOM parsing every 5 emails
                    if ($emailIndex % 5 === 4) {
                        gc_collect_cycles();
                    }

                    // Log warning if memory usage exceeds 80% of limit
                    if ($emailIndex % 10 === 9) {
                        $memUsage = memory_get_usage(true);
                        $memLimit = $this->getMemoryLimitBytes();
                        if ($memLimit > 0 && $memUsage > $memLimit * 0.8) {
                            $this->log('Memory warning during batch send', [
                                'batch_id' => $data['batch_id'] ?? 'unknown',
                                'email_index' => $emailIndex,
                                'memory_usage_mb' => round($memUsage / 1048576, 1),
                                'memory_limit_mb' => round($memLimit / 1048576, 1),
                            ]);
                        }
                    }

                    $emailIndex++;
                } catch (Throwable $e) {
                    ++$countError;
                    $errors[] = [
                        'email' => $recipient['email'] ?? '',
                        'message' => $e->getMessage(),
                    ];

                    // --- Log to file per batch ---
                    $batchId = $data['batch_id'] ?? 'unknown';

                    $logDir = WP_CONTENT_DIR . '/mailerpress-logs';
                    $logFile = $logDir . "/batch-{$batchId}.log";

                    if (!is_dir($logDir)) {
                        wp_mkdir_p($logDir); // creates dir safely with proper perms
                    }

                    $logEntry = sprintf(
                        "[%s] Email: %s | Error: %s\n",
                        current_time('mysql'),
                        $recipient['email'] ?? 'N/A',
                        $e->getMessage()
                    );

                    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
                }
            }


            // Batch insert contact stats (replaces per-email SELECT+INSERT with single INSERT IGNORE)
            if (!empty($statsToInsert)) {
                global $wpdb;
                $contactStatsTable = Tables::get(Tables::MAILERPRESS_CONTACT_STATS);
                $now = current_time('mysql');

                $placeholders = [];
                $values = [];
                foreach ($statsToInsert as $stat) {
                    $placeholders[] = '(%d, %d, 0, 0, 0, NULL, 0, %s, %s, %s)';
                    $values[] = $stat['contact_id'];
                    $values[] = $stat['campaign_id'];
                    $values[] = 'neutral';
                    $values[] = $now;
                    $values[] = $now;
                }

                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$contactStatsTable}
                    (contact_id, campaign_id, opened, clicked, click_count, last_click_at, revenue, status, created_at, updated_at)
                    VALUES " . implode(', ', $placeholders),
                    ...$values
                ));
            }

            // Mettre à jour sent_emails et error_emails en une seule fois à la fin (plus efficace et évite les problèmes de concurrence)
            if (!empty($data['batch_id']) && ($countSuccess > 0 || $countError > 0)) {
                global $wpdb;
                $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
                $batch_id = (int) $data['batch_id'];
            

                // Mettre à jour sent_emails et error_emails de manière atomique (évite race conditions)
                if ($countSuccess > 0 || $countError > 0) {
                    $wpdb->query('START TRANSACTION');

                    $batch_locked = $wpdb->get_row($wpdb->prepare(
                        "SELECT sent_emails, error_emails FROM {$batchTable} WHERE id = %d FOR UPDATE",
                        $batch_id
                    ));

                    if ($batch_locked) {
                        $wpdb->update(
                            $batchTable,
                            [
                                'sent_emails' => (int)$batch_locked->sent_emails + $countSuccess,
                                'error_emails' => (int)$batch_locked->error_emails + $countError,
                                'updated_at' => current_time('mysql'),
                            ],
                            ['id' => $batch_id],
                            ['%d', '%d', '%s'],
                            ['%d']
                        );
                    }

                    $wpdb->query('COMMIT');
                }

                // Vérifier si tous les emails sont envoyés et mettre à jour le statut du batch à 'sent'
                $batch = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT total_emails, sent_emails, error_emails, status, campaign_id FROM {$batchTable} WHERE id = %d",
                        $batch_id
                    ),
                    ARRAY_A
                );

                if ($batch && $batch['status'] !== 'sent') {
                    $total_emails = (int) ($batch['total_emails'] ?? 0);
                    $sent_emails = (int) ($batch['sent_emails'] ?? 0);
                    $error_emails = (int) ($batch['error_emails'] ?? 0);
                    $campaign_id = (int) ($batch['campaign_id'] ?? 0);


                    // Si tous les emails sont envoyés (succès + erreurs = total)
                    if ($total_emails > 0 && ($sent_emails + $error_emails) >= $total_emails) {
                        
                        // Mettre à jour le statut du batch à 'sent'
                        $wpdb->update(
                            $batchTable,
                            ['status' => 'sent', 'updated_at' => current_time('mysql')],
                            ['id' => $batch_id],
                            ['%s', '%s'],
                            ['%d']
                        );

                        do_action('mailerpress_batch_event', 'sent', $campaign_id, $batch_id);
                    } else {
                        // Mettre à jour le statut à 'in_progress' dès le premier email envoyé
                        // Même si le batch est déjà en 'in_progress', on déclenche le hook pour s'assurer que la campagne est mise à jour
                        $shouldUpdateStatus = $batch['status'] !== 'in_progress';
                        
                        if ($shouldUpdateStatus) {
                            
                            $updated = $wpdb->update(
                                $batchTable,
                                ['status' => 'in_progress', 'updated_at' => current_time('mysql')],
                                ['id' => $batch_id],
                                ['%s', '%s'],
                                ['%d']
                            );


                        }

    
                        do_action('mailerpress_batch_event', 'in_progress', $campaign_id, $batch_id);
                        
                    }
                }
            }
        } catch (Throwable $e) {
            // This means something big failed (config, container, parsing, etc.)
            $countSuccess = 0;
            $countError = count($data['to']);
            $jobException = $e;

            // Store the error message in the batch for debugging
            if (!empty($data['batch_id'])) {
                global $wpdb;
                $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
                $wpdb->update(
                    $batchTable,
                    ['error_message' => $e->getMessage()],
                    ['id' => (int) $data['batch_id']],
                    ['%s'],
                    ['%d']
                );
            }
        } finally {
            if (
                \array_key_exists('webhook_url', $data)
                && \array_key_exists('batch_id', $data)
            ) {
                $this->sendNotification(
                    $data['webhook_url'],
                    [
                        'action' => 'batch_update',
                        'batch_id' => $data['batch_id'],
                        'countSent' => [
                            'success' => $countSuccess,
                            'error' => $countError,
                        ],
                        // optional: pass transient key for cleanup
                        'transient_key' => $data['transient_key'] ?? null,
                    ]
                );
            }
        }

        // If the job blew up, rethrow so the queue system can mark it failed / retry.
        if ($jobException) {
            throw $jobException; // Action Scheduler will log failure & retry per its rules
        }
    }

    private function refreshAutomatedCampaignSender(array $data): array
    {
        if (!function_exists('mailerpress_resolve_sender_config')) {
            return $data;
        }

        $campaignId = $this->resolveCampaignId($data);

        if ($campaignId <= 0 || !$this->isAutomatedCampaign($campaignId)) {
            return $data;
        }

        $senderConfig = [
            'fromName' => $data['sender_name'] ?? '',
            'fromTo' => $data['sender_to'] ?? '',
        ];

        if (!empty($data['senderId'])) {
            $senderConfig['senderId'] = $data['senderId'];
        }

        $senderConfig = mailerpress_resolve_sender_config($senderConfig, true);

        $data['sender_name'] = $senderConfig['fromName'] ?? ($data['sender_name'] ?? '');
        $data['sender_to'] = $senderConfig['fromTo'] ?? ($data['sender_to'] ?? '');

        if (!empty($senderConfig['senderId'])) {
            $data['senderId'] = $senderConfig['senderId'];
        }

        return $data;
    }

    private function resolveCampaignId(array $data): int
    {
        $campaignId = (int) ($data['campaign_id'] ?? $data['campaignId'] ?? 0);

        if ($campaignId > 0) {
            return $campaignId;
        }

        foreach (($data['to'] ?? []) as $recipient) {
            $campaignId = (int) ($recipient['campaign_id'] ?? 0);

            if ($campaignId > 0) {
                return $campaignId;
            }
        }

        if (empty($data['batch_id'])) {
            return 0;
        }

        global $wpdb;

        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

        if (!Tables::exists($batchTable)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT campaign_id FROM {$batchTable} WHERE id = %d",
            (int) $data['batch_id']
        ));
    }

    private function isAutomatedCampaign(int $campaignId): bool
    {
        global $wpdb;

        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        if (!Tables::exists($campaignsTable)) {
            return false;
        }

        return 'automated' === $wpdb->get_var($wpdb->prepare(
            "SELECT campaign_type FROM {$campaignsTable} WHERE campaign_id = %d",
            $campaignId
        ));
    }

    /**
     * Log message to file for debugging
     */
    private function log(string $message, array $context = []): void
    {
        $logDir = WP_CONTENT_DIR . '/mailerpress-logs';
        if (!is_dir($logDir)) {
            wp_mkdir_p($logDir);
        }

        $logFile = $logDir . '/queue-debug.log';
        $timestamp = current_time('mysql');
        $contextStr = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
        $logEntry = sprintf("[%s] %s%s\n", $timestamp, $message, $contextStr);
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    private function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ( '-1' === $limit ) {
            return 0;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Post a JSON webhook and optionally clear transient.
     */
    private function sendNotification(string $url, array $payload): void
    {
        if (!empty($payload['transient_key'])) {
            delete_transient($payload['transient_key']);
        }

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 15, // be explicit; default 5s may be too low at scale
        ];

        wp_remote_post($url, $args);
    }
}
