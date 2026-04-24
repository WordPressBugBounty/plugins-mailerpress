<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Webhooks\WebhookManager;

/**
 * Processor pour les webhooks sortants en queue (via Action Scheduler)
 *
 * Permet l'envoi asynchrone des webhooks sans bloquer l'exécution PHP
 *
 * @since 1.2.0
 */
class OutgoingWebhookProcessor
{
    /**
     * Traite un webhook sortant depuis la queue
     *
     * @param string $eventKey Clé de l'événement
     * @param array $data Données de l'événement
     * @param array $urls URLs de destination
     * @param array $options Options d'envoi (secret, etc.)
     * @param int $attemptNumber Numéro de tentative (pour les retries)
     */
    #[Action('mailerpress_process_outgoing_webhook', acceptedArgs: 5)]
    public static function processWebhook(
        string $eventKey,
        array $data,
        array $urls,
        array $options = [],
        int $attemptNumber = 1
    ): void {
        $manager = WebhookManager::getInstance();

        // Créer l'événement
        $event = $manager->getEventRegistry()->create($eventKey, $data);

        if (!$event) {
            return;
        }

        $responses = [];
        $failedUrls = [];

        // Envoyer le webhook vers chaque URL
        foreach ($urls as $url) {
            try {
                $response = $manager->getDispatcher()->dispatch($url, $event, $options);
                $statusCode = wp_remote_retrieve_response_code($response);

                $responses[$url] = [
                    'status_code' => $statusCode,
                    'success' => $statusCode >= 200 && $statusCode < 300,
                    'attempt' => $attemptNumber,
                ];

                // Si échec, marquer pour retry
                if ($statusCode < 200 || $statusCode >= 300) {
                    $failedUrls[] = $url;
                }
            } catch (\Exception $e) {
                $responses[$url] = [
                    'error' => true,
                    'message' => $e->getMessage(),
                    'attempt' => $attemptNumber,
                ];
                $failedUrls[] = $url;
            }
        }

        // Planifier un retry pour les URLs échouées (max 3 tentatives)
        if (!empty($failedUrls) && $attemptNumber < 3) {
            self::scheduleRetry($eventKey, $data, $failedUrls, $options, $attemptNumber + 1);
        }

        // Hook pour permettre le logging externe
        do_action('mailerpress_outgoing_webhook_processed', $eventKey, $data, $responses, $attemptNumber);
    }

    /**
     * Planifie un retry pour les webhooks échoués
     *
     * @param string $eventKey
     * @param array $data
     * @param array $urls URLs qui ont échoué
     * @param array $options
     * @param int $attemptNumber
     */
    private static function scheduleRetry(
        string $eventKey,
        array $data,
        array $urls,
        array $options,
        int $attemptNumber
    ): void {
        // Backoff exponentiel: 1min, 5min, 15min
        $delays = [
            2 => MINUTE_IN_SECONDS,      // 2ème tentative: 1 minute
            3 => 5 * MINUTE_IN_SECONDS,  // 3ème tentative: 5 minutes
        ];

        $delay = $delays[$attemptNumber] ?? MINUTE_IN_SECONDS;

        as_schedule_single_action(
            time() + $delay,
            'mailerpress_process_outgoing_webhook',
            [
                'event_key' => $eventKey,
                'data' => $data,
                'urls' => $urls,
                'options' => $options,
                'attempt_number' => $attemptNumber,
            ],
            'mailerpress-webhooks'
        );
    }

    /**
     * Annule tous les webhooks en attente pour un événement donné
     *
     * @param string $eventKey
     */
    public static function cancelPendingWebhooks(string $eventKey): void
    {
        as_unschedule_all_actions(
            'mailerpress_process_outgoing_webhook',
            ['event_key' => $eventKey],
            'mailerpress-webhooks'
        );
    }

    /**
     * Obtient le nombre de webhooks en attente
     *
     * @return int
     */
    public static function getPendingWebhookCount(): int
    {
        return (int) as_get_scheduled_actions([
            'hook' => 'mailerpress_process_outgoing_webhook',
            'status' => 'pending',
            'group' => 'mailerpress-webhooks',
            'per_page' => -1,
        ], 'ids');
    }
}
