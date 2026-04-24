<?php

declare(strict_types=1);

namespace MailerPress\Core\Webhooks;

\defined('ABSPATH') || exit;

/**
 * Gestionnaire des handlers pour les webhooks entrants
 *
 * Permet d'enregistrer et d'exécuter des actions personnalisées
 * lorsqu'un webhook est reçu, au-delà des workflows.
 *
 * @since 1.2.0
 */
class IncomingWebhookHandlerManager
{
    private array $handlers = [];
    private static ?IncomingWebhookHandlerManager $instance = null;

    public function __construct()
    {
        $this->registerDefaultHandlers();
    }

    /**
     * Instance singleton
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Enregistre un handler personnalisé
     *
     * @param string $handlerId Identifiant unique du handler
     * @param callable $handler Fonction à exécuter (reçoit $webhookId, $payload, $request)
     * @param int $priority Priorité d'exécution (plus bas = exécuté en premier)
     * @return void
     */
    public function registerHandler(string $handlerId, callable $handler, int $priority = 10): void
    {
        if (!isset($this->handlers[$priority])) {
            $this->handlers[$priority] = [];
        }

        $this->handlers[$priority][$handlerId] = $handler;

        // Trier par priorité
        ksort($this->handlers);
    }

    /**
     * Désenregistre un handler
     *
     * @param string $handlerId Identifiant du handler
     * @return void
     */
    public function unregisterHandler(string $handlerId): void
    {
        foreach ($this->handlers as $priority => &$handlers) {
            if (isset($handlers[$handlerId])) {
                unset($handlers[$handlerId]);
                if (empty($handlers)) {
                    unset($this->handlers[$priority]);
                }
                break;
            }
        }
    }

