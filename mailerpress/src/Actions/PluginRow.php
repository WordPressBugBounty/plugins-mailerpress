<?php

namespace MailerPress\Actions;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\Capabilities;

class PluginRow
{
    private function is_user_pro(): bool
    {
        return file_exists(WP_PLUGIN_DIR . '/mailerpress-pro/mailerpress-pro.php');
    }

    #[Action('admin_enqueue_scripts')]
    function enqueue_plugin_row_styles()
    {
        $css = '
    .wp-list-table .plugin-title .mailerpress-pro-dashicon {
        font-size: 16px;
        padding-right: 4px;
        vertical-align: middle;
        color: #3858e9;
    }
    .wp-list-table .plugin-title a.mailerpress-go-pro-link {
           display: inline-block;
        vertical-align: middle;
        gap: 4px;
        font-weight: bold;
        color: #3858e9;
    }
       .wp-list-table .plugin-title a.mailerpress-go-pro-link .mailerpress-pro-dashicon {
        width: 20px;
    height: 20px;
    }
    .wp-list-table .plugin-title a.mailerpress-go-pro-link span:before {
        font-size: 20px !important;
        background-color: transparent;
        box-shadow: none;
        color: inherit;
    }
    ';

        wp_add_inline_style('wp-admin', $css);
    }

    #[Filter('plugin_action_links_mailerpress/mailerpress.php')]
    function plugin_row($actions)
    {
        // plugin_action_links can be called before init hook
        // Use hardcoded strings to avoid WordPress 6.7.0+ translation loading warnings
        // These strings are simple and don't require translation at this early stage
        $options = apply_filters('mailerpress_white_label_options', []);

        if (
            (isset($options['white_label_active']) && false === $options['white_label_active']) ||
            count($options) === 0
        ) {
            // Use hardcoded English strings to avoid early translation loading
            // These will be displayed in English only on the plugin list page
            $actions[] = '<a href="https://mailerpress.com/docs" target="_blank">Documentation</a>';

            if (!is_plugin_active('mailerpress-pro/mailerpress-pro.php')) {
                $actions[] = '<a href="https://mailerpress.com/pricing" target="_blank">Go Pro</a>';
            }
        } elseif (!empty($options['custom_documentation_url'])) {
            $actions[] = '<a href="' . esc_url($options['custom_documentation_url']) . '" target="_blank">Documentation</a>';
        }

        return $actions;
    }


    #[Action('admin_notices')]
    public function noticeGoPro()
    {
        // Ensure textdomain is loaded before using _e() (WordPress 6.7.0+ requirement)
        if (!is_textdomain_loaded('mailerpress') && function_exists('load_plugin_textdomain')) {
            $plugin_file = defined('MAILERPRESS_PLUGIN_DIR_PATH')
                ? MAILERPRESS_PLUGIN_DIR_PATH . '../mailerpress.php'
                : __FILE__;
            load_plugin_textdomain('mailerpress', false, dirname(plugin_basename($plugin_file)) . '/languages');
        }

        $user_id = get_current_user_id();
        $dismissed = get_user_meta($user_id, 'mailerpress_go_pro_notice', true);

        if ($dismissed || $this->is_user_pro()) {
            return;
        }
?>
        <div class="notice notice-success is-dismissible mailerpress-go-pro-notice">
            <style>
                .mailerpress-go-pro-notice {
                    display: flex;
                    flex-direction: column;
                    padding: 20px 24px;
                    border-left: 4px solid #0073aa;
                    background: #f1f7fc;
                    position: relative;
                    align-items: flex-start;
                }

                .mailerpress-go-pro-notice-header {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    margin-bottom: 12px;
                }

                .mailerpress-go-pro-notice-header .dashicons {
                    color: #0073aa;
                }

                .mailerpress-go-pro-benefits {
                    margin: 0 0 16px 0;
                    padding: 0;
                    list-style: none;
                }

                .mailerpress-go-pro-benefits li {
                    position: relative;
                    padding-left: 24px;
                    margin-bottom: 6px;
                    color: #111;
                }

                .mailerpress-go-pro-benefits li::before {
                    content: "\f147";
                    font-family: dashicons;
                    position: absolute;
                    left: 0;
                    top: 0;
                    color: #0073aa;
                    font-size: 16px;
                }

                .mailerpress-go-pro-notice .button-primary {
                    font-weight: bold;
                }
            </style>

            <div class="mailerpress-go-pro-notice-header">
                <span class="dashicons dashicons-awards"></span>
                <strong style="font-weight: bold"><?php _e('Unlock MailerPress Pro!', 'mailerpress'); ?></strong>
            </div>

            <ul class="mailerpress-go-pro-benefits">
                <li><?php _e('AI-powered email personalization', 'mailerpress'); ?></li>
                <li><?php _e('AI image generation for campaigns', 'mailerpress'); ?></li>
                <li><?php _e('Mobile campaign management', 'mailerpress'); ?></li>
                <li><?php _e('Advanced contact segmentation', 'mailerpress'); ?></li>
                <li><?php _e('And much more!', 'mailerpress'); ?></li>
            </ul>

            <a href="https://mailerpress.com/pricing" target="_blank" class="button button-primary">
                <?php _e('Upgrade to Pro Now', 'mailerpress'); ?>
            </a>

            <button type="button" class="notice-dismiss mailerpress-dismiss-go"
                aria-label="<?php _e('Dismiss permanently', 'mailerpress'); ?>"></button>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const notice = document.querySelector('.mailerpress-go-pro-notice');
                if (!notice) return;

                const dismissBtn = notice.querySelector('.notice-dismiss');
                dismissBtn.addEventListener('click', function() {
                    notice.style.display = 'none';
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: new URLSearchParams({
                            action: 'mailerpress_dismiss_go_pro',
                            nonce: '<?php echo wp_create_nonce('mailerpress_dismiss_go_pro'); ?>'
                        })
                    });
                });
            });
        </script>
<?php
    }
}
