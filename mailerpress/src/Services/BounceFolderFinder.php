<?php

namespace MailerPress\Services;

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class BounceFolderFinder
{
    public static function findFolderWithUnseenMessages(array $config): ?array
    {
        try {
            $client = self::createClient($config);
            $client->connect();

            // Récupérer le dossier INBOX
            $folder = $client->getFolder('INBOX');
            
            if (!$folder) {
                $client->disconnect();
                return null;
            }

            // Chercher les messages non lus
            $messages = $folder->query()->unseen()->get();
            
            $hasUnseenMessages = $messages->count() > 0;
            
            $client->disconnect();
            
            return $hasUnseenMessages ? ['client_config' => $config, 'folder' => $folder] : null;
            
        } catch (\Exception $e) {
            return null;
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
}
