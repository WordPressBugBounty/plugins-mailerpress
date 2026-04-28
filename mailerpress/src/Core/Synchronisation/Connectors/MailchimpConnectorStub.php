<?php

declare(strict_types=1);

namespace MailerPress\Core\Synchronisation\Connectors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Synchronisation\ProConnectorStub;

/**
 * Free-plugin stub for the Mailchimp connector.
 *
 * Exposes metadata so the connector card always appears in the UI.
 * When the Pro plugin is active, the real MailchimpConnector replaces this stub
 * via the 'mailerpress_register_sync_connectors' hook.
 */
class MailchimpConnectorStub extends ProConnectorStub
{
    public function getKey(): string
    {
        return 'mailchimp';
    }

    public function getLabel(): string
    {
        return __('Mailchimp', 'mailerpress');
    }

    public function getDescription(): string
    {
        return __('Import and synchronize contacts from all your Mailchimp audiences.', 'mailerpress');
    }

    public function getIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="4 3.043 92 92" fill="none"><g filter="url(#filter0_d_2944_85294)"><rect width="92" height="92" x="4" y="3.043" fill="#FFE01B" rx="46"></rect><path fill="#1E1E1E" d="m73.282 55.794-.86-2.438s3.585-5.45-5.307-7.315c0 0 .574-6.74-2.581-9.895 6.884-6.74 5.306-16.923-10.326-10.326-8.031-15.345-39.009 20.652-26.102 26.388-1.29 1.721-1.29 10.326 7.601 11.187 6.024 12.907 20.652 13.767 29.114 9.895 8.461-3.872 13.337-16.206 8.461-17.496ZM35.564 61.53c-7.314-.717-8.031-10.756-1.721-11.76 6.31-1.004 7.888 12.334 1.721 11.76Zm-2.151-13.624c-2.008 0-4.446 2.725-4.446 2.725-9.752-4.733 17.64-36.14 23.52-23.95 0 0-14.342 6.883-19.074 21.225Zm28.683 12.19c0-.574-3.012.86-8.462-1.004.43-3.012 6.884 2.582 17.64-4.733l.86 3.012c4.016-.717 0 12.907-12.907 12.764-10.469-.143-13.767-10.9-8.03-16.78 1.146-1.147-4.16-3.441-3.156-8.461.43-2.151 2.295-5.306 7.027-4.446 4.733.86 5.737-3.442 8.892-1.864 3.155 1.577 1.29 7.6 1.721 8.461.43.86 5.02 1.004 5.88 3.442.86 2.438-5.88 7.745-16.35 6.31-2.437-.286-3.871 2.869-2.294 4.877 3.155 4.589 16.063 1.577 18.214-2.869-5.45 4.16-16.636 5.737-17.497 1.291 3.155 1.434 8.462.574 8.462 0Zm-18.788-22.66c3.156-3.872 7.315-6.166 7.315-6.166l-.86 2.15s3.01-2.294 6.31-2.294l-1.148 1.147c3.729.144 5.306 1.578 5.306 1.578s-8.748-2.581-16.923 3.586ZM62.67 43.03c1.865-.144 1.291 4.159 1.291 4.159h-1.147s-2.008-4.016-.144-4.16Zm-8.461 4.732c-1.29.144-2.725.86-2.581.287.573-2.294 5.88-1.72 5.736.287-.143 2.008-1.29-.86-3.155-.574Zm3.012 1.721c.143.287-1.004 0-1.865.144-.86.143-1.72.573-1.72.287 0-.287 3.298-1.578 3.585-.43Zm2.868.43c.43-.86 2.151 0 1.72.861-.43.86-2.15 0-1.72-.86Zm3.585.287c-.86 0-.86-1.864 0-1.864s.86 2.008 0 2.008V50.2ZM37.86 57.801c.43.43-.86 1.291-1.865.574-1.004-.717 1.148-4.159-1.434-5.02-2.581-.86-1.864 2.439-2.581 2.008-.717-.43 1.004-5.02 4.015-3.155 3.012 1.865-.86 4.733.86 5.593 1.722.86.718-.287 1.005 0Z"></path></g><defs><filter id="filter0_d_2944_85294" width="100" height="100" x="0" y=".043" color-interpolation-filters="sRGB" filterUnits="userSpaceOnUse"><feFlood flood-opacity="0" result="BackgroundImageFix"></feFlood><feColorMatrix in="SourceAlpha" result="hardAlpha" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"></feColorMatrix><feOffset dy="1"></feOffset><feGaussianBlur stdDeviation="2"></feGaussianBlur><feColorMatrix values="0 0 0 0 0.0687866 0 0 0 0 0.097585 0 0 0 0 0.37981 0 0 0 0.0779552 0"></feColorMatrix><feBlend in2="BackgroundImageFix" result="effect1_dropShadow_2944_85294"></feBlend><feBlend in="SourceGraphic" in2="effect1_dropShadow_2944_85294" result="shape"></feBlend></filter></defs></svg>';
    }
}
