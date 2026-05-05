<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation\Connectors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Synchronisation\ProConnectorStub;

/**
 * Free-plugin stub for the EmailIt connector.
 *
 * Exposes metadata so the connector card always appears in the UI.
 * When the Pro plugin is active, the real EmailItConnector replaces this stub
 * via the 'mailerpress_register_sync_connectors' hook.
 */
class EmailItConnectorStub extends ProConnectorStub
{
    public function getKey(): string
    {
        return 'emailit';
    }

    public function getLabel(): string
    {
        return __('Emailit', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Import and synchronize contacts from all your Emailit audiences.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return '<svg style="width: 44px;height: 44px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 67.64 53.24"><path d="M33.82,26.46c7.45.24,14.34-6.45,32.62-19.58-1.96-4.06-6.13-6.88-10.92-6.88H12.12C7.33,0,3.17,2.82,1.2,6.88c18.29,13.12,25.18,19.82,32.62,19.58h0Z" style="fill: rgb(0, 123, 94); fill-rule: evenodd;"></path><path d="M18.11,25.91c4.71,3.17,9.84,6.13,15.71,5.99,5.87.13,11-2.82,15.71-5.99,6.14-4.13,12.1-8.78,18.11-13.18v28.39c0,6.66-5.45,12.12-12.12,12.12H12.12c-6.66,0-12.12-5.45-12.12-12.12V12.73c6.02,4.39,11.97,9.05,18.11,13.18h0Z" style="fill: rgb(21, 193, 130); fill-rule: evenodd;"></path></svg>';
    }
}
