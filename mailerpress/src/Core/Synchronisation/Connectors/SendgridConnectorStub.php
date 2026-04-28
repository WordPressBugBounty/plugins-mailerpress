<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation\Connectors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Synchronisation\ProConnectorStub;

/**
 * Free-plugin stub for the SendGrid connector.
 *
 * Exposes metadata so the connector card always appears in the UI.
 * When the Pro plugin is active, the real SendgridConnector replaces this stub
 * via the 'mailerpress_register_sync_connectors' hook.
 */
class SendgridConnectorStub extends ProConnectorStub
{
    public function getKey(): string
    {
        return 'sendgrid';
    }

    public function getLabel(): string
    {
        return __('SendGrid', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Import and synchronize contacts from all your SendGrid Marketing lists.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="4 3 92 92"><g filter="url(#sg_shadow)"><rect width="92" height="92" x="4" y="3" fill="#1A82E2" rx="46"/><path fill="#fff" d="M28 49.5C28 38.178 37.178 29 48.5 29h4.8A1.7 1.7 0 0 1 55 30.7v3.6a1.7 1.7 0 0 1-1.7 1.7H48.5C41.044 36 35 42.044 35 49.5S41.044 63 48.5 63h4.8a1.7 1.7 0 0 1 1.7 1.7v3.6A1.7 1.7 0 0 1 53.3 70H48.5C37.178 70 28 60.822 28 49.5Zm21.5-8.3h11.8a1.7 1.7 0 0 1 1.7 1.7v13.6a1.7 1.7 0 0 1-1.7 1.7H49.5a6.5 6.5 0 0 1 0-13h5.8a1.7 1.7 0 0 0 1.7-1.7 1.7 1.7 0 0 0-1.7-1.7H49.5a1.7 1.7 0 0 1-1.7-1.7 1.7 1.7 0 0 1 1.7-1.7h.001Zm6.8 10h-6.8a1.5 1.5 0 0 0 0 3h6.8v-3Z"/></g><defs><filter id="sg_shadow" width="100" height="100" x="0" y="0" color-interpolation-filters="sRGB" filterUnits="userSpaceOnUse"><feFlood flood-opacity="0" result="BackgroundImageFix"/><feColorMatrix in="SourceAlpha" result="hardAlpha" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"/><feOffset dy="1"/><feGaussianBlur stdDeviation="2"/><feColorMatrix values="0 0 0 0 0.069 0 0 0 0 0.098 0 0 0 0 0.38 0 0 0 0.078 0"/><feBlend in2="BackgroundImageFix" result="effect1_dropShadow"/><feBlend in="SourceGraphic" in2="effect1_dropShadow" result="shape"/></filter></defs></svg>';
    }
}
