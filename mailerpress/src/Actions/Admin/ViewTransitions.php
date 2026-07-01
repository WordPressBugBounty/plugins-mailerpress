<?php

declare(strict_types=1);

namespace MailerPress\Actions\Admin;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class ViewTransitions
{
    private const ADMIN_VIEW_TRANSITIONS_HANDLE = 'wp-view-transitions-admin';
    private const OPT_OUT_HANDLE = 'mailerpress-admin-view-transitions-opt-out';

    #[Action('admin_enqueue_scripts', priority: 0)]
    public function enqueueViewTransitionOptOut(): void
    {
        if (!$this->isMailerPressAdminPage()) {
            return;
        }

        $version = defined('MAILERPRESS_VERSION') ? MAILERPRESS_VERSION : null;

        wp_register_style(self::OPT_OUT_HANDLE, false, [], $version);
        wp_enqueue_style(self::OPT_OUT_HANDLE);
        wp_add_inline_style(self::OPT_OUT_HANDLE, '@view-transition { navigation: none; }');
    }

    #[Action(['admin_enqueue_scripts', 'admin_print_styles', 'wp_print_styles'], priority: 999)]
    public function disableWordPressAdminViewTransitions(): void
    {
        if (!$this->isMailerPressAdminPage()) {
            return;
        }

        wp_dequeue_style(self::ADMIN_VIEW_TRANSITIONS_HANDLE);
        wp_deregister_style(self::ADMIN_VIEW_TRANSITIONS_HANDLE);
    }

    private function isMailerPressAdminPage(): bool
    {
        if (!is_admin()) {
            return false;
        }

        $page = isset($_GET['page'])
            ? rawurldecode(sanitize_text_field(wp_unslash($_GET['page'])))
            : '';

        return str_starts_with($page, 'mailerpress');
    }
}
