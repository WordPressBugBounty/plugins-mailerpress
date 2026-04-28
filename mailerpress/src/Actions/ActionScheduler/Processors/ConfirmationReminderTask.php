<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Models\Contacts;

class ConfirmationReminderTask
{
    private Contacts $contactModel;

    public function __construct(
        Contacts $contactModel,
    ) {
        $this->contactModel = $contactModel;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Action('mailerpress_send_confirmation_reminder', priority: 10)]
    public function run(int $contactId): void
    {
        // Get the contact
        $contact = $this->contactModel->get($contactId);

        if (!$contact) {
            return;
        }

        // Check if contact is still pending, if not, don't send reminder
        if ($contact->subscription_status !== 'pending') {
            return;
        }

        // Switch to the subscriber's language so get_option returns WPML-translated values
        $contactLang = $this->getContactLanguage($contactId);
        $langSwitched = false;
        if ($contactLang) {
            $shortLang = substr($contactLang, 0, 2);
            // Switch WordPress locale for __() translations
            $wpLocale = $shortLang . '_' . strtoupper($shortLang);
            switch_to_locale($wpLocale);
            // Switch WPML language for admin-texts option translations
            if (has_filter('wpml_switch_language')) {
                do_action('wpml_switch_language', $shortLang);
            }
            $langSwitched = true;
        }

        // Get reminder settings
        $signupConfirmationOption = mailerpress_get_signup_confirmation_option();

        // Check if reminders are enabled
        if (empty($signupConfirmationOption['enableReminders'])) {
            return;
        }

        global $wpdb;
        $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);

        // Check if reminder already sent
        $reminderSent = $wpdb->get_var($wpdb->prepare(
            "SELECT field_value FROM {$customFieldsTable}
            WHERE contact_id = %d AND field_key = %s",
            $contactId,
            'reminder_sent'
        ));

        // If reminder already sent, skip
        if ($reminderSent === '1') {
            return;
        }

        $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
        $config = $mailer->getConfig();

        if (
            empty($config['conf']['default_email'])
            || empty($config['conf']['default_name'])
        ) {
            $defaultSettings = get_option('mailerpress_default_settings', []);
            if (is_string($defaultSettings)) {
                $defaultSettings = json_decode($defaultSettings, true) ?: [];
            }

            if (!empty($defaultSettings['fromAddress']) && !empty($defaultSettings['fromName'])) {
                $config['conf']['default_email'] = $defaultSettings['fromAddress'];
                $config['conf']['default_name'] = $defaultSettings['fromName'];
            } else {
                $globalSender = get_option('mailerpress_global_email_senders');
                if (is_string($globalSender)) {
                    $globalSender = json_decode($globalSender, true);
                }
                if (is_array($globalSender)) {
                    $config['conf']['default_email'] = $globalSender['fromAddress'] ?? '';
                    $config['conf']['default_name'] = $globalSender['fromName'] ?? '';
                }
            }
        }

        // Get Reply to settings
        $defaultSettings = get_option('mailerpress_default_settings', []);
        if (is_string($defaultSettings)) {
            $defaultSettings = json_decode($defaultSettings, true) ?: [];
        }

        $replyToName = !empty($defaultSettings['replyToName'])
            ? $defaultSettings['replyToName']
            : ($config['conf']['default_name'] ?? '');
        $replyToAddress = !empty($defaultSettings['replyToAddress'])
            ? $defaultSettings['replyToAddress']
            : ($config['conf']['default_email'] ?? '');

        $emailSubject = $signupConfirmationOption['emailSubject'] ?? __('Confirm your subscription to [site:title]', 'mailerpress');

        $site = [
            'title' => get_bloginfo('name'),
            'home_url' => home_url('/'),
        ];

        // Prepare email
        $contactData = [
            'email' => $contact->email,
            'first_name' => $contact->first_name ?? '',
            'last_name' => $contact->last_name ?? '',
            'activation_link' => wp_unslash(
                home_url(
                    \sprintf(
                        '?mailpress-pages=mailerpress&action=confirm&cid=%s&data=%s',
                        esc_attr($contact->access_token),
                        esc_attr($contact->unsubscribe_token),
                    )
                )
            ),
        ];

