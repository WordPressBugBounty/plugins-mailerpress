<?php

namespace MailerPress\Actions\ActionScheduler\Processors;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\DynamicPostRenderer;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\HtmlParser;
use MailerPress\Core\Interfaces\ContactFetcherInterface;
use MailerPress\Core\Kernel;
use MailerPress\Models\Contacts;
use MailerPress\Services\ClassicContactFetcher;
use MailerPress\Services\SegmentContactFetcher;

class MailerPressEmailBatch
{
    /**
     * @param $sendType
     * @param $post
     * @param $config
     * @param $scheduledAt
     * @param $recipientTargeting
     * @param $lists
     * @param $tags
     * @param $segment
     * @param $openTracking
     * @param $clickTracking
     * @return void|\WP_REST_Response
     */
    #[Action('mailerpress_batch_email', priority: 10, acceptedArgs: 10)]
    public function process(
        $sendType = null,
        $post = null,
        $config = null,
        $scheduledAt = null,
        $recipientTargeting = null,
        $lists = null,
        $tags = null,
        $segment = null,
        $openTracking = 'yes',
        $clickTracking = 'yes',
    ) {
        global $wpdb;

        try {
            // Handle case where ActionScheduler passes arguments as an array (first argument)
            // This happens when ActionScheduler calls do_action with array instead of spread
            if (is_array($sendType) && $post === null) {
                $args = $sendType;
                $sendType = $args[0] ?? null;
                $post = $args[1] ?? null;
                $config = $args[2] ?? null;
                $scheduledAt = $args[3] ?? null;
                $recipientTargeting = $args[4] ?? null;
                $lists = $args[5] ?? [];
                $tags = $args[6] ?? [];
                $segment = $args[7] ?? [];
                $openTracking = $args[8] ?? 'yes';
                $clickTracking = $args[9] ?? 'yes';
            }

            // Ensure arrays are arrays
            $lists = is_array($lists) ? $lists : [];
            $tags = is_array($tags) ? $tags : [];
            $segment = is_array($segment) ? $segment : [];

            // Validate essential parameters
            if (empty($post) || empty($config)) {
                return;
            }

            // Select fetcher based on targeting type
            // Default to 'classic' if recipientTargeting is null or empty
            $recipientTargeting = $recipientTargeting ?? 'classic';
            $fetcher = $this->getFetcher($recipientTargeting, $lists, $tags, $segment);

            if (!$fetcher) {
                // Log error if fetcher is still null (should not happen with fallback)
                return;
            }

            // Frequency settings
            $frequencySending = get_option('mailerpress_frequency_sending', [
                "settings" => [
                    "numberEmail" => 25,
                    "config" => ["value" => 5, "unit" => "minutes"],
                ],
            ]);

            if (is_string($frequencySending)) {
                $decoded = json_decode($frequencySending, true);
                $frequencySending = is_array($decoded) ? $decoded : [
                    "settings" => [
                        "numberEmail" => 25,
                        "config" => ["value" => 5, "unit" => "minutes"],
                    ],
                ];
            }

            // Support both new and old config formats for backwards compatibility
            if (isset($frequencySending['effectiveConfig'])) {
                // New format (from refactored SendingFrequency component)
                $effectiveConfig = $frequencySending['effectiveConfig'];
                $numberEmail = $effectiveConfig['numberEmail'] ?? 25;
                $frequencyConfig = $effectiveConfig['frequency'] ?? ['value' => 5, 'unit' => 'minutes'];
                $rateLimit = $effectiveConfig['rate_limit'] ?? 10;
            } else {
                // Old format (backwards compatibility)
                $numberEmail = $frequencySending['settings']['numberEmail'] ?? 25;
                $frequencyConfig = $frequencySending['settings']['config'] ?? ['value' => 5, 'unit' => 'minutes'];
                $rateLimit = $frequencySending['settings']['rate_limit'] ?? 10;
            }

            $unit_multipliers = ['seconds' => 1, 'minutes' => MINUTE_IN_SECONDS, 'hours' => HOUR_IN_SECONDS];
            $interval_seconds = ($frequencyConfig['value'] ?? 5) * ($unit_multipliers[$frequencyConfig['unit']] ?? MINUTE_IN_SECONDS);

            $status = ('future' === $sendType) ? 'scheduled' : 'pending';

            // Get subject from config or fallback to campaign title
            $subject = $config['subject'] ?? '';
            if (empty($subject) && !empty($post)) {
                $campaign = get_post($post);
                $subject = $campaign ? $campaign->post_title : '';
            }

            // Check if a batch already exists for this campaign (created in createBatchV2)
            $existing_batch = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM " . Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES) . "
                 WHERE campaign_id = %d AND status IN ('scheduled', 'pending')
                 ORDER BY id DESC LIMIT 1",
                    $post
                )
            );

