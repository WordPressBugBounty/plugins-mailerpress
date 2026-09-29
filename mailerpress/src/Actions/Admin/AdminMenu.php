<?php

namespace MailerPress\Actions\Admin;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Capabilities;

class AdminMenu
{
    private const SHOW_ADDONS_MENU = false;

    private array|string $options = [];

    private static function adminMenuIcon(): string
    {
        return \defined('MAILERPRESS_PLUGIN_DIR_URL')
            ? MAILERPRESS_PLUGIN_DIR_URL . 'build/public/images/admin-menu-logo.svg'
            : 'none';
    }

    private static function getMenuCapability(): string
    {
        foreach (Capabilities::get_capabilities() as $capability) {
            if (current_user_can($capability)) {
                return $capability;
            }
        }

        return 'do_not_allow';
    }

    private static function getAudienceCapability(): string
    {
        foreach ([
            Capabilities::MANAGE_CONTACTS,
            Capabilities::MANAGE_LISTS,
            Capabilities::MANAGE_CONTACT_SEGMENTATION,
            Capabilities::MANAGE_TAGS,
            Capabilities::MANAGE_SETTINGS,
        ] as $capability) {
            if (current_user_can($capability)) {
                return $capability;
            }
        }

        return 'do_not_allow';
    }

    private static function getEditorCapability(): string
    {
        foreach ([
            Capabilities::MANAGE_CAMPAIGNS,
            Capabilities::MANAGE_TEMPLATES,
            Capabilities::MANAGE_AUTOMATIONS,
            Capabilities::MANAGE_SETTINGS,
        ] as $capability) {
            if (current_user_can($capability)) {
                return $capability;
            }
        }

        return 'do_not_allow';
    }

