<?php

namespace MailerPress\Actions\Admin;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Capabilities;

class AdminMenu
{
    private array|string $options = [];

    public static function mailerpressRoot(): void
    {
        // Normalize path
        $path = isset($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';

        $capability = match ($path) {
            '/home/settings', '/home/integrations' => Capabilities::MANAGE_SETTINGS,
            '/home/contacts' => match (isset($_GET['activeView']) ? sanitize_text_field(wp_unslash($_GET['activeView'])) : '') {
                'Segmentation' => Capabilities::MANAGE_CONTACT_SEGMENTATION,
                'Contact Lists' => Capabilities::MANAGE_LISTS,
                'Contact Tags' => Capabilities::MANAGE_TAGS,
                default => Capabilities::MANAGE_CONTACTS,
            },
            '/home/templates' => Capabilities::MANAGE_TEMPLATES,
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

        <div id="mailerpress"></div>
        <div id="toast-root"></div>
    <?php
    }

    public static function mailpressCampaigns(): void
    {
    ?>
        <div id="mailerpress-root"></div>
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
        <div id="mailerpress-workflow-root"></div>
    <?php
    }


    #[Action('admin_menu')]
    public function adminMenu(): void
    {
        // Always load options fresh
        $options =  apply_filters('mailerpress_white_label_options', [
            'white_label_active' => false,
        ]);;

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
            $labels['settings'] = $options['settings_name'] ?? $labels['settings'];
        }

        // Fallback icon if no white label
        $menu_icon = !empty($options['white_label_active'])
            ? 'dashicons-email'
            : 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyBpZD0iQ2FscXVlXzEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbG5zOnNlcmlmPSJodHRwOi8vd3d3LnNlcmlmLmNvbS8iIHZlcnNpb249IjEuMSIgdmlld0JveD0iMCAwIDEwNTguMSA4NzMuOSI+CiAgPGRlZnM+CiAgICA8c3R5bGU+CiAgICAgIC5zdDAgewogICAgICAgIGZpbGw6ICNhN2FhYWQ7CiAgICAgIH0KICAgIDwvc3R5bGU+CiAgPC9kZWZzPgogIDxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0zMTguMywzODcuOGMwLDAtLjEsMC0uMiwwLS42LDAtMSwuNS0xLDFoMGMxLjMsOTcuNiwxLjksMTk1LjIsMS45LDI5MywwLDMyLDQuMSw1My4yLDMwLjksNjYuNyw1LjksMywxNi4zLDQuNCwzMS4xLDQuMywxODAuMy0xLDM0Ni4zLS45LDQ5Ny45LjQsNDUuNy40LDY4LjUtMjIuOSw2OC41LTY5LjktLjItNTMuMS0uNS0yMTQuOC0uOC00ODUuMSwwLTIwLjktMS4zLTM0LjItMy45LTM5LjgtNS43LTEyLjItMTMuNi0yMi0yMy44LTI5LjEtNy00LjktMTguNy03LjQtMzUuMS03LjQtMjM4LjUsMC00NzYuOS4xLTcxNS40LDAtMjIsMC0zOC4xLDgtNDguMiwyNC01LjksOS41LTguOCwyNC40LTguNiw0NC45LDEuMSwxNTUuNywyLjMsMzA2LjMsMy40LDQ1MS44LDAsMi40LTEuNyw0LjQtNC4xLDQuOC0yNi4xLDQuMi01MC40LDQuNC03Mi42LTEyLjJDMTIuOCw2MTYsLjEsNTg5LjYuMSw1NTYuMSwwLDMyNi4zLDAsMTk3LjQsMCwxNjkuMy0uMSwxMDkuOSwyNCw2My40LDcyLjUsMjkuNywxMDgsNSwxMzguMiwwLDE4NC44LDBjMzA4LjIuMiw1MzguNi4yLDY5MS4xLjIsNzkuMiwwLDEzNS44LDM2LDE2OS43LDEwOCw2LjcsMTQuMSwxMCwzMC45LDEwLjEsNTAuNCwxLjIsMjcyLjYsMiw0NTQuMSwyLjUsNTQ0LjYuMyw1MC44LTE5LjcsOTMuNi02MC4xLDEyOC4yLTM3LjUsMzIuMS03MS44LDQxLjktMTI0LjQsNDItMzAuMywwLTE4NC45LjMtNDYzLjguNS00Ni40LDAtNzYuNi0yLjQtOTAuNi03LjUtNTEuMS0xOC4yLTk2LjYtNjctMTA2LjItMTIyLjMtMS01LjktMS42LTI4LjMtMS43LTY3LjEtLjgtMTU5LjctLjgtMzEwLjEsMC00NTEuMSwwLTMuMSwxLjYtNC43LDQuNy00LjdoOTcuNGM0LjIsMCw4LjIsMS44LDExLDQuOWwyMDIuNywyMjQuNGMwLDAsLjEuMS4yLjIsMi4zLDIuMyw2LjEsMi4zLDguNCwwLDAsMCwuMS0uMS4yLS4ybDIwMC0yMjEuNmM0LjItNC42LDEwLjEtNy4yLDE2LjItNy4yaDg1LjljMi40LDAsNC40LDIsNC40LDQuNHY0MTUuNmMwLDEuNy0uOCwyLjYtMi41LDIuNmgtOTcuOWMtMS42LDAtMy0xLjMtMy0zdi0yNDcuOWMwLTUuOC0xLjktNi41LTUuNi0yLjFsLTE5Ny4zLDIyNy42Yy0uOCwxLTIsMS41LTMuMywxLjVzLTIuMi0uNS0zLTEuM2MtNzEuOC03MS0xMzYuMi0xNDcuNC0yMDcuNS0yMjktMS4yLTEuMy0yLjUtMi4xLTMuOS0yLjRaIi8+Cjwvc3ZnPg==';

        // Register top-level menu
        add_menu_page(
            $labels['main'],
            $labels['main'],
            'edit_posts',
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
                'cap' => Capabilities::MANAGE_CAMPAIGNS,
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
                'cap' => Capabilities::MANAGE_CAMPAIGNS,
                'callback' => 'mailpressCampaigns',
            ],
            // Workflow menu items hidden - automation menu access disabled
            // [
            //     'title' => '',
            //     'menu_title' => '',
            //     'slug' => 'mailerpress/workflow',
            //     'cap' => Capabilities::MANAGE_AUTOMATIONS,
            //     'callback' => 'mailerpressWorkflow',
            // ],
            // [
            //     'title' => $labels['workflow'],
            //     'menu_title' => $labels['workflow'],
            //     'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fworkflow',
            //     'cap' => Capabilities::MANAGE_AUTOMATIONS,
            //     'callback' => 'mailerpressWorkflow',
            // ],
            [
                'title' => $labels['audience'],
                'menu_title' => $labels['audience'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fcontacts',
                'cap' => Capabilities::MANAGE_CONTACTS,
            ],
            [
                'title' => $labels['templates'],
                'menu_title' => $labels['templates'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Ftemplates',
                'cap' => Capabilities::MANAGE_TEMPLATES,
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
                'title' => $labels['settings'],
                'menu_title' => $labels['settings'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings',
                'cap' => Capabilities::MANAGE_SETTINGS,

            ],
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

        if (function_exists('is_plugin_active') && is_plugin_active('mailerpress-pro/mailerpress-pro.php')) {
            $submenus[] = [
                'title' => $labels['licence'],
                'menu_title' => $labels['licence'],
                'slug' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings&activeView=Licence',
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
                if (isset($item[2]) && strpos($item[2], 'https://mailerpress.com/docs/') === 0) {
                    // Ensure the link is treated as external
                    $submenu['mailerpress/campaigns.php'][$key][2] = $item[2];
                }
            }
        }
    }


    #[Action('admin_head')]
    public function hideFirstMenuItem(): void
    {
    ?>
        <style>
            #toplevel_page_mailerpress-campaigns ul.wp-submenu li:nth-child(3),
            #toplevel_page_mailerpress-campaigns ul.wp-submenu li:nth-child(6) {
                display: none !important;
            }
        </style>
        <script>
            (function() {
                document.addEventListener('DOMContentLoaded', function() {
                    const docLinks = document.querySelectorAll('#toplevel_page_mailerpress-campaigns ul.wp-submenu a[href="https://mailerpress.com/docs/"]');
                    docLinks.forEach(function(link) {
                        link.setAttribute('target', '_blank');
                        link.setAttribute('rel', 'noopener noreferrer');
                        // Prevent WordPress from trying to load it as an admin page
                        link.addEventListener('click', function(e) {
                            e.preventDefault();
                            window.open('https://mailerpress.com/docs/', '_blank', 'noopener,noreferrer');
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
            // Workflow menu items hidden - automation menu access disabled
            // 'mailerpress/workflow',
            // 'mailerpress-workflow',
            // 'mailerpress-workflows',
            // 'mailerpress/campaigns.php&path=/home/workflow',
            'mailerpress/campaigns.php&path=/home/contacts',
            'mailerpress/campaigns.php&path=/home/templates',
            'mailerpress/campaigns.php&path=/home/integrations',
            'mailerpress/campaigns.php&path=/home/webhooks',
            'mailerpress/campaigns.php&path=/home/settings',
            'mailerpress/campaigns.php&path=/home/getting-started',
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
            // Workflow menu items hidden - automation menu access disabled
            // 'mailerpress/workflow' => 'mailerpress/workflow',
            // 'mailerpress-workflow' => 'mailerpress/workflow',
            // 'mailerpress-workflows' => 'mailerpress/workflow',
            'mailerpress/campaigns.php&path=/home/contacts' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fcontacts',
            'mailerpress/campaigns.php&path=/home/templates' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Ftemplates',
            'mailerpress/campaigns.php&path=/home/integrations' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fintegrations',
            'mailerpress/campaigns.php&path=/home/webhooks' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fwebhooks',
            'mailerpress/campaigns.php&path=/home/settings' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fsettings',
            'mailerpress/campaigns.php&path=/home/getting-started' => 'mailerpress%2Fcampaigns.php&path=%2Fhome%2Fgetting-started',
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
