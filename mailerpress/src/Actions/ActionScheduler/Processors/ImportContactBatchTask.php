<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Factories\MailerFactory;
use MailerPress\Models\Contacts;

class ImportContactBatchTask
{
    private Contacts $contactModel;

    public function __construct(
        Contacts $contactModel,
    ) {
        $this->contactModel = $contactModel;
    }

    #[Action('batch_contact_import_process', priority: 10)]
    public function run(): void
    {
        global $wpdb;

        $limit = 1000;

        // Étape 1 : Récupérer les batches non terminés
        $batches = $wpdb->get_results(
            "SELECT id, sender_name, sender_to, subject
		     FROM {$wpdb->prefix}mailerpress_email_batches
		     WHERE status IN ('pending', 'in_progress')
		     AND (scheduled_at IS NULL OR scheduled_at <= NOW())"
        );

        if (!empty($batches)) {
            foreach ($batches as $batch) {
                $batch_id = $batch->id;

                // Étape 2 : Récupérer les emails associés à ce batch qui sont en statut 'pending'
                $emails = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}mailerpress_email_queue
                    WHERE batch_id = %d AND status = 'pending'
                    LIMIT {$limit}",
                        $batch_id
                    )
                );

                if (!empty($emails)) {
                    // Étape 3 : Envoyer les emails et mettre à jour leur statut
                    foreach ($emails as $email) {
                        // Logique d'envoi d'email (exemple : envoyer_email($email))
                        $contact = $this->contactModel->get($email->contact_id);

                        $mailer = MailerFactory::createMailer();

                        $is_sent = $mailer->sendEmail(
                            $contact->email,
                            $batch->subject,
                            $email->html_content,
                            [
                                'sender_name' => $batch->sender_name,
                                'sender_to' => $batch->sender_to,
                            ]
                        );

                        // Mise à jour du statut de l'email
                        if ($is_sent) {
                            $wpdb->update(
                                "{$wpdb->prefix}mailerpress_email_queue",
                                ['status' => 'sent'],
                                ['contact_id' => $email->contact_id],
                                ['%s'],
                                ['%d']
                            );

                            $wpdb->query(
                                $wpdb->prepare(
                                    "UPDATE {$wpdb->prefix}mailerpress_email_batches SET sent_emails = sent_emails + 1 WHERE id = %d",
                                    $batch_id
                                )
                            );
                        } else {
                            $wpdb->update(
                                "{$wpdb->prefix}mailerpress_email_queue",
                                ['status' => 'failed'],
                                ['contact_id' => $email->contact_id],
                                ['%s'],
                                ['%d']
                            );

                            $wpdb->query(
                                $wpdb->prepare(
                                    "UPDATE {$wpdb->prefix}mailerpress_email_batches SET error_emails = error_emails + 1 WHERE id = %d",
                                    $batch_id
                                )
                            );
                        }
                    }

                    // Étape 4 : Vérifier si tous les emails du batch sont traités
                    $remaining_emails = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}mailerpress_email_queue
                         WHERE batch_id = %d AND status = 'pending'",
                            $batch_id
                        )
                    );

                    // Si plus aucun email n'est en 'pending', mettre à jour le statut du batch
                    if (0 === $remaining_emails) {
                        $wpdb->update(
                            "{$wpdb->prefix}mailerpress_email_batches",
                            ['status' => 'completed'],
                            ['id' => $batch_id],
                            ['%s'],
                            ['%d']
                        );
                    }

                    if ($remaining_emails > 0) {
                        $wpdb->update(
                            "{$wpdb->prefix}mailerpress_email_batches",
                            ['status' => 'in_progress'],
                            ['id' => $batch_id],
                            ['%s'],
                            ['%d']
                        );
                    }
                }
            }
        }
    }
}
