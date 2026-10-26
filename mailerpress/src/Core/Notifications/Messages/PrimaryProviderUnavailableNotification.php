<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Capabilities;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Notification shown while WordPress sends in place of an unavailable primary provider,
 * e.g. a Pro ESP after MailerPress Pro was deactivated.
 */
class PrimaryProviderUnavailableNotification extends AbstractNotificationMessage
{
    protected string $type = 'warning';
    protected ?int $duration = null;
    protected bool $dismissible = false;
    protected bool $persistent = true;

    public function getMessage(): string
    {
        if (\defined('MAILERPRESS_PRO_VERSION')) {
            return __('Your primary email provider is no longer available. Emails are sent with WordPress until you choose another provider.', 'mailerpress');
        }

        return __('Your primary email provider requires MailerPress Pro, which is inactive. Emails are sent from your site’s server with WordPress until you reactivate MailerPress Pro or choose another provider. SMTP plugins are not used, and these emails often land in spam.', 'mailerpress');
    }

    public function getRequiredCapability(): string
    {
        return Capabilities::MANAGE_SETTINGS;
    }

    public function shouldDisplay(): bool
    {
        if (!$this->userHasCapability()) {
            return false;
        }

        return null !== Kernel::getContainer()->get(EmailServiceManager::class)->getUnavailableServiceKey();
    }

    public function getAction(): ?array
    {
        return [
            'label' => __('Configure', 'mailerpress'),
            'url' => admin_url('admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome%2Fintegrations'),
        ];
    }
}
