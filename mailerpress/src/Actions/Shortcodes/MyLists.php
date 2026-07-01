<?php

declare(strict_types=1);

namespace MailerPress\Actions\Shortcodes;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\Kernel;
use MailerPress\Models\Lists;

class MyLists
{
    #[Action('init')]
    public function registerShortcode(): void
    {
        $this->register();
    }

    #[Action('wp_loaded')]
    public function registerShortcodeFallback(): void
    {
        $this->register();
    }

    private function register(): void
    {
        if (function_exists('shortcode_exists') && shortcode_exists('mailerpress_my_lists')) {
            return;
        }

        add_shortcode('mailerpress_my_lists', [$this, 'render']);
    }

    #[Filter('the_content', priority: 8)]
    public function normalizeShortcodeContent(string $content): string
    {
        if (!str_contains($content, '[mailerpress_my_lists')) {
            return $content;
        }

        return preg_replace_callback(
            '/\[mailerpress_my_lists\b[^\]]*\]/u',
            fn (array $matches): string => $this->normalizeShortcodeText($matches[0]),
            $content
        ) ?? $content;
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
            'loading_text' => __('Saving...', 'mailerpress'),
            'success_message' => $is_logged_in
                ? __('Your subscription preferences have been updated successfully.', 'mailerpress')
                : __('Thank you for subscribing! Please check your email to confirm your subscription.', 'mailerpress'),
            'error_message' => __('An error occurred while updating your preferences. Please try again.', 'mailerpress'),
            'email_label' => __('Email Address', 'mailerpress'),
            'email_placeholder' => __('your@email.com', 'mailerpress'),
            'first_name_label' => __('First Name', 'mailerpress'),
            'last_name_label' => __('Last Name', 'mailerpress'),
            'subscription_status_label' => __('Subscription status', 'mailerpress'),
            'subscribed_label' => __('Subscribed', 'mailerpress'),
            'unsubscribed_label' => __('Unsubscribed', 'mailerpress'),
            'lists_label' => __('Newsletter Subscriptions', 'mailerpress'),
            'no_lists_message' => __('No newsletter lists available at the moment.', 'mailerpress'),
            'show_list_descriptions' => 'true',
            'visible_lists' => '10',
            'show_more_text' => '',
            'list_columns' => '1',
            'list_gap' => '',
            'wrapper_class' => '',
            'form_class' => '',
            'title_class' => '',
            'field_class' => '',
            'label_class' => '',
            'input_class' => '',
            'lists_class' => '',
            'lists_label_class' => '',
            'list_items_class' => '',
            'list_item_class' => '',
            'button_class' => '',
            'message_class' => '',
            'wrapper_style' => '',
            'form_style' => '',
            'title_style' => '',
            'field_style' => '',
            'label_style' => '',
            'input_style' => '',
            'lists_style' => '',
            'lists_label_style' => '',
            'list_items_style' => '',
            'list_item_style' => '',
            'button_style' => '',
            'message_style' => '',
            'button_color' => '',
            'button_background' => '',
            'button_text_color' => '',
            'button_border_radius' => '',
        ];

        $atts = $this->normalizeRawAttributes($atts);
        $atts = $this->normalizeAttributeAliases($atts);
        $atts = shortcode_atts($defaults, $atts, 'mailerpress_my_lists');

        // Convert string boolean values to actual booleans
        $show_name_fields = $this->stringToBool($atts['show_name_fields']);
        $show_list_descriptions = $this->stringToBool($atts['show_list_descriptions']);
        $visible_lists = max(0, (int) $atts['visible_lists']);
        $list_columns = max(1, min(6, (int) $atts['list_columns']));

        // Initialize variables
        $email = '';
        $first_name = '';
        $last_name = '';
        $subscription_status = 'subscribed';
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
                $contact_status = sanitize_key($contact->subscription_status ?? '');
                $subscription_status = $contact_status === 'subscribed' ? 'subscribed' : 'unsubscribed';