            if ($existing_batch) {
                // Use existing batch
                $batch_id = (int)$existing_batch->id;
            } else {
                // Insert new batch record (fallback for old code paths)
                $wpdb->insert(
                    Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES),
                    [
                        'status' => $status,
                        'total_emails' => 0,
                        'sender_name' => $config['fromName'] ?? '',
                        'sender_to' => $config['fromTo'] ?? '',
                        'subject' => $subject,
                        'scheduled_at' => $scheduledAt,
                        'campaign_id' => $post,
                    ]
                );

                $batch_id = $wpdb->insert_id;
                if (!$batch_id) {
                    return new \WP_REST_Response(null, 400);
                }
            }

            $htmlContent = get_option('mailerpress_batch_' . $post . '_html');

            if (empty($htmlContent)) {
                // Try to get HTML from campaign meta as fallback
                $campaign = get_post($post);
                if ($campaign) {
                    $meta = get_post_meta($post, 'meta', true);
                    if (is_string($meta)) {
                        $meta = json_decode($meta, true);
                    }
                    if (is_array($meta) && !empty($meta['html'])) {
                        $htmlContent = $meta['html'];
                    } elseif (!empty($campaign->post_content)) {
                        $htmlContent = $campaign->post_content;
                    }
                }

                if (empty($htmlContent)) {
                    $this->markBatchAsFailed(
                        $batch_id,
                        $post,
                        __('Email HTML content not found. Please save the campaign before sending.', 'mailerpress')
                    );
                    return;
                }
            }

            if (!empty($htmlContent) && containsStartQueryBlock($htmlContent)) {
                $renderer = new DynamicPostRenderer($htmlContent);
                $renderer->setCampaignId($post);
                $renderedHtml = $renderer->render();

                // Check if there are any new posts (not processed yet)
                if (empty($renderedHtml)) {
                    // No new posts to send, stop the process
                    return new \WP_REST_Response(__('No new content to send for this automated campaign'), 400);
                }

                $htmlContent = $renderedHtml;
            }


            $dbChunk = 1000;
            $offset = 0;
            $chunk_index = 0;
            $totalEmails = 0;
            $now = time();

            // Calculate base time for scheduling chunks
            $base_time = $now;
            if ('future' === $sendType && !empty($scheduledAt)) {
                $base_time = $this->convert_scheduled_at_to_timestamp($scheduledAt);
                if ($base_time <= $now) {
                    // If scheduled time is past, fallback to now
                    $base_time = $now;
                }
            }

            $foundContacts = false;

            $servicesData = get_option('mailerpress_email_services', []);
            $defaultService = $servicesData['default_service'] ?? '';

            // Get API key from service configuration
            $apiKey = '';
            if (!empty($defaultService) && isset($servicesData['services'][$defaultService]['conf']['api_key'])) {
                $apiKey = $servicesData['services'][$defaultService]['conf']['api_key'];
            } elseif (isset($config['api_key'])) {
                $apiKey = $config['api_key'];
            }

            if ('mailerpress' === $defaultService) {
                $offset = 0;
                $foundContacts = false;
                $totalEmails = 0;

                do {
                    $contacts = $fetcher->fetch($dbChunk, $offset);
                    if (empty($contacts)) {
                        break;
                    }

                    $foundContacts = true;
                    $totalEmails += count($contacts);

                    // Split contacts into smaller chunks for Laravel
                    $sendingChunks = array_chunk($contacts, 1000);

                    foreach ($sendingChunks as $sendingChunk) {



                        $parser = Kernel::getContainer()->get(HtmlParser::class);
                        $payload = [
                            'wp_batch_id' => $batch_id,
                            'domain_id' => $servicesData['services']['mailerpress']['conf']['domain'],
                            'name' => $subject,
                            'emails' => array_map(function ($c) use ($htmlContent, $subject, $batch_id, $post, $parser, $openTracking, $clickTracking) {
                                $contact = Kernel::getContainer()->get(Contacts::class)->get($c);

                                $trackContactId = ('anonymously' === $openTracking) ? 0 : (int) $contact->contact_id;
                                $contact_variables = [
                                    'CONTACT_ID'  => (int) $contact->contact_id,
                                    'CAMPAIGN_ID' => (int) $post,
                                    'UNSUB_LINK'  => wp_unslash( sprintf(
                                        '%s&data=%s&cid=%s&batchId=%s',
                                        mailerpress_get_page( 'unsub_page' ),
                                        esc_attr( $contact->unsubscribe_token ),
                                        esc_attr( $contact->access_token ),
                                        $batch_id
                                    ) ),
                                    'MANAGE_SUB_LINK' => wp_unslash( sprintf(
                                        '%s&cid=%s',
                                        mailerpress_get_page( 'manage_page' ),
                                        esc_attr( $contact->access_token )
                                    ) ),
                                    'CONTACT_NAME'        => esc_html( $contact->first_name ) . ' ' . esc_html( $contact->last_name ),
                                    'contact_name'        => esc_html( $contact->first_name ) . ' ' . esc_html( $contact->last_name ),
                                    'contact_email'       => esc_html( $contact->email ),
                                    'contact_first_name'  => esc_html( $contact->first_name ),
                                    'contact_last_name'   => esc_html( $contact->last_name ),
                                ];

                                if ( 'no' !== $openTracking ) {
                                    $contact_variables['TRACK_OPEN'] = get_rest_url(
                                        null,
                                        sprintf(
                                            'mailerpress/v1/campaign/track-open?token=%s',
                                            HtmlParser::generateTrackOpenToken(
                                                $trackContactId,
                                                (int) $post,
                                                (int) $batch_id
                                            )
                                        )
                                    );
                                }

                                $body = $parser->init( $htmlContent, $contact_variables )->replaceVariables( $clickTracking );

                                return [
                                    'to'      => $contact->email,
                                    'subject' => $subject,
                                    'body'    => $body,
                                ];
                            }, $sendingChunk),
                        ];

                        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        // Send to Laravel API
                        $response = wp_remote_post('https://mailerpress.pro/api/batch', [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'x-api-key' => $servicesData['services']['mailerpress']['conf']['api_key'],
                            ],
                            'body' => $payloadJson,
                            'timeout' => 60,
                        ]);

                        if (is_wp_error($response)) {
                            // Mark batch as failed if API error occurs
                            $this->markBatchAsFailed($batch_id, $post, 'Laravel API error: ' . $response->get_error_message());
                            return;
                        } else {
                            $status_code = wp_remote_retrieve_response_code($response);
                            $body = wp_remote_retrieve_body($response);
                        }
                    }

                    $offset += $dbChunk;
                } while (!empty($contacts));

                // Update batch totals
                if (!$foundContacts) {
                    $wpdb->update(
                        Tables::get(Tables::MAILERPRESS_CAMPAIGNS),
                        ['status' => 'error', 'updated_at' => current_time('mysql')],
                        ['campaign_id' => intval($post)],
                        ['%s', '%s'],
                        ['%d']
                    );
                } else {
                    $wpdb->update(
                        Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES),
                        ['total_emails' => $totalEmails],
                        ['id' => $batch_id],
                        ['%d'],
                        ['%d']
                    );
                    do_action('mailerpress_batch_event', $status, $post, $batch_id);
                }
            } else {
                // Étape 1 : Collecter TOUS les contact IDs en une seule passe
                // Ceci évite de parcourir deux fois et garantit la cohérence
                $allContactIds = [];
                do {
                    $contacts = $fetcher->fetch($dbChunk, $offset);
                    if (empty($contacts)) {
                        break;
                    }

                    $foundContacts = true;
                    $allContactIds = array_merge($allContactIds, $contacts);
                    $offset += $dbChunk;
                } while (!empty($contacts));

                if (!$foundContacts || empty($allContactIds)) {
                    $wpdb->update(
                        Tables::get(Tables::MAILERPRESS_CAMPAIGNS),
                        ['status' => 'error', 'updated_at' => current_time('mysql')],
                        ['campaign_id' => intval($post)],
                        ['%s', '%s'],
                        ['%d']
                    );
                    return;
                }

                // Étape 2 : Calculer le total et mettre à jour AVANT de planifier
                $totalEmails = count($allContactIds);
                $wpdb->update(
                    Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES),
                    ['total_emails' => $totalEmails],
                    ['id' => $batch_id],
                    ['%d'],
                    ['%d']
                );

                // Étape 3 : Diviser en chunks et planifier
                $sendingChunks = array_chunk($allContactIds, $numberEmail);

                foreach ($sendingChunks as $sendingChunk) {
                    $scheduled_time = $base_time + ($chunk_index * $interval_seconds);

                    // Préparer données du chunk
                    $chunkData = [
                        'html' => $htmlContent,
                        'campaignId' => $post,
                        'subject' => $config['subject'],
                        'sender_name' => $config['fromName'],
                        'api_key' => $apiKey,
                        'sender_to' => $config['fromTo'],
                        'scheduled_at' => $scheduledAt,
                        'webhook_url' => get_rest_url(null, 'mailerpress/v1/webhook/notify'),
                        'sendType' => $sendType,
                        'rate_limit' => $rateLimit, // Rate limit (emails per second)
                        'openTracking' => $openTracking,
                        'clickTracking' => $clickTracking,
                    ];

                    // 1. PERSISTANCE DATABASE (source de vérité)
                    // Le ChunkWorker récurrent se chargera de traiter ces chunks
                    $wpdb->insert(
                        Tables::get(Tables::MAILERPRESS_EMAIL_CHUNKS),
                        [
                            'batch_id' => $batch_id,
                            'chunk_index' => $chunk_index,
                            'status' => 'pending',
                            'contact_ids' => wp_json_encode($sendingChunk),
                            'chunk_data' => wp_json_encode($chunkData),
                            'retry_count' => 0,
                            'scheduled_at' => gmdate('Y-m-d H:i:s', $scheduled_time),
                            'created_at' => current_time('mysql'),
                        ],
                        ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
                    );

                    $chunk_id = $wpdb->insert_id;

                    // 2. CACHE TRANSIENT (performance, sans expiration - nettoyage manuel)
                    $transient_key = 'mailerpress_chunk_' . $batch_id . '_' . $chunk_index;
                    set_transient($transient_key, array_merge($chunkData, [
                        'contacts' => $sendingChunk,
                        'chunk_id' => $chunk_id,
                    ]), 0); // Pas d'expiration - le cron nettoiera après completion

                    // 3. PAS d'action ActionScheduler individuelle
                    // Le ChunkWorker récurrent va traiter ce chunk automatiquement

                    $chunk_index++;
                }

                // Déclencher l'événement pour notifier que le batch est prêt
                do_action('mailerpress_batch_event', $status, $post, $batch_id);
            }

        } catch (\Throwable $e) {
            // Try to mark batch as failed if we have the batch_id
            if (isset($batch_id) && !empty($batch_id) && !empty($post)) {
                try {
                    $this->markBatchAsFailed($batch_id, $post, 'Critical error: ' . $e->getMessage());
                } catch (\Throwable) {
                }
            } elseif (!empty($post)) {
                // Try to find batch_id from database
                global $wpdb;
                $batch = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM " . Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES) . "
                     WHERE campaign_id = %d
                     ORDER BY id DESC LIMIT 1",
                    $post
                ));
                if ($batch) {
                    try {
                        $this->markBatchAsFailed((int)$batch->id, $post, 'Critical error: ' . $e->getMessage());
                    } catch (\Throwable) {
                    }
                }
            }
        }
    }

    /**
     * Convert scheduled_at string to Unix timestamp.
     * The value is expected in UTC (stored that way since createBatchV2 passes UTC).
     * Falls back to WP timezone interpretation for backward compatibility with
     * any batches created before the UTC fix.
     */
    private function convert_scheduled_at_to_timestamp(string $scheduledAt): int
    {
        try {
            // Try UTC first (new behavior: createBatchV2 passes UTC)
            $dt = new \DateTime($scheduledAt, new \DateTimeZone('UTC'));
            return $dt->getTimestamp();
        } catch (\Exception $e) {
            // fallback to current time if parsing fails
            return time();
        }
    }

    /**
     * Mark batch and campaign as failed
     */
    private function markBatchAsFailed(int $batch_id, int $campaign_id, string $error_message): void
    {
        global $wpdb;

        $batchTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $campaignTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Update batch status to failed with error message
        $wpdb->update(
            $batchTable,
            [
                'status' => 'failed',
                'error_message' => $error_message,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $batch_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        // Update campaign status to error
        $wpdb->update(
            $campaignTable,
            [
                'status' => 'error',
                'updated_at' => current_time('mysql'),
            ],
            ['campaign_id' => $campaign_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Returns a ContactFetcher based on recipient targeting
     */
    private function getFetcher(
        ?string $type,
        array $lists,
        array $tags,
        $segment
    ): ?ContactFetcherInterface {
        // Default to 'classic' if type is null or empty (backward compatibility)
        $type = $type ?? 'classic';

        return match ($type) {
            'classic' => new ClassicContactFetcher($lists, $tags),
            'segment' => new SegmentContactFetcher(is_array($segment) ? $segment[0] : $segment),
            default => new ClassicContactFetcher($lists, $tags) // Fallback to classic instead of null
        };
    }
}
