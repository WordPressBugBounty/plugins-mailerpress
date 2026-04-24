<?php

declare(strict_types=1);

namespace MailerPress\Actions\Admin;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Capabilities;
use MailerPress\Core\CapabilitiesManager;
use MailerPress\Core\DynamicPostRenderer;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use MailerPress\Models\Campaigns;

class Init
{
    /**
     * Allows you to display the plugin setup if the configuration has not been done.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    #[Action('admin_init')]
    public function maybeShowWizardSetup(): void
    {
        // Avoid redirecting during AJAX, network admin, or for unauthorized users
        if (wp_doing_ajax() || is_network_admin() || !current_user_can('manage_options')) {
            return;
        }

        // Check if the setup wizard has been completed
        $pluginActivated = get_option('mailerpress_activated');

        // Get the current admin page
        $currentPage = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        // Check if the setup is incomplete and we're not already on the target page
        if (
            false === Kernel::getContainer()->get(Editor::class)->checkPluginInit()
            && 'yes' === $pluginActivated
            && 'mailerpress/campaigns.php' !== $currentPage // Avoid redirecting to the same page
        ) {
            // Delete the activation flag
            delete_option('mailerpress_activated');
            // Redirect to the setup wizard page
            wp_safe_redirect(esc_url_raw(admin_url('admin.php?page=mailerpress/campaigns.php')));

            exit; // Important to stop further execution
        }
    }

    #[Action('admin_body_class')]
    public function addAdminBodyClass(string $classes): string
    {
        $user_id = get_current_user_id();

        if ($user_id) {
            $is_fullscreen = get_user_meta($user_id, 'mailerpress_fullscreen', true);

            if ($is_fullscreen === '') {
                $classes .= ' mailerpress-ui-full-screen';
            } else {
                $classes .= ' mailerpress-ui-no-full-screen';
            }
        }

        return $classes;
    }

    #[Action('admin_init')]
    public function maybeRestrictCampaignAccess(): void
    {
        // Only run on your plugin's edit page
        if (!isset($_GET['page']) || $_GET['page'] !== 'mailerpress/new') {
            return;
        }

        // Check basic capability first
        if (!current_user_can(Capabilities::MANAGE_CAMPAIGNS)) {
            wp_die(__('Sorry, you are not allowed to access this page.'));
        }

        // Check if it's an edit request
        if (empty($_GET['edit'])) {
            // No edit parameter means it's a new campaign, allow access
            return;
        }

        $campaign_id = (int)$_GET['edit'];
        $campaign = Kernel::getContainer()->get(Campaigns::class)->find($campaign_id);

        if (!$campaign) {
            // Campaign not found - might be a race condition, allow access and let the editor handle it
            return;
        }

        $current_user_id = get_current_user_id();

        // Check if user owns the campaign or can edit others
        if ((int)$campaign->user_id === $current_user_id) {
            $canEdit = current_user_can(Capabilities::MANAGE_CAMPAIGNS);
        } else {
            $canEdit = current_user_can(Capabilities::EDIT_OTHERS_CAMPAIGNS);
        }

        if (!$canEdit) {
            wp_die(__('Sorry, you are not allowed to edit this item.'));
        }

        // Only block editing if campaign is in a non-editable status
        // Allow editing draft, scheduled, and error campaigns
        if (in_array($campaign->status, ['sent', 'pending', 'trash', 'in_progress'], true)) {
            wp_die(__('Sorry, you are not allowed to edit this item.'));
        }
    }

    /**
     * Clear update transients when accessing the update-core.php page.
     * This ensures that update information is refreshed when the user visits the updates page.
     */
    #[Action('load-update-core.php')]
    public function clearUpdateTransients(): void
    {
        // Clear the MailerPress Pro update info transient
        delete_transient('mailerpress_update_info');
    }
}
