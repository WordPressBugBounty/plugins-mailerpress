<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Actions;

\defined('ABSPATH') || exit;

use MailerPress\Core\Workflows\Handlers\StepHandlerInterface;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Workflows\Handlers\SendEmailStepHandler;
use MailerPress\Core\Enums\Tables;

/**
 * Handler pour l'A/B Testing d'emails dans les workflows
 * 
 * Permet de tester deux versions d'emails et d'envoyer automatiquement 
 * la version gagnante après un délai configuré.
 * 
 * @since 1.2.0
 */
class ABTestStepHandler implements StepHandlerInterface
{
    /**
     * Vérifie si ce handler supporte la clé d'action donnée
     * 
     * @param string $key La clé de l'action
     * @return bool True si ce handler supporte la clé
     */
    public function supports(string $key): bool
    {
        return $key === 'ab_test';
    }

    /**
     * Définition de l'action pour l'éditeur de workflow
     * 
     * @return array
     */
    public function getDefinition(): array
    {
        return [
            'key' => 'ab_test',
            'label' => \__('A/B Test', 'mailerpress'),
            'description' => \__('Test two email versions and automatically send the winning version to remaining contacts. Split your audience into two groups, test different approaches (subjects, content) and let the system determine the best version based on open rates, click rates, or conversion rates.', 'mailerpress'),
            'icon' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.31-8.86c-1.77-.45-2.34-.94-2.34-1.67 0-.84.79-1.43 2.1-1.43 1.38 0 1.9.66 1.94 1.64h1.71c-.05-1.34-.87-2.57-2.49-2.97V5H10.9v1.69c-1.51.32-2.72 1.3-2.72 2.81 0 1.79 1.49 2.69 3.66 3.21 1.95.46 2.34 1.15 2.34 1.87 0 .53-.39 1.39-2.1 1.39-1.6 0-2.23-.72-2.32-1.64H8.04c.1 1.7 1.36 2.66 2.86 2.97V19h2.34v-1.67c1.52-.29 2.72-1.16 2.72-2.92 0-1.14-.49-2.19-3.61-2.81z"/></svg>',
            'category' => 'testing',
            'type' => 'ACTION',
            'settings_schema' => [
                [
                    'key' => 'test_name',
                    'label' => \__('Test Name', 'mailerpress'),
                    'type' => 'text',
                    'required' => true,
                    'help' => \__('Give this A/B test a name (e.g., "Subject Line Test")', 'mailerpress'),
                ],
                [
                    'key' => 'version_a_template_id',
                    'label' => \__('Version A - Email Template', 'mailerpress'),
                    'type' => 'select_dynamic',
                    'data_source' => 'campaigns',
                    'required' => true,
                    'help' => \__('Choose the email campaign/template to send to Version A group. You can use the same template for both versions or different ones.', 'mailerpress'),
                ],
                [
                    'key' => 'version_a_subject',
                    'label' => \__('Version A - Email Subject Line', 'mailerpress'),
                    'type' => 'text',
                    'required' => true,
                    'help' => \__('Customize the subject line for Version A.', 'mailerpress'),
                ],
                [
                    'key' => 'version_b_template_id',
                    'label' => \__('Version B - Email Template', 'mailerpress'),
                    'type' => 'select_dynamic',
                    'data_source' => 'campaigns',
                    'required' => true,
                    'help' => \__('Choose the email campaign/template to send to Version B group. Tip: Use the same template with different subjects to test subject lines, or different templates to test content.', 'mailerpress'),
                ],
                [
                    'key' => 'version_b_subject',
                    'label' => \__('Version B - Email Subject Line', 'mailerpress'),
                    'type' => 'text',
                    'required' => true,
                    'help' => \__('Customize the subject line for Version B.', 'mailerpress'),
                ],
                [
                    'key' => 'split_percentage',
                    'label' => \__('Split Percentage', 'mailerpress'),
                    'type' => 'number',
                    'required' => false,
                    'default' => 50,
                    'min' => 10,
                    'max' => 90,
                    'help' => \__('Percentage of contacts to receive version A (rest get version B). Default: 50%', 'mailerpress'),
                ],
                [
                    'key' => 'test_duration',
                    'label' => \__('Test Duration (hours)', 'mailerpress'),
                    'type' => 'number',
                    'required' => false,
                    'default' => 24,
                    'min' => 1,
                    'max' => 168,
                    'help' => \__('How long to wait before sending winner to remaining contacts (1-168 hours)', 'mailerpress'),
                ],
                [
                    'key' => 'winning_criteria',
                    'label' => \__('Winning Criteria', 'mailerpress'),
                    'type' => 'select',
                    'required' => false,
                    'default' => 'open_rate',
                    'options' => [
                        ['value' => 'open_rate', 'label' => \__('Highest Open Rate', 'mailerpress')],
                        ['value' => 'click_rate', 'label' => \__('Highest Click Rate', 'mailerpress')],
                        ['value' => 'conversion_rate', 'label' => \__('Highest Conversion Rate', 'mailerpress')],
                    ],
                    'help' => \__('Metric used to determine the winner', 'mailerpress'),
                ],
            ],
        ];
    }

