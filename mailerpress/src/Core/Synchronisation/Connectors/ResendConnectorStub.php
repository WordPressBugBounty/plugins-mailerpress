<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation\Connectors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Synchronisation\ProConnectorStub;

/**
 * Free-plugin stub for the Resend connector.
 *
 * Exposes metadata so the connector card always appears in the UI.
 * When the Pro plugin is active, the real ResendConnector replaces this stub
 * via the 'mailerpress_register_sync_connectors' hook.
 */
class ResendConnectorStub extends ProConnectorStub
{
    public function getKey(): string
    {
        return 'resend';
    }

    public function getLabel(): string
    {
        return __('Resend', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Import and synchronize contacts from all your Resend audiences.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 92 92" fill="none"><rect width="92" height="92" rx="46" fill="black"></rect><path d="M50.4381 24.75C58.5726 24.75 63.4101 29.5879 63.4101 36.0098C63.4101 42.4318 58.5726 47.2696 50.4381 47.2696H46.3278L66.75 66.75H52.3216L36.7804 51.9791C35.6674 50.9519 35.1537 49.7531 35.1536 48.7257C35.1536 47.27 36.1814 45.9856 38.1508 45.429L46.1566 43.2881C49.1963 42.4746 51.2945 40.1198 51.2945 37.0372C51.294 33.2698 48.2114 31.0864 44.4011 31.0864H24.75V24.75H50.4381Z" fill="white"></path></svg>';
    }
}
