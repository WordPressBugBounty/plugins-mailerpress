<?php

declare(strict_types=1);

namespace MailerPress\Actions\Shortcodes;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Kernel;
use MailerPress\Models\Lists;

class MyLists
{
    #[Action('init')]
    public function registerShortcode(): void
    {
        add_shortcode('mailerpress_my_lists', [$this, 'render']);
    }

    /**
     * Renders the my lists management shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string Form HTML
     */
    public function render(array $atts): string
    {
        // Mark that the shortcode is used to load assets
        add_action('wp_footer', [$this, 'enqueueAssets'], 5);

        // Check if user is logged in
        $is_logged_in = is_user_logged_in();

        // Default values
        $defaults = [
            'show_name_fields' => 'true',
            'lists' => '', // Empty = all lists, or comma-separated IDs like "1,2,3"
            'title' => $is_logged_in
                ? __('Manage Your Newsletter Subscriptions', 'mailerpress')
                : __('Subscribe to Our Newsletter', 'mailerpress'),
            'button_text' => $is_logged_in
                ? __('Save Preferences', 'mailerpress')
                : __('Subscribe', 'mailerpress'),
            'success_message' => $is_logged_in
                ? __('Your subscription preferences have been updated successfully.', 'mailerpress')
                : __('Thank you for subscribing! Please check your email to confirm your subscription.', 'mailerpress'),
            'error_message' => __('An error occurred while updating your preferences. Please try again.', 'mailerpress'),
        ];

        $atts = shortcode_atts($defaults, $atts, 'mailerpress_my_lists');

        // Convert string boolean values to actual booleans
        $show_name_fields = $this->stringToBool($atts['show_name_fields']);

        // Initialize variables
        $email = '';
        $first_name = '';
        $last_name = '';
        $user_lists = [];
        $contact = null;

        if ($is_logged_in) {
            // Get current user
            $user = wp_get_current_user();
            $email = $user->user_email;

            // Get contact if exists
            global $wpdb;
            $contact_table = $wpdb->prefix . 'mailerpress_contact';
            $contact = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$contact_table} WHERE email = %s", $email)
            );

            // Get user's current lists if contact exists
            if ($contact) {
                $contact_lists_table = $wpdb->prefix . 'mailerpress_contact_lists';
                $user_lists = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT list_id FROM {$contact_lists_table} WHERE contact_id = %d",
                        $contact->contact_id
                    )
                );
                $user_lists = array_map('intval', $user_lists);
            }

            // Get first name and last name
            if ($contact) {
                $first_name = $contact->first_name;
                $last_name = $contact->last_name;
            } else {
                // Get from WordPress user meta
                $first_name = get_user_meta($user->ID, 'first_name', true) ?: $user->first_name ?: '';
                $last_name = get_user_meta($user->ID, 'last_name', true) ?: $user->last_name ?: '';
            }
        }

        // Get all available lists or filter by IDs if specified
        $all_lists = Lists::getLists();

        if (!empty($atts['lists'])) {
            $list_ids = array_map('trim', explode(',', $atts['lists']));
            $list_ids = array_filter($list_ids, 'is_numeric');
            $list_ids = array_map('intval', $list_ids);

            if (!empty($list_ids)) {
                $filtered = array_filter($all_lists, function ($list) use ($list_ids) {
                    return in_array((int)$list['list_id'], $list_ids, true);
                });
                // Only apply the filter if it yields results; otherwise show all lists.
                if (!empty($filtered)) {
                    $all_lists = $filtered;
                }
            }
        }

        // Load template
        $template_path = Kernel::$config['root'] . '/templates/my-lists-form.php';

        if (!file_exists($template_path)) {
            return '<p>' . esc_html__('Template file not found.', 'mailerpress') . '</p>';
        }

        // Extract variables for template
        $title = esc_html($atts['title']);
        $button_text = esc_html($atts['button_text']);
        $success_message = esc_attr($atts['success_message']);
        $error_message = esc_attr($atts['error_message']);

        ob_start();
        include $template_path;
        return ob_get_clean();
    }

    /**
     * Converts string boolean values to actual booleans
     * Handles "true", "false", "1", "0", "yes", "no", etc.
     *
     * @param mixed $value Value to convert
     * @return bool Boolean value
     */
    private function stringToBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Enqueues necessary CSS and JS assets
     * This method is called via wp_footer to avoid multiple loads
     */
    public function enqueueAssets(): void
    {
        // Avoid multiple loads
        static $enqueued = false;
        if ($enqueued) {
            return;
        }
        $enqueued = true;

        $root = Kernel::$config['root'];
        $rootUrl = Kernel::$config['rootUrl'];

        // Enqueue CSS
        $css_file = $rootUrl . '/assets/css/my-lists-form.css';
        $css_version = file_exists($root . '/assets/css/my-lists-form.css')
            ? filemtime($root . '/assets/css/my-lists-form.css')
            : '1.0.0';

        wp_enqueue_style(
            'mailerpress-my-lists-css',
            $css_file,
            [],
            $css_version
        );

        // Enqueue JS
        $js_file = $rootUrl . '/assets/js/my-lists-form.js';
        $js_version = file_exists($root . '/assets/js/my-lists-form.js')
            ? filemtime($root . '/assets/js/my-lists-form.js')
            : '1.0.0';

        wp_enqueue_script(
            'mailerpress-my-lists-js',
            $js_file,
            [],
            $js_version,
            true // In footer
        );

        // Localize script with REST API URL and nonce
        wp_localize_script(
            'mailerpress-my-lists-js',
            'mailerpressMyLists',
            [
                'apiUrl' => rest_url('mailerpress/v1/my-lists'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]
        );
    }
}
