<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Actions;

\defined('ABSPATH') || exit;

use MailerPress\Core\Workflows\Handlers\SendEmailStepHandler;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;

/**
 * Handler pour envoyer automatiquement la version gagnante d'un test A/B
 * 
 * @since 1.2.0
 */
class ABTestWinnerHandler
{
    /**
     * Enregistre les hooks WordPress pour l'envoi automatique de la version gagnante
     */
    public function __construct()
    {
        add_action('mailerpress_ab_test_send_winner', [$this, 'sendWinner'], 10, 2);
    }

    /**
     * Envoie la version gagnante aux contacts restants
     * 
     * @param int $testId L'ID du test A/B
     * @param string $stepId L'ID de l'étape du workflow
     */
    public function sendWinner(int $testId, string $stepId): void
    {
        global $wpdb;

        $testTable = $wpdb->prefix . 'mailerpress_ab_tests';
        $participantsTable = $wpdb->prefix . 'mailerpress_ab_test_participants';

        // Récupérer les informations du test
        $test = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$testTable} WHERE id = %d AND status = 'running'",
            $testId
        ));

        if (!$test) {
            return;
        }

        // Calculer les métriques pour chaque version
        $metrics = $this->calculateMetrics($testId, $test->winning_criteria);

        // Déterminer la version gagnante
        $winner = $this->determineWinner($metrics, $test->winning_criteria);

        if (!$winner) {
            return;
        }

        // Marquer le test comme terminé
        $wpdb->update(
            $testTable,
            [
                'status' => 'completed',
                'winner' => $winner,
                'winner_metric' => $metrics[$winner][$test->winning_criteria],
                'completed_at' => current_time('mysql'),
            ],
            ['id' => $testId],
            ['%s', '%s', '%f', '%s'],
            ['%d']
        );

        // Récupérer les user_id des contacts qui ont déjà participé au test
        $participantUserIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$participantsTable} WHERE test_id = %d",
            $testId
        ));

        $participantUserIds = array_map('intval', $participantUserIds);

        // Récupérer tous les jobs actifs de l'automation qui sont à l'étape A/B Test
        $automationId = (int) $test->automation_id;
        $stepId = $test->step_id;

        $jobsTable = $wpdb->prefix . 'mailerpress_automations_jobs';
        $logTable = $wpdb->prefix . 'mailerpress_automations_log';

        // Trouver tous les jobs de l'automation qui sont à l'étape A/B Test ou juste après
        // On cherche les jobs qui ont un log pour cette étape mais qui n'ont pas encore continué
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT j.* FROM {$jobsTable} j
            INNER JOIN {$logTable} l ON j.id = l.job_id
            WHERE j.automation_id = %d
            AND j.status IN ('ACTIVE', 'WAITING', 'PROCESSING')
            AND l.step_id = %s
            AND l.status = 'PROCESSING'",
            $automationId,
            $stepId
        ), ARRAY_A);

        // Filtrer les jobs pour ne garder que ceux qui n'ont pas encore reçu d'email
        $jobsToSend = [];
        foreach ($jobs as $jobData) {
            $userId = (int) $jobData['user_id'];

            // Si ce user_id n'est pas dans les participants, c'est un contact qui n'a pas encore reçu d'email
            if (!in_array($userId, $participantUserIds, true)) {
                $jobsToSend[] = $jobData;
            }
        }

        // Déterminer le template et le sujet de la version gagnante
        $winnerTemplateId = $winner === 'A' ? (int) $test->version_a_template_id : (int) $test->version_b_template_id;
        $winnerSubject = $winner === 'A' ? $test->version_a_subject : $test->version_b_subject;

        // Envoyer la version gagnante à chaque contact restant
        $workflowSystem = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
        $executor = $workflowSystem->getManager()->getExecutor();
        $stepRepo = new \MailerPress\Core\Workflows\Repositories\StepRepository();
        $jobRepo = new \MailerPress\Core\Workflows\Repositories\AutomationJobRepository();

        $sentCount = 0;
        foreach ($jobsToSend as $jobData) {
            $job = new \MailerPress\Core\Workflows\Models\AutomationJob($jobData);
            $userId = $job->getUserId();

            // Créer une étape temporaire pour envoyer la version gagnante
            $winnerStep = new \MailerPress\Core\Workflows\Models\Step([
                'key' => 'send_email',
                'type' => 'ACTION',
                'settings' => [
                    'template_id' => (string) $winnerTemplateId,
                    'subject' => $winnerSubject,
                    'name' => $test->test_name . ' - Winner (Version ' . $winner . ')',
                ],
                'automation_id' => $automationId,
                'step_id' => $stepId . '_winner',
            ]);

            // Envoyer l'email via SendEmailStepHandler
            $emailHandler = new \MailerPress\Core\Workflows\Handlers\SendEmailStepHandler();
            $context = [];

            $result = $emailHandler->handle($winnerStep, $job, $context);

            if ($result->isSuccess()) {
                // Enregistrer la participation au test (même si c'est la version gagnante)
                $this->recordWinnerParticipation($testId, $userId, $winner);

                // Continuer le workflow au prochain step
                $nextStepId = $job->getNextStepId();
                if ($nextStepId) {
                    $job->setNextStepId($nextStepId);
                    $job->setStatus('ACTIVE');
                    $jobRepo->update($job);

                    // Continuer l'exécution du workflow
                    $executor->executeJob($job->getId(), $context);
                }

                $sentCount++;
            }
        }
    }

    /**
     * Calcule les métriques pour chaque version du test
     * 
     * @param int $testId L'ID du test
     * @param string $criteria Le critère de victoire
     * @return array Métriques par version
     */
    private function calculateMetrics(int $testId, string $criteria): array
    {
        global $wpdb;

        $participantsTable = $wpdb->prefix . 'mailerpress_ab_test_participants';

        // Récupérer les statistiques par groupe
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                test_group,
                COUNT(*) as total,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked
            FROM {$participantsTable}
            WHERE test_id = %d
            GROUP BY test_group",
            $testId
        ), ARRAY_A);

        $metrics = [
            'A' => [
                'total' => 0,
                'opened' => 0,
                'clicked' => 0,
                'open_rate' => 0,
                'click_rate' => 0,
                'conversion_rate' => 0,
            ],
            'B' => [
                'total' => 0,
                'opened' => 0,
                'clicked' => 0,
                'open_rate' => 0,
                'click_rate' => 0,
                'conversion_rate' => 0,
            ],
        ];

        foreach ($stats as $stat) {
            $group = $stat['test_group'];
            $total = (int) $stat['total'];
            $opened = (int) $stat['opened'];
            $clicked = (int) $stat['clicked'];

            $metrics[$group]['total'] = $total;
            $metrics[$group]['opened'] = $opened;
            $metrics[$group]['clicked'] = $clicked;
            $metrics[$group]['open_rate'] = $total > 0 ? ($opened / $total) * 100 : 0;
            $metrics[$group]['click_rate'] = $total > 0 ? ($clicked / $total) * 100 : 0;
            $metrics[$group]['conversion_rate'] = $opened > 0 ? ($clicked / $opened) * 100 : 0;
        }

        return $metrics;
    }

    /**
     * Détermine la version gagnante basée sur les métriques
     * 
     * @param array $metrics Les métriques calculées
     * @param string $criteria Le critère de victoire
     * @return string|null 'A' ou 'B', ou null si égalité
     */
    private function determineWinner(array $metrics, string $criteria): ?string
    {
        $metricKey = match ($criteria) {
            'open_rate' => 'open_rate',
            'click_rate' => 'click_rate',
            'conversion_rate' => 'conversion_rate',
            default => 'open_rate',
        };

        $aTotal = $metrics['A']['total'] ?? 0;
        $bTotal = $metrics['B']['total'] ?? 0;

        // Si une version n'a pas envoyé d'emails (total = 0), elle ne peut pas gagner
        // Donner la priorité à la version qui a des données réelles
        if ($aTotal === 0 && $bTotal > 0) {
            return 'B';
        }
        if ($bTotal === 0 && $aTotal > 0) {
            return 'A';
        }
        if ($aTotal === 0 && $bTotal === 0) {
            // Aucune version n'a envoyé d'emails, retourner null ou A par défaut
            return null;
        }

        $aValue = $metrics['A'][$metricKey] ?? 0;
        $bValue = $metrics['B'][$metricKey] ?? 0;

        if ($aValue > $bValue) {
            return 'A';
        } elseif ($bValue > $aValue) {
            return 'B';
        }

        // En cas d'égalité, utiliser le taux d'ouverture comme critère secondaire
        if ($metrics['A']['open_rate'] > $metrics['B']['open_rate']) {
            return 'A';
        } elseif ($metrics['B']['open_rate'] > $metrics['A']['open_rate']) {
            return 'B';
        }

        // En cas d'égalité totale, donner la priorité à la version avec le plus d'envois
        if ($aTotal > $bTotal) {
            return 'A';
        } elseif ($bTotal > $aTotal) {
            return 'B';
        }

        // Si toujours égal, retourner A par défaut
        return 'A';
    }

    /**
     * Enregistre la participation d'un contact qui reçoit la version gagnante
     * 
     * @param int $testId L'ID du test
     * @param int $userId L'ID de l'utilisateur/contact
     * @param string $winner La version gagnante ('A' ou 'B')
     */
    private function recordWinnerParticipation(int $testId, int $userId, string $winner): void
    {
        global $wpdb;

        $participantsTable = $wpdb->prefix . 'mailerpress_ab_test_participants';

        // Vérifier si le contact participe déjà
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$participantsTable} WHERE test_id = %d AND user_id = %d",
            $testId,
            $userId
        ));

        if ($existing) {
            return; // Déjà enregistré
        }

        // Enregistrer comme participant de la version gagnante
        $wpdb->insert(
            $participantsTable,
            [
                'test_id' => $testId,
                'user_id' => $userId,
                'test_group' => $winner,
                'sent_at' => current_time('mysql'),
                'opened_at' => null,
                'clicked_at' => null,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );
    }
}

// Initialiser le handler
new ABTestWinnerHandler();