        // Old textarea format placeholders
        $placeholders = [
            '[contact:email]' => $contactData['email'],
            '[contact:firstName]' => $contactData['first_name'],
            '[contact:lastName]' => $contactData['last_name'],
            '[site:title]' => $site['title'],
            '[activation_link]' => '<a href="' . $contactData['activation_link'] . '">',
            '[/activation_link]' => '</a>',
            '[site:homeURL]' => $site['home_url'],
        ];

        // Editor merge tag format {{variable}}
        $editorPlaceholders = [
            '{{contact_first_name}}' => $contactData['first_name'],
            '{{contact_last_name}}' => $contactData['last_name'],
            '{{contact_email}}' => $contactData['email'],
            '{{site_title}}' => $site['title'],
            '{{site_url}}' => $site['home_url'],
            '{{activation_link}}' => $contactData['activation_link'],
        ];

        // Helper to strip merge-tag spans and replace both placeholder formats
        $replaceDynamicVariables = function (string $content, bool $applyNl2br = true, bool $stripActivationLink = false) use ($placeholders, $editorPlaceholders): string {
            // When using MJML template, strip activation_link shortcodes entirely
            if ($stripActivationLink) {
                // Strip complete shortcode pair
                $content = preg_replace('/\[activation_link\].*?\[\/activation_link\]/s', '', $content);
                // Strip orphan tags (translator may omit the closing tag)
                $content = str_replace(['[activation_link]', '[/activation_link]'], '', $content);
            }

            // Old textarea format
            $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);

            // Strip merge-tag spans from editor HTML
            $content = preg_replace_callback(
                '#<span[^>]*(?:class=["\'][^"\']*\bmerge-tag-span\b[^"\']*["\']|data-merge-tag-id=["\'][^"\']*["\'])[^>]*>(.*?)</span>#is',
                fn($m) => $m[1] ?? '',
                $content
            );

            // Editor {{variable}} format
            $content = str_replace(array_keys($editorPlaceholders), array_values($editorPlaceholders), $content);

