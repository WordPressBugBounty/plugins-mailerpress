<?php

namespace MailerPress\Actions\ActionScheduler\Processors;

use MailerPress\Core\Attributes\Action;
use MailerPress\Services\BounceParser;

class CheckBounce
{
    #[Action('mailerpress_check_bounces')]
    public function checkBounce()
    {
        try {
            $config = BounceParser::getValidatedConfig();

            if ($config === null) {
                return;
            }

            BounceParser::parse();

        } catch (\Exception $e) {
            throw $e;
        }
    }
}