    /**
     * Exécute tous les handlers enregistrés pour un webhook
     *
     * @param string $webhookId Identifiant du webhook
     * @param array $payload Données du webhook
     * @param mixed $request Objet de requête REST
     * @return array Résultats de l'exécution de chaque handler
     */
    public function executeHandlers(string $webhookId, array $payload, $request = null): array
    {
        $results = [];

        // Exécuter les handlers par ordre de priorité
        foreach ($this->handlers as $priority => $handlers) {
            foreach ($handlers as $handlerId => $handler) {
                try {
                    $result = call_user_func($handler, $webhookId, $payload, $request);
                    $results[$handlerId] = [
                        'success' => true,
                        'result' => $result,
                    ];
                } catch (\Exception $e) {
                    $results[$handlerId] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Récupère la liste des handlers disponibles
     *
     * @return array Liste des handlers avec leurs métadonnées
     */
    public function getAvailableHandlers(): array
    {
        $handlers = [];

        foreach ($this->handlers as $priority => $priorityHandlers) {
            foreach ($priorityHandlers as $handlerId => $handler) {
                $handlers[] = [
                    'id' => $handlerId,
                    'priority' => $priority,
                ];
            }
        }

        return $handlers;
    }

    /**
     * Vérifie si un handler est enregistré
     *
     * @param string $handlerId Identifiant du handler
     * @return bool
     */
    public function isHandlerRegistered(string $handlerId): bool
    {
        foreach ($this->handlers as $priorityHandlers) {
            if (isset($priorityHandlers[$handlerId])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exécute un handler spécifique
     *
     * @param string $handlerId Identifiant du handler
     * @param string $webhookId Identifiant du webhook
     * @param array $payload Données du webhook
     * @param mixed $request Objet de requête REST
     * @return mixed Résultat de l'exécution
     */
    public function executeHandler(string $handlerId, string $webhookId, array $payload, $request = null)
    {
        foreach ($this->handlers as $priorityHandlers) {
            if (isset($priorityHandlers[$handlerId])) {
                return call_user_func($priorityHandlers[$handlerId], $webhookId, $payload, $request);
            }
        }

        throw new \Exception("Handler '{$handlerId}' not found");
    }

    /**
     * Récupère les informations sur les handlers par défaut
     *
     * @return array Liste des handlers avec leurs descriptions
     */
    public function getDefaultHandlersInfo(): array
    {
        return [
            'create_contact' => [
                'id' => 'create_contact',
                'label' => __('Create Contact', 'mailerpress'),
                'description' => __('Creates a new contact from webhook data. Supports: email, first_name, last_name, subscription_status, tags (array of IDs/names), lists (array of IDs/names), custom_fields object, or cpt:field_key prefix. Respects the double opt-in configuration: if enabled and subscription_status is not provided, contact will be created as "pending" and confirmation email will be sent.', 'mailerpress'),
                'required_fields' => ['email'],
            ],
            'update_contact' => [
                'id' => 'update_contact',
                'label' => __('Update Contact', 'mailerpress'),
                'description' => __('Updates an existing contact. Supports: email, first_name, last_name, subscription_status, tags (array of IDs/names), lists (array of IDs/names), custom_fields object, or cpt:field_key prefix', 'mailerpress'),
                'required_fields' => ['email'],
            ],
            'add_tag' => [
                'id' => 'add_tag',
                'label' => __('Add Tag', 'mailerpress'),
                'description' => __('Adds a tag to a contact. Requires: email, tag or tag_name', 'mailerpress'),
                'required_fields' => ['email', 'tag'],
            ],
            'add_to_list' => [
                'id' => 'add_to_list',
                'label' => __('Add to List', 'mailerpress'),
                'description' => __('Adds a contact to a list. Requires: email, list or list_name', 'mailerpress'),
                'required_fields' => ['email', 'list'],
            ],
        ];
    }

    /**
     * Enregistre les handlers par défaut
     *
     * @return void
     */
    private function registerDefaultHandlers(): void
    {
        // Handler pour créer un contact
        $this->registerHandler('create_contact', function ($webhookId, $payload, $request) {
            $email = $payload['email'] ?? $payload['customer_email'] ?? $payload['user_email'] ?? '';

            if (empty($email) || !is_email($email)) {
                return ['success' => false, 'error' => 'Email is required and must be valid'];
            }

            $contactsModel = new \MailerPress\Models\Contacts();
            $existingContact = $contactsModel->getContactByEmail($email);

            if ($existingContact) {
                return ['success' => false, 'error' => 'Contact already exists', 'contact_id' => $existingContact->contact_id];
            }

            global $wpdb;
            $contactTable = $wpdb->prefix . 'mailerpress_contact';
            $contactCustomFieldsTable = $wpdb->prefix . 'mailerpress_contact_custom_fields';

            // Générer les tokens nécessaires pour le double opt-in
            $unsubscribe_token = wp_generate_uuid4();
            $access_token = bin2hex(random_bytes(32));

            // Vérifier l'option de double opt-in
            $signupConfirmationOption = get_option('mailerpress_signup_confirmation', wp_json_encode([
                'enableSignupConfirmation' => true
            ]));

            if (is_string($signupConfirmationOption)) {
                $signupConfirmationOption = json_decode($signupConfirmationOption, true);
            }

            // Déterminer le statut d'abonnement
            // Si subscription_status est explicitement fourni dans le payload, l'utiliser
            // Sinon, respecter la configuration du double opt-in
            $optInSource = $webhookId;
            $subscriptionStatus = $payload['subscription_status'] ?? null;

            if ($subscriptionStatus === null) {
                // Pas de statut explicite : respecter la configuration du double opt-in
                if (!empty($signupConfirmationOption) && true === ($signupConfirmationOption['enableSignupConfirmation'] ?? false)) {
                    // Double opt-in activé : créer le contact en "pending"
                    $subscriptionStatus = 'pending';
                } else {
                    // Double opt-in désactivé : créer directement en "subscribed"
                    $subscriptionStatus = 'subscribed';
                }
            } else {
                // Statut explicite fourni : l'utiliser tel quel
                $subscriptionStatus = sanitize_text_field($subscriptionStatus);
            }

            $contactData = [
                'email' => sanitize_email($email),
                'first_name' => sanitize_text_field($payload['first_name'] ?? $payload['customer_first_name'] ?? ''),
                'last_name' => sanitize_text_field($payload['last_name'] ?? $payload['customer_last_name'] ?? ''),
                'subscription_status' => $subscriptionStatus,
                'opt_in_source' => $optInSource,
                'unsubscribe_token' => $unsubscribe_token,
                'access_token' => $access_token,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ];

            $wpdb->insert($contactTable, $contactData);
            $contactId = (int) $wpdb->insert_id;

            // Gérer les champs personnalisés
            // Support de deux formats :
            // 1. Objet custom_fields : { "custom_fields": { "phone": "123" } }
            // 2. Préfixe cpt: : { "cpt:phone": "123" }
            $customFields = [];

            // Récupérer les champs depuis l'objet custom_fields
            if (!empty($payload['custom_fields']) && is_array($payload['custom_fields'])) {
                $customFields = array_merge($customFields, $payload['custom_fields']);
            }

            // Récupérer les champs avec le préfixe cpt:
            foreach ($payload as $key => $value) {
                if (strpos($key, 'cpt:') === 0) {
                    $field_key = substr($key, 4); // Retirer le préfixe "cpt:"
                    if (!empty($field_key)) {
                        $customFields[$field_key] = $value;
                    }
                }
            }

            // Traiter les champs personnalisés
            if (!empty($customFields) && is_array($customFields)) {
                $standardFields = ['email', 'first_name', 'last_name', 'created_at', 'updated_at'];

                foreach ($customFields as $field_key => $field_value) {
                    // Ignorer les champs standards (ne doivent pas être dans custom_fields)
                    if (in_array($field_key, $standardFields, true)) {
                        continue;
                    }

                    // Vérifier que le champ existe dans les définitions
                    $fieldDefinition = (new \MailerPress\Models\CustomFields())->getByKey($field_key);
                    if (!$fieldDefinition) {
                        // Ignorer les champs qui n'existent pas dans les définitions
                        continue;
                    }

                    // Sanitizer la valeur selon le type de champ
                    $sanitized_value = \MailerPress\Models\CustomFields::sanitizeValue($field_key, $field_value);

                    // Ignorer les valeurs null (vides ou invalides)
                    if ($sanitized_value === null) {
                        continue;
                    }

                    // Convertir en string pour le stockage en base de données
                    $db_value = is_numeric($sanitized_value)
                        ? (string) $sanitized_value
                        : sanitize_text_field((string) $sanitized_value);

                    $wpdb->insert($contactCustomFieldsTable, [
                        'contact_id' => $contactId,
                        'field_key' => sanitize_text_field($field_key),
                        'field_value' => $db_value,
                    ]);

                    // Déclencher l'action pour notifier que le champ a été ajouté
                    do_action('mailerpress_contact_custom_field_added', $contactId, sanitize_text_field($field_key), $sanitized_value);
                }
            }

            // Gérer les tags
            if (!empty($payload['tags']) && is_array($payload['tags'])) {
                $tagsTable = $wpdb->prefix . 'mailerpress_tags';
                $contactTagsTable = $wpdb->prefix . 'mailerpress_contact_tags';

                foreach ($payload['tags'] as $tagData) {
                    $tagId = null;
                    $tagName = null;

                    // Support de plusieurs formats : ID simple, nom simple, ou objet {id: X, name: Y}
                    if (is_numeric($tagData)) {
                        $tagId = (int) $tagData;
                    } elseif (is_string($tagData)) {
                        $tagName = $tagData;
                    } elseif (is_array($tagData)) {
                        $tagId = isset($tagData['id']) ? (int) $tagData['id'] : null;
                        $tagName = $tagData['name'] ?? null;
                    }

                    // Si on a un nom mais pas d'ID, chercher ou créer le tag
                    if (empty($tagId) && !empty($tagName)) {
                        $tag = $wpdb->get_row($wpdb->prepare(
                            "SELECT tag_id FROM {$tagsTable} WHERE name = %s",
                            $tagName
                        ));

                        if (!$tag) {
                            $wpdb->insert($tagsTable, [
                                'name' => sanitize_text_field($tagName),
                                'created_at' => current_time('mysql'),
                            ]);
                            $tagId = (int) $wpdb->insert_id;
                        } else {
                            $tagId = (int) $tag->tag_id;
                        }
                    }

                    // Ajouter le tag au contact s'il n'existe pas déjà
                    if (!empty($tagId)) {
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$contactTagsTable} WHERE contact_id = %d AND tag_id = %d",
                            $contactId,
                            $tagId
                        ));

                        if (!$existing) {
                            $wpdb->insert($contactTagsTable, [
                                'contact_id' => $contactId,
                                'tag_id' => $tagId,
                            ]);
                        }
                    }
                }
            }

            // Gérer les listes
            $listsToProcess = [];

            if (!empty($payload['lists']) && is_array($payload['lists'])) {
                $listsToProcess = $payload['lists'];
            } else {
                // Aucune liste fournie : utiliser la liste par défaut si elle existe
                $listsTable = $wpdb->prefix . 'mailerpress_lists';
                $defaultListId = $wpdb->get_var("SELECT list_id FROM {$listsTable} WHERE is_default = 1 LIMIT 1");

                if ($defaultListId) {
                    $listsToProcess = [(int) $defaultListId];
                }
            }

            if (!empty($listsToProcess)) {
                $listsTable = $wpdb->prefix . 'mailerpress_lists';
                $contactListsTable = $wpdb->prefix . 'mailerpress_contact_lists';

                foreach ($listsToProcess as $listData) {
                    $listId = null;
                    $listName = null;

                    // Support de plusieurs formats : ID simple, nom simple, ou objet {id: X, name: Y}
                    if (is_numeric($listData)) {
                        $listId = (int) $listData;
                    } elseif (is_string($listData)) {
                        $listName = $listData;
                    } elseif (is_array($listData)) {
                        $listId = isset($listData['id']) ? (int) $listData['id'] : null;
                        $listName = $listData['name'] ?? null;
                    }

                    // Si on a un nom mais pas d'ID, chercher ou créer la liste
                    if (empty($listId) && !empty($listName)) {
                        $list = $wpdb->get_row($wpdb->prepare(
                            "SELECT list_id FROM {$listsTable} WHERE name = %s",
                            $listName
                        ));

                        if (!$list) {
                            $wpdb->insert($listsTable, [
                                'name' => sanitize_text_field($listName),
                                'created_at' => current_time('mysql'),
                            ]);
                            $listId = (int) $wpdb->insert_id;
                        } else {
                            $listId = (int) $list->list_id;
                        }
                    }

                    // Ajouter le contact à la liste s'il n'y est pas déjà
                    if (!empty($listId)) {
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$contactListsTable} WHERE contact_id = %d AND list_id = %d",
                            $contactId,
                            $listId
                        ));

                        if (!$existing) {
                            $wpdb->insert($contactListsTable, [
                                'contact_id' => $contactId,
                                'list_id' => $listId,
                            ]);
                        }
                    }
                }
            }

            // Déclencher le hook WordPress pour l'envoi du double opt-in
            // L'Action ContactCreated écoutera ce hook et enverra l'email si nécessaire
            do_action('mailerpress_contact_created', $contactId);

            return [
                'success' => true,
                'contact_id' => $contactId,
                'subscription_status' => $subscriptionStatus,
                'double_optin_email_sent' => $subscriptionStatus === 'pending'
            ];
        }, 10);

        // Handler pour mettre à jour un contact
        $this->registerHandler('update_contact', function ($webhookId, $payload, $request) {
            $email = $payload['email'] ?? $payload['customer_email'] ?? $payload['user_email'] ?? '';

            if (empty($email) || !is_email($email)) {
                return ['success' => false, 'error' => 'Email is required and must be valid'];
            }

            $contactsModel = new \MailerPress\Models\Contacts();
            $contact = $contactsModel->getContactByEmail($email);

            if (!$contact) {
                return ['success' => false, 'error' => 'Contact not found'];
            }

            global $wpdb;
            $contactTable = $wpdb->prefix . 'mailerpress_contact';
            $contactCustomFieldsTable = $wpdb->prefix . 'mailerpress_contact_custom_fields';

            $updateData = [];
            if (isset($payload['first_name']) || isset($payload['customer_first_name'])) {
                $updateData['first_name'] = sanitize_text_field($payload['first_name'] ?? $payload['customer_first_name'] ?? '');
            }
            if (isset($payload['last_name']) || isset($payload['customer_last_name'])) {
                $updateData['last_name'] = sanitize_text_field($payload['last_name'] ?? $payload['customer_last_name'] ?? '');
            }
            if (isset($payload['subscription_status'])) {
                $updateData['subscription_status'] = sanitize_text_field($payload['subscription_status']);
            }

            if (!empty($updateData)) {
                $updateData['updated_at'] = current_time('mysql');
                $wpdb->update(
                    $contactTable,
                    $updateData,
                    ['contact_id' => $contact->contact_id]
                );
            }

            // Gérer les champs personnalisés
            // Support de deux formats :
            // 1. Objet custom_fields : { "custom_fields": { "phone": "123" } }
            // 2. Préfixe cpt: : { "cpt:phone": "123" }
            $customFields = [];

            // Récupérer les champs depuis l'objet custom_fields
            if (!empty($payload['custom_fields']) && is_array($payload['custom_fields'])) {
                $customFields = array_merge($customFields, $payload['custom_fields']);
            }

            // Récupérer les champs avec le préfixe cpt:
            foreach ($payload as $key => $value) {
                if (strpos($key, 'cpt:') === 0) {
                    $field_key = substr($key, 4); // Retirer le préfixe "cpt:"
                    if (!empty($field_key)) {
                        $customFields[$field_key] = $value;
                    }
                }
            }

            // Traiter les champs personnalisés
            if (!empty($customFields) && is_array($customFields)) {
                $standardFields = ['email', 'first_name', 'last_name', 'created_at', 'updated_at'];

                foreach ($customFields as $field_key => $field_value) {
                    // Ignorer les champs standards (ne doivent pas être dans custom_fields)
                    if (in_array($field_key, $standardFields, true)) {
                        continue;
                    }

                    // Vérifier que le champ existe dans les définitions
                    $fieldDefinition = (new \MailerPress\Models\CustomFields())->getByKey($field_key);
                    if (!$fieldDefinition) {
                        // Ignorer les champs qui n'existent pas dans les définitions
                        continue;
                    }

                    // Vérifier si le champ existe déjà
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT field_id FROM {$contactCustomFieldsTable} WHERE contact_id = %d AND field_key = %s LIMIT 1",
                        $contact->contact_id,
                        $field_key
                    ));

                    // Sanitizer la valeur selon le type de champ
                    $sanitized_value = \MailerPress\Models\CustomFields::sanitizeValue($field_key, $field_value);

                    // Si la valeur est null (vide ou invalide), supprimer le champ existant
                    if ($sanitized_value === null) {
                        if ($existing) {
                            $wpdb->delete($contactCustomFieldsTable, ['field_id' => $existing]);
                        }
                        continue;
                    }

                    // Convertir en string pour le stockage en base de données
                    $db_value = is_numeric($sanitized_value)
                        ? (string) $sanitized_value
                        : sanitize_text_field((string) $sanitized_value);

                    if ($existing) {
                        // Mettre à jour le champ existant
                        $wpdb->update(
                            $contactCustomFieldsTable,
                            ['field_value' => $db_value],
                            ['field_id' => $existing]
                        );
                        // Déclencher l'action pour notifier que le champ a été mis à jour
                        do_action('mailerpress_contact_custom_field_updated', $contact->contact_id, sanitize_text_field($field_key), $sanitized_value);
                    } else {
                        // Créer un nouveau champ
                        $wpdb->insert($contactCustomFieldsTable, [
                            'contact_id' => $contact->contact_id,
                            'field_key' => sanitize_text_field($field_key),
                            'field_value' => $db_value,
                        ]);
                        // Déclencher l'action pour notifier que le champ a été ajouté
                        do_action('mailerpress_contact_custom_field_added', $contact->contact_id, sanitize_text_field($field_key), $sanitized_value);
                    }
                }
            }

            // Gérer les tags
            if (!empty($payload['tags']) && is_array($payload['tags'])) {
                $tagsTable = $wpdb->prefix . 'mailerpress_tags';
                $contactTagsTable = $wpdb->prefix . 'mailerpress_contact_tags';

                foreach ($payload['tags'] as $tagData) {
                    $tagId = null;
                    $tagName = null;

                    // Support de plusieurs formats : ID simple, nom simple, ou objet {id: X, name: Y}
                    if (is_numeric($tagData)) {
                        $tagId = (int) $tagData;
                    } elseif (is_string($tagData)) {
                        $tagName = $tagData;
                    } elseif (is_array($tagData)) {
                        $tagId = isset($tagData['id']) ? (int) $tagData['id'] : null;
                        $tagName = $tagData['name'] ?? null;
                    }

                    // Si on a un nom mais pas d'ID, chercher ou créer le tag
                    if (empty($tagId) && !empty($tagName)) {
                        $tag = $wpdb->get_row($wpdb->prepare(
                            "SELECT tag_id FROM {$tagsTable} WHERE name = %s",
                            $tagName
                        ));

                        if (!$tag) {
                            $wpdb->insert($tagsTable, [
                                'name' => sanitize_text_field($tagName),
                                'created_at' => current_time('mysql'),
                            ]);
                            $tagId = (int) $wpdb->insert_id;
                        } else {
                            $tagId = (int) $tag->tag_id;
                        }
                    }

                    // Ajouter le tag au contact s'il n'existe pas déjà
                    if (!empty($tagId)) {
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$contactTagsTable} WHERE contact_id = %d AND tag_id = %d",
                            $contact->contact_id,
                            $tagId
                        ));

                        if (!$existing) {
                            $wpdb->insert($contactTagsTable, [
                                'contact_id' => $contact->contact_id,
                                'tag_id' => $tagId,
                            ]);
                        }
                    }
                }
            }

            // Gérer les listes
            $listsToProcess = [];

            if (!empty($payload['lists']) && is_array($payload['lists'])) {
                $listsToProcess = $payload['lists'];
            } else {
                // Aucune liste fournie : vérifier si le contact est dans au moins une liste
                $contactListsTable = $wpdb->prefix . 'mailerpress_contact_lists';
                $hasLists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$contactListsTable} WHERE contact_id = %d",
                    $contact->contact_id
                ));

                // Si le contact n'est dans aucune liste, utiliser la liste par défaut
                if (!$hasLists) {
                    $listsTable = $wpdb->prefix . 'mailerpress_lists';
                    $defaultListId = $wpdb->get_var("SELECT list_id FROM {$listsTable} WHERE is_default = 1 LIMIT 1");

                    if ($defaultListId) {
                        $listsToProcess = [(int) $defaultListId];
                    }
                }
            }

            if (!empty($listsToProcess)) {
                $listsTable = $wpdb->prefix . 'mailerpress_lists';
                $contactListsTable = $wpdb->prefix . 'mailerpress_contact_lists';

                foreach ($listsToProcess as $listData) {
                    $listId = null;
                    $listName = null;

                    // Support de plusieurs formats : ID simple, nom simple, ou objet {id: X, name: Y}
                    if (is_numeric($listData)) {
                        $listId = (int) $listData;
                    } elseif (is_string($listData)) {
                        $listName = $listData;
                    } elseif (is_array($listData)) {
                        $listId = isset($listData['id']) ? (int) $listData['id'] : null;
                        $listName = $listData['name'] ?? null;
                    }

                    // Si on a un nom mais pas d'ID, chercher ou créer la liste
                    if (empty($listId) && !empty($listName)) {
                        $list = $wpdb->get_row($wpdb->prepare(
                            "SELECT list_id FROM {$listsTable} WHERE name = %s",
                            $listName
                        ));

                        if (!$list) {
                            $wpdb->insert($listsTable, [
                                'name' => sanitize_text_field($listName),
                                'created_at' => current_time('mysql'),
                            ]);
                            $listId = (int) $wpdb->insert_id;
                        } else {
                            $listId = (int) $list->list_id;
                        }
                    }

                    // Ajouter le contact à la liste s'il n'y est pas déjà
                    if (!empty($listId)) {
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$contactListsTable} WHERE contact_id = %d AND list_id = %d",
                            $contact->contact_id,
                            $listId
                        ));

                        if (!$existing) {
                            $wpdb->insert($contactListsTable, [
                                'contact_id' => $contact->contact_id,
                                'list_id' => $listId,
                            ]);
                        }
                    }
                }
            }

            return ['success' => true, 'contact_id' => $contact->contact_id];
        }, 20);

        // Handler pour ajouter un tag
        $this->registerHandler('add_tag', function ($webhookId, $payload, $request) {
            $email = $payload['email'] ?? $payload['customer_email'] ?? $payload['user_email'] ?? '';
            $handlerConfig = $payload['_handler_config'] ?? [];

            // Utiliser tag_id configuré si disponible, sinon utiliser tag_name du payload
            $tagId = null;
            $tagName = null;

            if (!empty($handlerConfig['tag_id'])) {
                $tagId = (int) $handlerConfig['tag_id'];
            } else {
                $tagName = $payload['tag'] ?? $payload['tag_name'] ?? '';
            }

            if (empty($email) || !is_email($email)) {
                return ['success' => false, 'error' => 'Email is required and must be valid'];
            }

            if (empty($tagId) && empty($tagName)) {
                return ['success' => false, 'error' => 'Tag ID or tag name is required'];
            }

            $contactsModel = new \MailerPress\Models\Contacts();
            $contact = $contactsModel->getContactByEmail($email);

            if (!$contact) {
                return ['success' => false, 'error' => 'Contact not found'];
            }

            global $wpdb;
            $tagsTable = $wpdb->prefix . 'mailerpress_tags';
            $contactTagsTable = $wpdb->prefix . 'mailerpress_contact_tags';

            // Si tag_id n'est pas fourni, chercher ou créer le tag par nom
            if (empty($tagId)) {
                $tag = $wpdb->get_row($wpdb->prepare(
                    "SELECT tag_id FROM {$tagsTable} WHERE name = %s",
                    $tagName
                ));

                if (!$tag) {
                    $wpdb->insert($tagsTable, [
                        'name' => sanitize_text_field($tagName),
                        'created_at' => current_time('mysql'),
                    ]);
                    $tagId = (int) $wpdb->insert_id;
                } else {
                    $tagId = (int) $tag->tag_id;
                }
            }

            // Vérifier si le tag est déjà assigné
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$contactTagsTable} WHERE contact_id = %d AND tag_id = %d",
                $contact->contact_id,
                $tagId
            ));

            if (!$existing) {
                $wpdb->insert($contactTagsTable, [
                    'contact_id' => $contact->contact_id,
                    'tag_id' => $tagId,
                ]);

                do_action('mailerpress_contact_tag_added', $contact->contact_id, $tagId);
            }

            // Récupérer le nom du tag pour la réponse
            $tag = $wpdb->get_row($wpdb->prepare(
                "SELECT name FROM {$tagsTable} WHERE tag_id = %d",
                $tagId
            ));
            $tagName = $tag ? $tag->name : '';

            return ['success' => true, 'tag_id' => $tagId, 'tag_name' => $tagName];
        }, 30);

        // Handler pour ajouter à une liste
        $this->registerHandler('add_to_list', function ($webhookId, $payload, $request) {
            $email = $payload['email'] ?? $payload['customer_email'] ?? $payload['user_email'] ?? '';
            $handlerConfig = $payload['_handler_config'] ?? [];

            // Utiliser list_id configuré si disponible, sinon utiliser list_name du payload
            $listId = null;
            $listName = null;

            if (!empty($handlerConfig['list_id'])) {
                $listId = (int) $handlerConfig['list_id'];
            } else {
                $listName = $payload['list'] ?? $payload['list_name'] ?? '';
            }

            if (empty($email) || !is_email($email)) {
                return ['success' => false, 'error' => 'Email is required and must be valid'];
            }

            if (empty($listId) && empty($listName)) {
                return ['success' => false, 'error' => 'List ID or list name is required'];
            }

            $contactsModel = new \MailerPress\Models\Contacts();
            $contact = $contactsModel->getContactByEmail($email);

            if (!$contact) {
                return ['success' => false, 'error' => 'Contact not found'];
            }

            global $wpdb;
            $listsTable = $wpdb->prefix . 'mailerpress_lists';
            $contactListsTable = $wpdb->prefix . 'mailerpress_contact_lists';

            // Si list_id n'est pas fourni, chercher ou créer la liste par nom
            if (empty($listId)) {
                $list = $wpdb->get_row($wpdb->prepare(
                    "SELECT list_id FROM {$listsTable} WHERE name = %s",
                    $listName
                ));

                if (!$list) {
                    $wpdb->insert($listsTable, [
                        'name' => sanitize_text_field($listName),
                        'created_at' => current_time('mysql'),
                    ]);
                    $listId = (int) $wpdb->insert_id;
                } else {
                    $listId = (int) $list->list_id;
                }
            }

            // Vérifier si le contact est déjà dans la liste
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$contactListsTable} WHERE contact_id = %d AND list_id = %d",
                $contact->contact_id,
                $listId
            ));

            if (!$existing) {
                $wpdb->insert($contactListsTable, [
                    'contact_id' => $contact->contact_id,
                    'list_id' => $listId,
                ]);

                do_action('mailerpress_contact_list_added', $contact->contact_id, $listId);
            }

            // Récupérer le nom de la liste pour la réponse
            $list = $wpdb->get_row($wpdb->prepare(
                "SELECT name FROM {$listsTable} WHERE list_id = %d",
                $listId
            ));
            $listName = $list ? $list->name : '';

            return ['success' => true, 'list_id' => $listId, 'list_name' => $listName];
        }, 40);

        // Permettre aux développeurs d'enregistrer leurs propres handlers
        do_action('mailerpress_register_incoming_webhook_handlers', $this);
    }
}