            return $applyNl2br ? nl2br($content) : $content;
        };

        // Try to use the MJML campaign HTML if available and enabled
        $campaignId = $signupConfirmationOption['campaign_id'] ?? null;
        $useDesignedEmail = !empty($signupConfirmationOption['useDesignedEmail']);
        $campaignHtml = null;
        if ($campaignId && $useDesignedEmail) {
            $campaignHtml = get_option("mailerpress_batch_{$campaignId}_html");
        }

        $emailContent = $signupConfirmationOption['emailContent'] ?? '';

        if (!empty($campaignHtml)) {
            // Split content around [activation_link] BEFORE variable replacement
            // (the button block in the MJML template handles it via {{activation_link}})
            [$beforeText, $afterText] = $this->splitAroundActivationLink($emailContent);
            $buttonLabel = $this->extractActivationLinkLabel($emailContent);
            $beforeHtml = $replaceDynamicVariables($beforeText, true, true);
            $afterHtml = $replaceDynamicVariables($afterText, true, true);
            $body = $this->injectContentIntoTemplate($campaignHtml, $beforeHtml, $afterHtml);
            // Replace the button label with the translated one (before {{activation_link}} is resolved)
            if ($buttonLabel) {
                $body = $this->replaceButtonLabel($body, $buttonLabel);
            }
            // Replace editor merge tags in the campaign HTML (e.g. {{activation_link}} in button href)
            $body = $replaceDynamicVariables($body, false, true);
        } else {
            // Simple text email (keeps [activation_link] for inline <a> rendering)
            $body = $replaceDynamicVariables($emailContent);
        }

        $subject = $replaceDynamicVariables($emailSubject, false);

        // Send email
        $result = $mailer->sendEmail([
            'to' => $contact->email,
            'html' => true,
            'body' => $body,
            'subject' => $subject,
            'sender_name' => $config['conf']['default_name'],
            'sender_to' => $config['conf']['default_email'],
            'reply_to_name' => $replyToName,
            'reply_to_address' => $replyToAddress,
            'apiKey' => $config['conf']['api_key'] ?? '',
        ]);

        if ($result && !is_wp_error($result)) {
            // Mark reminder as sent
            $existingReminder = $wpdb->get_var($wpdb->prepare(
                "SELECT field_id FROM {$customFieldsTable}
                WHERE contact_id = %d AND field_key = %s",
                $contactId,
                'reminder_sent'
            ));

            if ($existingReminder) {
                $wpdb->update(
                    $customFieldsTable,
                    ['field_value' => '1'],
                    ['field_id' => $existingReminder],
                    ['%s'],
                    ['%d']
                );
            } else {
                $wpdb->insert(
                    $customFieldsTable,
                    [
                        'contact_id' => $contactId,
                        'field_key' => 'reminder_sent',
                        'field_value' => '1',
                    ],
                    ['%d', '%s', '%s']
                );
            }
        }

        // Restore locale and WPML language
        if ($langSwitched) {
            restore_previous_locale();
            if (has_filter('wpml_switch_language')) {
                do_action('wpml_switch_language', null);
            }
        }
    }

    private function extractActivationLinkLabel(string $content): ?string
    {
        if (preg_match('/\[activation_link\](.*?)\[\/activation_link\]/s', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function replaceButtonLabel(string $html, string $newLabel): string
    {
        return preg_replace(
            '/(<a\b[^>]*href=["\'][^"\']*\{\{activation_link\}\}[^"\']*["\'][^>]*>)(.*?)(<\/a>)/s',
            '${1}' . htmlspecialchars($newLabel, ENT_QUOTES, 'UTF-8') . '${3}',
            $html
        );
    }

    private function splitAroundActivationLink(string $content): array
    {
        // Complete pair: [activation_link]...[/activation_link]
        if (preg_match('/\[activation_link\].*?\[\/activation_link\]/s', $content)) {
            $parts = preg_split('/\[activation_link\].*?\[\/activation_link\]/s', $content);
            return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
        }
        // Orphan opening tag only (translator omitted closing tag)
        if (str_contains($content, '[activation_link]')) {
            $parts = explode('[activation_link]', $content, 2);
            return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
        }
        return [$content, ''];
    }

    private function injectContentIntoTemplate(string $html, string $beforeContent, string $afterContent = ''): string
    {
        $wrapStyle = 'padding: 16px 10px; font-size: 16px; line-height: 1.6; color: #333333;';

        // Replace BEFORE marker
        $beforePattern = '/<!-- MAILERPRESS_EMAIL_CONTENT_BEFORE_START -->.*?<!-- MAILERPRESS_EMAIL_CONTENT_BEFORE_END -->/s';
        if (preg_match($beforePattern, $html)) {
            $html = preg_replace($beforePattern, '<div style="' . $wrapStyle . '">' . $beforeContent . '</div>', $html);
        }

        // Replace AFTER marker
        $afterPattern = '/<!-- MAILERPRESS_EMAIL_CONTENT_AFTER_START -->.*?<!-- MAILERPRESS_EMAIL_CONTENT_AFTER_END -->/s';
        if (preg_match($afterPattern, $html)) {
            $html = preg_replace($afterPattern, '<div style="' . $wrapStyle . '">' . $afterContent . '</div>', $html);
        }

        // Legacy fallback: single marker
        $legacyPattern = '/<!-- MAILERPRESS_EMAIL_CONTENT_START -->.*?<!-- MAILERPRESS_EMAIL_CONTENT_END -->/s';
        if (preg_match($legacyPattern, $html)) {
            $fullContent = trim($beforeContent . "\n" . $afterContent);
            $html = preg_replace($legacyPattern, '<div style="' . $wrapStyle . '">' . $fullContent . '</div>', $html);
        }

        return $html;
    }

    private function getContactLanguage(int $contactId): ?string
    {
        global $wpdb;
        $cfTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
        return $wpdb->get_var($wpdb->prepare(
            "SELECT field_value FROM {$cfTable} WHERE contact_id = %d AND field_key = '_language'",
            $contactId
        ));
    }
}
