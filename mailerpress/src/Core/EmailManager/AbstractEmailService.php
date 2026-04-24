<?php

declare(strict_types=1);

namespace MailerPress\Core\EmailManager;

\defined('ABSPATH') || exit;

use MailerPress\Core\Kernel;

abstract class AbstractEmailService implements EmailServiceInterface
{
    protected array $config = [];
    private ?EmailLogger $logger = null;

    public function getConfig(): array
    {
        return $this->config;
    }

    public function connect(array $config): bool
    {
        $this->config = $config;

        return true;
    }

    public function testConnection(): bool
    {
        return true; // Par défaut, considère que la connexion fonctionne.
    }

    /**
     * Get the email logger instance
     */
    protected function getLogger(): EmailLogger
    {
        if ($this->logger === null) {
            $this->logger = Kernel::getContainer()->get(EmailLogger::class);
        }
        return $this->logger;
    }

    /**
     * Log email sending attempt
     * 
     * @param array $emailData Email data
     * @param string $serviceKey Service key (php, smtp, brevo, etc.)
     * @param bool|WP_Error|null $result Result from sendEmail
     * @param string|null $errorMessage Custom error message
     * @return void
     */
    protected function logEmail(array $emailData, string $serviceKey, $result = null, ?string $errorMessage = null): void
    {
        try {
            // Determine status
            $status = 'pending';
            if ($result instanceof \WP_Error) {
                $status = 'error';
            } elseif ($result === false) {
                $status = 'error';
            } elseif ($result === true) {
                $status = 'success';
            }

            // Prepare log data
            $logData = array_merge($emailData, [
                'service' => $serviceKey,
            ]);

            // If custom error message provided, use it
            if ($errorMessage && $status === 'error') {
                $logData['error_message'] = $errorMessage;
            }

            $this->getLogger()->log($logData, $status, $result);
        } catch (\Throwable $e) {
            // Don't fail email sending if logging fails
            if (defined('WP_DEBUG') && \WP_DEBUG) {
                \error_log('MailerPress EmailLogger: Failed to log email - ' . $e->getMessage());
            }
        }
    }
}
