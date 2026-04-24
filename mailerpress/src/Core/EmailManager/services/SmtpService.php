<?php

declare(strict_types=1);

namespace MailerPress\Core\EmailManager\services;

\defined('ABSPATH') || exit;

use Exception;
use MailerPress\Core\EmailManager\AbstractEmailService;
use MailerPress\Services\BounceParser;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use WP_Error;

class SmtpService extends AbstractEmailService
{
    public function sendEmail(array $emailData): bool|WP_Error
    {
        // Initialiser les variables de configuration en dehors du try pour qu'elles soient disponibles dans les catch
        $host = $this->config['conf']['host'] ?? '';
        $port = $this->config['conf']['port'] ?? 587;
        $encryption = $this->config['conf']['encryption'] ?? 'tls';
        $username = $this->config['conf']['auth_id'] ?? '';
        $password = $this->config['conf']['auth_password'] ?? '';

        try {

            // Validation de la configuration
            if (empty($host)) {
                $errorMessage = __('SMTP host is required. Please configure your SMTP settings.', 'mailerpress');
                $this->logEmail($emailData, 'smtp', false, $errorMessage);
                if (!empty($emailData['isTest'])) {
                    return new WP_Error('smtp_config_error', $errorMessage);
                }
                return false;
            }

            if (empty($username) || empty($password)) {
                $errorMessage = __('SMTP authentication credentials are required. Please provide a username and password.', 'mailerpress');
                $this->logEmail($emailData, 'smtp', false, $errorMessage);
                if (!empty($emailData['isTest'])) {
                    return new WP_Error('smtp_auth_error', $errorMessage);
                }
                return false;
            }

            $dsn = sprintf(
                '%s://%s:%s@%s:%s',
                $encryption === 'ssl' ? 'smtps' : 'smtp',
                urlencode($username),
                urlencode($password),
                $host,
                $port
            );

            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);

            $email = (new Email())
                ->from(new Address($emailData['sender_to'], $emailData['sender_name'] ?? ''))
                ->to($emailData['to'])
                ->subject($emailData['subject'] ?? '')
                ->html($emailData['body'] ?? '');

            // Ajouter Reply-To si fourni (utiliser From si Reply-To est vide)
            $replyToName = $emailData['reply_to_name'] ?? $emailData['sender_name'] ?? '';
            $replyToAddress = $emailData['reply_to_address'] ?? $emailData['sender_to'] ?? '';

            if (!empty($replyToAddress)) {
                $email->replyTo(new Address($replyToAddress, $replyToName));
            }

            // Utiliser la configuration de bounce validée pour définir l'envelope sender
            $bounceConfig = BounceParser::getValidatedConfig();
            if ($bounceConfig !== null && !empty($bounceConfig['email'])) {
                $envelopeSender = $emailData['return_path'] ?? $bounceConfig['email'];
                $envelope = new Envelope(new Address($envelopeSender), [new Address($emailData['to'])]);
                $mailer->send($email, $envelope);
            } else {
                $mailer->send($email);
            }

            // Log success
            $this->logEmail($emailData, 'smtp', true);

            return true;
        } catch (TransportExceptionInterface $e) {
            // Erreur de transport SMTP - message détaillé
            $errorMessage = $this->formatSmtpErrorMessage($e, $host, $port, $encryption);
            $this->logEmail($emailData, 'smtp', false, $errorMessage);

            // Pour les emails de test, retourner WP_Error avec le message détaillé
            if (!empty($emailData['isTest'])) {
                return new WP_Error('smtp_transport_error', $errorMessage);
            }

            return false;
        } catch (Exception $e) {
            // Autres exceptions
            $errorMessage = sprintf(
                __('SMTP error: %s', 'mailerpress'),
                $e->getMessage()
            );
            $this->logEmail($emailData, 'smtp', false, $errorMessage);

            // Pour les emails de test, retourner WP_Error avec le message détaillé
            if (!empty($emailData['isTest'])) {
                return new WP_Error('smtp_error', $errorMessage);
            }

            return false;
        }
    }

    /**
     * Formate un message d'erreur SMTP détaillé pour le débogage
     * 
     * @param TransportExceptionInterface $e L'exception de transport
     * @param string $host Le serveur SMTP
     * @param int|string $port Le port SMTP
     * @param string $encryption Le type d'encryption
     * @return string Message d'erreur formaté
     */
    private function formatSmtpErrorMessage(TransportExceptionInterface $e, string $host, $port, string $encryption): string
    {
        $baseMessage = $e->getMessage();

        // Messages d'erreur courants et leurs explications
        $commonErrors = [
            'Connection refused' => __('Connection refused. Please verify that the SMTP host and port are correct, and that your server can reach the SMTP server.', 'mailerpress'),
            'Connection timed out' => __('Connection timed out. Please check your network connection and firewall settings.', 'mailerpress'),
            'Authentication failed' => __('Authentication failed. Please verify your SMTP username and password.', 'mailerpress'),
            'Could not authenticate' => __('Could not authenticate. Please verify your SMTP username and password.', 'mailerpress'),
            'SSL' => __('SSL/TLS connection error. Please verify that the encryption type (TLS/SSL) matches your SMTP server configuration.', 'mailerpress'),
            'TLS' => __('TLS connection error. Please verify that the encryption type matches your SMTP server configuration.', 'mailerpress'),
        ];

        // Chercher un message d'erreur correspondant
        foreach ($commonErrors as $key => $message) {
            if (stripos($baseMessage, $key) !== false) {
                return $message;
            }
        }

        // Message générique avec contexte
        return sprintf(
            __('SMTP error: %s (Server: %s:%s, Encryption: %s)', 'mailerpress'),
            $baseMessage,
            $host,
            $port,
            strtoupper($encryption)
        );
    }

    public function testConnection(): bool
    {
        // Optional: You can implement a test email send or SMTP handshake check here
        return true;
    }

    public function config(): array
    {
        return [
            'key' => 'smtp',
            'name' => 'Custom SMTP',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" fill="none" viewBox="0 0 92 92"><rect width="92" height="92" fill="#FF4F00" rx="46"></rect><path fill="#fff" d="M51.012 65.526c-.312.748-.857 1.216-1.636 1.405-.779.186-1.449-.002-2.01-.563l-6.637-6.642c-.343-.343-.544-.78-.606-1.31-.063-.53.06-1.012.372-1.449l8.507-14.03-13.975 8.652c-.436.249-.911.35-1.425.303a2.098 2.098 0 0 1-1.333-.63l-6.637-6.642c-.56-.561-.748-1.232-.563-2.011.189-.78.657-1.325 1.404-1.637l36.411-14.826c.935-.312 1.745-.125 2.43.561.686.686.857 1.481.515 2.386L51.012 65.526Z"></path></svg>',
            'link' => 'https://www.brevo.com/fr/pricing/',
            'createAccountLink' => 'https://onboarding.brevo.com/account/register',
            'linkApiKey' => 'https://app.brevo.com/settings/keys/api',
            'description' => __(
                'Connect to any SMTP server using our Custom SMTP feature. For setup instructions, see our documentation.',
                'mailerpress'
            ),
            'recommended' => false,
            'sending_frequency' => [
                "numberEmail" => 75,
                "frequency" => [
                    'value' => 2,
                    'unit' => 'minutes',
                ]
            ]
        ];
    }
}
