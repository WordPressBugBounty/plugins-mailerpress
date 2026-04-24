<?php

namespace MailerPress\Actions\Widgets;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Kernel;
use MailerPress\Models\Contacts;

class CreateCampaign
{
    //#[Action('wp_dashboard_setup')]
    public function mailerpress_register_dashboard_widget()
    {
        wp_add_dashboard_widget(
            'mailerpress_dashboard_widget', // Widget slug (ID)
            esc_html__('MailerPress', 'mailerpress'), // Widget title
            [$this, 'mailerpress_dashboard_widget_content'] // Function to display the content
        );
    }

    public function mailerpress_dashboard_widget_content(): void
    {
        $subscriber_count = Kernel::getContainer()->get(Contacts::class)->count();
        /* translators: %s is the number of total subscribers */
        echo '<p>' . wp_kses_post( sprintf( __('Total Subscribers: <strong>%s</strong>', 'mailerpress'), number_format_i18n($subscriber_count) ) ) . '</p>';
    }
}