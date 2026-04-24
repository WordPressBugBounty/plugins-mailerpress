<?php

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Services\BounceParser;
use Webklex\PHPIMAP\ClientManager;

\defined('ABSPATH') || exit;

class BounceDump
{
    #[Command('mailerpress bounce:dump')]
    public function dump(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('🔍 DUMP COMPLET DES MESSAGES BOUNCE...');
        \WP_CLI::log('');

        // Vérifier la configuration
        $config = BounceParser::getValidatedConfig();

        if ($config === null) {
            \WP_CLI::error('❌ Aucune configuration de bounce trouvée.');
            return;
        }

        \WP_CLI::log('✅ Configuration trouvée');
        \WP_CLI::log('');

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

            $unseenMessages = $inbox->query()->unseen()->get();
            
            if ($unseenMessages->count() === 0) {
                \WP_CLI::warning('⚠️  Aucun message non lu');
                $client->disconnect();
                return;
            }

            \WP_CLI::log('');
            \WP_CLI::log('📧 ' . $unseenMessages->count() . ' message(s) non lu(s) trouvé(s)');
            \WP_CLI::log('');

            $messageCount = 0;
            foreach ($unseenMessages as $message) {
                $messageCount++;
                
                \WP_CLI::log(str_repeat('=', 100));
                \WP_CLI::log("MESSAGE #{$messageCount}");
                \WP_CLI::log(str_repeat('=', 100));
                \WP_CLI::log('');
                
                // SUJET
                \WP_CLI::log('📌 SUJET:');
                \WP_CLI::log($message->getSubject() ?? '[Aucun sujet]');
                \WP_CLI::log('');
                
                // EN-TÊTES BRUTS
                \WP_CLI::log('📋 EN-TÊTES COMPLETS:');
                \WP_CLI::log(str_repeat('-', 100));
                
                try {
                    $headers = $message->getHeaders();
                    foreach ($headers as $header) {
                        try {
                            $line = $header->toString();
                            \WP_CLI::log($line);
                        } catch (\Exception $e) {
                            \WP_CLI::log($header->getName() . ': [Erreur: ' . $e->getMessage() . ']');
                        }
                    }
                } catch (\Exception $e) {
                    \WP_CLI::error('Erreur en-têtes: ' . $e->getMessage());
                }
                
                \WP_CLI::log(str_repeat('-', 100));
                \WP_CLI::log('');
                
                // CORPS DU MESSAGE
                \WP_CLI::log('📄 CORPS DU MESSAGE:');
                \WP_CLI::log(str_repeat('-', 100));
                
                $body = $message->getTextBody();
                if (empty($body)) {
                    $body = $message->getHTMLBody();
                }
                if (empty($body)) {
                    $body = $message->getRawBody();
                }
                
                \WP_CLI::log($body);
                \WP_CLI::log(str_repeat('-', 100));
                \WP_CLI::log('');
                
                // RECHERCHE D'EMAILS
                \WP_CLI::log('🔍 TOUS LES EMAILS TROUVÉS DANS CE MESSAGE:');
                preg_match_all('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $body, $allEmails);
                
                if (!empty($allEmails[1])) {
                    foreach (array_unique($allEmails[1]) as $email) {
                        \WP_CLI::log('   - ' . $email);
                    }
                } else {
                    \WP_CLI::warning('   Aucun email trouvé dans le corps');
                }
                
                \WP_CLI::log('');
                \WP_CLI::log('');
            }

            $client->disconnect();
            \WP_CLI::success('✅ Dump terminé');

        } catch (\Exception $e) {
            \WP_CLI::error('❌ Erreur: ' . $e->getMessage());
            \WP_CLI::log('');
            \WP_CLI::log('Stack trace:');
            \WP_CLI::log($e->getTraceAsString());
        }
    }
}

