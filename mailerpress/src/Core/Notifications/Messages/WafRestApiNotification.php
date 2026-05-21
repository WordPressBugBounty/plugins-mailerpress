<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Capabilities;
use MailerPress\Core\Notifications\AbstractNotificationMessage;

class WafRestApiNotification extends AbstractNotificationMessage
{
    protected string $type = 'warning';
    protected ?int $duration = null;
    protected bool $dismissible = true;
    protected bool $persistent = false;

    public function getMessage(): string
    {
        return __('If MailerPress admin tools return JSON or API errors, a server firewall such as 7G Nginx Firewall may be blocking MailerPress REST requests. Whitelist trusted MailerPress endpoints under /wp-json/mailerpress/v1/ in your WAF configuration.', 'mailerpress');
    }

    public function getRequiredCapability(): string
    {
        return Capabilities::MANAGE_SETTINGS;
    }

    public function getAction(): ?array
    {
        return null;
    }
}
