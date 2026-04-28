<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation\Connectors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Synchronisation\ProConnectorStub;

/**
 * Free-plugin stub for the Mailjet connector.
 *
 * Exposes metadata so the connector card always appears in the UI.
 * When the Pro plugin is active, the real MailjetConnector replaces this stub
 * via the 'mailerpress_register_sync_connectors' hook.
 */
class MailjetConnectorStub extends ProConnectorStub
{
    public function getKey(): string
    {
        return 'mailjet';
    }

    public function getLabel(): string
    {
        return __('Mailjet', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Import and synchronize contacts from all your Mailjet contact lists.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="4 3 92 92"><g filter="url(#mj_shadow)"><rect width="92" height="92" x="4" y="3" fill="#ED4536" rx="46"/><path fill="#fff" d="M50 28 28 72h14.4L50 55.2 57.6 72H72L50 28Z"/></g><defs><filter id="mj_shadow" width="100" height="100" x="0" y="0" color-interpolation-filters="sRGB" filterUnits="userSpaceOnUse"><feFlood flood-opacity="0" result="BackgroundImageFix"/><feColorMatrix in="SourceAlpha" result="hardAlpha" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"/><feOffset dy="1"/><feGaussianBlur stdDeviation="2"/><feColorMatrix values="0 0 0 0 0.069 0 0 0 0 0.098 0 0 0 0 0.38 0 0 0 0.078 0"/><feBlend in2="BackgroundImageFix" result="effect1_dropShadow"/><feBlend in="SourceGraphic" in2="effect1_dropShadow" result="shape"/></filter></defs></svg>';
    }
}
