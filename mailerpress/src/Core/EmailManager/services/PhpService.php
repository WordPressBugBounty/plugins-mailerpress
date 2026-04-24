<?php

declare(strict_types=1);

namespace MailerPress\Core\EmailManager\services;

\defined('ABSPATH') || exit;

use MailerPress\Core\EmailManager\AbstractEmailService;
use MailerPress\Services\BounceParser;

class PhpService extends AbstractEmailService
{
    /**
     * Send email using native PHP mail() function without wp_mail()
     * 
     * @param array $emailData Email data containing to, subject, body, headers, etc.
     * @return bool Whether the email was sent successfully
     */
    public function sendEmail(array $emailData): bool
    {
        try {
            // Prepare headers
            $headers = [];
            
            // Content-Type header
            $contentType = true === $emailData['html'] ? 'text/html' : 'text/plain';
            $headers[] = 'Content-Type: ' . $contentType . '; charset=UTF-8';
            
            // From header
            $senderName = $emailData['sender_name'] ?? 'WordPress';
            $senderEmail = $emailData['sender_to'] ?? '';
            
            if (empty($senderEmail)) {
                return false;
            }
            
            $headers[] = 'From: ' . $this->formatEmailHeader($senderName, $senderEmail);
            
            // Reply-To header if provided
            $replyToName = $emailData['reply_to_name'] ?? $senderName ?? '';
            $replyToAddress = $emailData['reply_to_address'] ?? $senderEmail ?? '';
            
            if (!empty($replyToAddress)) {
                $headers[] = 'Reply-To: ' . $this->formatEmailHeader($replyToName, $replyToAddress);
            }
            
            // Return-Path header if bounce configuration is set
            $bounceConfig = BounceParser::getValidatedConfig();
            if ($bounceConfig !== null && !empty($bounceConfig['email'])) {
                $returnPath = $emailData['return_path'] ?? $bounceConfig['email'];
                $headers[] = 'Return-Path: <' . $returnPath . '>';
            }
            
            // Additional headers
            $headers[] = 'X-Mailer: MailerPress/PHP-Service';
            $headers[] = 'X-Mailer-Version: 1.0';
            
            // Prepare email data
            $to = $emailData['to'];
            $subject = $emailData['subject'];
            $body = $emailData['body'];
            
            // Validate email address
            if (empty($to) || !$this->isValidEmail($to)) {
                return false;
            }
            
            if (empty($subject)) {
                return false;
            }
            
            if (empty($body)) {
                return false;
            }
            
            // Join headers into a string
            $headerString = implode("\r\n", $headers);
            
            // Additional mail parameters (safer with -f flag)
            $additionalParams = '-f' . $senderEmail;
            
            // Send email using native PHP mail() function
            $result = \mail(
                $to,
                $this->encodeSubject($subject),
                $this->encodeBody($body),
                $headerString,
                $additionalParams
            );
            
            // Log the email attempt
            $this->logEmail($emailData, 'php_native', $result);
            
            return $result;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Format email header with name and email address
     * 
     * @param string $name Display name
     * @param string $email Email address
     * @return string Formatted email header
     */
    private function formatEmailHeader(string $name, string $email): string
    {
        $name = trim($name);
        $email = trim($email);
        
        // If name contains special characters, encode it
        if (!empty($name) && preg_match('/[^a-zA-Z0-9\s\-\.]/', $name)) {
            $name = '=?UTF-8?B?' . base64_encode($name) . '?=';
        }
        
        if (!empty($name)) {
            return '"' . addslashes($name) . '" <' . $email . '>';
        }
        
        return '<' . $email . '>';
    }
    
    /**
     * Encode email subject with UTF-8
     * 
     * @param string $subject Email subject
     * @return string Encoded subject
     */
    private function encodeSubject(string $subject): string
    {
        // If subject contains non-ASCII characters, encode it
        if (preg_match('/[^\x00-\x7F]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        return $subject;
    }
    
    /**
     * Encode email body with proper line endings
     * 
     * @param string $body Email body
     * @return string Encoded body
     */
    private function encodeBody(string $body): string
    {
        // Convert line endings to CRLF for email standards
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\r", "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        
        // Ensure no line exceeds 998 characters (RFC 5322)
        $lines = explode("\r\n", $body);
        $encodedLines = [];
        
        foreach ($lines as $line) {
            if (strlen($line) > 998) {
                // Split long lines
                $encodedLines[] = wordwrap($line, 998, "\r\n", true);
            } else {
                $encodedLines[] = $line;
            }
        }
        
        return implode("\r\n", $encodedLines);
    }
    
    /**
     * Validate email address
     * 
     * @param string $email Email address to validate
     * @return bool Whether the email is valid
     */
    private function isValidEmail(string $email): bool
    {
        // Use WordPress function if available, otherwise use filter_var
        if (function_exists('is_email')) {
            return (bool) is_email($email);
        }
        
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function testConnection(): bool
    {
        return true;
    }

    public function config(): array
    {
        return [
            'key' => 'php',
            'name' => 'PHP Mail',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="4 3 92 92" fill="none"><g filter="url(#filter0_d_3582_119693)"><rect width="92" height="92" x="4" y="3" fill="#7A86B8" rx="46"></rect><path fill="#fff" d="M20.384 39.786h9.99c2.933.025 5.057.866 6.375 2.523 1.317 1.657 1.752 3.92 1.305 6.79a12.977 12.977 0 0 1-1.156 3.858 11.468 11.468 0 0 1-2.386 3.413c-1.243 1.286-2.572 2.102-3.989 2.448-1.416.347-2.883.52-4.398.52H21.65l-1.416 7.049h-5.182l5.33-26.6Zm4.361 4.23-2.236 11.13c.149.024.298.037.447.037h.522c2.386.024 4.374-.21 5.964-.705 1.59-.52 2.66-2.325 3.206-5.417.448-2.597 0-4.093-1.342-4.489-1.317-.396-2.97-.581-4.958-.556-.298.024-.584.037-.857.037h-.783l.037-.037ZM43.956 32.7h5.145l-1.454 7.086h4.622c2.535.05 4.424.57 5.667 1.558 1.267.99 1.64 2.87 1.118 5.64l-2.498 12.354h-5.219l2.386-11.798c.248-1.237.174-2.115-.224-2.634-.397-.52-1.254-.78-2.572-.78l-4.138-.036-3.056 15.248h-5.145L43.956 32.7ZM64.578 39.786h9.99c2.933.025 5.058.866 6.375 2.523 1.318 1.657 1.752 3.92 1.305 6.79a12.976 12.976 0 0 1-1.156 3.858 11.468 11.468 0 0 1-2.385 3.413c-1.243 1.286-2.573 2.102-3.99 2.448-1.416.347-2.882.52-4.398.52h-4.473l-1.417 7.049h-5.182l5.331-26.6Zm4.362 4.23-2.237 11.13c.15.024.298.037.447.037h.522c2.386.024 4.374-.21 5.965-.705 1.59-.52 2.66-2.325 3.206-5.417.447-2.597 0-4.093-1.342-4.489-1.318-.396-2.97-.581-4.958-.556-.299.024-.584.037-.858.037h-.783l.038-.037Z"></path></g><defs><filter id="filter0_d_3582_119693" width="100" height="100" x="0" y="0" color-interpolation-filters="sRGB" filterUnits="userSpaceOnUse"><feFlood flood-opacity="0" result="BackgroundImageFix"></feFlood><feColorMatrix in="SourceAlpha" result="hardAlpha" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"></feColorMatrix><feOffset dy="1"></feOffset><feGaussianBlur stdDeviation="2"></feGaussianBlur><feColorMatrix values="0 0 0 0 0.0687866 0 0 0 0 0.097585 0 0 0 0 0.37981 0 0 0 0.0779552 0"></feColorMatrix><feBlend in2="BackgroundImageFix" result="effect1_dropShadow_3582_119693"></feBlend><feBlend in="SourceGraphic" in2="effect1_dropShadow_3582_119693" result="shape"></feBlend></filter></defs></svg>',
            'link' => 'https://www.brevo.com/pricing/',
            'createAccountLink' => 'https://onboarding.brevo.com/account/register',
            'linkApiKey' => 'https://app.brevo.com/settings/keys/api',
            'description' => __('Use your server’s default PHP mailer to send emails.', 'mailerpress'),
            'recommended' => false,
            'sending_frequency' => [
                "numberEmail" => 25,
                "frequency" => [
                    'value' => 5,
                    'unit' => 'minutes',
                ]
            ]
        ];
    }
}
