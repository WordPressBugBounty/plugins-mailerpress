<?php

declare(strict_types=1);

namespace MailerPress\Actions\Frontend;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use MailerPress\Models\Contacts;
use MailerPress\Services\ManageSubscriptionLink;
use MailerPress\Services\SendingFrequencyLimiter;

/**
 * Handles the two buttons of the expired Manage subscription page:
 * email a fresh link to the contact, or unsubscribe them.
 *
 * Accepted links: signed ones (expired or not) and legacy unsigned ones that
 * carry only the access token. A link whose signature does not match is refused.
 */
class ManageSubscriptionLinkRequest
{
    private const RESEND_COOLDOWN = 15 * MINUTE_IN_SECONDS;

    private Contacts $contacts;

    public function __construct(Contacts $contacts)
    {
        $this->contacts = $contacts;
    }

    #[Action(['admin_post_nopriv_mailerpress_manage_link', 'admin_post_mailerpress_manage_link'])]
    public function handle(): void
    {
        nocache_headers();

        $linkParams = ManageSubscriptionLink::params($_POST);
        $request = isset($_POST['request']) ? sanitize_key(wp_unslash($_POST['request'])) : '';
        $nonce = isset($_POST['mailerpress_nonce']) ? sanitize_text_field(wp_unslash($_POST['mailerpress_nonce'])) : '';

        $contact = '' !== $linkParams['cid'] ? $this->contacts->getByAccessToken($linkParams['cid']) : null;
        if (null === $contact) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $isLegacy = '' === $linkParams['exp'] && '' === $linkParams['sig'];
        $status = ManageSubscriptionLink::check($linkParams['cid'], $linkParams['exp'], $linkParams['sig']);

        if (
            !wp_verify_nonce($nonce, 'mailerpress_manage_link')
            || (ManageSubscriptionLink::INVALID === $status && !$isLegacy)
            || !in_array($request, ['resend', 'unsubscribe'], true)
        ) {
            $this->redirectBack($linkParams, 'error');
        }

        if ('unsubscribe' === $request) {
            $this->contacts->unsubscribe((string) $contact->contact_id);
            do_action('mailerpress_contact_unsubscribed', (int) $contact->contact_id);
            do_action('mailerpress_unsubscribe');
            $this->redirectBack($linkParams, 'unsubscribed');
        }

        $this->redirectBack($linkParams, $this->resend($contact));
    }

    private function resend(object $contact): string
    {
        $cooldownKey = 'mailerpress_manage_link_sent_' . (int) $contact->contact_id;
        if (false !== get_transient($cooldownKey)) {
            return 'wait';
        }

        $email = sanitize_email((string) ($contact->email ?? ''));
        $isActive = !in_array((string) ($contact->status ?? ''), ['deleted', 'disabled'], true);
        if (!$isActive || !is_email($email)) {
            return 'error';
        }

        set_transient($cooldownKey, 1, self::RESEND_COOLDOWN);

        $siteName = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $url = ManageSubscriptionLink::url((string) $contact->access_token);
        $ttlDays = max(1, (int) round(ManageSubscriptionLink::ttl() / DAY_IN_SECONDS));

        $subject = sprintf(
            /* translators: %s: site name */
            __('Your link to manage your subscription to %s', 'mailerpress'),
            $siteName
        );

        $body = sprintf(
            '<p>%1$s</p><p>%2$s</p><p><a href="%3$s">%4$s</a></p><p>%5$s</p>',
            esc_html__('Hello,', 'mailerpress'),
            esc_html(sprintf(
                /* translators: 1: site name, 2: number of days the link stays valid */
                _n(
                    'Here is your new link to manage your subscription to %1$s. It is valid for %2$d day.',
                    'Here is your new link to manage your subscription to %1$s. It is valid for %2$d days.',
                    $ttlDays,
                    'mailerpress'
                ),
                $siteName,
                $ttlDays
            )),
            esc_url($url),
            esc_html__('Manage my subscription', 'mailerpress'),
            esc_html__('If you did not ask for this link, you can ignore this email.', 'mailerpress')
        );

        try {
            $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
            $config = $this->senderConfig($mailer->getConfig());

            $result = SendingFrequencyLimiter::send($mailer, [
                'to' => $email,
                'html' => true,
                'body' => $body,
                'subject' => $subject,
                'sender_name' => $config['conf']['default_name'] ?? '',
                'sender_to' => $config['conf']['default_email'] ?? '',
                'reply_to_name' => $config['reply_to_name'] ?? '',
                'reply_to_address' => $config['reply_to_address'] ?? '',
                'apiKey' => $config['conf']['api_key'] ?? '',
            ]);
        } catch (\Throwable $e) {
            $result = false;
        }

        if (true !== $result) {
            delete_transient($cooldownKey);

            return 'error';
        }

        return 'sent';
    }

    /**
     * Same sender fallbacks as the other transactional emails.
     */
    private function senderConfig(array $config): array
    {
        $defaultSettings = get_option('mailerpress_default_settings', []);
        if (is_string($defaultSettings)) {
            $defaultSettings = json_decode($defaultSettings, true) ?: [];
        }

        if (empty($config['conf']['default_email']) || empty($config['conf']['default_name'])) {
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

    /**
     * Goes back to the expired page with the same link and a notice.
     */
    private function redirectBack(array $linkParams, string $notice): void
    {
        $args = array_filter([
            'cid' => rawurlencode($linkParams['cid']),
            'exp' => $linkParams['exp'],
            'sig' => $linkParams['sig'],
        ], static fn (string $value): bool => '' !== $value);
        $args['mp_notice'] = $notice;

        wp_safe_redirect(add_query_arg($args, mailerpress_get_page('manage_page')));
        exit;
    }
}
