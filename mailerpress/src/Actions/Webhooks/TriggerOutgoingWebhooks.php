<?php

declare(strict_types=1);

namespace MailerPress\Actions\Webhooks;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Webhooks\WebhookManager;

/**
 * Déclenche automatiquement les webhooks sortants lors des événements
 *
 * @since 1.2.0
 */
class TriggerOutgoingWebhooks
{
    private WebhookManager $webhookManager;

    private array $sentWebhooks = []; // Cache pour éviter d'envoyer plusieurs webhooks pour le même contact

    public function __construct()
    {
        $this->webhookManager = WebhookManager::getInstance();
    }

    /**
     * Vérifie si MailerPress Pro est actif
     *
     * @return bool True si Pro est actif
     */
    private function isProActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once \ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active')
            && \is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    /**
     * Déclenche le webhook contact.created
     * Planifie l'envoi après 5 secondes via Action Scheduler pour permettre aux custom fields d'être sauvegardés
     */
    #[Action('mailerpress_contact_created', priority: 20)]
    public function onContactCreated(int $contactId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        // Planifier l'envoi du webhook dans 5 secondes via Action Scheduler
        // Cela laisse le temps aux custom fields d'être sauvegardés
        if (function_exists('as_schedule_single_action')) {
            // Annuler tout webhook en attente pour ce contact (éviter les doublons)
            as_unschedule_all_actions('mailerpress_send_contact_created_webhook', ['contact_id' => $contactId], 'mailerpress-webhooks');

            // Planifier l'envoi dans 5 secondes
            as_schedule_single_action(
                time() + 5, // 5 secondes plus tard
                'mailerpress_send_contact_created_webhook',
                ['contact_id' => $contactId],
                'mailerpress-webhooks'
            );
        } else {
            // Fallback si Action Scheduler n'est pas disponible
            // Attendre 500ms et envoyer directement
            usleep(500000); // 500ms
            $this->sendContactWebhook($contactId, 'contact.created');
        }
    }