    public static function mailerpressRoot(): void
    {
        // Normalize path
        $path = isset($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';

        $capability = match ($path) {
            '', '/home' => self::getMenuCapability(),
            '/home/settings', '/home/tools', '/home/integrations', '/home/webhooks' => Capabilities::MANAGE_SETTINGS,
            '/home/contacts' => match (isset($_GET['activeView']) ? sanitize_text_field(wp_unslash($_GET['activeView'])) : '') {
                'segmentation', 'Segmentation' => Capabilities::MANAGE_CONTACT_SEGMENTATION,
                'contact-lists', 'Contact Lists' => Capabilities::MANAGE_LISTS,
                'contact-tags', 'Contact Tags' => Capabilities::MANAGE_TAGS,
                default => self::getAudienceCapability(),
            },
            '/home/templates' => Capabilities::MANAGE_TEMPLATES,
            '/home/workflow' => Capabilities::MANAGE_AUTOMATIONS,
            default => Capabilities::MANAGE_CAMPAIGNS,
        };

        // Access check
        if (!current_user_can($capability)) : ?>
            <div class="wrap">
                <div class="mp-error-page"
                    style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 300px; text-align: center; padding: 50px 20px;">
                    <h2 style="font-size: 28px; font-weight: 400; margin-bottom: 10px; color: #222;">
                        <?php esc_html_e('Access Denied', 'mailerpress'); ?>
                    </h2>
                    <p style="font-size: 16px; color: #555; max-width: 400px; margin-bottom: 30px; line-height: 1.5;">
                        <?php esc_html_e(
                            'Sorry, you do not have the necessary permissions to access this page. Please contact your administrator if you believe this is an error.',
                            'mailerpress'
                        ); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome')); ?>"
                        class="button button-primary">
                        <?php esc_html_e('Return to Dashboard', 'mailerpress'); ?>
                    </a>
                </div>
            </div>
        <?php
            return;
        endif;

        ?>

        <div id="mailerpress">
            <?php self::renderInitialLoader(); ?>
        </div>
        <div id="toast-root"></div>
    <?php
    }

    public static function mailpressCampaigns(): void
    {
    ?>
        <div id="mailerpress-root">
            <?php self::renderInitialLoader(); ?>
        </div>
        <div id="toast-root"></div>
        <?php
    }

    public function mailerpressWorkflow()
    {
        // Access check
        if (!current_user_can(Capabilities::MANAGE_AUTOMATIONS)) : ?>
            <div class="wrap">
                <div class="mp-error-page"
                    style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 300px; text-align: center; padding: 50px 20px;">
                    <h2 style="font-size: 28px; font-weight: 400; margin-bottom: 10px; color: #222;">
                        <?php esc_html_e('Access Denied', 'mailerpress'); ?>
                    </h2>
                    <p style="font-size: 16px; color: #555; max-width: 400px; margin-bottom: 30px; line-height: 1.5;">
                        <?php esc_html_e(
                            'Sorry, you do not have the necessary permissions to access this page. Please contact your administrator if you believe this is an error.',
                            'mailerpress'
                        ); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome')); ?>"
                        class="button button-primary">
                        <?php esc_html_e('Return to Dashboard', 'mailerpress'); ?>
                    </a>
                </div>
            </div>
        <?php
            return;
        endif;

        ?>
        <div id="mailerpress-workflow-root">
            <?php self::renderInitialLoader(); ?>
        </div>
    <?php
    }

    private static function renderInitialLoader(): void
    {
        ?>
        <style>
            .mailerpress-initial-loader {
                align-items: center;
                background: #1f1f1f;
                color: #ffffff;
                display: flex;
                flex-direction: column;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                gap: 16px;
                inset: 0;
                justify-content: center;
                min-height: 100vh;
                position: absolute;
                z-index: 1;
            }

            .mailerpress-initial-loader__spinner {
                animation: mailerpress-initial-loader-spin 0.9s linear infinite;
                border: 3px solid rgba(255, 255, 255, 0.2);
                border-top-color: #ffffff;
                border-radius: 999px;
                height: 36px;
                width: 36px;
            }

            .mailerpress-initial-loader__label {
                font-size: 13px;
                font-weight: 500;
                line-height: 1.4;
                opacity: 0.82;
            }

            @keyframes mailerpress-initial-loader-spin {
                to {
                    transform: rotate(360deg);
                }
            }
        </style>
        <div class="mailerpress-initial-loader" role="status" aria-live="polite">
            <div class="mailerpress-initial-loader__spinner" aria-hidden="true"></div>
            <div class="mailerpress-initial-loader__label">
                <?php esc_html_e('Loading MailerPress...', 'mailerpress'); ?>
            </div>
        </div>
        <?php
    }


    #[Action('admin_menu')]
    public function adminMenu(): void
    {
        // Always load options fresh
        $options =  apply_filters('mailerpress_white_label_options', [
            'white_label_active' => false,
        ]);

        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            $options = [];
        }

        $this->options = $options; // Keep available in class if needed

        // Base default labels
        $labels = [
            'main' => __('MailerPress', 'mailerpress'),
            'dashboard' => __('Dashboard', 'mailerpress'),
            'campaigns' => __('Campaigns', 'mailerpress'),
            'audience' => __('Audience', 'mailerpress'),
            'templates' => __('Templates', 'mailerpress'),
            'integrations' => __('Integrations', 'mailerpress'),
            'webhooks' => __('Webhooks', 'mailerpress'),
            'tools' => __('Tools', 'mailerpress'),
            'settings' => __('Settings', 'mailerpress'),
            'licence' => __('License', 'mailerpress'),
            'workflow' => __('Automations', 'mailerpress'),
        ];

        // Override if white-label active
        if (!empty($options['white_label_active'])) {
            $labels['main'] = $options['admin_menu_title'] ?? $labels['main'];
            $labels['dashboard'] = $options['dashboard_name'] ?? $labels['dashboard'];
            $labels['campaigns'] = $options['campaigns_name'] ?? $labels['campaigns'];
            $labels['audience'] = $options['audience_name'] ?? $labels['audience'];
            $labels['templates'] = $options['templates_name'] ?? $labels['templates'];
            $labels['integrations'] = $options['integrations_name'] ?? $labels['integrations'];
            $labels['webhooks'] = $options['webhooks_name'] ?? $labels['webhooks'];
            $labels['tools'] = $options['tools_name'] ?? $labels['tools'];
            $labels['settings'] = $options['settings_name'] ?? $labels['settings'];
        }

        // Fallback icon if no white label
        $menu_icon = !empty($options['white_label_active'])
            ? 'dashicons-email'
            : 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMDAgMjAwIj4KICA8cGF0aAogICAgZmlsbD0iIzAwMCIKICAgIGQ9Ik0xNzIuMDQsMTY2LjQyaC00OC41MnYtMzYuMzhsLTQ4LjUxLDM2LjM4di0zMy4xNmwtNDcuNzYsMzMuMTZ2LTg4LjVsNDcuOTgtMzMuNTl2MjUuM2w0OC4yOS0zNi41aDQ4LjUydjEzMy4yOFoiCiAgLz4KPC9zdmc+Cg==';

        // Register top-level menu
        add_menu_page(
            $labels['main'],
            $labels['main'],
            self::getMenuCapability(),
            'mailerpress/campaigns.php',
            [$this, 'mailerpressRoot'],
            $menu_icon,
            20
        );

        // Define submenus
        $submenus = [
            [
                'title' => $labels['dashboard'],
                'menu_title' => $labels['dashboard'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome',
                'cap' => self::getMenuCapability(),
            ],
            [
                'title' => __('New Campaign', 'mailerpress'),
                'menu_title' => __('New Campaign', 'mailerpress'),
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome&view=create-campaign',
                'cap' => Capabilities::MANAGE_CAMPAIGNS,
            ],
            [
                'title' => $labels['campaigns'],
                'menu_title' => $labels['campaigns'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fcampaigns',
                'cap' => Capabilities::MANAGE_CAMPAIGNS,
            ],
            [
                'title' => __('Email Editor', 'mailerpress'),
                'menu_title' => __('Email Editor', 'mailerpress'),
                'slug' => 'mailerpress/new',
                'cap' => self::getEditorCapability(),
                'callback' => 'mailpressCampaigns',
            ],
            [
                'title' => $labels['audience'],
                'menu_title' => $labels['audience'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fcontacts',
                'cap' => self::getAudienceCapability(),
            ],
            [
                'title' => $labels['templates'],
                'menu_title' => $labels['templates'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Ftemplates',
                'cap' => Capabilities::MANAGE_TEMPLATES,
            ],
            [
                'title' => '',
                'menu_title' => '',
                'slug' => 'mailerpress/workflow',
                'cap' => Capabilities::MANAGE_AUTOMATIONS,
                'callback' => 'mailerpressWorkflow',
            ],
            [
                'title' => $labels['workflow'],
                'menu_title' => $labels['workflow'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fworkflow',
                'cap' => Capabilities::MANAGE_AUTOMATIONS,
            ],
            [
                'title' => $labels['integrations'],
                'menu_title' => $labels['integrations'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fintegrations',
                'cap' => Capabilities::MANAGE_SETTINGS,

            ],
            [
                'title' => $labels['webhooks'],
                'menu_title' => $labels['webhooks'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fwebhooks',
                'cap' => Capabilities::MANAGE_SETTINGS,
            ],
            [
                'title' => $labels['tools'],
                'menu_title' => $labels['tools'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Ftools',
                'cap' => Capabilities::MANAGE_SETTINGS,
            ],
            // [
            //     'title' => __('Add-ons', 'mailerpress'),
            //     'menu_title' => __('Add-ons', 'mailerpress'),
            //     'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Faddons',
            //     'cap' => 'edit_posts',
            // ],
            [
                'title' => $labels['settings'],
                'menu_title' => $labels['settings'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings',
                'cap' => Capabilities::MANAGE_SETTINGS,

            ],
            self::SHOW_ADDONS_MENU ? [
                'title' => __('Add-ons', 'mailerpress'),
                'menu_title' => __('Add-ons', 'mailerpress'),
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Faddons',
                'cap' => 'edit_posts',
            ] : null,
            // [
            //     'title' => __('Getting Started', 'mailerpress'),
            //     'menu_title' => __('Getting Started', 'mailerpress'),
            //     'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fgetting-started',
            //     'cap' => 'edit_posts',
            // ],
            // [
            //     'title' => __('Documentation', 'mailerpress'),
            //     'menu_title' => __('Documentation', 'mailerpress'),
            //     'slug' => 'https://mailerpress.com/docs/',
            //     'cap' => 'edit_posts',
            //     'external' => true,
            // ],
        ];
        $submenus = array_values(array_filter($submenus));

        if (function_exists('is_plugin_active') && is_plugin_active('mailerpress-pro/mailerpress-pro.php')) {
            $submenus[] = [
                'title' => $labels['licence'],
                'menu_title' => $labels['licence'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings&activeView=licence',
                'cap' => Capabilities::MANAGE_SETTINGS,
            ];
        }

        // Register all submenus
        foreach ($submenus as $menu_item) {
            if (!empty($menu_item['external'])) {
                // Skip external links here, we'll add them after all menus are registered
                continue;
            }
            add_submenu_page(
                'mailerpress/campaigns.php',
                $menu_item['title'],
                $menu_item['menu_title'],
                $menu_item['cap'],
                $menu_item['slug'],
                [$this, !empty($menu_item['callback']) ? $menu_item['callback'] : 'mailpressCampaigns']
            );
        }

        // Add external links after all menus are registered
        foreach ($submenus as $menu_item) {
            if (!empty($menu_item['external'])) {
                global $submenu;
                if (isset($submenu['mailerpress/campaigns.php'])) {
                    $submenu['mailerpress/campaigns.php'][] = [
                        $menu_item['menu_title'],
                        $menu_item['cap'],
                        $menu_item['slug'],
                        $menu_item['title'],
                    ];
                }
            }
        }
    }

    #[Action('admin_menu', priority: 9999)]
    public function modifyExternalLinks(): void
    {
        global $submenu;
        if (isset($submenu['mailerpress/campaigns.php'])) {
            foreach ($submenu['mailerpress/campaigns.php'] as $key => $item) {
                if (isset($item[2]) && strpos($item[2], \MailerPress\Core\ExternalLinks::get('docs')) === 0) {
                    // Ensure the link is treated as external
                    $submenu['mailerpress/campaigns.php'][$key][2] = $item[2];
                }
            }
        }
    }


    #[Action('admin_menu', priority: 999)]
    public function hideSubmenuItems(): void
    {
        global $submenu;

        // Slugs to hide from WordPress admin menu (but still accessible via direct URL)
        // Note: We don't remove 'mailerpress/new' and 'mailerpress/workflow' from $submenu
        // because WordPress needs them to call the callbacks. We only hide them visually with CSS.
        $hidden_slugs = [
            'mailerpress%2Fcampaigns.php&path=%2Fhome&view=create-campaign', // New Campaign
            // 'mailerpress/new' - NOT removed, only hidden with CSS so callback still works
            // 'mailerpress/workflow' - NOT removed, only hidden with CSS so callback still works
        ];

        if (!isset($submenu['mailerpress/campaigns.php'])) {
            return;
        }

        // Remove hidden menu items by matching slug (but keep mailerpress/new and mailerpress/workflow)
        foreach ($submenu['mailerpress/campaigns.php'] as $key => $item) {
            if (isset($item[2])) {
                $slug = $item[2];
                // Normalize slug for comparison (handle both encoded and decoded)
                $normalized_slug = rawurldecode($slug);

                // Don't remove mailerpress/new or mailerpress/workflow - they need to stay for callbacks to work
                if (
                    $slug === 'mailerpress/new' || $normalized_slug === 'mailerpress/new' ||
                    $slug === 'mailerpress/workflow' || $normalized_slug === 'mailerpress/workflow'
                ) {
                    continue;
                }

                $normalized_hidden = array_map('rawurldecode', $hidden_slugs);
                if (in_array($slug, $hidden_slugs, true) || in_array($normalized_slug, $normalized_hidden, true)) {
                    unset($submenu['mailerpress/campaigns.php'][$key]);
                }
            }
        }
    }

    #[Action('admin_head')]
    public function hideSubmenuHead(): void
    {
    ?>
        <style>
            #toplevel_page_mailerpress-campaigns .wp-menu-image img {
                height: 20px;
                opacity: 1;
                padding: 7px 0 0;
                width: 20px;
            }

            #toplevel_page_mailerpress-campaigns .wp-submenu-head,
            #toplevel_page_mailerpress-campaigns .wp-first-item {
                display: none !important;
            }

            /* Hide mailerpress/new and mailerpress/workflow from menu but keep them accessible via direct URL */
            #toplevel_page_mailerpress-campaigns .wp-submenu a[href*="page=mailerpress/new"],
            #toplevel_page_mailerpress-campaigns .wp-submenu a[href*="page=mailerpress/workflow"] {
                display: none !important;
            }
        </style>
        <script>
            (function() {
                document.addEventListener('DOMContentLoaded', function() {
                    const docLinks = document.querySelectorAll('#toplevel_page_mailerpress-campaigns ul.wp-submenu a[href="<?php echo esc_url(\MailerPress\Core\ExternalLinks::get('docs')); ?>"]');
                    docLinks.forEach(function(link) {
                        link.setAttribute('target', '_blank');
                        link.setAttribute('rel', 'noopener noreferrer');
                        // Prevent WordPress from trying to load it as an admin page
                        link.addEventListener('click', function(e) {
                            e.preventDefault();
                            window.open('<?php echo esc_url(\MailerPress\Core\ExternalLinks::get('docs')); ?>', '_blank', 'noopener,noreferrer');
                        });
                    });
                });
            })();
        </script>
<?php
    }

    #[Action('parent_file', priority: 1000)]
    public function setParentFile(string $parent_file): string
    {
        global $plugin_page;

        // Normalize plugin_page for comparison
        $page = $plugin_page ? rawurldecode($plugin_page) : '';

        // Use decoded slugs here — match your actual slugs used in add_submenu_page
        $mailerpress_submenus = [
            'mailerpress/campaigns.php&path=/home',
            'mailerpress/campaigns.php&path=/home&view=create-campaign',
            'mailerpress/new',
            'mailerpress/campaigns.php&path=/home/campaigns',
            'mailerpress/workflow',
            'mailerpress/campaigns.php&path=/home/workflow',
            'mailerpress/campaigns.php&path=/home/contacts',
            'mailerpress/campaigns.php&path=/home/templates',
            'mailerpress/campaigns.php&path=/home/integrations',
            'mailerpress/campaigns.php&path=/home/webhooks',
            'mailerpress/campaigns.php&path=/home/tools',
            'mailerpress/campaigns.php&path=/home/settings',
            'mailerpress/campaigns.php&path=/home/getting-started',
            'mailerpress/campaigns.php&path=/home/addons',
        ];

        if (in_array($page, $mailerpress_submenus, true)) {
            return 'mailerpress/campaigns.php';
        }

        return $parent_file;
    }

    #[Action('admin_body_class')]
    public function addAdminBodyClass(string $classes): string
    {
        // add a body class so you can target MailerPress admin pages easily
        if (isset($_GET['page']) && strpos(rawurldecode(sanitize_text_field(wp_unslash($_GET['page']))), 'mailerpress') === 0) {
            $classes .= ' mailerpress-page';
        }
        return $classes;
    }

    #[Action('parent_file', priority: 1000)]
    public function fixParentFile(string $parent_file): string
    {
        global $submenu_file;

        // Current request
        $current_page = isset($_GET['page']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['page']))) : '';
        $current_path = isset($_GET['path']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['path']))) : '';

        // Map decoded page/path → encoded slug exactly as used in add_submenu_page
        $map = [
            'mailerpress/campaigns.php&path=/home' => 'mailerpress%2Fcampaigns.php&path=%2Fhome',
            'mailerpress/campaigns.php&path=/home&view=create-campaign' => 'mailerpress%2Fcampaigns.php&path=%2Fhome&view=create-campaign',
            'mailerpress/new' => 'mailerpress%2Fcampaigns.php&path=%2Fhome&view=create-campaign',
            'mailerpress/campaigns.php&path=/home/campaigns' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fcampaigns',
            'mailerpress/workflow' => 'mailerpress/workflow',
            'mailerpress/campaigns.php&path=/home/workflow' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fworkflow',
            'mailerpress/campaigns.php&path=/home/contacts' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fcontacts',
            'mailerpress/campaigns.php&path=/home/templates' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Ftemplates',
            'mailerpress/campaigns.php&path=/home/integrations' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fintegrations',
            'mailerpress/campaigns.php&path=/home/webhooks' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fwebhooks',
            'mailerpress/campaigns.php&path=/home/tools' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Ftools',
            'mailerpress/campaigns.php&path=/home/settings' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings',
            'mailerpress/campaigns.php&path=/home/getting-started' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fgetting-started',
            'mailerpress/campaigns.php&path=/home/addons' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Faddons',
        ];

        $current_view = isset($_GET['view']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['view']))) : '';
        $decoded_key = $current_page . ($current_path ? "&path={$current_path}" : '') . ($current_view ? "&view={$current_view}" : '');

        if (isset($map[$decoded_key])) {
            $parent_file = 'mailerpress/campaigns.php';
            $submenu_file = $map[$decoded_key];
        }

        return $parent_file;
    }
}
