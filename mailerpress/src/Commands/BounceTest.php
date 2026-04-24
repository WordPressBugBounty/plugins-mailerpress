<?php

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Core\Enums\Tables;
use MailerPress\Services\BounceParser;
use Webklex\PHPIMAP\ClientManager;

\defined('ABSPATH') || exit;

class BounceTest
{
    #[Command('mailerpress bounce:test')]
    public function test(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        $debug = isset($assoc_args['debug']) ? true : false;

        \WP_CLI::log('🔍 Test de détection des bounces MailerPress...');
        \WP_CLI::log('');

        // 1. Vérifier la configuration
        $config = BounceParser::getValidatedConfig();

        if ($config === null) {
            \WP_CLI::error('❌ Aucune configuration de bounce trouvée ou configuration invalide.');
            \WP_CLI::log('💡 Veuillez configurer le Bounce Manager dans les paramètres de MailerPress.');
            return;
        }

        \WP_CLI::log('✅ Configuration trouvée:');
        \WP_CLI::log('   📧 Email: ' . $config['email']);
        \WP_CLI::log('   🖥️  Host: ' . $config['host']);
        \WP_CLI::log('   🔌 Port: ' . ($config['port'] ?? 993));
        \WP_CLI::log('   👤 Username: ' . $config['username']);
        \WP_CLI::log('');

        // 2. Tester la connexion IMAP
        \WP_CLI::log('🔌 Test de connexion au serveur IMAP...');

        try {
            $clientManager = new ClientManager();

            $client = $clientManager->make([
                'host' => $config['host'],
                'port' => $config['port'] ?? 993,
                'encryption' => 'ssl',
                'validate_cert' => false,
                'username' => $config['username'],
                'password' => $config['password'],
                'protocol' => 'imap'
            ]);

            $client->connect();
            \WP_CLI::success('✅ Connexion réussie au serveur IMAP');

            // 3. Lister les dossiers disponibles
            \WP_CLI::log('');
            \WP_CLI::log('📁 Dossiers disponibles:');
            $folders = $client->getFolders();
            foreach ($folders as $folder) {
                \WP_CLI::log('   - ' . $folder->name);
            }

            // 4. Vérifier le dossier INBOX
            \WP_CLI::log('');
            \WP_CLI::log('📬 Analyse du dossier INBOX...');
            $inbox = $client->getFolder('INBOX');

            if (!$inbox) {
                \WP_CLI::error('❌ Impossible d\'accéder au dossier INBOX');
                $client->disconnect();
                return;
            }

            // 5. Compter les messages
            $allMessages = $inbox->query()->all()->get();
            $unseenMessages = $inbox->query()->unseen()->get();

            \WP_CLI::log('   📊 Total messages: ' . $allMessages->count());
            \WP_CLI::log('   🆕 Messages non lus: ' . $unseenMessages->count());

            if ($unseenMessages->count() === 0) {
                \WP_CLI::warning('⚠️  Aucun message non lu trouvé dans INBOX');
                $client->disconnect();
                return;
            }

            // 6. Analyser les messages non lus
            \WP_CLI::log('');
            \WP_CLI::log('🔍 Analyse des messages non lus...');
            \WP_CLI::log('');

            $processedCount = 0;
            $bouncedCount = 0;

            foreach ($unseenMessages as $message) {
                $processedCount++;

                \WP_CLI::log("📧 Message #{$processedCount}:");
                \WP_CLI::log("   Sujet: " . ($message->getSubject() ?? '[Aucun sujet]'));
                \WP_CLI::log("   De: " . ($message->getFrom()[0]->mail ?? 'Inconnu'));
                \WP_CLI::log("   Date: " . ($message->getDate() ?? 'Inconnue'));

                // Récupérer le corps du message
                $body = $message->getTextBody() ?: $message->getHTMLBody();

                if (empty($body)) {
                    $body = $message->getRawBody();
                }

                // Mode debug : afficher le contenu complet
                if ($debug) {
                    \WP_CLI::log("   🐛 DEBUG - En-têtes complets:");
                    \WP_CLI::log("   " . str_repeat('-', 80));

                    try {
                        $headers = $message->getHeaders();
                        foreach ($headers as $header) {
                            $name = $header->getName();
                            $value = is_object($header) && method_exists($header, 'toString')
                                ? $header->toString()
                                : $name . ': [object]';
                            \WP_CLI::log("   " . $value);
                        }
                    } catch (\Exception $e) {
                        \WP_CLI::log("   Erreur en-têtes: " . $e->getMessage());
                    }

                    \WP_CLI::log("   ");
                    \WP_CLI::log("   🐛 DEBUG - Corps complet:");
                    \WP_CLI::log("   " . str_repeat('-', 80));
                    $debugBody = substr($body, 0, 2000);
                    \WP_CLI::log($debugBody);
                    \WP_CLI::log("   " . str_repeat('-', 80));
                    \WP_CLI::log("");
                }

                // Extraire le destinataire original (en-têtes + corps)
                $originalEmail = $this->extractOriginalRecipient($body, $message);

                if ($originalEmail) {
                    \WP_CLI::log("   ✅ Bounce détecté pour: {$originalEmail}");

                    // Vérifier si le contact existe
                    global $wpdb;
                    $table = Tables::get(Tables::MAILERPRESS_CONTACT);
                    $contact = $wpdb->get_row(
                        $wpdb->prepare("SELECT contact_id, subscription_status FROM $table WHERE email = %s", $originalEmail)
                    );

                    if ($contact) {
                        \WP_CLI::log("   👤 Contact trouvé (ID: {$contact->contact_id}, Status: {$contact->subscription_status})");
                        $bouncedCount++;
                    } else {
                        \WP_CLI::log("   ⚠️  Contact non trouvé dans la base de données");
                    }
                } else {
                    \WP_CLI::log("   ⚠️  Impossible d'extraire l'email du destinataire");

                    if (!$debug) {
                        // Afficher un extrait du corps pour debug
                        $excerpt = substr(strip_tags($body), 0, 200);
                        \WP_CLI::log("   📄 Extrait: " . $excerpt . '...');
                        \WP_CLI::log("   💡 Utilisez --debug pour voir le contenu complet");
                    }
                }

                \WP_CLI::log('');
            }

            $client->disconnect();

            // 7. Résumé
            \WP_CLI::log('');
            \WP_CLI::log('📊 Résumé:');
            \WP_CLI::log("   Messages analysés: {$processedCount}");
            \WP_CLI::log("   Bounces détectés: {$bouncedCount}");
            \WP_CLI::log('');

            // 8. Demander confirmation pour marquer les contacts
            if ($bouncedCount > 0) {
                \WP_CLI::confirm('⚠️  Voulez-vous marquer ces contacts comme "bounced" dans la base de données ?', $assoc_args);

                \WP_CLI::log('');
                \WP_CLI::log('🔄 Exécution du parser de bounce...');

                BounceParser::parse();

                \WP_CLI::success("✅ {$bouncedCount} contact(s) marqué(s) comme bounced");
            } else {
                \WP_CLI::log('ℹ️  Aucun bounce à traiter');
            }
        } catch (\Exception $e) {
            \WP_CLI::error('❌ Erreur: ' . $e->getMessage());
            \WP_CLI::log('');
            \WP_CLI::log('🐛 Stack trace:');
            \WP_CLI::log($e->getTraceAsString());
        }
    }

