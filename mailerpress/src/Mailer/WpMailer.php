<?php

declare(strict_types=1);

namespace MailerPress\Mailer;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\MailerInterface;

class WpMailer implements MailerInterface
{
    public function sendEmail($to, $subject, $body, $headers): bool
    {
        return wp_mail(
            $to,
            $subject,
            $body,
            [
                'Content-Type: text/html; charset=UTF-8',
                'From: '.$headers['sender_name'].' <'.$headers['sender_to'].'>',
            ]
        );
    }
}