    /**
     * Traite le webhook contact.created planifié
     * Appelé par Action Scheduler après le délai
     */
    #[Action('mailerpress_send_contact_created_webhook')]
    public function processSendContactCreatedWebhook(int $contactId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        // Vérifier combien de custom fields existent maintenant
        global $wpdb;
        $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$customFieldsTable} WHERE contact_id = %d",
            $contactId
        ));

        // Envoyer le webhook
        $this->sendContactWebhook($contactId, 'contact.created');
    }

    /**
     * Écoute les champs personnalisés ajoutés (pour logging uniquement)
     * Le webhook contact.created est maintenant envoyé via Action Scheduler avec un délai de 5 secondes
     */
    #[Action('mailerpress_contact_custom_field_added', priority: 99, acceptedArgs: 3)]
    public function onCustomFieldAdded(int $contactId, string $fieldKey, $fieldValue): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }
    }


    /**
     * Déclenche le webhook campaign.sent
     */
    #[Action('mailerpress_campaign_sent', acceptedArgs: 2)]
    public function onCampaignSent(int $campaignId, array $data = []): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        // Si $data est vide, utiliser un tableau vide pour éviter les erreurs
        if (empty($data) || !is_array($data)) {
            $data = [];
        }

        $webhookData = array_merge([
            'campaign_id' => $campaignId,
        ], $data);

        $this->webhookManager->triggerOutgoingWebhook('campaign.sent', $webhookData);
    }

    /**
     * Déclenche le webhook contact.updated
     */
    #[Action('mailerpress_contact_updated', acceptedArgs: 1)]
    public function onContactUpdated(int $contactId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        $this->sendContactWebhook($contactId, 'contact.updated');
    }

    /**
     * Déclenche le webhook contact.tag.added
     */
    #[Action('mailerpress_contact_tag_added', acceptedArgs: 2)]
    public function onContactTagAdded(int $contactId, int $tagId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }
        global $wpdb;
        $tagsTable = Tables::get(Tables::MAILERPRESS_TAGS);
        $tag = $wpdb->get_row($wpdb->prepare(
            "SELECT tag_id, name FROM {$tagsTable} WHERE tag_id = %d",
            $tagId
        ), \ARRAY_A);

        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'contact_email' => $contact['email'] ?? '',
            'tag_id' => $tagId,
            'tag_name' => $tag['name'] ?? '',
        ];

        $this->webhookManager->triggerOutgoingWebhook('contact.tag.added', $data);
    }

    /**
     * Déclenche le webhook contact.tag.removed
     */
    #[Action('mailerpress_contact_tag_removed', acceptedArgs: 2)]
    public function onContactTagRemoved(int $contactId, int $tagId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        global $wpdb;
        $tagsTable = Tables::get(Tables::MAILERPRESS_TAGS);
        $tag = $wpdb->get_row($wpdb->prepare(
            "SELECT tag_id, name FROM {$tagsTable} WHERE tag_id = %d",
            $tagId
        ), \ARRAY_A);

        // Si le tag n'existe plus dans la table tags, on utilise un nom par défaut
        $tagName = $tag['name'] ?? __('Unknown Tag', 'mailerpress');
        if (empty($tagName)) {
            $tagName = sprintf(__('Tag ID: %d', 'mailerpress'), $tagId);
        }

        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'contact_email' => $contact['email'] ?? '',
            'tag_id' => $tagId,
            'tag_name' => $tagName,
        ];

        $this->webhookManager->triggerOutgoingWebhook('contact.tag.removed', $data);
    }

    /**
     * Déclenche le webhook contact.list.added
     */
    #[Action('mailerpress_contact_list_added', acceptedArgs: 2)]
    public function onContactListAdded(int $contactId, int $listId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }
        global $wpdb;
        $listsTable = Tables::get(Tables::MAILERPRESS_LIST);
        $list = $wpdb->get_row($wpdb->prepare(
            "SELECT list_id, name FROM {$listsTable} WHERE list_id = %d",
            $listId
        ), \ARRAY_A);

        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'contact_email' => $contact['email'] ?? '',
            'list_id' => $listId,
            'list_name' => $list['name'] ?? '',
        ];

        $this->webhookManager->triggerOutgoingWebhook('contact.list.added', $data);
    }

    /**
     * Déclenche le webhook contact.list.removed
     */
    #[Action('mailerpress_contact_list_removed', acceptedArgs: 2)]
    public function onContactListRemoved(int $contactId, int $listId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }
        global $wpdb;
        $listsTable = Tables::get(Tables::MAILERPRESS_LIST);
        $list = $wpdb->get_row($wpdb->prepare(
            "SELECT list_id, name FROM {$listsTable} WHERE list_id = %d",
            $listId
        ), \ARRAY_A);

        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'contact_email' => $contact['email'] ?? '',
            'list_id' => $listId,
            'list_name' => $list['name'] ?? '',
        ];

        $this->webhookManager->triggerOutgoingWebhook('contact.list.removed', $data);
    }

    /**
     * Déclenche le webhook contact.custom_field.updated
     * Et aussi contact.updated si la configuration l'exige
     */
    #[Action('mailerpress_contact_custom_field_updated', acceptedArgs: 3)]
    public function onContactCustomFieldUpdated(int $contactId, string $fieldKey, $fieldValue): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }
        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'contact_email' => $contact['email'] ?? '',
            'field_key' => $fieldKey,
            'field_value' => $fieldValue,
        ];

        $this->webhookManager->triggerOutgoingWebhook('contact.custom_field.updated', $data);

        // NOTE: On ne déclenche PLUS automatiquement contact.updated ici
        // car cela enverrait le webhook AVANT que tous les custom fields soient mis à jour.
        // À la place, contact.updated est déclenché à la fin de edit() / editSingle() / add()
        // après que TOUTES les modifications soient complètes.
    }

    /**
     * Déclenche le webhook campaign.created
     * Planifie l'envoi via Action Scheduler avec un délai pour ne pas bloquer la création
     */
    #[Action('mailerpress_campaign_created', acceptedArgs: 1)]
    public function onCampaignCreated(int $campaignId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        // IMPORTANT: Ne pas exécuter pendant les requêtes REST API pour éviter de bloquer l'éditeur
        // On utilise wp_doing_ajax() ou defined('REST_REQUEST') pour détecter les contextes AJAX/REST
        if (defined('REST_REQUEST') && REST_REQUEST) {
            // Planifier via wp_schedule_single_event (très léger, ne bloque pas)
            wp_schedule_single_event(time() + 5, 'mailerpress_deferred_campaign_created', [$campaignId]);
            return;
        }

        // Utiliser un transient persistant pour éviter d'envoyer le webhook plusieurs fois
        // même entre différentes requêtes (ex: lors du chargement de l'éditeur)
        $transientKey = 'mailerpress_webhook_sent_campaign_created_' . $campaignId;
        if (get_transient($transientKey)) {
            return;
        }

        // Marquer comme envoyé pour les 5 prochaines minutes
        set_transient($transientKey, true, 5 * MINUTE_IN_SECONDS);

        // Planifier l'envoi du webhook dans 3 secondes via Action Scheduler
        // Cela permet de retourner immédiatement la réponse HTTP à l'éditeur
        if (function_exists('as_schedule_single_action')) {
            // Annuler tout webhook en attente pour cette campagne (éviter les doublons)
            as_unschedule_all_actions('mailerpress_send_campaign_created_webhook', ['campaign_id' => $campaignId], 'mailerpress-webhooks');

            // Planifier l'envoi dans 3 secondes
            as_schedule_single_action(
                time() + 3,
                'mailerpress_send_campaign_created_webhook',
                ['campaign_id' => $campaignId],
                'mailerpress-webhooks'
            );
        } else {
            // Fallback: envoyer directement mais de manière asynchrone
            $this->sendCampaignCreatedWebhook($campaignId);
        }
    }

    /**
     * Traite le webhook campaign.created planifié
     * Appelé par Action Scheduler après le délai
     */
    #[Action('mailerpress_send_campaign_created_webhook')]
    public function processSendCampaignCreatedWebhook(int $campaignId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        $this->sendCampaignCreatedWebhook($campaignId);
    }

    /**
     * Traite le webhook campaign.created différé via wp-cron
     * Appelé par wp-cron après le délai pour éviter de bloquer les requêtes REST
     */
    #[Action('mailerpress_deferred_campaign_created')]
    public function processDeferredCampaignCreated(int $campaignId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        // Vérifier le transient ici aussi pour éviter les doublons
        $transientKey = 'mailerpress_webhook_sent_campaign_created_' . $campaignId;
        if (get_transient($transientKey)) {
            return;
        }

        // Marquer comme envoyé
        set_transient($transientKey, true, 5 * MINUTE_IN_SECONDS);

        $this->sendCampaignCreatedWebhook($campaignId);
    }

    /**
     * Envoie le webhook campaign.created
     */
    private function sendCampaignCreatedWebhook(int $campaignId): void
    {
        global $wpdb;
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT campaign_id, name, subject, status, campaign_type, created_at FROM {$campaignsTable} WHERE campaign_id = %d",
            intval($campaignId)
        ), \ARRAY_A);

        if (!$campaign) {
            return;
        }

        $data = [
            'campaign_id' => $campaignId,
            'campaign_name' => $campaign['name'] ?? '',
            'campaign_subject' => $campaign['subject'] ?? '',
            'status' => $campaign['status'] ?? '',
            'campaign_type' => $campaign['campaign_type'] ?? '',
            'created_at' => $campaign['created_at'] ?? current_time('mysql'),
        ];

        // Envoyer le webhook (async par défaut)
        $this->webhookManager->triggerOutgoingWebhook('campaign.created', $data, true);
    }

    /**
     * Déclenche le webhook campaign.scheduled
     */
    #[Action('mailerpress_campaign_scheduled', acceptedArgs: 2)]
    public function onCampaignScheduled(int $campaignId, array $data = []): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        global $wpdb;
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT campaign_id, name, subject, status, campaign_type, created_at FROM {$campaignsTable} WHERE campaign_id = %d",
            intval($campaignId)
        ), \ARRAY_A);

        if (!$campaign) {
            return;
        }

        // Convertir scheduled_time (timestamp) en format date lisible si présent
        $scheduledAt = '';
        if (isset($data['scheduled_time']) && is_numeric($data['scheduled_time'])) {
            $scheduledAt = date('Y-m-d H:i:s', (int)$data['scheduled_time']);
        }

        // Fusionner les données de la campagne avec les données supplémentaires fournies
        $webhookData = array_merge([
            'campaign_id' => $campaignId,
            'campaign_name' => $campaign['name'] ?? '',
            'campaign_subject' => $campaign['subject'] ?? '',
            'status' => $campaign['status'] ?? '',
            'campaign_type' => $campaign['campaign_type'] ?? '',
            'created_at' => $campaign['created_at'] ?? current_time('mysql'),
            'scheduled_at' => $scheduledAt,
        ], $data);

        $this->webhookManager->triggerOutgoingWebhook('campaign.scheduled', $webhookData);
    }

    /**
     * Déclenche le webhook campaign.in_progress
     */
    #[Action('mailerpress_batch_event', priority: 20, acceptedArgs: 3)]
    public function onBatchEvent(string $status, string $campaignId, string $batchId): void
    {
        // Vérifier que Pro est actif
        if (!$this->isProActive()) {
            return;
        }

        if ($status === 'in_progress') {
            global $wpdb;
            $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
            $batchesTable = Tables::get(Tables::MAILERPRESS_EMAIL_BATCHES);

            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT campaign_id, name, subject FROM {$campaignsTable} WHERE campaign_id = %d",
                (int) $campaignId
            ), \ARRAY_A);

            $batch = $wpdb->get_row($wpdb->prepare(
                "SELECT id, total_emails, sent_emails, error_emails FROM {$batchesTable} WHERE id = %d",
                (int) $batchId
            ), \ARRAY_A);

            if (!$campaign || !$batch) {
                return;
            }

            $data = [
                'campaign_id' => (int) $campaignId,
                'campaign_name' => $campaign['name'] ?? '',
                'campaign_subject' => $campaign['subject'] ?? '',
                'batch_id' => (int) $batchId,
                'total_emails' => isset($batch['total_emails']) ? (int) $batch['total_emails'] : 0,
                'sent_emails' => isset($batch['sent_emails']) ? (int) $batch['sent_emails'] : 0,
                'error_emails' => isset($batch['error_emails']) ? (int) $batch['error_emails'] : 0,
                'progress_percentage' => isset($batch['total_emails']) && $batch['total_emails'] > 0
                    ? round((($batch['sent_emails'] ?? 0) / $batch['total_emails']) * 100, 2)
                    : 0,
            ];

            $this->webhookManager->triggerOutgoingWebhook('campaign.in_progress', $data);
        }
    }

    /**
     * Déclenche le webhook contact.deleted
     */
    #[Action('mailerpress_contact_deleted', acceptedArgs: 4)]
    public function onContactDeleted(int $contactId, string $email = '', string $firstName = '', string $lastName = ''): void
    {
        if (!$this->isProActive()) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'deleted_at' => current_time('mysql'),
        ];

        $this->webhookManager->triggerOutgoingWebhook('contact.deleted', $data);
    }

    /**
     * Déclenche le webhook contact.unsubscribed
     */
    #[Action('mailerpress_contact_unsubscribed', acceptedArgs: 1)]
    public function onContactUnsubscribed(int $contactId): void
    {
        if (!$this->isProActive()) {
            return;
        }

        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'email' => $contact['email'] ?? '',
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'unsubscribed_at' => current_time('mysql'),
        ];

        $this->webhookManager->triggerOutgoingWebhook('contact.unsubscribed', $data);
    }

    /**
     * Déclenche le webhook subscription.confirmed
     */
    #[Action('mailerpress_subscription_confirmed', priority: 20, acceptedArgs: 1)]
    public function onSubscriptionConfirmed(int $contactId): void
    {
        if (!$this->isProActive()) {
            return;
        }

        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'email' => $contact['email'] ?? '',
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'confirmed_at' => current_time('mysql'),
        ];

        $this->webhookManager->triggerOutgoingWebhook('subscription.confirmed', $data);
    }

    /**
     * Déclenche le webhook email.opened
     */
    #[Action('mailerpress_email_opened', acceptedArgs: 3)]
    public function onEmailOpened(int $contactId, int $campaignId, ?int $batchId = null): void
    {
        if (!$this->isProActive()) {
            return;
        }

        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        global $wpdb;
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT campaign_id, name, subject FROM {$campaignsTable} WHERE campaign_id = %d",
            $campaignId
        ), \ARRAY_A);

        $data = [
            'contact_id' => $contactId,
            'contact_email' => $contact['email'] ?? '',
            'campaign_id' => $campaignId,
            'campaign_name' => $campaign['name'] ?? '',
            'campaign_subject' => $campaign['subject'] ?? '',
            'batch_id' => $batchId,
            'opened_at' => current_time('mysql'),
        ];

        $this->webhookManager->triggerOutgoingWebhook('email.opened', $data);
    }

    /**
     * Déclenche le webhook email.clicked
     */
    #[Action('mailerpress_email_clicked', acceptedArgs: 3)]
    public function onEmailClicked(int $contactId, int $campaignId, string $url = ''): void
    {
        if (!$this->isProActive()) {
            return;
        }

        $contact = $this->getContactBasicData($contactId);
        if (!$contact) {
            return;
        }

        global $wpdb;
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT campaign_id, name, subject FROM {$campaignsTable} WHERE campaign_id = %d",
            $campaignId
        ), \ARRAY_A);

        $data = [
            'contact_id' => $contactId,
            'contact_email' => $contact['email'] ?? '',
            'campaign_id' => $campaignId,
            'campaign_name' => $campaign['name'] ?? '',
            'campaign_subject' => $campaign['subject'] ?? '',
            'url' => $url,
            'clicked_at' => current_time('mysql'),
        ];

        $this->webhookManager->triggerOutgoingWebhook('email.clicked', $data);
    }

    /**
     * Déclenche le webhook email.bounced
     */
    #[Action('mailerpress_email_bounced', acceptedArgs: 2)]
    public function onEmailBounced(int $contactId, string $email = ''): void
    {
        if (!$this->isProActive()) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'email' => $email,
            'bounced_at' => current_time('mysql'),
            'bounce_type' => 'hard',
        ];

        $this->webhookManager->triggerOutgoingWebhook('email.bounced', $data);
    }

    /**
     * Déclenche le webhook email.complained
     */
    #[Action('mailerpress_email_complained', acceptedArgs: 2)]
    public function onEmailComplained(int $contactId, string $email = ''): void
    {
        if (!$this->isProActive()) {
            return;
        }

        $data = [
            'contact_id' => $contactId,
            'email' => $email,
            'complained_at' => current_time('mysql'),
        ];

        $this->webhookManager->triggerOutgoingWebhook('email.complained', $data);
    }

    /**
     * Récupère les données de base d'un contact
     */
    private function getContactBasicData(int $contactId): ?array
    {
        global $wpdb;
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        $contact = $wpdb->get_row($wpdb->prepare(
            "SELECT contact_id, email, first_name, last_name, subscription_status FROM {$contactTable} WHERE contact_id = %d",
            $contactId
        ), \ARRAY_A);

        return $contact ?: null;
    }

    /**
     * Envoie le webhook contact avec toutes les données du contact
     * @param int $contactId
     * @param string $eventType Type d'événement (contact.created ou contact.updated)
     */
    private function sendContactWebhook(int $contactId, string $eventType = 'contact.created'): void
    {
        // Vérifier si ce webhook a déjà été envoyé dans cette requête (éviter les doublons)
        $cacheKey = $eventType . '_' . $contactId;
        if (isset($this->sentWebhooks[$cacheKey])) {
            return;
        }

        // Marquer comme envoyé
        $this->sentWebhooks[$cacheKey] = true;

        // Récupérer les données du contact
        global $wpdb;
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);

        $contact = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$contactTable} WHERE contact_id = %d",
            $contactId
        ), \ARRAY_A);

        if (!$contact) {
            return;
        }

        // Récupérer les tags du contact
        $contactTagsTable = Tables::get(Tables::CONTACT_TAGS);
        $tagsTable = Tables::get(Tables::MAILERPRESS_TAGS);
        $tags = $wpdb->get_results($wpdb->prepare("
            SELECT t.tag_id, t.name as tag_name
            FROM {$contactTagsTable} ct
            INNER JOIN {$tagsTable} t ON ct.tag_id = t.tag_id
            WHERE ct.contact_id = %d
        ", $contactId), \ARRAY_A);

        $tagsData = [];
        foreach ($tags as $tag) {
            $tagsData[] = [
                'tag_id' => (int) $tag['tag_id'],
                'tag_name' => $tag['tag_name'],
            ];
        }

        // Récupérer les listes du contact
        $contactListsTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        $listsTable = Tables::get(Tables::MAILERPRESS_LIST);
        $lists = $wpdb->get_results($wpdb->prepare("
            SELECT l.list_id, l.name as list_name
            FROM {$contactListsTable} cl
            INNER JOIN {$listsTable} l ON cl.list_id = l.list_id
            WHERE cl.contact_id = %d
        ", $contactId), \ARRAY_A);

        $listsData = [];
        foreach ($lists as $list) {
            $listsData[] = [
                'list_id' => (int) $list['list_id'],
                'list_name' => $list['list_name'],
            ];
        }

        // Récupérer les champs personnalisés du contact
        $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);

        $customFields = $wpdb->get_results($wpdb->prepare("
            SELECT field_key, field_value
            FROM {$customFieldsTable}
            WHERE contact_id = %d
        ", $contactId), \ARRAY_A);

        $customFieldsData = [];
        foreach ($customFields as $field) {
            // Désérialiser la valeur si nécessaire
            $value = is_serialized($field['field_value'])
                ? unserialize($field['field_value'], ['allowed_classes' => false])
                : $field['field_value'];
            $customFieldsData[$field['field_key']] = $value;
        }

        // Préparer les données pour le webhook
        $data = [
            'contact_id' => $contactId,
            'email' => $contact['email'] ?? '',
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'subscription_status' => $contact['subscription_status'] ?? '',
            'created_at' => $contact['created_at'] ?? '',
            'updated_at' => $contact['updated_at'] ?? '',
            'tags' => $tagsData,
            'lists' => $listsData,
            'custom_fields' => $customFieldsData,
        ];

        $this->webhookManager->triggerOutgoingWebhook($eventType, $data);
    }
}
