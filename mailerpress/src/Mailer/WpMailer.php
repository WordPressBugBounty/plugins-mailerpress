<?php

declare(strict_types=1);

namespace MailerPress\Mailer;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\MailerInterface;

class WpMailer implements MailerInterface
{
    public function sendEmail($to, $subject, $body, $headers): bool
    {
        $wpHeaders = [
            'Content-Type: text/html; charset=UTF-8',
            'From: '.$headers['sender_name'].' <'.$headers['sender_to'].'>',
        ];

        if ( ! empty( $headers['custom_headers'] ) && \is_array( $headers['custom_headers'] ) ) {
            foreach ( $headers['custom_headers'] as $headerName => $headerValue ) {
                $wpHeaders[] = $headerName . ': ' . $headerValue;
            }
        }

        return wp_mail( $to, $subject, $body, $wpHeaders );
    }
}
