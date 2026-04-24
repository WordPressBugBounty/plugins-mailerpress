<?php

declare(strict_types=1);

namespace MailerPress\Mailer;

\defined('ABSPATH') || exit;
use MailerPress\Core\Interfaces\MailerInterface;

class BrevoMailer implements MailerInterface
{
    public function sendEmail($to, $subject, $body, $headers): bool
    {
        $config = get_option('mailerpress_esp_config');
        $sender = get_option('mailerpress_senders');

        // Exemple d'utilisation de l'API Brevo avec des requêtes HTTP
        $response = wp_remote_post('https://api.brevo.com/v3/smtp/email', [
            'headers' => [
                'Content-Type' => 'application/json',
                'api-key' => $config['apiKey'],
            ],
            'body' => wp_json_encode([
                'sender' => ['name' => $headers['sender_name'] ?? $sender['from_name'], 'email' => $headers['sender_to'] ?? $sender['from_to']],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'htmlContent' => $body,
            ]),
        ]);

        return !is_wp_error($response) && 201 === wp_remote_retrieve_response_code($response);
    }
}
