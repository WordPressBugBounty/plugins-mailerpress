<?php

namespace MailerPress\Actions\Ajax;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class DismissGoProNotice
{
    #[Action('wp_ajax_mailerpress_dismiss_go_pro')]
    public function handle()
    {
        // Verify nonce
        check_ajax_referer('mailerpress_dismiss_go_pro', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error([
                'message' => __('User not found.', 'mailerpress'),
            ], 403);
        }

        // Update user meta to mark notice as dismissed
        update_user_meta($user_id, 'mailerpress_go_pro_notice', 1);

        wp_send_json_success([
            'message' => __('Notice dismissed.', 'mailerpress'),
        ]);
    }
}
