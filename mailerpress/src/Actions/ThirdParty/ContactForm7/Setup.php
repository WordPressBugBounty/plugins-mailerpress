<?php

namespace MailerPress\Actions\ThirdParty\ContactForm7;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use WPCF7_ConfigValidator;
use WPCF7_ContactForm;
use WPCF7_Submission;

class Setup
{
    #[Filter('wpcf7_form_elements')]
    public function addHoneypotField(string $content): string
    {
        if (\MailerPress\Services\RateLimitConfig::isHoneypotEnabled()) {
            $content .= '<div aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden;"><label for="mailerpress-hp-website">' . esc_html__('Website', 'mailerpress') . '</label><input type="text" id="mailerpress-hp-website" name="website" value="" tabindex="-1" autocomplete="off" /></div>';
        }

        return $content;
    }

    #[Filter('wpcf7_editor_panels')]
    public function showMailerPressMetabox($panels)
    {
        $new_page = [
                'mailerpress-extension' => [
                        'title' => __('MailerPress', 'mailerpress'),
                        'callback' => [$this, 'cf7_add_mailerpress_extension'],
                ],
        ];

        return array_merge($panels, $new_page);
    }

    /**
     * ✅ FIXED: Save MailerPress panel data, including nested custom fields.
     */
    #[Action('wpcf7_after_save', acceptedArgs: 1)]
    public function save($contact_form): void
    {
        if (
                empty($_POST)
                || !isset($_POST['wpcf7-mailerpress'])
                || !is_array($_POST['wpcf7-mailerpress'])
        ) {
            return;
        }

        // Sanitize recursively (preserving nested arrays)
        $data = $this->sanitize_recursive($_POST['wpcf7-mailerpress']);

        // Ensure array shape consistency
        if (!isset($data['custom_fields'])) {
            $data['custom_fields'] = [];
        }

        update_option('cf7_mailerpress_' . $contact_form->id(), $data);
    }

    private function sanitize_recursive($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitize_recursive'], $value);
        }

        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * ✅ Subscribes contact at submission time (no changes)
     */
    #[Action('wpcf7_before_send_mail', acceptedArgs: 1)]
    public function subscribe($form)
    {
        $subscriptionOption = get_option('mailerpress_signup_confirmation', null);
        if (is_string($subscriptionOption)) {
            $subscriptionOption = json_decode($subscriptionOption, true);
        } elseif (!is_array($subscriptionOption)) {
            $subscriptionOption = null;
        }

        $cf7_mailerpress = get_option('cf7_mailerpress_' . $form->id());
        $submission = WPCF7_Submission::get_instance();

        if (!$cf7_mailerpress || !$submission) {
            return;
        }

        $posted = $submission->get_posted_data();
        $regex = '/\[\s*([a-zA-Z_][0-9a-zA-Z:._-]*)\s*\]/';

        $email = $this->cf7_mch_tag_replace($regex, $cf7_mailerpress['email'] ?? '', $posted);
        $firstName = $this->cf7_mch_tag_replace($regex, $cf7_mailerpress['first-name'] ?? '', $posted);
        $lastName = $this->cf7_mch_tag_replace($regex, $cf7_mailerpress['last-name'] ?? '', $posted);
        $list = $this->cf7_mch_tag_replace($regex, $cf7_mailerpress['list'] ?? '', $posted);
        $tag = $this->cf7_mch_tag_replace($regex, $cf7_mailerpress['tag'] ?? '', $posted);
        $confirm = $this->cf7_mch_tag_replace($regex, $cf7_mailerpress['confirm'] ?? '', $posted);

        if (empty($email) || empty($list)) {
            return;
        }

        // Honeypot protection: if a hidden "website" field is filled, it's a bot
        if (\MailerPress\Services\RateLimitConfig::isHoneypotEnabled()) {
            $honeypot = sanitize_text_field($posted['website'] ?? '');
            if (!empty($honeypot)) {
                return; // Silently skip — don't add contact
            }
        }

        $contact_data = [
                'contactEmail' => $email,
                'contactFirstName' => $firstName,
                'contactLastName' => $lastName,
                'lists' => [['id' => $list]],
                'opt_in_source' => 'cf7',
                'optin_details' => wp_json_encode(['form_id' => $form->id()]),
        ];

        if ("1" === $confirm) {
            // CF7 form-level double opt-in takes priority
            $contact_data['contactStatus'] = 'pending';
        } elseif (isset($subscriptionOption['enableSignupConfirmation']) && $subscriptionOption['enableSignupConfirmation']) {
            // Fall back to global signup confirmation setting
            $contact_data['contactStatus'] = 'pending';
        } else {
            $contact_data['contactStatus'] = 'subscribed';
        }

        if (!empty($tag)) {
            $contact_data['tags'] = [['id' => $tag]];
        }

        // --- Custom fields mapping ---
        try {
            $customFieldsModel = \MailerPress\Core\Kernel::getContainer()->get(\MailerPress\Models\CustomFields::class);
            $custom_fields_defs = $customFieldsModel->all();
        } catch (\Throwable $e) {
            $custom_fields_defs = [];
        }

        if (!empty($custom_fields_defs)) {
            foreach ($custom_fields_defs as $field_def) {
                $field_key = $field_def->field_key;
                $mapping = $cf7_mailerpress['custom_fields'][$field_key] ?? '';

                if ('' === trim((string)$mapping)) {
                    continue;
                }

                $value = $this->cf7_mch_tag_replace($regex, $mapping, $posted, false);

                if ($value === $mapping || '' === $value) {
                    continue;
                }

                $contact_data['custom_fields'][$field_key] = $value;
            }
        }

        add_mailerpress_contact($contact_data);
    }

