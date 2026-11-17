<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Services\SendingFrequencyConfig;

class DeferredWorkflowSends
{
    /**
     * Retry, in batches, the workflow sends postponed by sending limits the user has since changed.
     */
    #[Action(SendingFrequencyConfig::RELEASE_WORKFLOWS_HOOK)]
    public function release(): void
    {
        SendingFrequencyConfig::releaseDeferredWorkflowSends();
    }
}
