<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Notifications\AbstractNotificationMessage;

/**
 * Notification shown when a recommended plugin is not activated
 */
class PluginNotActivatedNotification extends AbstractNotificationMessage
{
    protected string $type = 'warning';
    protected ?int $duration = null; // Manual dismiss only
    protected bool $dismissible = true;

    public function __construct(private string $pluginName, private string $pluginSlug)
    {
    }

    public function getMessage(): string
    {
        return sprintf(
            __('%s plugin is not activated. This plugin can enhance your MailerPress experience.', 'mailerpress'),
            $this->pluginName
        );
    }

    public function shouldDisplay(): bool
    {
        // Check if the plugin is installed but not activated
        return is_plugin_installed($this->pluginSlug) && !is_plugin_active($this->pluginSlug);
    }

    public function getAction(): ?array
    {
        if (!is_plugin_installed($this->pluginSlug)) {
            return null;
        }

        return [
            'label' => __('Activate', 'mailerpress'),
            'url' => wp_nonce_url(
                admin_url('plugins.php?action=activate&plugin=' . $this->pluginSlug),
                'activate-plugin_' . $this->pluginSlug
            ),
        ];
    }
}

/**
 * Helper function to check if a plugin is installed
 */
if (!function_exists('is_plugin_installed')) {
    function is_plugin_installed(string $plugin_slug): bool
    {
        $plugins = get_plugins();
        foreach ($plugins as $plugin_file => $plugin_data) {
            if (strpos($plugin_file, $plugin_slug) === 0) {
                return true;
            }
        }
        return false;
    }
}