    /* -----------------------------------------------------------------
     * Config validator integration
     * ----------------------------------------------------------------- */

    #[Filter('wpcf7_config_validator_available_error_codes', acceptedArgs: 1)]
    public function register_validator_codes(array $codes): array
    {
        $codes[] = 'mailerpress_extension_missing_email';
        $codes[] = 'mailerpress_extension_missing_list';
        return $codes;
    }

    #[Action('wpcf7_config_validator_validate', priority: 10, acceptedArgs: 1)]
    public function validate_mailerpress_config(WPCF7_ConfigValidator $validator): void
    {
        $contact_form = $validator->contact_form();
        if (!$contact_form instanceof WPCF7_ContactForm) {
            return;
        }

        $opts = (array) get_option('cf7_mailerpress_' . $contact_form->id(), []);
        $email = trim((string) ($opts['email'] ?? ''));
        $list = trim((string) ($opts['list'] ?? ''));

        if ('' === $email) {
            $validator->add_error(
                    'mailerpress-extension',
                    'mailerpress_extension_missing_email',
                    ['message' => __('You must map a subscriber email field.', 'mailerpress')]
            );
        }

        if ('' === $list) {
            $validator->add_error(
                    'mailerpress-extension',
                    'mailerpress_extension_missing_list',
                    ['message' => __('You must select a MailerPress list.', 'mailerpress')]
            );
        }
    }

    private function mailerpress_config_validator_box(\WPCF7_ContactForm $contact_form)
    {
        $validator = new \WPCF7_ConfigValidator($contact_form);
        $validator->validate();

        $error_messages = $validator->collect_error_messages();
        $section_msgs = $error_messages['mailerpress-extension'] ?? [];

        if (empty($section_msgs)) {
            return;
        }

        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left-width:4px;border-left-color:#d63638;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:1px 12px;">';
        foreach ($section_msgs as $m) {
            printf(
                    '<p><span class="icon-in-circle" aria-hidden="true">!</span>%s</p>',
                    esc_html($m['message'] ?? __('Unknown configuration issue.', 'mailerpress'))
            );
        }
        echo '</div>';
    }

