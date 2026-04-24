<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Actions;

\defined('ABSPATH') || exit;

use MailerPress\Core\Workflows\Handlers\StepHandlerInterface;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Enums\Tables;

/**
 * Handler pour créer une campagne MailerPress depuis un workflow
 * 
 * @since 1.2.0
 */
class CreateCampaignStepHandler implements StepHandlerInterface
{
    /**
     * Vérifie si ce handler supporte la clé d'action donnée
     * 
     * @param string $key La clé de l'action
     * @return bool True si ce handler supporte la clé
     */
    public function supports(string $key): bool
    {
        return $key === 'create_campaign';
    }

    /**
     * Définition de l'action pour l'éditeur de workflow
     * 
     * @return array
     */
    public function getDefinition(): array
    {
        return [
            'key' => 'create_campaign',
            'label' => \__('Create Campaign', 'mailerpress'),
            'description' => \__('Create a new MailerPress campaign automatically within your workflow. Useful for generating dynamic campaigns based on events, such as creating a newsletter campaign from a published post or generating personalized campaigns for each contact.', 'mailerpress'),
            'icon' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5.04-6.71l-2.75 3.54-1.96-2.36L6.5 17h11l-3.54-4.71z"/></svg>',
            'category' => 'campaign',
            'type' => 'ACTION',
            'settings_schema' => [
                [
                    'key' => 'name',
                    'label' => \__('Campaign Name', 'mailerpress'),
                    'type' => 'text',
                    'required' => true,
                    'help' => \__('Enter a name for the campaign', 'mailerpress'),
                ],
                [
                    'key' => 'subject',
                    'label' => \__('Subject', 'mailerpress'),
                    'type' => 'text',
                    'required' => false,
                    'help' => \__('Email subject line', 'mailerpress'),
                ],
                [
                    'key' => 'campaign_type',
                    'label' => \__('Campaign Type', 'mailerpress'),
                    'type' => 'select',
                    'options' => [
                        ['label' => \__('Newsletter', 'mailerpress'), 'value' => 'newsletter'],
                        ['label' => \__('Automated', 'mailerpress'), 'value' => 'automated'],
                        ['label' => \__('Automation', 'mailerpress'), 'value' => 'automation'],
                    ],
                    'default' => 'newsletter',
                    'required' => false,
                    'help' => \__('Select the type of campaign', 'mailerpress'),
                ],
            ],
        ];
    }

    /**
     * Gère l'exécution de l'action de création de campagne
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
        $name = $settings['name'] ?? '';
        $subject = $settings['subject'] ?? '';
        $campaignType = $settings['campaign_type'] ?? 'newsletter';

        // Validation du nom
        if (empty($name)) {
            return StepResult::failed(\__('Campaign name is required', 'mailerpress'));
        }

        // Validation du type de campagne
        if (!in_array($campaignType, ['newsletter', 'automated', 'automation'], true)) {
            $campaignType = 'newsletter';
        }

        // Récupérer le post_id depuis le contexte (peut être 'post_id', 'ID', 'id', etc.)
        $postId = $context['post_id'] ?? $context['ID'] ?? $context['id'] ?? null;

        // Générer la structure JSON de la campagne avec QueryBlock si post_id disponible
        $campaignJson = $this->createCampaignJson($postId);

        // Préparer les données de base pour la campagne
        $tableName = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $data = [
            'user_id' => \get_current_user_id(),
            'name' => \sanitize_text_field($name),
            'subject' => \sanitize_text_field($subject),
            'status' => 'draft',
            'email_type' => 'html',
            'content_html' => $campaignJson ? \wp_json_encode($campaignJson) : null,
            'created_at' => \current_time('mysql'),
            'updated_at' => \current_time('mysql'),
            'campaign_type' => $campaignType,
        ];

        // Insérer la campagne dans la base de données
        $inserted = $wpdb->insert($tableName, $data);

        if ($inserted === false) {
            $error = \__('Failed to create campaign. Database error: ', 'mailerpress') . $wpdb->last_error;
            return StepResult::failed($error);
        }

        $campaignId = $wpdb->insert_id;

        // Déclencher l'action WordPress pour les hooks
        \do_action('mailerpress_workflow_campaign_created', $campaignId, $job, $context);

        return StepResult::success($step->getNextStepId(), [
            'campaign_id' => $campaignId,
            'campaign_name' => $data['name'],
            'campaign_type' => $campaignType,
            'post_id' => $postId,
        ]);
    }

    /**
     * Crée la structure JSON de la campagne avec un QueryBlock pré-configuré
     * 
     * @param int|null $postId L'ID du post à inclure dans le QueryBlock
     * @return array|null Structure JSON de la campagne ou null si erreur
     */
    private function createCampaignJson(?int $postId): ?array
    {
        // Générer des clientIds uniques
        $generateClientId = function () {
            // Utiliser wp_generate_uuid4() si disponible (WordPress 6.0+), sinon utiliser un format similaire
            if (\function_exists('wp_generate_uuid4')) {
                return \wp_generate_uuid4();
            }
            // Fallback: générer un UUID v4 manuellement
            return \sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                \mt_rand(0, 0xffff),
                \mt_rand(0, 0xffff),
                \mt_rand(0, 0xffff),
                \mt_rand(0, 0x0fff) | 0x4000,
                \mt_rand(0, 0x3fff) | 0x8000,
                \mt_rand(0, 0xffff),
                \mt_rand(0, 0xffff),
                \mt_rand(0, 0xffff)
            );
        };