    /**
     * Gère l'exécution de l'A/B test
     * 
     * @param Step $step L'étape à exécuter
     * @param AutomationJob $job Le contexte du job d'automatisation
     * @param array $context Contexte additionnel
     * @return StepResult Le résultat de l'exécution
     */
    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        global $wpdb;

        $settings = $step->getSettings();
        $testName = $settings['test_name'] ?? '';
        $versionATemplateId = $settings['version_a_template_id'] ?? null;
        $versionASubject = $settings['version_a_subject'] ?? '';
        $versionBTemplateId = $settings['version_b_template_id'] ?? null;
        $versionBSubject = $settings['version_b_subject'] ?? '';
        $splitPercentage = (int) ($settings['split_percentage'] ?? 50);
        $testDuration = (int) ($settings['test_duration'] ?? 24);
        $winningCriteria = $settings['winning_criteria'] ?? 'open_rate';

        // Validation
        if (empty($testName)) {
            return StepResult::failed(\__('Test name is required', 'mailerpress'));
        }

        // Convertir les template IDs en int (ils peuvent venir comme string du frontend)
        $versionATemplateId = $versionATemplateId ? (int) $versionATemplateId : null;
        $versionBTemplateId = $versionBTemplateId ? (int) $versionBTemplateId : null;

        if (!$versionATemplateId || !$versionBTemplateId) {
            return StepResult::failed(\__('Both template versions are required', 'mailerpress'));
        }

        // Validation des sujets
        if (empty($versionASubject)) {
            return StepResult::failed(\__('Version A subject line is required', 'mailerpress'));
        }

        if (empty($versionBSubject)) {
            return StepResult::failed(\__('Version B subject line is required', 'mailerpress'));
        }

        // Valider le pourcentage de split
        if ($splitPercentage < 10 || $splitPercentage > 90) {
            $splitPercentage = 50;
        }

        // Valider la durée du test
        if ($testDuration < 1 || $testDuration > 168) {
            $testDuration = 24;
        }

        $userId = $job->getUserId();
        if (!$userId) {
            return StepResult::failed(\__('No user ID found', 'mailerpress'));
        }

        // Déterminer dans quel groupe placer ce contact (A ou B)
        // Utiliser un hash déterministe basé sur l'user_id et le step_id pour garantir la cohérence
        $hash = crc32($userId . '_' . $step->getStepId() . '_' . $job->getAutomationId());
        $group = ($hash % 100) < $splitPercentage ? 'A' : 'B';