                $contact_lists_table = $wpdb->prefix . 'mailerpress_contact_lists';
                if ($subscription_status === 'subscribed') {
                    $user_lists = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT list_id FROM {$contact_lists_table} WHERE contact_id = %d",
                            $contact->contact_id
                        )
                    );
                    $user_lists = array_map('intval', $user_lists);
                }
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
        $title = sanitize_text_field($atts['title']);
        $button_text = sanitize_text_field($atts['button_text']);
        $loading_text = sanitize_text_field($atts['loading_text']);
        $success_message = sanitize_text_field($atts['success_message']);
        $error_message = sanitize_text_field($atts['error_message']);
        $email_label = sanitize_text_field($atts['email_label']);
        $email_placeholder = sanitize_text_field($atts['email_placeholder']);
        $first_name_label = sanitize_text_field($atts['first_name_label']);
        $last_name_label = sanitize_text_field($atts['last_name_label']);
        $subscription_status_label = sanitize_text_field($atts['subscription_status_label']);
        $subscribed_label = sanitize_text_field($atts['subscribed_label']);
        $unsubscribed_label = sanitize_text_field($atts['unsubscribed_label']);
        $lists_label = sanitize_text_field($atts['lists_label']);
        $no_lists_message = sanitize_text_field($atts['no_lists_message']);
        $show_more_text = sanitize_text_field($atts['show_more_text']);

        $wrapper_classes = $this->classAttribute('mailerpress-my-lists-wrapper', $atts['wrapper_class']);
        $form_classes = $this->classAttribute('mailerpress-my-lists-form woocommerce-form', $atts['form_class']);
        $title_classes = $this->classAttribute('mailerpress-my-lists-title', $atts['title_class']);
        $field_classes = $this->classAttribute('mailerpress-my-lists-field woocommerce-form-row form-row', $atts['field_class']);
        $input_classes = $this->classAttribute('mailerpress-my-lists-input woocommerce-Input woocommerce-Input--text input-text', $atts['input_class']);
        $label_classes = $this->classAttribute('mailerpress-my-lists-label', $atts['label_class']);
        $lists_classes = $this->classAttribute('mailerpress-my-lists-lists', $atts['lists_class']);
        $lists_label_classes = $this->classAttribute('mailerpress-my-lists-lists-label', $atts['lists_label_class']);
        $list_items_classes = $this->classAttribute('mailerpress-my-lists-list-items', $atts['list_items_class']);
        $list_item_classes = $this->classAttribute('mailerpress-my-lists-list-item', $atts['list_item_class']);
        $button_classes = $this->classAttribute('mailerpress-my-lists-submit woocommerce-Button button wp-element-button', $atts['button_class']);
        $message_classes = $this->classAttribute('mailerpress-my-lists-message', $atts['message_class']);

        $wrapper_style = $this->sanitizeInlineStyle($atts['wrapper_style']);
        $form_style = $this->sanitizeInlineStyle($atts['form_style']);
        $title_style = $this->sanitizeInlineStyle($atts['title_style']);
        $field_style = $this->sanitizeInlineStyle($atts['field_style']);
        $label_style = $this->sanitizeInlineStyle($atts['label_style']);
        $input_style = $this->sanitizeInlineStyle($atts['input_style']);
        $lists_style = $this->sanitizeInlineStyle($atts['lists_style']);
        $lists_label_style = $this->sanitizeInlineStyle($atts['lists_label_style']);
        $list_items_style = $this->sanitizeInlineStyle($atts['list_items_style']);
        $list_item_style = $this->sanitizeInlineStyle($atts['list_item_style']);
        $button_style = $this->buildButtonStyle($atts);
        $message_style = $this->sanitizeInlineStyle($atts['message_style']);

        if ($list_columns > 1) {
            $list_items_style = $this->appendStyle($list_items_style, 'grid-template-columns: repeat(' . $list_columns . ', minmax(0, 1fr))');
        }

        $list_gap = $this->sanitizeCssSize($atts['list_gap']);
        if ($list_gap !== '') {
            $list_items_style = $this->appendStyle($list_items_style, 'gap: ' . $list_gap);
        }

        ob_start();
        include $template_path;
        return ob_get_clean();
    }

    private function normalizeRawAttributes(array $atts): array
    {
        $normalized = [];
        $fragments = [];
        $pendingKey = null;
        $pendingValue = '';

        foreach ($atts as $key => $value) {
            if (is_int($key)) {
                if ($pendingKey !== null) {
                    $pendingValue .= ' ' . (string) $value;

                    if ($this->hasClosingSmartQuote($pendingValue)) {
                        $normalized[$pendingKey] = $this->normalizeAttributeValue($pendingValue);
                        $pendingKey = null;
                        $pendingValue = '';
                    }
                } else {
                    $fragments[] = (string) $value;
                }

                continue;
            }

            if ($pendingKey !== null) {
                $normalized[$pendingKey] = $this->normalizeAttributeValue($pendingValue);
                $pendingKey = null;
                $pendingValue = '';
            }

            $normalizedKey = $this->normalizeAttributeKey((string) $key);
            $normalizedValue = (string) $value;

            if ($this->hasOpeningSmartQuote($normalizedValue) && !$this->hasClosingSmartQuote($normalizedValue)) {
                $pendingKey = $normalizedKey;
                $pendingValue = $normalizedValue;
                continue;
            }

            $normalized[$normalizedKey] = $this->normalizeAttributeValue($normalizedValue);
        }

        if ($pendingKey !== null) {
            $normalized[$pendingKey] = $this->normalizeAttributeValue($pendingValue);
        }

        foreach ($this->parseAttributeFragments($fragments) as $key => $value) {
            if (!isset($normalized[$key])) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function parseAttributeFragments(array $fragments): array
    {
        if (empty($fragments)) {
            return [];
        }

        $raw = $this->normalizeShortcodeText(implode(' ', $fragments));
        $raw = $this->normalizeUnicodeDashes($raw);
        $raw = str_replace(['“', '”', '„', '‟', '«', '»'], '"', $raw);

        if (function_exists('shortcode_parse_atts')) {
            $parsed = shortcode_parse_atts($raw);
        } else {
            $parsed = [];
            preg_match_all('/([\w-]+)\s*=\s*"([^"]*)"/u', $raw, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $parsed[$match[1]] = $match[2];
            }
        }

        if (!is_array($parsed)) {
            return [];
        }

        $normalized = [];
        foreach ($parsed as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $normalized[$this->normalizeAttributeKey((string) $key)] = $this->normalizeAttributeValue((string) $value);
        }

        return $normalized;
    }

    private function normalizeAttributeKey(string $key): string
    {
        $key = $this->normalizeUnicodeDashes($key);
        $key = strtolower($key);

        return preg_replace('/[^a-z0-9_-]/', '', $key) ?: $key;
    }

    private function normalizeAttributeValue(string $value): string
    {
        $value = $this->normalizeUnicodeDashes($value);
        $value = str_replace(['‘', '’'], "'", $value);

        return trim($value, " \t\n\r\0\x0B“”„‟«»");
    }

    private function normalizeShortcodeText(string $shortcode): string
    {
        $shortcode = str_replace(
            ['&ndash;', '&mdash;', '&#8210;', '&#8208;', '&#8209;', '&#8211;', '&#8212;', '&#8213;', '&#8722;'],
            '-',
            $shortcode
        );
        $shortcode = str_replace(
            ['&ldquo;', '&rdquo;', '&#8220;', '&#8221;', '&#8222;', '&#8223;', '&laquo;', '&raquo;'],
            '"',
            $shortcode
        );
        $shortcode = str_replace(
            ['&lsquo;', '&rsquo;', '&#8216;', '&#8217;'],
            "'",
            $shortcode
        );

        $shortcode = $this->normalizeUnicodeDashes($shortcode);
        $shortcode = str_replace(['“', '”', '„', '‟', '«', '»'], '"', $shortcode);

        return str_replace(['‘', '’'], "'", $shortcode);
    }

    private function normalizeUnicodeDashes(string $value): string
    {
        return str_replace(
            ["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"],
            '-',
            $value
        );
    }

    private function hasOpeningSmartQuote(string $value): bool
    {
        return preg_match('/^[“”„‟«»]/u', trim($value)) === 1;
    }

    private function hasClosingSmartQuote(string $value): bool
    {
        return preg_match('/[“”„‟«»]$/u', trim($value)) === 1;
    }

    private function normalizeAttributeAliases(array $atts): array
    {
        $aliases = [
            'show-name-fields' => 'show_name_fields',
            'button-text' => 'button_text',
            'loading-text' => 'loading_text',
            'success-message' => 'success_message',
            'error-message' => 'error_message',
            'email-label' => 'email_label',
            'email-placeholder' => 'email_placeholder',
            'first-name-label' => 'first_name_label',
            'last-name-label' => 'last_name_label',
            'list-label' => 'lists_label',
            'list_label' => 'lists_label',
            'list-title' => 'lists_label',
            'list_title' => 'lists_label',
            'lists-label' => 'lists_label',
            'lists-title' => 'lists_label',
            'lists_title' => 'lists_label',
            'lists-heading' => 'lists_label',
            'lists_heading' => 'lists_label',
            'no-lists-message' => 'no_lists_message',
            'show-list-descriptions' => 'show_list_descriptions',
            'visible-lists' => 'visible_lists',
            'show-more-text' => 'show_more_text',
            'list-columns' => 'list_columns',
            'columns' => 'list_columns',
            'list-gap' => 'list_gap',
            'gap' => 'list_gap',
            'class' => 'wrapper_class',
            'css-class' => 'wrapper_class',
            'css_class' => 'wrapper_class',
            'style' => 'wrapper_style',
            'css' => 'wrapper_style',
            'wrapper-class' => 'wrapper_class',
            'form-class' => 'form_class',
            'title-class' => 'title_class',
            'field-class' => 'field_class',
            'label-class' => 'label_class',
            'input-class' => 'input_class',
            'lists-class' => 'lists_class',
            'lists-label-class' => 'lists_label_class',
            'list-items-class' => 'list_items_class',
            'list-item-class' => 'list_item_class',
            'button-class' => 'button_class',
            'message-class' => 'message_class',
            'wrapper-style' => 'wrapper_style',
            'form-style' => 'form_style',
            'title-style' => 'title_style',
            'field-style' => 'field_style',
            'label-style' => 'label_style',
            'input-style' => 'input_style',
            'lists-style' => 'lists_style',
            'lists-label-style' => 'lists_label_style',
            'list-items-style' => 'list_items_style',
            'list-item-style' => 'list_item_style',
            'button-style' => 'button_style',
            'message-style' => 'message_style',
            'text' => 'button_text',
            'background' => 'button_background',
            'color' => 'button_text_color',
            'radius' => 'button_border_radius',
            'button-background' => 'button_background',
            'button-text-color' => 'button_text_color',
            'button-border-radius' => 'button_border_radius',
        ];

        foreach ($aliases as $alias => $target) {
            if (isset($atts[$alias]) && (!isset($atts[$target]) || '' === $atts[$target])) {
                $atts[$target] = $atts[$alias];
            }
        }

        return $atts;
    }

    private function classAttribute(string $baseClasses, mixed $extraClasses): string
    {
        $classes = array_filter(array_merge(
            preg_split('/\s+/', trim($baseClasses)) ?: [],
            preg_split('/\s+/', trim((string) $extraClasses)) ?: []
        ));

        $classes = array_map(static fn ($class) => sanitize_html_class($class), $classes);

        return implode(' ', array_filter($classes));
    }

    private function sanitizeInlineStyle(mixed $style): string
    {
        return trim(safecss_filter_attr((string) $style));
    }

    private function buildButtonStyle(array $atts): string
    {
        $style = $this->sanitizeInlineStyle($atts['button_style']);
        $buttonBackground = sanitize_hex_color($atts['button_background']) ?: sanitize_hex_color($atts['button_color']);
        $buttonTextColor = sanitize_hex_color($atts['button_text_color']);
        $buttonBorderRadius = $this->sanitizeCssSize($atts['button_border_radius']);

        if ($buttonBackground) {
            $style = $this->appendStyle($style, 'background-color: ' . $buttonBackground);
        }

        if ($buttonTextColor) {
            $style = $this->appendStyle($style, 'color: ' . $buttonTextColor);
        }

        if ($buttonBorderRadius !== '') {
            $style = $this->appendStyle($style, 'border-radius: ' . $buttonBorderRadius);
        }

        return $style;
    }

    private function appendStyle(string $style, string $declaration): string
    {
        $declaration = trim($declaration, " \t\n\r\0\x0B;");

        if ($declaration === '') {
            return $style;
        }

        return trim($style) === ''
            ? $declaration . ';'
            : rtrim($style, ';') . '; ' . $declaration . ';';
    }

    private function sanitizeCssSize(mixed $value): string
    {
        $value = trim(sanitize_text_field((string) $value));

        if ($value === '0') {
            return $value;
        }

        return preg_match('/^\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh)$/', $value) ? $value : '';
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

        $cssRelativePath = 'build/public/my-lists-form.css';
        if (!file_exists($root . '/' . $cssRelativePath) && file_exists($root . '/assets/css/my-lists-form.css')) {
            $cssRelativePath = 'assets/css/my-lists-form.css';
        }

        $cssPath = $root . '/' . $cssRelativePath;
        $css_file = $rootUrl . $cssRelativePath;
        $css_version = file_exists($cssPath)
            ? filemtime($cssPath)
            : MAILERPRESS_VERSION;

        wp_enqueue_style(
            'mailerpress-my-lists-css',
            $css_file,
            [],
            $css_version
        );

        $jsRelativePath = 'build/public/my-lists-form.js';
        if (!file_exists($root . '/' . $jsRelativePath) && file_exists($root . '/assets/js/my-lists-form.js')) {
            $jsRelativePath = 'assets/js/my-lists-form.js';
        }

        $jsPath = $root . '/' . $jsRelativePath;
        $js_file = $rootUrl . $jsRelativePath;
        $js_version = file_exists($jsPath)
            ? filemtime($jsPath)
            : MAILERPRESS_VERSION;

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
