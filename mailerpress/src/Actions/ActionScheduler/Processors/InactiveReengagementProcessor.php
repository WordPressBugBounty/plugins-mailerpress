<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use MailerPress\Services\InactiveContactManager;
use MailerPress\Services\Logger;

class InactiveReengagementProcessor
{
    private const HOOK = 'mailerpress_send_inactive_reengagement_email';
    private const CHUNK_HOOK = 'mailerpress_send_inactive_reengagement_chunk';
    private const GROUP = 'mailerpress';
    private const SCHEDULE_CHECK_TRANSIENT = 'mailerpress_inactive_reengagement_daily_schedule_check';
    private const RECURRENCE = DAY_IN_SECONDS;
    private const CHUNK_SIZE = 10;

    public function __construct(private readonly InactiveContactManager $inactiveContactManager)
    {
    }

    #[Action('init', priority: 21)]
    public function schedule(): void
    {
        $this->syncSchedule();
    }

    #[Action([
        'add_option_' . InactiveContactManager::OPTION_NAME,
        'update_option_' . InactiveContactManager::OPTION_NAME,
    ], priority: 10, acceptedArgs: 0)]
    public function scheduleAfterSettingsChange(): void
    {
        delete_transient(self::SCHEDULE_CHECK_TRANSIENT);
        $this->syncSchedule(true);
    }

    private function syncSchedule(bool $force = false): void
    {
        if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        $isEnabled = $this->inactiveContactManager->isReengagementEnabled();
        $hasScheduledAction = (bool) as_next_scheduled_action(self::HOOK, [], self::GROUP);
        if ($isEnabled && !get_option(InactiveContactManager::REENGAGEMENT_ENABLED_AT_OPTION)) {
            add_option(InactiveContactManager::REENGAGEMENT_ENABLED_AT_OPTION, current_time('mysql'), '', false);
        }

        if (!$force && get_transient(self::SCHEDULE_CHECK_TRANSIENT) && (!$isEnabled || $hasScheduledAction)) {
            return;
        }

        set_transient(self::SCHEDULE_CHECK_TRANSIENT, 1, HOUR_IN_SECONDS);

        if (!$isEnabled) {
            delete_option(InactiveContactManager::REENGAGEMENT_ENABLED_AT_OPTION);
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(self::HOOK, [], self::GROUP);
                as_unschedule_all_actions(self::CHUNK_HOOK, [], self::GROUP);
            }
            return;
        }

        if (!$hasScheduledAction) {
            as_schedule_recurring_action(
                time() + MINUTE_IN_SECONDS,
                self::RECURRENCE,
                self::HOOK,
                [],
                self::GROUP
            );
        }
    }

    #[Action(self::HOOK)]
    public function process(): void
    {
        $settings = $this->inactiveContactManager->getSettings();
        if (
            empty($settings['reengagement_enabled'])
            || !function_exists('as_schedule_single_action')
            || as_next_scheduled_action(self::CHUNK_HOOK, null, self::GROUP)
        ) {
            return;
        }

        $contacts = $this->inactiveContactManager->findReengagementCandidates((int) $settings['batch_size']);
        if (empty($contacts)) {
            return;
        }

        $frequency = get_option('mailerpress_frequency_sending', []);
        if (is_string($frequency)) {
            $frequency = json_decode($frequency, true);
        }
        $frequency = is_array($frequency) ? $frequency : [];
        $config = $frequency['effectiveConfig'] ?? $frequency['settings'] ?? [];
        $numberEmail = max(1, (int) ($config['numberEmail'] ?? 25));
        $period = $config['frequency'] ?? $config['config'] ?? ['value' => 5, 'unit' => 'minutes'];
        $multipliers = ['seconds' => 1, 'minutes' => MINUTE_IN_SECONDS, 'hours' => HOUR_IN_SECONDS];
        $interval = max(1, (int) ($period['value'] ?? 5)) * ($multipliers[$period['unit'] ?? 'minutes'] ?? MINUTE_IN_SECONDS);
        $rateLimit = (int) ($config['rate_limit'] ?? 10);
        $interval = max($interval, $rateLimit > 0 ? (int) ceil($numberEmail / $rateLimit) : 0);
        $enabledAt = (string) get_option(InactiveContactManager::REENGAGEMENT_ENABLED_AT_OPTION, '');

        $contactIds = array_map(static fn($contact) => (int) $contact->contact_id, $contacts);
        foreach (array_chunk($contactIds, $numberEmail) as $windowIndex => $windowIds) {
            foreach (array_chunk($windowIds, self::CHUNK_SIZE) as $chunkIndex => $chunkIds) {
                as_schedule_single_action(
                    time() + ($windowIndex * $interval) + (int) ceil($chunkIndex * self::CHUNK_SIZE * $interval / $numberEmail),
                    self::CHUNK_HOOK,
                    [$chunkIds, $enabledAt, $rateLimit],
                    self::GROUP
                );
            }
        }

        Logger::info('Inactive re-engagement chunks scheduled', ['candidates' => count($contacts)]);
    }

    #[Action(self::CHUNK_HOOK, acceptedArgs: 3)]
    public function processChunk(array $contactIds, string $enabledAt, int $rateLimit): void
    {
        if (
            !$this->inactiveContactManager->isReengagementEnabled()
            || $enabledAt !== get_option(InactiveContactManager::REENGAGEMENT_ENABLED_AT_OPTION)
        ) {
            return;
        }

        $settings = $this->inactiveContactManager->getSettings();
        $sent = 0;
        $failed = 0;

        foreach ($contactIds as $index => $contactId) {
            $contact = $this->inactiveContactManager->findReengagementCandidate((int) $contactId);
            if (!$contact) {
                continue;
            }

            if ($index > 0 && $rateLimit > 0) {
                usleep((int) (1000000 / $rateLimit));
            }

            if ($this->sendReengagementEmail($contact, $settings)) {
                $this->inactiveContactManager->markReengagementSent((int) $contact->contact_id);
                $sent++;
            } else {
                $failed++;
            }
        }

        Logger::info('Inactive re-engagement chunk completed', [
            'sent' => $sent,
            'failed' => $failed,
            'candidates' => count($contactIds),
        ]);
    }

    private function sendReengagementEmail(object $contact, array $settings): bool
    {
        $confirmationUrl = $this->inactiveContactManager->buildReengagementConfirmationUrl($contact);
        $unsubscribeToken = (string) ($contact->unsubscribe_token ?? '');
        if ($confirmationUrl === '' || $unsubscribeToken === '' || empty($contact->email) || !is_email((string) $contact->email)) {
            return false;
        }

        $unsubscribeUrl = add_query_arg(
            [
                'data' => $unsubscribeToken,
                'cid' => (string) $contact->access_token,
            ],
            mailerpress_get_page('unsub_page')
        );
        $oneClickUrl = get_rest_url(null, sprintf(
            'mailerpress/v1/one-click-unsubscribe?token=%s',
            urlencode($unsubscribeToken)
        ));

        $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
        $config = $this->senderConfig($mailer->getConfig());
        $body = $this->renderEmailBody($contact, $settings, $confirmationUrl, $unsubscribeUrl);
        $body = $this->ensureConfirmationLink($body, $confirmationUrl);
        $body = $this->ensureUnsubscribeLink($body, $unsubscribeUrl);
        $subject = $this->replaceVariables((string) $settings['reengagement_subject'], $contact, $confirmationUrl, $unsubscribeUrl, false);

        $result = $mailer->sendEmail([
            'to' => sanitize_email((string) $contact->email),
            'html' => true,
            'body' => $body,
            'subject' => $subject,
            'sender_name' => $config['conf']['default_name'] ?? '',
            'sender_to' => $config['conf']['default_email'] ?? '',
            'reply_to_name' => $config['reply_to_name'] ?? '',
            'reply_to_address' => $config['reply_to_address'] ?? '',
            'apiKey' => $config['conf']['api_key'] ?? '',
            'custom_headers' => [
                'List-Unsubscribe' => '<' . $oneClickUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ]);

        return !is_wp_error($result) && false !== $result;
    }

    private function senderConfig(array $config): array
    {
        $defaultSettings = get_option('mailerpress_default_settings', []);
        if (is_string($defaultSettings)) {
            $defaultSettings = json_decode($defaultSettings, true) ?: [];
        }

        if (
            empty($config['conf']['default_email'])
            || empty($config['conf']['default_name'])
        ) {
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

        $config['reply_to_name'] = !empty($defaultSettings['replyToName'])
            ? $defaultSettings['replyToName']
            : ($config['conf']['default_name'] ?? '');
        $config['reply_to_address'] = !empty($defaultSettings['replyToAddress'])
            ? $defaultSettings['replyToAddress']
            : ($config['conf']['default_email'] ?? '');

        return $config;
    }

    private function renderEmailBody(object $contact, array $settings, string $confirmationUrl, string $unsubscribeUrl): string
    {
        $content = (string) ($settings['reengagement_content'] ?? '');
        $campaignId = !empty($settings['campaign_id']) ? absint($settings['campaign_id']) : 0;

        if ($campaignId > 0 && !empty($settings['useDesignedEmail'])) {
            $campaignHtml = get_option("mailerpress_batch_{$campaignId}_html");
            if (!empty($campaignHtml) && is_string($campaignHtml)) {
                [$beforeText, $afterText] = $this->splitAroundReengagementLink($content);
                $buttonLabel = $this->extractReengagementLinkLabel($content);
                $beforeHtml = nl2br(wp_kses_post($this->replaceContentVariables($beforeText, $contact, $confirmationUrl, $unsubscribeUrl, true)));
                $afterHtml = nl2br(wp_kses_post($this->replaceContentVariables($afterText, $contact, $confirmationUrl, $unsubscribeUrl, true)));
                $body = $this->injectContentIntoTemplate($campaignHtml, $beforeHtml, $afterHtml);

                if ($buttonLabel) {
                    $body = $this->replaceButtonLabel($body, $buttonLabel);
                }

                return $this->replaceContentVariables($body, $contact, $confirmationUrl, $unsubscribeUrl);
            }
        }

        return $this->replaceVariables($content, $contact, $confirmationUrl, $unsubscribeUrl, true);
    }

    private function ensureConfirmationLink(string $body, string $confirmationUrl): string
    {
        $decodedBody = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $linkPattern = '/<a\b[^>]*\s+href\s*=\s*["\']' . preg_quote($confirmationUrl, '/') . '["\'][^>]*>/i';
        if (preg_match($linkPattern, $decodedBody)) {
            return $body;
        }

        $link = sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url($confirmationUrl),
            esc_html__('Yes, keep me subscribed', 'mailerpress')
        );

        if (preg_match('/<\/body\s*>/i', $body)) {
            return preg_replace_callback(
                '/<\/body\s*>/i',
                static fn() => $link . '</body>',
                $body,
                1
            );
        }

        return $body . "\n\n" . $link;
    }

    private function ensureUnsubscribeLink(string $body, string $unsubscribeUrl): string
    {
        $decodedBody = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $linkPattern = '/<a\b[^>]*\s+href\s*=\s*["\']' . preg_quote($unsubscribeUrl, '/') . '["\'][^>]*>/i';
        if (preg_match($linkPattern, $decodedBody)) {
            return $body;
        }

        $footer = sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url($unsubscribeUrl),
            esc_html__('Unsubscribe', 'mailerpress')
        );

        if (preg_match('/<\/body\s*>/i', $body)) {
            return preg_replace_callback(
                '/<\/body\s*>/i',
                static fn() => $footer . '</body>',
                $body,
                1
            );
        }

        return $body . "\n\n" . $footer;
    }

    private function extractReengagementLinkLabel(string $content): ?string
    {
        if (preg_match('/\[reengagement_link\](.*?)\[\/reengagement_link\]/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function replaceButtonLabel(string $html, string $newLabel): string
    {
        return preg_replace(
            '/(<a\b[^>]*href=["\'][^"\']*(?:\{\{activation_link\}\}|\{\{reengagement_link\}\})[^"\']*["\'][^>]*>)(.*?)(<\/a>)/s',
            '${1}' . htmlspecialchars($newLabel, ENT_QUOTES, 'UTF-8') . '${3}',
            $html
        );
    }

    private function splitAroundReengagementLink(string $content): array
    {
        if (preg_match('/\[reengagement_link\].*?\[\/reengagement_link\]/s', $content)) {
            $parts = preg_split('/\[reengagement_link\].*?\[\/reengagement_link\]/s', $content);
            return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
        }

        if (str_contains($content, '[reengagement_link]')) {
            $parts = explode('[reengagement_link]', $content, 2);
            return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
        }

        return [$content, ''];
    }

    private function injectContentIntoTemplate(string $html, string $beforeContent, string $afterContent = ''): string
    {
        $wrapStyle = 'padding: 16px 10px; font-size: 16px; line-height: 1.6; color: #333333;';

        $beforePattern = '/<!-- MAILERPRESS_EMAIL_CONTENT_BEFORE_START -->.*?<!-- MAILERPRESS_EMAIL_CONTENT_BEFORE_END -->/s';
        if (preg_match($beforePattern, $html)) {
            $html = preg_replace($beforePattern, '<div style="' . $wrapStyle . '">' . $beforeContent . '</div>', $html);
        }

        $afterPattern = '/<!-- MAILERPRESS_EMAIL_CONTENT_AFTER_START -->.*?<!-- MAILERPRESS_EMAIL_CONTENT_AFTER_END -->/s';
        if (preg_match($afterPattern, $html)) {
            $html = preg_replace($afterPattern, '<div style="' . $wrapStyle . '">' . $afterContent . '</div>', $html);
        }

        $legacyPattern = '/<!-- MAILERPRESS_EMAIL_CONTENT_START -->.*?<!-- MAILERPRESS_EMAIL_CONTENT_END -->/s';
        if (preg_match($legacyPattern, $html)) {
            $html = preg_replace($legacyPattern, '<div style="' . $wrapStyle . '">' . trim($beforeContent . "\n" . $afterContent) . '</div>', $html);
        }

        return $html;
    }

    private function replaceContentVariables(string $content, object $contact, string $confirmationUrl, string $unsubscribeUrl, bool $stripReengagementLink = false): string
    {
        if ($stripReengagementLink) {
            $content = preg_replace('/\[reengagement_link\].*?\[\/reengagement_link\]/s', '', $content);
            $content = str_replace(['[reengagement_link]', '[/reengagement_link]'], '', $content);
        }

        $content = preg_replace_callback(
            '#<span[^>]*(?:class=["\'][^"\']*\bmerge-tag-span\b[^"\']*["\']|data-merge-tag-id=["\'][^"\']*["\'])[^>]*>(.*?)</span>#is',
            static fn($matches) => $matches[1] ?? '',
            $content
        );
        $content = $this->replaceUnsubscribeLink($content, $unsubscribeUrl);

        $siteTitle = get_bloginfo('name');
        $replacements = [
            '[contact:email]' => esc_html((string) ($contact->email ?? '')),
            '[contact:firstName]' => esc_html((string) ($contact->first_name ?? '')),
            '[contact:lastName]' => esc_html((string) ($contact->last_name ?? '')),
            '[site:title]' => $siteTitle,
            '[site:homeURL]' => home_url('/'),
            '{{contact_first_name}}' => esc_html((string) ($contact->first_name ?? '')),
            '{{contact_last_name}}' => esc_html((string) ($contact->last_name ?? '')),
            '{{contact_email}}' => esc_html((string) ($contact->email ?? '')),
            '{{site_title}}' => $siteTitle,
            '{{site_url}}' => home_url('/'),
            '{{activation_link}}' => $confirmationUrl,
            '{{reengagement_link}}' => $confirmationUrl,
            '%UNSUB_LINK%' => esc_url($unsubscribeUrl),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function replaceVariables(string $content, object $contact, string $confirmationUrl, string $unsubscribeUrl, bool $html): string
    {
        $linkPattern = '/\[reengagement_link\](.*?)\[\/reengagement_link\]/is';
        $linkReplaced = false;
        $content = preg_replace_callback($linkPattern, static function (array $matches) use ($confirmationUrl, &$linkReplaced): string {
            $linkReplaced = true;
            $label = trim(wp_strip_all_tags($matches[1] ?? ''));
            if ($label === '') {
                $label = __('Yes, keep me subscribed', 'mailerpress');
            }

            return sprintf(
                '<a href="%s">%s</a>',
                esc_url($confirmationUrl),
                esc_html($label)
            );
        }, $content);
        $content = $this->replaceUnsubscribeLink($content, $unsubscribeUrl);

        if ($html && !$linkReplaced && !str_contains(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $confirmationUrl)) {
            $content .= "\n\n" . sprintf(
                '<a href="%s">%s</a>',
                esc_url($confirmationUrl),
                esc_html__('Yes, keep me subscribed', 'mailerpress')
            );
        }

        $siteTitle = get_bloginfo('name');
        $replacements = [
            '[contact:email]' => $html ? esc_html((string) ($contact->email ?? '')) : (string) ($contact->email ?? ''),
            '[contact:firstName]' => $html ? esc_html((string) ($contact->first_name ?? '')) : (string) ($contact->first_name ?? ''),
            '[contact:lastName]' => $html ? esc_html((string) ($contact->last_name ?? '')) : (string) ($contact->last_name ?? ''),
            '[site:title]' => $siteTitle,
            '[site:homeURL]' => home_url('/'),
        ];

        $content = str_replace(array_keys($replacements), array_values($replacements), $content);

        if (!$html) {
            return wp_strip_all_tags($content);
        }

        return nl2br(wp_kses_post($content));
    }

    private function replaceUnsubscribeLink(string $content, string $unsubscribeUrl): string
    {
        return preg_replace_callback(
            '/\[unsubscribe_link\](.*?)\[\/unsubscribe_link\]/is',
            static function (array $matches) use ($unsubscribeUrl): string {
                $label = trim(wp_strip_all_tags($matches[1] ?? ''));
                if ($label === '') {
                    $label = __('Unsubscribe', 'mailerpress');
                }

                return sprintf('<a href="%s">%s</a>', esc_url($unsubscribeUrl), esc_html($label));
            },
            $content
        );
    }
}
