<?php

declare(strict_types=1);

namespace MailerPress\Actions\Mailer;

\defined('ABSPATH') || exit;

use MailerPress\Core\EmailManager\EmailServiceInterface;
use MailerPress\Core\EmailManager\EmailServiceManager;

class Mailer
{
    private EmailServiceManager $emailServiceManager;

    public function __construct(EmailServiceManager $emailServiceManager)
    {
        $this->emailServiceManager = $emailServiceManager;
    }

    // #[Filter( 'wp_mail', acceptedArgs: 1 )]
    public function overrideWpMailer($args)
    {
        $mailer = $this->emailServiceManager->getActiveService();

        if ($mailer instanceof EmailServiceInterface) {
            $sent = $mailer->sendEmail($args);
            $args['message'] = '';
        }

        return $args;
    }
}
