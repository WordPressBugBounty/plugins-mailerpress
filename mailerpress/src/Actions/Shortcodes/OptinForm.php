<?php

declare(strict_types=1);

namespace MailerPress\Actions\Shortcodes;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Kernel;

class OptinForm
{
    #[Action('init')]
    public function registerShortcode(): void
    {
        add_shortcode('mailerpress_optin', [$this, 'render']);
    }

    /**
     * Renders the opt-in form shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string Form HTML
     */
    public function render(array $atts): string
    {
        // Mark that the shortcode is used to load assets
        add_action('wp_footer', [$this, 'enqueueAssets'], 5);

        // Get list and tags first (before shortcode_atts to preserve values)
        // These are critical attributes that must be preserved even if empty
        $list = isset($atts['list']) ? sanitize_text_field($atts['list']) : '';
        $tags = isset($atts['tags']) ? sanitize_text_field($atts['tags']) : '';

        // Default values
        $defaults = [
            'list' => '',
            'tags' => '',
            'success_message' => __('Thank you for your registration.', 'mailerpress'),
            'error_message' => __('Oops! Something went wrong. Please try again.', 'mailerpress'),
            'show_first_name' => 'false',
            'show_last_name' => 'false',
            'first_name_label' => __('First Name', 'mailerpress'),
            'last_name_label' => __('Last Name', 'mailerpress'),
            'email_label' => __('Email', 'mailerpress'),
            'email_placeholder' => __('Enter your email', 'mailerpress'),
            'first_name_placeholder' => __('Enter your first name', 'mailerpress'),
            'last_name_placeholder' => __('Enter your last name', 'mailerpress'),
            'button_text' => __('Subscribe', 'mailerpress'),
            'button_color' => '#000',
            'text_color' => '#fff',
            'border_radius' => '0px',
            'email_required' => 'true',
            'first_name_required' => 'false',
            'last_name_required' => 'false',
            'redirect' => '',
        ];

        $atts = shortcode_atts($defaults, $atts, 'mailerpress_optin');

        // Restore list and tags values (shortcode_atts might have overwritten them)
        if (!empty($list)) {
            $atts['list'] = $list;
        }
        if (!empty($tags)) {
            $atts['tags'] = $tags;
        }

        // Use the preserved or merged values
        $list = !empty($atts['list']) ? sanitize_text_field($atts['list']) : $list;
        $tags = !empty($atts['tags']) ? sanitize_text_field($atts['tags']) : $tags;

        // Handle redirect URL - can be a page ID or full URL
        $redirectUrl = '';
        if (!empty($atts['redirect'])) {
            $redirect = sanitize_text_field($atts['redirect']);
            // Check if it's a numeric page ID
            if (is_numeric($redirect)) {
                $permalink = get_permalink((int) $redirect);
                // Validate that get_permalink returned a valid URL
                if ($permalink !== false && filter_var($permalink, FILTER_VALIDATE_URL) !== false) {
                    $redirectUrl = esc_url_raw($permalink);
                }
            } else {
                // Assume it's a URL - validate it
                $validatedUrl = esc_url_raw($redirect);
                if (filter_var($validatedUrl, FILTER_VALIDATE_URL) !== false) {
                    $redirectUrl = $validatedUrl;
                }
            }
        }

        $successMessage = esc_attr($atts['success_message']);
        $errorMessage = esc_attr($atts['error_message']);

        // Convert string boolean values to actual booleans
        $showFirstName = $this->stringToBool($atts['show_first_name']);
        $showLastName = $this->stringToBool($atts['show_last_name']);

        $buttonText = esc_html($atts['button_text']);
        $buttonColor = sanitize_hex_color($atts['button_color']) ?: '#000';
        $textColor = sanitize_hex_color($atts['text_color']) ?: '#fff';
        $borderRadius = esc_attr($atts['border_radius']);

        // Convert string boolean values to actual booleans
        $emailRequired = $this->stringToBool($atts['email_required']);
        $firstNameRequired = $this->stringToBool($atts['first_name_required']);
        $lastNameRequired = $this->stringToBool($atts['last_name_required']);

        // Convert lists to JSON array of IDs (matching Gutenberg block format)
        // Lists can be comma-separated string like "1,2,3" or empty
        $listsArray = [];
        if (!empty($list)) {
            $listsList = explode(',', $list);
            $listsArray = array_map('trim', $listsList);
            // Filter out empty values and validate as numeric IDs
            $listsArray = array_filter($listsArray, function ($listId) {
                return !empty($listId) && is_numeric($listId);
            });
            // Convert to array of IDs (as strings to match Gutenberg format)
            $listsArray = array_values($listsArray);
        }

        // Convert tags to JSON array of IDs (matching Gutenberg block format)
        // Tags can be comma-separated string like "1,2,3" or empty
        $tagsArray = [];
        if (!empty($tags)) {
            $tagsList = explode(',', $tags);
            $tagsArray = array_map('trim', $tagsList);
            // Filter out empty values and validate as numeric IDs
            $tagsArray = array_filter($tagsArray, function ($tag) {
                return !empty($tag) && is_numeric($tag);
            });
            // Convert to array of IDs (as strings to match Gutenberg format)
            $tagsArray = array_values($tagsArray);
        }

        // Read global double opt-in setting
        $signupConfirmation = get_option('mailerpress_signup_confirmation', null);
        if (is_string($signupConfirmation)) {
            $signupConfirmation = json_decode($signupConfirmation, true);
        } elseif (!is_array($signupConfirmation)) {
            $signupConfirmation = null;
        }
        $doubleOptinEnabled = !empty($signupConfirmation) && true === ($signupConfirmation['enableSignupConfirmation'] ?? false);

        // Generate unique IDs for fields
        $formId = 'mailerpress-optin-' . wp_generate_password(8, false);
        $emailId = $formId . '-email';
        $firstNameId = $formId . '-firstname';
        $lastNameId = $formId . '-lastname';

        // CSS classes (theme-agnostic, can be styled by theme)
        // Use mailerpress_shortcode prefix to avoid conflicts with Gutenberg block styles
        $formClasses = ['mailerpress_shortcode-optin-form'];
        $fieldClasses = ['mailerpress_shortcode-optin-form__field'];
        $submitClasses = ['mailerpress_shortcode-optin-form__submit'];

        // Button classes - add WordPress standard button class for theme compatibility
        // Also add custom class for specific targeting
        $buttonClasses = ['mailerpress_shortcode-optin-form__button'];

        // Add WordPress standard button class if theme supports it
        // This allows themes to style the button with their default button styles
        $buttonClasses[] = 'button';

        // Build inline styles only if custom colors are provided (not default)
        $buttonStyle = '';
        if ($buttonColor !== '#000' || $textColor !== '#fff' || $borderRadius !== '0px') {
            $buttonStyle = sprintf(
                'background-color: %s; color: %s; border-radius: %s;',
                esc_attr($buttonColor),
                esc_attr($textColor),
                esc_attr($borderRadius)
            );
        }

        ob_start();
?>
        <div class="<?php echo esc_attr(implode(' ', $formClasses)); ?>">
            <form
                id="<?php echo esc_attr($formId); ?>"
                class="mailerpress-optin-form mailerpress_shortcode-optin-form"
                data-success-message="<?php echo $successMessage; ?>"
                data-error-message="<?php echo $errorMessage; ?>"
                data-double-optin="<?php echo $doubleOptinEnabled ? 'true' : 'false'; ?>"
                <?php if (!empty($redirectUrl)): ?>data-redirect-url="<?php echo esc_attr($redirectUrl); ?>" <?php endif; ?>>
                <div class="<?php echo esc_attr(implode(' ', $fieldClasses)); ?>">
                    <label for="<?php echo esc_attr($emailId); ?>">
                        <?php echo esc_html($atts['email_label']); ?>
                        <?php if ($emailRequired): ?>*<?php endif; ?>
                    </label>
                    <input
                        type="email"
                        id="<?php echo esc_attr($emailId); ?>"
                        name="contactEmail"
                        placeholder="<?php echo esc_attr($atts['email_placeholder']); ?>"
                        <?php if ($emailRequired): ?>required<?php endif; ?>
                        aria-label="<?php echo esc_attr($atts['email_label']); ?>" />
                </div>

                <?php if ($showFirstName): ?>
                    <div class="<?php echo esc_attr(implode(' ', $fieldClasses)); ?>">
                        <label for="<?php echo esc_attr($firstNameId); ?>">
                            <?php echo esc_html($atts['first_name_label']); ?>
                            <?php if ($firstNameRequired): ?>*<?php endif; ?>
                        </label>
                        <input
                            type="text"
                            id="<?php echo esc_attr($firstNameId); ?>"
                            name="contactFirstName"
                            placeholder="<?php echo esc_attr($atts['first_name_placeholder']); ?>"
                            <?php if ($firstNameRequired): ?>required<?php endif; ?>
                            aria-label="<?php echo esc_attr($atts['first_name_label']); ?>" />
                    </div>
                <?php endif; ?>

                <?php if ($showLastName): ?>
                    <div class="<?php echo esc_attr(implode(' ', $fieldClasses)); ?>">
                        <label for="<?php echo esc_attr($lastNameId); ?>">
                            <?php echo esc_html($atts['last_name_label']); ?>
                            <?php if ($lastNameRequired): ?>*<?php endif; ?>
                        </label>
                        <input
                            type="text"
                            id="<?php echo esc_attr($lastNameId); ?>"
                            name="contactLastName"
                            placeholder="<?php echo esc_attr($atts['last_name_placeholder']); ?>"
                            <?php if ($lastNameRequired): ?>required<?php endif; ?>
                            aria-label="<?php echo esc_attr($atts['last_name_label']); ?>" />
                    </div>
                <?php endif; ?>

                <div class="<?php echo esc_attr(implode(' ', $submitClasses)); ?>">
                    <button
                        type="submit"
                        class="<?php echo esc_attr(implode(' ', $buttonClasses)); ?>"
                        <?php if (!empty($buttonStyle)): ?>style="<?php echo esc_attr($buttonStyle); ?>" <?php endif; ?>>
                        <?php echo $buttonText; ?>
                    </button>
                </div>

                <input type="hidden" name="mailerpress-list" value="<?php echo esc_attr(wp_json_encode($listsArray, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>" />
                <input type="hidden" name="mailerpress-tags" value="<?php echo esc_attr(wp_json_encode($tagsArray, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>" />
            </form>
        </div>
<?php
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

        // Register empty script then add inline content
        wp_register_script(
            'mailerpress-optin-form-js',
            false, // No external file
            [],
            '1.0.0',
            true // In footer
        );

        // Localize script with REST API URL
        wp_localize_script(
            'mailerpress-optin-form-js',
            'mailerpressOptin',
            [
                'apiUrl' => rest_url('mailerpress/v1/contact'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]
        );

        wp_enqueue_script('mailerpress-optin-form-js');

        // Include JavaScript view.js directly in footer
        // This avoids file accessibility issues
        // Validate path to prevent directory traversal attacks
        $viewScriptPath = $root . '/packages/gutenberg/mailerpress-form/view.js';
        // Ensure the path is within the plugin root directory
        $realRoot = realpath($root);
        $realScriptPath = realpath($viewScriptPath);
        if ($realRoot && $realScriptPath && strpos($realScriptPath, $realRoot) === 0 && file_exists($viewScriptPath)) {
            $scriptContent = file_get_contents($viewScriptPath);
            if ($scriptContent) {
                // Replace relative path with dynamic URL from wp_localize_script
                // Use rest_url() to get correct URL and properly escape for JavaScript
                $apiUrl = rest_url('mailerpress/v1/contact');
                $apiUrlJson = wp_json_encode($apiUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $scriptContent = str_replace(
                    'const response = await fetch("/wp-json/mailerpress/v1/contact",',
                    'const apiUrl = (typeof mailerpressOptin !== "undefined" && mailerpressOptin && mailerpressOptin.apiUrl) ? mailerpressOptin.apiUrl : ' . $apiUrlJson . ';' . "\n                const response = await fetch(apiUrl,",
                    $scriptContent
                );

                // Update lists handling to support multiple lists (like tags)
                // Change from single value to JSON array parsing
                $scriptContent = str_replace(
                    'lists: [formData.get(\'mailerpress-list\')].filter(Boolean).map(id => ({ id })),',
                    'lists: JSON.parse(formData.get(\'mailerpress-list\') || \'[]\').map(id => ({ id })),',
                    $scriptContent
                );

                // Add redirect functionality after successful submission
                // Insert redirect logic inside the success block, after form.reset()
                // Note: redirectUrl is already validated and escaped via esc_attr() in the HTML
                $redirectScript = "\n                    // Handle redirect after successful submission
                    const redirectUrl = form.dataset.redirectUrl;
                    if (redirectUrl) {
                        // Validate URL before redirecting to prevent XSS
                        try {
                            const url = new URL(redirectUrl, window.location.origin);
                            // Redirect after a short delay to show success message
                            setTimeout(() => {
                                window.location.href = url.href;
                            }, 1500);
                        } catch (e) {
                            console.error('Invalid redirect URL:', redirectUrl);
                        }
                    }";

                // Insert redirect logic after form.reset() in the success block
                $scriptContent = str_replace(
                    'form.reset();',
                    'form.reset();' . $redirectScript,
                    $scriptContent
                );

                wp_add_inline_script(
                    'mailerpress-optin-form-js',
                    $scriptContent,
                    'after'
                );
            }
        }
    }
}
