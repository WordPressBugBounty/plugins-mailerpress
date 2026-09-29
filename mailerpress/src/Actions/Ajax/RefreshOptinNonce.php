<?php

declare(strict_types=1);

namespace MailerPress\Actions\Ajax;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class RefreshOptinNonce
{
    #[Action(['wp_ajax_mailerpress_refresh_optin_nonce', 'wp_ajax_nopriv_mailerpress_refresh_optin_nonce'])]
    public function handle(): void
    {
        nocache_headers();

        wp_send_json_success([
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'optin_nonce' => wp_create_nonce('mailerpress_optin_submit'),
            'manage_nonce' => wp_create_nonce('mailerpress_update_contact_nonce'),
        ]);
    }
}