        $pageClientId = $generateClientId();
        $queryClientId = $generateClientId();
        $postTemplateClientId = $generateClientId();

        // Structure de base : PAGE -> SECTION -> COLUMN -> QUERY
        $queryBlock = [
            'type' => 'query',
            'clientId' => $queryClientId,
            'data' => [
                'selection' => 'manual',
                'pattern' => 'query-pattern-default',
                'template' => [],
                'query' => [
                    'postType' => 'post',
                    'per_page' => 5,
                    'order' => 'date/desc',
                ],
                'posts' => [],
            ],
            'attributes' => [],
            'children' => [
                [
                    'type' => 'post-template',
                    'clientId' => $postTemplateClientId,
                    'data' => [],
                    'attributes' => [],
                    'children' => [],
                ],
            ],
        ];

        // Si un post_id est fourni, récupérer les informations du post
        if ($postId) {
            $post = \get_post($postId);

            if ($post) {
                // Récupérer l'image à la une
                $featuredImageId = \get_post_thumbnail_id($postId);
                $featuredImageUrl = $featuredImageId ? \wp_get_attachment_image_url($featuredImageId, 'full') : null;

                // Construire l'objet post dans le format attendu par QueryBlock
                $postData = [
                    'ID' => $post->ID,
                    'post_title' => $post->post_title,
                    'post_excerpt' => $post->post_excerpt ?: \wp_trim_words($post->post_content, 55),
                    'post_content' => $post->post_content,
                    'post_date' => $post->post_date,
                    'post_date_gmt' => $post->post_date_gmt,
                    'post_modified' => $post->post_modified,
                    'post_modified_gmt' => $post->post_modified_gmt,
                    'post_status' => $post->post_status,
                    'post_type' => $post->post_type,
                    'post_name' => $post->post_name,
                    'guid' => $post->guid,
                    'post_author' => $post->post_author,
                    'subType' => $post->post_type,
                ];

                // Ajouter l'image à la une si disponible
                if ($featuredImageUrl) {
                    $postData['featured_image'] = $featuredImageUrl;
                    $postData['featured_image_id'] = $featuredImageId;
                }

                // Ajouter le permalink
                $postData['permalink'] = \get_permalink($postId);

                // Ajouter le post au QueryBlock
                $queryBlock['data']['posts'] = [$postData];
            }
        }

        // Structure complète de la campagne
        $campaignJson = [
            'type' => 'page',
            'clientId' => $pageClientId,
            'attributes' => [
                'width' => '600px',
            ],
            'data' => [
                'lock' => false,
            ],
            'children' => [
                $queryBlock
            ],
        ];

        return $campaignJson;
    }
}
