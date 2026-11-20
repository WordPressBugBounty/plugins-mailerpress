<?php

declare(strict_types=1);

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Services\ManageSubscriptionLink;

\defined('ABSPATH') || exit;

class ManageLinks
{
    /**
     * Invalidates every "Manage subscription" link already sent.
     *
     * Contacts who open an old link can still unsubscribe or ask for a new link.
     */
    #[Command('mailerpress manage-links:rotate-key')]
    public function rotateKey(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        ManageSubscriptionLink::rotateKey();
        \WP_CLI::success('Signing key rotated: every Manage subscription link sent so far is now expired.');
    }
}
