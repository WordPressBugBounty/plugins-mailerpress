<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Actions\Setup\EspServices;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Models\Contacts;

class EmailBatchesTask
{
    private Contacts $contactModel;

    public function __construct(
        Contacts $contactModel,
    ) {
        $this->contactModel = $contactModel;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Action('process_email_batch', priority: 1)]
    public function run(): void
    {
        $services = Kernel::getContainer()->get(EmailServiceManager::class)->getServices();
        if (empty($services)) {
            Kernel::getContainer()->get(EspServices::class)->registerService();
        }

        global $wpdb;

        $limit = 1000;

        $tableBatch = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);
        $tableEmailQueue = Tables::get(Tables::MAILERPRESS_EMAIL_QUEUE);

        // Étape 1 : Récupérer les batches non terminés
        $batches = $wpdb->get_results(
            "SELECT id, sender_name, sender_to, subject, campaign_id
         FROM {$tableBatch}
         WHERE status IN ('pending', 'in_progress')
         AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())"
        );

        if (!empty($batches)) {
            foreach ($batches as $batch) {
                $batch_id = $batch->id;
                $campaign_id = $batch->campaign_id;

                // Étape 2 : Récupérer la date de traitement du dernier email
                $last_processed_email = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT MAX(processed_at) FROM {$tableEmailQueue} WHERE batch_id = %d AND processed_at IS NOT NULL",
                        $batch_id
                    )
                );

                if (!$last_processed_email) {
                    $last_processed_email = '1970-01-01 00:00:00'; // Ou un timestamp très ancien pour commencer à traiter les premiers emails
                }

                $emails = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$tableEmailQueue}
                         WHERE batch_id = %d AND status = 'pending' AND (processed_at IS NULL OR processed_at > %s)
                         ORDER BY contact_id ASC
                         LIMIT %d",
                        $batch_id,
                        $last_processed_email,
                        $limit
                    )
                );

                if (!empty($emails)) {
                    // Étape 3 : Envoyer les emails et mettre à jour leur statut
                    foreach ($emails as $email) {
                        // Logique d'envoi d'email (exemple : envoyer_email($email))
                        $contact = $this->contactModel->get((int) $email->contact_id);
                        $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
                        $config = $mailer->getConfig();

                        // Récupérer les paramètres Reply to depuis les paramètres par défaut
                        $defaultSettings = get_option('mailerpress_default_settings', []);
                        if (is_string($defaultSettings)) {
                            $defaultSettings = json_decode($defaultSettings, true) ?: [];
                        }
                        
                        // Déterminer les valeurs Reply to (utiliser From si Reply to est vide)
                        $replyToName = !empty($defaultSettings['replyToName']) 
                            ? $defaultSettings['replyToName'] 
                            : ($config['conf']['default_name'] ?? '');
                        $replyToAddress = !empty($defaultSettings['replyToAddress']) 
                            ? $defaultSettings['replyToAddress'] 
                            : ($config['conf']['default_email'] ?? '');

                        $is_sent = $mailer->sendEmail([
                            'to' => $contact->email,
                            'html' => true,
                            'body' => $email->html_content,
                            'subject' => $batch->subject,
                            'sender_name' => $config['conf']['default_name'],
                            'sender_to' => $config['conf']['default_email'],
                            'reply_to_name' => $replyToName,
                            'reply_to_address' => $replyToAddress,
                            'apiKey' => $config['conf']['api_key'] ?? '',
                        ]);

                        // Mise à jour du statut de l'email et du champ processed_at
                        if ($is_sent) {
                            $wpdb->update(
                                $tableEmailQueue,
                                ['status' => 'sent', 'processed_at' => current_time('mysql')],
                                ['contact_id' => $email->contact_id, 'batch_id' => $batch_id],
                                ['%s', '%s'],
                                ['%d', '%d']
                            );

                            // Mettre à jour sent_emails en utilisant une requête SQL directe pour l'incrémentation
                            $wpdb->query(
                                $wpdb->prepare(
                                    "UPDATE {$tableBatch} SET sent_emails = COALESCE(sent_emails, 0) + 1 WHERE id = %d",
                                    $batch_id
                                )
                            );
                        } else {
                            $wpdb->update(
                                $tableEmailQueue,
                                ['status' => 'failed', 'processed_at' => current_time('mysql')],
                                ['contact_id' => $email->contact_id, 'batch_id' => $batch_id],
                                ['%s', '%s'],
                                ['%d', '%d']
                            );

                            // Mettre à jour error_emails en utilisant une requête SQL directe pour l'incrémentation
                            $wpdb->query(
                                $wpdb->prepare(
                                    "UPDATE {$tableBatch} SET error_emails = COALESCE(error_emails, 0) + 1 WHERE id = %d",
                                    $batch_id
                                )
                            );
                        }
                    }
                }

                // Étape 4 : Vérifier si tous les emails du batch sont traités
                // Récupérer les statistiques du batch pour vérifier si tous les emails ont été traités
                $batch_stats = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT total_emails, sent_emails, error_emails, status FROM {$tableBatch} WHERE id = %d",
                        $batch_id
                    ),
                    ARRAY_A
                );

                if ($batch_stats) {
                    $total_emails = (int) ($batch_stats['total_emails'] ?? 0);
                    $sent_emails = (int) ($batch_stats['sent_emails'] ?? 0);
                    $error_emails = (int) ($batch_stats['error_emails'] ?? 0);
                    $current_status = $batch_stats['status'] ?? 'pending';

                    // Vérifier également s'il reste des emails "pending" dans la queue (pour les flux qui utilisent la table email_queue)
                    $remaining_queue_emails = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$tableEmailQueue}
                             WHERE batch_id = %d AND status = 'pending'",
                            $batch_id
                        )
                    );

                    // Le batch est complet si :
                    // 1. Tous les emails ont été traités (sent + error >= total) ET
                    // 2. Il n'y a plus d'emails "pending" dans la queue
                    $is_batch_complete = $total_emails > 0
                        && ($sent_emails + $error_emails) >= $total_emails
                        && (int) $remaining_queue_emails === 0;

                    if ($is_batch_complete && $current_status !== 'sent') {
                        // Tous les emails sont traités, marquer le batch comme 'sent'
                        $wpdb->update(
                            $tableBatch,
                            ['status' => 'sent'],
                            ['id' => $batch_id],
                            ['%s'],
                            ['%d']
                        );

                        // Déclencher le hook pour mettre à jour le statut de la campagne
                        do_action('mailerpress_batch_event', 'sent', $campaign_id, $batch_id);
                    } elseif (!$is_batch_complete && $current_status !== 'sent' && ($sent_emails > 0 || (int) $remaining_queue_emails > 0)) {
                        // Le batch est en cours de traitement, mettre à jour le statut à 'in_progress'
                        if ($current_status === 'pending' || $current_status === 'scheduled') {
                            $wpdb->update(
                                $tableBatch,
                                ['status' => 'in_progress'],
                                ['id' => $batch_id],
                                ['%s'],
                                ['%d']
                            );

                            do_action('mailerpress_batch_event', 'in_progress', $campaign_id, $batch_id);
                        }
                    }
                }
            }
        }
    }
}