    #[Command('mailerpress bounce:parse')]
    public function parse(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('🔄 Lancement du parser de bounce...');

        try {
            BounceParser::parse();
            \WP_CLI::success('✅ Parser de bounce exécuté avec succès');
        } catch (\Exception $e) {
            \WP_CLI::error('❌ Erreur lors du parsing: ' . $e->getMessage());
        }
    }

    #[Command('mailerpress bounce:mark-unseen')]
    public function markUnseen(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('🔄 Marquage des messages comme non lus...');
        \WP_CLI::log('');

        $config = BounceParser::getValidatedConfig();

        if ($config === null) {
            \WP_CLI::error('❌ Aucune configuration de bounce trouvée.');
            return;
        }

        try {
            $clientManager = new ClientManager();

            $client = $clientManager->make([
                'host' => $config['host'],
                'port' => $config['port'] ?? 993,
                'encryption' => 'ssl',
                'validate_cert' => false,
                'username' => $config['username'],
                'password' => $config['password'],
                'protocol' => 'imap'
            ]);

            $client->connect();
            \WP_CLI::success('✅ Connecté au serveur IMAP');

            $inbox = $client->getFolder('INBOX');
            if (!$inbox) {
                \WP_CLI::error('❌ Impossible d\'accéder à INBOX');
                $client->disconnect();
                return;
            }

            // Récupérer TOUS les messages (lus et non lus)
            $allMessages = $inbox->query()->all()->get();

            \WP_CLI::log('');
            \WP_CLI::log("📧 {$allMessages->count()} message(s) trouvé(s)");
            \WP_CLI::log('');

            $unmarkedCount = 0;

            foreach ($allMessages as $message) {
                try {
                    // Enlever le flag Seen
                    $message->clearFlag('Seen');
                    $unmarkedCount++;
                    \WP_CLI::log("   ✓ Message marqué comme non lu: " . ($message->getSubject() ?? '[Aucun sujet]'));
                } catch (\Exception $e) {
                    \WP_CLI::warning("   ✗ Erreur pour un message: " . $e->getMessage());
                }
            }

            $client->disconnect();

            \WP_CLI::log('');
            \WP_CLI::success("✅ {$unmarkedCount} message(s) marqué(s) comme non lu(s)");
        } catch (\Exception $e) {
            \WP_CLI::error('❌ Erreur: ' . $e->getMessage());
        }
    }

    #[Command('mailerpress bounce:stats')]
    public function stats(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('📊 Statistiques des bounces...');
        \WP_CLI::log('');

        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_CONTACT);

        // Compter les contacts par statut
        $stats = $wpdb->get_results("
            SELECT subscription_status, COUNT(*) as count 
            FROM $table 
            GROUP BY subscription_status
        ");

        \WP_CLI::log('👥 Statuts des contacts:');
        foreach ($stats as $stat) {
            $emoji = $this->getStatusEmoji($stat->subscription_status);
            \WP_CLI::log("   {$emoji} {$stat->subscription_status}: {$stat->count}");
        }

        \WP_CLI::log('');

        // Derniers bounces
        $recentBounces = $wpdb->get_results("
            SELECT email, updated_at 
            FROM $table 
            WHERE subscription_status = 'bounced' 
            ORDER BY updated_at DESC 
            LIMIT 10
        ");

        if (!empty($recentBounces)) {
            \WP_CLI::log('🔴 10 derniers bounces:');
            foreach ($recentBounces as $bounce) {
                \WP_CLI::log("   - {$bounce->email} ({$bounce->updated_at})");
            }
        } else {
            \WP_CLI::log('✅ Aucun bounce enregistré');
        }
    }

    private function extractOriginalRecipient(string $body, $message = null): ?string
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

    private function getStatusEmoji(string $status): string
    {
        return match ($status) {
            'subscribed' => '✅',
            'unsubscribed' => '❌',
            'bounced' => '🔴',
            'pending' => '⏳',
            default => '❓',
        };
    }
}
