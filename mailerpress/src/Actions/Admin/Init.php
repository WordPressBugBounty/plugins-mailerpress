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
        $is_editor = isset($_GET['page'])
            && 'mailerpress/new' === sanitize_text_field(wp_unslash($_GET['page']));

        if ($user_id && $is_editor) {
            $is_fullscreen = get_user_meta($user_id, 'mailerpress_fullscreen', true);

            if ($is_fullscreen === '') {
                $classes .= ' mailerpress-ui-full-screen';
            } else {
                $classes .= ' mailerpress-ui-no-full-screen';
            }
        } else {
            $classes .= ' mailerpress-ui-full-screen';
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

        $isTemplateRequest = isset($_GET['template_edit'])
            || (
                isset($_GET['template_mode'])
                && sanitize_key(wp_unslash($_GET['template_mode'])) === 'new'
            );
        $requestedCampaignType = isset($_GET['campaign_type'])
            ? sanitize_key(wp_unslash($_GET['campaign_type']))
            : '';
        $campaignId = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $campaign = $campaignId > 0
            ? Kernel::getContainer()->get(Campaigns::class)->find($campaignId)
            : null;
        $campaignType = $campaign->campaign_type ?? $requestedCampaignType;
        $isAutomationCampaign = $campaignType === 'automation';
        $isSettingsEmail = in_array($campaignType, ['wp_email', 'wc_email', 'confirm_email'], true);
        $requiredCapability = $isTemplateRequest
            ? Capabilities::MANAGE_TEMPLATES
            : (
                $isAutomationCampaign
                    ? Capabilities::MANAGE_AUTOMATIONS
                    : ($isSettingsEmail ? Capabilities::MANAGE_SETTINGS : Capabilities::MANAGE_CAMPAIGNS)
            );

        if (!current_user_can($requiredCapability)) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'mailerpress'));
        }

        if ($campaignId === 0) {
            return;
        }

        if (!$campaign) {
            // Campaign not found - might be a race condition, allow access and let the editor handle it
            return;
        }

        $isSiteTemplate = $this->isSiteTemplateCampaign($campaignId, $campaignType);

        if ($isSiteTemplate && 'trash' !== $campaign->status) {
            return;
        }

        $current_user_id = get_current_user_id();

        // Check if the user owns the item or can edit other users' items.
        if ((int)$campaign->user_id === $current_user_id) {
            $canEdit = true;
        } elseif ($isSettingsEmail) {
            $canEdit = true;
        } elseif ($isAutomationCampaign) {
            $canEdit = current_user_can('edit_others_posts');
        } else {
            $canEdit = current_user_can(Capabilities::EDIT_OTHERS_CAMPAIGNS);
        }

        if (!$canEdit) {
            wp_die(__('Sorry, you are not allowed to edit this item.', 'mailerpress'));
        }

        // Only block editing if campaign is in a non-editable status
        // Allow editing draft, scheduled, and error campaigns
        // Exception: Always allow editing automation campaigns (campaign_type = 'automation') regardless of status
        if (!$isAutomationCampaign && in_array($campaign->status, ['sent', 'pending', 'trash', 'in_progress'], true)) {
            wp_die(__('Sorry, you are not allowed to edit this item.', 'mailerpress'));
        }
    }

    private function isSiteTemplateCampaign(int $campaignId, string $campaignType): bool
    {
        if (in_array($campaignType, ['confirm_email', 'wp_email', 'wc_email'], true)) {
            return true;
        }

        $signupConfirmation = mailerpress_get_signup_confirmation_option();
        if ((int)($signupConfirmation['campaign_id'] ?? 0) === $campaignId) {
            return true;
        }

        foreach (['mailerpress_wp_email_templates', 'mailerpress_wc_email_templates'] as $optionName) {
            $templates = get_option($optionName, []);
            if (is_string($templates)) {
                $templates = json_decode($templates, true);
            }

            if (!is_array($templates)) {
                continue;
            }

            foreach ($templates as $template) {
                if (is_array($template) && (int)($template['campaign_id'] ?? 0) === $campaignId) {
                    return true;
                }
            }
        }

        return false;
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
