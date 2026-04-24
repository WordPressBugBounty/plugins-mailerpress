<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

use MailerPress\Api\CountDown;
use MailerPress\Core\Attributes\Action;
use WP_REST_Request;

\defined('ABSPATH') || exit;

class RegenerateGif
{
    private CountDown $countDown;

    /**
     * @param CountDown $countDown
     */
    public function __construct(CountDown $countDown)
    {
        $this->countDown = $countDown;
    }

    #[Action('mailerpress_regenerate_countdown', priority: 10, acceptedArgs: 2)]
    public function regenerate($campaignId, $imageName): void
    {
        $this->countDown->regenerateGif($campaignId, $imageName);
    }
}