        // Vérifier d'abord s'il existe un test complété avec un gagnant pour cette automation
        global $wpdb;
        $testTable = $wpdb->prefix . 'mailerpress_ab_tests';
        $completedTest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, winner, version_a_template_id, version_a_subject, version_b_template_id, version_b_subject 
            FROM {$testTable} 
            WHERE step_id = %s AND automation_id = %d AND status = 'completed' AND winner IS NOT NULL
            ORDER BY completed_at DESC
            LIMIT 1",
            $step->getStepId(),
            $step->getAutomationId()
        ));

        $useWinner = false;
        $winner = null;
        $testId = null;

        if ($completedTest) {
            // Un test complété existe avec un gagnant - utiliser le gagnant
            $winner = $completedTest->winner;
            $testId = (int) $completedTest->id;
            $useWinner = true;
            // Utiliser le template et le sujet de la version gagnante
            $templateId = $winner === 'A' ? (int) $completedTest->version_a_template_id : (int) $completedTest->version_b_template_id;
            $subject = $winner === 'A' ? $completedTest->version_a_subject : $completedTest->version_b_subject;
            $group = $winner; // Utiliser le groupe gagnant pour l'enregistrement
        } else {
            // Pas de test complété - créer ou récupérer un test en cours
            $testId = $this->getOrCreateABTest($step, $testName, $versionATemplateId, $versionASubject, $versionBTemplateId, $versionBSubject, $splitPercentage, $testDuration, $winningCriteria);

            // Enregistrer la participation de ce contact au test
            $this->recordContactParticipation($testId, $userId, $group);

            // Déterminer quelle version envoyer
            $templateId = $group === 'A' ? $versionATemplateId : $versionBTemplateId;
            $subject = $group === 'A' ? $versionASubject : $versionBSubject;
        }

        // Les sujets sont maintenant obligatoires, donc on les passe toujours
        $tempStepSettings = [
            'template_id' => (string) $templateId, // SendEmailStepHandler attend une string
            'name' => $testName . ' - Version ' . $group,
            'subject' => $subject,
        ];

        // Utiliser le SendEmailStepHandler pour envoyer l'email
        $emailHandler = new SendEmailStepHandler();

        // Créer une étape temporaire pour l'envoi d'email
        // La classe Step utilise un constructeur avec un tableau de données
        $tempStep = new Step([
            'key' => 'send_email',
            'type' => 'ACTION',
            'settings' => $tempStepSettings,
            'automation_id' => $step->getAutomationId(),
            'step_id' => $step->getStepId() . '_ab_' . $group,
        ]);

        // Vérifier que le template existe avant d'essayer d'envoyer
        global $wpdb;
        $campaignsTable = \MailerPress\Core\Enums\Tables::get(\MailerPress\Core\Enums\Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT campaign_id, subject, content_html, config, campaign_type, status FROM {$campaignsTable} WHERE campaign_id = %d",
                $templateId
            )
        );

        if (!$campaign) {
            return StepResult::failed(
                \sprintf(\__('Template not found for version %s', 'mailerpress'), $group)
            );
        }

        // Vérifier que le HTML est disponible
        $htmlContent = \get_option('mailerpress_batch_' . $templateId . '_html');
        if (empty($htmlContent) && empty($campaign->content_html)) {
            return StepResult::failed(
                \sprintf(\__('Email content not available for version %s. Please save the campaign first to generate the HTML content.', 'mailerpress'), $group)
            );
        }

       $emailResult = $emailHandler->handle($tempStep, $job, $context);

        if (!$emailResult->isSuccess()) {
            return StepResult::failed(
                \sprintf(\__('Failed to send A/B test email: %s', 'mailerpress'), $emailResult->getError())
            );
        }

        // Enregistrer la participation si on utilise le gagnant (pas encore enregistré)
        if ($useWinner) {
            $this->recordContactParticipation($testId, $userId, $group);
        }

        // Planifier l'envoi de la version gagnante après le délai (seulement si ce n'est pas déjà un test complété)
        if (!$useWinner) {
            $this->scheduleWinnerSend($testId, $step, $testDuration);
        }

        return StepResult::success($step->getNextStepId(), [
            'ab_test_id' => $testId,
            'test_name' => $testName,
            'group' => $group,
            'template_sent' => $templateId,
            'version' => $group,
        ]);
    }

    /**
     * Crée ou récupère un enregistrement de test A/B
     */
    private function getOrCreateABTest(
        Step $step,
        string $testName,
        int $versionATemplateId,
        string $versionASubject,
        int $versionBTemplateId,
        string $versionBSubject,
        int $splitPercentage,
        int $testDuration,
        string $winningCriteria
    ): int {
        global $wpdb;

        $tableName = $wpdb->prefix . 'mailerpress_ab_tests';

        // Créer la table si elle n'existe pas
        $this->createABTestsTable();

        // Vérifier si un test existe déjà pour ce step
        $existingTest = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$tableName} WHERE step_id = %s AND automation_id = %d AND status = 'running'",
            $step->getStepId(),
            $step->getAutomationId()
        ));

        if ($existingTest) {
            return (int) $existingTest->id;
        }

        // Créer un nouveau test
        $wpdb->insert(
            $tableName,
            [
                'step_id' => $step->getStepId(),
                'automation_id' => $step->getAutomationId(),
                'test_name' => $testName,
                'version_a_template_id' => $versionATemplateId,
                'version_a_subject' => $versionASubject,
                'version_b_template_id' => $versionBTemplateId,
                'version_b_subject' => $versionBSubject,
                'split_percentage' => $splitPercentage,
                'test_duration' => $testDuration,
                'winning_criteria' => $winningCriteria,
                'status' => 'running',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Enregistre la participation d'un contact au test
     */
    private function recordContactParticipation(int $testId, int $userId, string $group): void
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'mailerpress_ab_test_participants';

        // Créer la table si elle n'existe pas
        $this->createABTestParticipantsTable();

        // Vérifier si le contact participe déjà
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$tableName} WHERE test_id = %d AND user_id = %d",
            $testId,
            $userId
        ));

        if ($existing) {
            return; // Déjà enregistré
        }

        $wpdb->insert(
            $tableName,
            [
                'test_id' => $testId,
                'user_id' => $userId,
                'test_group' => $group,
                'sent_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s']
        );
    }

    /**
     * Planifie l'envoi de la version gagnante après le délai
     */
    private function scheduleWinnerSend(int $testId, Step $step, int $testDuration): void
    {
        global $wpdb;

        // Vérifier si le test est déjà terminé
        $testTable = $wpdb->prefix . 'mailerpress_ab_tests';
        $test = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$testTable} WHERE id = %d",
            $testId
        ));

        if ($test && $test->status === 'completed') {
            return;
        }

        // Vérifier si un événement est déjà planifié pour ce test
        // On ne veut qu'UN SEUL événement par test A/B, pas un par contact
        $alreadyScheduled = false;

        if (function_exists('as_has_scheduled_action')) {
            // Vérifier avec Action Scheduler
            $alreadyScheduled = as_has_scheduled_action('mailerpress_ab_test_send_winner', [$testId, $step->getStepId()]);
        } else {
            // Vérifier avec wp_schedule_single_event (moins fiable mais fallback)
            // Note: wp_schedule_single_event ne permet pas de vérifier facilement
            // On utilise une option WordPress pour tracker les événements planifiés
            $scheduledTests = get_option('mailerpress_ab_test_scheduled', []);
            $alreadyScheduled = in_array($testId, $scheduledTests, true);
        }

        if ($alreadyScheduled) {
            return;
        }

        // Marquer ce test comme planifié
        if (!function_exists('as_has_scheduled_action')) {
            $scheduledTests = get_option('mailerpress_ab_test_scheduled', []);
            $scheduledTests[] = $testId;
            update_option('mailerpress_ab_test_scheduled', $scheduledTests);
        }

        // Utiliser Action Scheduler de WordPress si disponible
        if (!function_exists('as_schedule_single_action')) {
            // Fallback: utiliser wp_schedule_single_event
            $timestamp = time() + ($testDuration * HOUR_IN_SECONDS);
            wp_schedule_single_event($timestamp, 'mailerpress_ab_test_send_winner', [$testId, $step->getStepId()]);
        } else {
            $timestamp = time() + ($testDuration * HOUR_IN_SECONDS);
            as_schedule_single_action($timestamp, 'mailerpress_ab_test_send_winner', [$testId, $step->getStepId()]);
        }
    }

    /**
     * Crée la table pour stocker les tests A/B
     */
    private function createABTestsTable(): void
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'mailerpress_ab_tests';
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$tableName} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            step_id varchar(255) NOT NULL,
            automation_id bigint(20) UNSIGNED NOT NULL,
            test_name varchar(255) NOT NULL,
            version_a_template_id bigint(20) UNSIGNED NOT NULL,
            version_a_subject text,
            version_b_template_id bigint(20) UNSIGNED NOT NULL,
            version_b_subject text,
            split_percentage int(3) NOT NULL DEFAULT 50,
            test_duration int(11) NOT NULL DEFAULT 24,
            winning_criteria varchar(50) NOT NULL DEFAULT 'open_rate',
            status varchar(50) NOT NULL DEFAULT 'running',
            winner varchar(1) DEFAULT NULL,
            winner_metric decimal(10,2) DEFAULT NULL,
            created_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY step_id (step_id),
            KEY automation_id (automation_id),
            KEY status (status)
        ) {$charsetCollate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Met à jour le statut d'ouverture d'un participant A/B Test
     * 
     * @param int $campaignId L'ID de la campagne
     * @param int $userId L'ID de l'utilisateur/contact
     * @return bool True si la mise à jour a réussi
     */
    public static function updateParticipantOpen(int $campaignId, int $userId): bool
    {
        global $wpdb;

        $abTestsTable = $wpdb->prefix . 'mailerpress_ab_tests';
        $participantsTable = $wpdb->prefix . 'mailerpress_ab_test_participants';

        // Trouver le test A/B qui utilise ce template
        $test = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$abTestsTable} 
            WHERE (version_a_template_id = %d OR version_b_template_id = %d) 
            AND status = 'running' 
            ORDER BY created_at DESC 
            LIMIT 1",
            $campaignId,
            $campaignId
        ));

        if (!$test) {
            // Ce n'est pas un email A/B Test
            return false;
        }

        $testId = (int) $test->id;
        $openedAt = current_time('mysql');

        // Essayer de mettre à jour avec le user_id fourni
        $updated = $wpdb->update(
            $participantsTable,
            ['opened_at' => $openedAt],
            [
                'test_id' => $testId,
                'user_id' => $userId,
            ],
            ['%s'],
            ['%d', '%d']
        );

        // Si la mise à jour a échoué, essayer de trouver le participant par test_id seulement
        // (au cas où user_id serait différent mais qu'on puisse le trouver)
        if ($updated === false || $updated === 0) {
            // Chercher tous les participants de ce test et essayer de trouver une correspondance
            $participants = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id FROM {$participantsTable} WHERE test_id = %d",
                $testId
            ));

            // Si on trouve un seul participant, c'est probablement celui-là
            if (count($participants) === 1) {
                $participantUserId = (int) $participants[0]->user_id;
                $updated = $wpdb->update(
                    $participantsTable,
                    ['opened_at' => $openedAt],
                    [
                        'test_id' => $testId,
                        'user_id' => $participantUserId,
                    ],
                    ['%s'],
                    ['%d', '%d']
                );

                if ($updated !== false && $updated > 0) {
                    return true;
                }
            }

            return false;
        }

        if ($updated > 0) {
            return true;
        }

        return false;
    }

    /**
     * Met à jour le statut de clic d'un participant A/B Test
     * 
     * @param int $campaignId L'ID de la campagne
     * @param int $userId L'ID de l'utilisateur/contact
     * @return bool True si la mise à jour a réussi
     */
    public static function updateParticipantClick(int $campaignId, int $userId): bool
    {
        global $wpdb;

        $abTestsTable = $wpdb->prefix . 'mailerpress_ab_tests';
        $participantsTable = $wpdb->prefix . 'mailerpress_ab_test_participants';

        // Trouver le test A/B qui utilise ce template
        $test = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$abTestsTable} 
            WHERE (version_a_template_id = %d OR version_b_template_id = %d) 
            AND status = 'running' 
            ORDER BY created_at DESC 
            LIMIT 1",
            $campaignId,
            $campaignId
        ));

        if (!$test) {
            // Ce n'est pas un email A/B Test
            return false;
        }

        $testId = (int) $test->id;
        $clickedAt = current_time('mysql');

        // Mettre à jour clicked_at pour ce participant (seulement si pas déjà cliqué)
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, clicked_at FROM {$participantsTable} 
            WHERE test_id = %d AND user_id = %d",
            $testId,
            $userId
        ));

        if ($participant) {
        } else {

            // Si le participant n'est pas trouvé avec ce user_id, essayer de trouver par test_id seulement
            $participants = $wpdb->get_results($wpdb->prepare(
                "SELECT id, user_id, clicked_at FROM {$participantsTable} WHERE test_id = %d",
                $testId
            ));

            // Si on trouve un seul participant, c'est probablement celui-là
            if (count($participants) === 1) {
                $participant = $participants[0];
            } else if (count($participants) > 1) {
                // Si plusieurs participants, essayer de trouver par correspondance d'email
                // Récupérer l'email du contact si c'est un contact MailerPress
                $contact = $wpdb->get_row($wpdb->prepare(
                    "SELECT email FROM {$wpdb->prefix}mailerpress_contact WHERE contact_id = %d",
                    $userId
                ));

                if ($contact && !empty($contact->email)) {
                    // Trouver l'utilisateur WordPress par email
                    $user = get_user_by('email', $contact->email);
                    if ($user) {
                        $wpUserId = $user->ID;
                        // Chercher le participant avec ce user_id
                        foreach ($participants as $p) {
                            if ((int)$p->user_id === $wpUserId) {
                                $participant = $p;
                                break;
                            }
                        }
                    }
                }

                // Si toujours pas trouvé, essayer de trouver par contact_id direct
                if (!$participant) {
                    foreach ($participants as $p) {
                        if ((int)$p->user_id === $userId) {
                            $participant = $p;
                            break;
                        }
                    }
                }

                if (!$participant) {
                    return false;
                }
            } else {
                return false;
            }
        }

        // Ne mettre à jour que si clicked_at est NULL (premier clic)
        if ($participant->clicked_at === null) {
            $updated = $wpdb->update(
                $participantsTable,
                ['clicked_at' => $clickedAt],
                ['id' => (int) $participant->id],
                ['%s'],
                ['%d']
            );

            if ($updated !== false && $updated > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Crée la table pour stocker les participants aux tests A/B
     */
    private function createABTestParticipantsTable(): void
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'mailerpress_ab_test_participants';
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$tableName} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            test_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) NOT NULL,
            test_group varchar(1) NOT NULL,
            sent_at datetime NOT NULL,
            opened_at datetime DEFAULT NULL,
            clicked_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY test_id (test_id),
            KEY user_id (user_id),
            KEY test_group (test_group)
        ) {$charsetCollate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
