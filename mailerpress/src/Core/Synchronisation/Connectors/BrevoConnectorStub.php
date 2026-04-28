<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation\Connectors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Synchronisation\ProConnectorStub;

/**
 * Free-plugin stub for the Brevo connector.
 *
 * Exposes metadata so the connector card always appears in the UI.
 * When the Pro plugin is active, the real BrevoConnector replaces this stub
 * via the 'mailerpress_register_sync_connectors' hook.
 */
class BrevoConnectorStub extends ProConnectorStub
{
    public function getKey(): string
    {
        return 'brevo';
    }

    public function getLabel(): string
    {
        return __('Brevo', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Import and synchronize contacts from all your Brevo lists.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="4 3 92 92"><g filter="url(#filter0_d_4669_137863)"><rect width="92" height="92" x="4" y="3" fill="#0B996E" rx="46"></rect><path fill="#fff" d="M37.694 49.02V28.3H50.14c4.203 0 6.98 2.462 6.98 6.198 0 4.244-3.615 7.47-11.015 9.933-5.046 1.611-7.315 2.97-8.157 4.586l-.254.003Zm0 20.802v-8.66c0-3.82 3.196-7.556 7.653-9 3.954-1.36 7.231-2.718 10.008-4.16 3.7 2.21 5.97 6.027 5.97 10.019 0 6.791-6.393 11.8-15.054 11.8h-8.577Zm-7.569 7.3h16.819c12.784 0 22.368-8.065 22.368-18.763 0-5.86-2.942-11.121-8.157-14.519 2.692-2.718 3.954-5.86 3.954-9.68 0-7.895-5.635-13.16-14.127-13.16H30.125v56.122Z"></path></g><defs><filter id="filter0_d_4669_137863" width="100" height="100" x="0" y="0" color-interpolation-filters="sRGB" filterUnits="userSpaceOnUse"><feFlood flood-opacity="0" result="BackgroundImageFix"></feFlood><feColorMatrix in="SourceAlpha" result="hardAlpha" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"></feColorMatrix><feOffset dy="1"></feOffset><feGaussianBlur stdDeviation="2"></feGaussianBlur><feColorMatrix values="0 0 0 0 0.0687866 0 0 0 0 0.097585 0 0 0 0 0.37981 0 0 0 0.0779552 0"></feColorMatrix><feBlend in2="BackgroundImageFix" result="effect1_dropShadow_4669_137863"></feBlend><feBlend in="SourceGraphic" in2="effect1_dropShadow_4669_137863" result="shape"></feBlend></filter></defs></svg>';
    }
}