    /**
     * ✅ Render the MailerPress panel in the CF7 editor
     */
    public function cf7_add_mailerpress_extension($contact_form): void
    {
        $lists = \MailerPress\Core\Kernel::getContainer()->get(\MailerPress\Models\Lists::class)->getLists();
        $formattedLists = array_map(
                fn($list) => ['label' => $list['name'], 'value' => $list['list_id']],
                $lists
        );

        $tags = \MailerPress\Core\Kernel::getContainer()->get(\MailerPress\Models\Tags::class)->getAll();
        $formattedTags = array_map(
                fn($tag) => ['label' => $tag->name, 'value' => $tag->tag_id],
                $tags
        );

        $cf7_mailerpress = get_option('cf7_mailerpress_' . $contact_form->id(), []);
        ?>
        <div class="metabox-holder">
            <?php $this->mailerpress_config_validator_box($contact_form); ?>
            <h2><?php echo esc_html__('MailerPress Extension', 'mailerpress'); ?></h2>

            <fieldset>
                <legend>
                    <?php echo esc_html__('Configure opt-ins to MailerPress when this form is submitted.', 'mailerpress'); ?>
                </legend>

                <p class="mail-field">
                    <label><?php esc_html_e('Subscriber First Name:', 'mailerpress'); ?></label><br/>
                    <input type="text" name="wpcf7-mailerpress[first-name]" class="wide" size="70"
                           placeholder="[your-name]"
                           value="<?php echo esc_attr($cf7_mailerpress['first-name'] ?? ''); ?>"/>
                </p>

                <p class="mail-field">
                    <label><?php esc_html_e('Subscriber Last Name:', 'mailerpress'); ?></label><br/>
                    <input type="text" name="wpcf7-mailerpress[last-name]" class="wide" size="70"
                           placeholder="[your-last-name]"
                           value="<?php echo esc_attr($cf7_mailerpress['last-name'] ?? ''); ?>"/>
                </p>

                <p class="mail-field">
                    <label><?php esc_html_e('Subscriber Email:', 'mailerpress'); ?></label><br/>
                    <input type="text" name="wpcf7-mailerpress[email]" class="wide" size="70"
                           placeholder="[your-email]"
                           value="<?php echo esc_attr($cf7_mailerpress['email'] ?? ''); ?>"/>
                </p>

                <p class="mail-field">
                    <label><?php esc_html_e('Contact List', 'mailerpress'); ?></label><br/>
                    <select name="wpcf7-mailerpress[list]" class="wide">
                        <option value=""><?php esc_html_e('-- Select a list --', 'mailerpress'); ?></option>
                        <?php foreach ($formattedLists as $list): ?>
                            <option value="<?php echo esc_attr($list['value']); ?>" <?php selected($cf7_mailerpress['list'] ?? '', $list['value']); ?>>
                                <?php echo esc_html($list['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="mail-field">
                    <label><?php esc_html_e('Contact Tag', 'mailerpress'); ?></label><br/>
                    <select name="wpcf7-mailerpress[tag]" class="wide">
                        <option value=""><?php esc_html_e('-- Select a tag --', 'mailerpress'); ?></option>
                        <?php foreach ($formattedTags as $tag): ?>
                            <option value="<?php echo esc_attr($tag['value']); ?>" <?php selected($cf7_mailerpress['tag'] ?? '', $tag['value']); ?>>
                                <?php echo esc_html($tag['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <hr/>
                <h3><?php echo esc_html__('Custom field mappings', 'mailerpress'); ?></h3>
                <p class="description"><?php echo esc_html__('Map your MailerPress contact custom fields to CF7 form tags (e.g. [your-field]).', 'mailerpress'); ?></p>

                <?php if (!is_plugin_active('mailerpress-pro/mailerpress-pro.php')): ?>
                    <div class="pro-notice" style="margin: 10px 0; padding: 10px; background: #fff8e5; border-left: 4px solid #ffb900;">
                        <p>
                            <?php echo wp_kses_post(__('Custom field mapping is available in <strong>MailerPress Pro</strong>. <a href="https://mailerpress.com/pricing" target="_blank">Upgrade now</a> to unlock this feature.', 'mailerpress')); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php
                    $customFieldsModel = \MailerPress\Core\Kernel::getContainer()->get(\MailerPress\Models\CustomFields::class);
                    $custom_fields_defs = $customFieldsModel->all();

                    if (!empty($custom_fields_defs)) :
                        foreach ($custom_fields_defs as $cf_def) :
                            $key = $cf_def->field_key;
                            $label = $cf_def->label ?: $key;
                            $mapped = $cf7_mailerpress['custom_fields'][$key] ?? '';
                            ?>
                            <p class="mail-field">
                                <label><?php echo esc_html(sprintf('%s (%s):', $label, $key)); ?></label><br/>
                                <input type="text"
                                       name="wpcf7-mailerpress[custom_fields][<?php echo esc_attr($key); ?>]"
                                       class="wide"
                                       size="70"
                                       placeholder="[your-field]"
                                       value="<?php echo esc_attr($mapped); ?>"/>
                            </p>
                        <?php
                        endforeach;
                    else :
                        ?>
                        <p class="description"><?php echo esc_html__('No custom fields defined in MailerPress.', 'mailerpress'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <p class="mail-field">
                    <label>
                        <input type="checkbox" name="wpcf7-mailerpress[confirm]" value="1"
                                <?php checked(!empty($cf7_mailerpress['confirm'])); ?> />
                        <strong><?php esc_html_e('Enable Double Opt-in', 'mailerpress'); ?></strong>
                    </label>
                </p>
            </fieldset>
        </div>
        <?php
    }

    private function cf7_mch_tag_replace($pattern, $subject, $posted_data, $html = false)
    {
        if (preg_match($pattern, $subject, $matches) > 0) {
            if (isset($posted_data[$matches[1]])) {
                $submitted = $posted_data[$matches[1]];
                $replaced = is_array($submitted) ? implode(', ', $submitted) : $submitted;

                if ($html) {
                    $replaced = strip_tags($replaced);
                    $replaced = wptexturize($replaced);
                }

                $replaced = apply_filters('wpcf7_mail_tag_replaced', $replaced, $submitted);
                return stripslashes($replaced);
            }
            return $matches[0];
        }

        return $subject;
    }
}
