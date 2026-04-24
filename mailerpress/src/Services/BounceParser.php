<?php

namespace MailerPress\Services;

use MailerPress\Core\Enums\Tables;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class BounceParser
{
    /**
     * Récupère et valide la configuration de bounce
     *
     * @return array|null Retourne la configuration validée ou null si invalide
     */
    public static function getValidatedConfig(): ?array
    {
        $bounceManager = get_option('mailerpress_bounce_config');

        // Si la configuration n'existe pas ou est vide, on ne fait rien
        if (empty($bounceManager)) {
            return null;
        }

        if (is_string($bounceManager)) {
            $decoded = json_decode($bounceManager, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $bounceManager = $decoded;
            } else {
                return null;
            }
        }

        // Vérifier que la configuration contient les champs nécessaires
        if (!is_array($bounceManager) || empty($bounceManager['username']) || empty($bounceManager['password']) || empty($bounceManager['email'])) {
            return null;
        }

        return $bounceManager;
    }

    public static function parse(): void
    {
        $bounceManager = self::getValidatedConfig();

        if ($bounceManager === null) {
            return;
        }


        $folderData = \MailerPress\Services\BounceFolderFinder::findFolderWithUnseenMessages($bounceManager);

        if (null === $folderData) {
            return;
        }

        try {
            // Créer le client IMAP
            $client = self::createClient($bounceManager);
            $client->connect();

            // Récupérer le dossier INBOX
            $folder = $client->getFolder('INBOX');

            if (!$folder) {
                $client->disconnect();
                return;
            }

            // Récupérer les messages non lus
            $messages = $folder->query()->unseen()->get();

            if ($messages->count() === 0) {
                $client->disconnect();
                return;
            }

            // Traiter chaque message
            $processedCount = 0;
            $bouncedCount = 0;

            foreach ($messages as $message) {
                try {
                    $processedCount++;

                    $body = $message->getTextBody() ?: $message->getHTMLBody();

                    if (empty($body)) {
                        // Si aucun corps de texte, essayer de récupérer le corps brut
                        $body = $message->getRawBody();
                    }

                    $originalEmail = self::extractOriginalRecipient($body, $message);

                    if ($originalEmail) {
                        self::markContactAsBounced($originalEmail);
                        $bouncedCount++;
                    }

                    // Marquer le message comme lu
                    $message->setFlag('Seen');
                } catch (\Exception $e) {
                    // Continuer avec le message suivant en cas d'erreur
                    continue;
                }
            }

            $client->disconnect();
        } catch (\Exception $e) {
            // Gérer silencieusement l'erreur de connexion
            return;
        }
    }

    /**
     * Crée un client IMAP avec la configuration donnée
     *
     * @param array $config
     * @return \Webklex\PHPIMAP\Client
     */
    private static function createClient(array $config): \Webklex\PHPIMAP\Client
    {
        $clientManager = new ClientManager();

        return $clientManager->make([
            'host' => $config['host'],
            'port' => $config['port'] ?? 993,
            'encryption' => 'ssl',
            'validate_cert' => false,
            'username' => $config['username'],
            'password' => $config['password'],
            'protocol' => 'imap'
        ]);
    }

    private static function extractOriginalRecipient(string $body, $message = null): ?string
    {
        // ÉTAPE 1 : Vérifier les EN-TÊTES du message (le plus fiable)
        if ($message) {
            try {
                $headers = $message->getHeaders();

                // Liste des en-têtes à vérifier pour les bounces
                $headerNames = [
                    'X-Failed-Recipients',
                    'X-Actual-Recipient',
                    'Original-Recipient',
                    'Final-Recipient',
                    'To',
                    'X-Original-To',
                    'Delivered-To',
                    'Envelope-To'
                ];

                foreach ($headerNames as $headerName) {
                    try {
                        if ($headers->has($headerName)) {
                            $header = $headers->get($headerName);
                            if ($header && $header->count() > 0) {
                                $headerObj = $header->first();
                                $headerText = method_exists($headerObj, 'toString')
                                    ? $headerObj->toString()
                                    : '';

                                // Extraire l'email de l'en-tête
                                if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $headerText, $matches)) {
                                    return trim($matches[1]);
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        // Continuer avec l'en-tête suivant
                        continue;
                    }
                }
            } catch (\Exception $e) {
                // Si erreur sur les en-têtes, continuer avec le corps
            }
        }

        // ÉTAPE 2 : Parser le CORPS du message

        // 1. Format RFC3464 standard
        if (preg_match('/Final-Recipient:\s*rfc822;\s*([^\s<>]+)/i', $body, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/Original-Recipient:\s*rfc822;\s*([^\s<>]+)/i', $body, $matches)) {
            return trim($matches[1]);
        }

        // 2. En-tête X-Failed-Recipients dans le corps
        if (preg_match('/X-Failed-Recipients:\s*([^\s<>]+)/i', $body, $matches)) {
            return trim($matches[1]);
        }

        // 3. Format "To: email@example.com" dans le corps
        if (preg_match('/(?:^|\n)To:\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>?/im', $body, $matches)) {
            return trim($matches[1]);
        }

        // 4. Format "Recipient: email@example.com"
        if (preg_match('/Recipient:\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>?/i', $body, $matches)) {
            return trim($matches[1]);
        }

        // 5. Format "Destinataire: email@example.com" (français)
        if (preg_match('/Destinataire:\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>?/i', $body, $matches)) {
            return trim($matches[1]);
        }

        // 6. Format "failed to deliver to email@example.com"
        if (preg_match('/failed\s+to\s+deliver\s+to\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>?/i', $body, $matches)) {
            return trim($matches[1]);
        }

        // 7. Format "impossible d'envoyer à email@example.com" (français)
        if (preg_match('/impossible\s+d.envoyer\s+[àa]\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>?/iu', $body, $matches)) {
            return trim($matches[1]);
        }

        // 8. Format "delivery failed for email@example.com"
        if (preg_match('/delivery\s+failed\s+for\s*<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>?/i', $body, $matches)) {
            return trim($matches[1]);
        }

        // 9. Format "<email@example.com>: error message" (O2Switch et autres)
        if (preg_match('/<([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>:\s*/i', $body, $matches)) {
            return trim($matches[1]);
        }

        // 10. Recherche générale d'email dans le message (dernier recours)
        if (preg_match('/(?:pour|for|to|recipient)[\s:]+<?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>?/i', $body, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private static function markContactAsBounced(string $email): void
    {
        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_CONTACT);

        $contact = $wpdb->get_row(
            $wpdb->prepare("SELECT contact_id, subscription_status FROM $table WHERE email = %s", $email)
        );

        if ($contact) {
            $wpdb->update(
                $table,
                [
                    'subscription_status' => 'bounced',
                ],
                ['contact_id' => $contact->contact_id]
            );

            do_action('mailerpress_email_bounced', (int) $contact->contact_id, $email);
        }
    }
}
