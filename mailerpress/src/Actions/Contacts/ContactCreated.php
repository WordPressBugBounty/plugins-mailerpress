<?php

declare(strict_types=1);

namespace MailerPress\Actions\Contacts;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Kernel;
use MailerPress\Models\Contacts;

class ContactCreated
{
    private Contacts $contacts;

    public function __construct(Contacts $contacts)
    {
        $this->contacts = $contacts;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Action('mailerpress_contact_created', priority: 10)]
    public function handleContactCreated($contactId): void
    {
        // Convertir en int car Contacts::get() attend un int
        $contactId = (int) $contactId;
        $contactEntity = $this->contacts->get($contactId);

        if (
            'pending' === $contactEntity->subscription_status &&
            ('manual' !== $contactEntity->opt_in_source && 'batch_import_file' !== $contactEntity->opt_in_source)
        ) {
            $signupConfirmationOption = get_option('mailerpress_signup_confirmation', wp_json_encode([
                'enableSignupConfirmation' => true,

                /** translators: %s is replaced with the site title (e.g., "Confirm your subscription to My Website") */
                'emailSubject' => __('Confirm your subscription to [site:title]', 'mailerpress'),

                /**
                 * translators:
                 * - [contact:firstName] and [contact:lastName] will be replaced with the subscriber’s name.
                 * - [site:title] is the website name.
                 * - [activation_link] and [/activation_link] wrap the confirmation link.
                 * - [site:homeURL] is the site URL.
                 */
                'emailContent' => __(
                    'Hello [contact:firstName] [contact:lastName],

You have received this email regarding your subscription to [site:title]. Please confirm it to receive emails from us:

[activation_link]Click here to confirm your subscription[/activation_link]

If you received this email in error, simply delete it. You will no longer receive emails from us if you do not confirm your subscription using the link above.

Thank you,

<a target="_blank" href="[site:homeURL]">[site:title]</a>',
                    'mailerpress'
                )
            ]));


            if ($contactEntity && $signupConfirmationOption) {
                $decoded = is_string($signupConfirmationOption) ? json_decode($signupConfirmationOption, true) : $signupConfirmationOption;
                $content = $decoded['emailContent'] ?? '';
                $subject = $decoded['emailSubject'] ?? '';
                $contact = [
                    'email' => $contactEntity->email,
                    'first_name' => $contactEntity->first_name,
                    'last_name' => $contactEntity->last_name,
                    'activation_link' => wp_unslash(
                        home_url(
                            \sprintf(
                                '?mailpress-pages=mailerpress&action=confirm&cid=%s&data=%s',
                                esc_attr($contactEntity->access_token),
                                esc_attr($contactEntity->unsubscribe_token),
                            )
                        )
                    ),
                ];

                $site = [
                    'title' => get_bloginfo('name'),
                    'home_url' => home_url('/'),
                ];

                $body = $this->replaceDynamicVariables($content, $contact, $site);
                $subject = $this->replaceDynamicVariables($subject, $contact, $site);

                $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
                $config = $mailer->getConfig();

                if (
                    empty($config['conf']['default_email'])
                    || empty($config['conf']['default_name'])
                ) {
                    $globalSender = get_option('mailerpress_global_email_senders');
                    if (is_string($globalSender)) {
                        $globalSender = json_decode($globalSender, true);
                    }
                    $config['conf']['default_email'] = $globalSender['fromAddress'];
                    $config['conf']['default_name'] = $globalSender['fromName'];
                }

                // Récupérer les paramètres Reply to depuis les paramètres par défaut
                $defaultSettings = get_option('mailerpress_default_settings', []);
                if (is_string($defaultSettings)) {
                    $defaultSettings = json_decode($defaultSettings, true) ?: [];
                }

                // Déterminer les valeurs Reply to (utiliser From si Reply to est vide)
                $replyToName = !empty($defaultSettings['replyToName'])
                    ? $defaultSettings['replyToName']
                    : ($config['conf']['default_name'] ?? '');
                $replyToAddress = !empty($defaultSettings['replyToAddress'])
                    ? $defaultSettings['replyToAddress']
                    : ($config['conf']['default_email'] ?? '');

                try {
                    $mailer->sendEmail([
                        'to' => $contactEntity->email,
                        'html' => true,
                        'body' => $body,
                        'subject' => $subject,
                        'sender_name' => $config['conf']['default_name'],
                        'sender_to' => $config['conf']['default_email'],
                        'reply_to_name' => $replyToName,
                        'reply_to_address' => $replyToAddress,
                        'apiKey' => $config['conf']['api_key'] ?? '',
                    ]);

                } catch (\Exception $e) {
                    error_log(sprintf(
                        '[MailerPress] ERROR sending double opt-in email to %s: %s',
                        $contactEntity->email,
                        $e->getMessage()
                    ));
                }

                // Schedule reminder email if enabled
                $signupConfirmationOption = get_option('mailerpress_signup_confirmation', wp_json_encode([
                    'enableReminders' => false,
                    'reminderIntervalDays' => 7,
                ]));

                if (is_string($signupConfirmationOption)) {
                    $signupConfirmationOption = json_decode($signupConfirmationOption, true);
                }

                if (!empty($signupConfirmationOption['enableReminders'])) {
                    $reminderIntervalDays = (int) ($signupConfirmationOption['reminderIntervalDays'] ?? 7);
                    $reminderTimestamp = time() + ($reminderIntervalDays * DAY_IN_SECONDS);

                    // Schedule single action for this specific contact
                    if (\function_exists('as_schedule_single_action')) {
                        as_schedule_single_action(
                            $reminderTimestamp,
                            'mailerpress_send_confirmation_reminder',
                            [$contactId],
                            'mailerpress'
                        );
                    }
                }
            }
        }
    }

    private function replaceDynamicVariables(string $content, array $contact, array $site): string
    {
        $placeholders = [
            '[contact:email]' => $contact['first_name'] ?? '',
            '[contact:firstName]' => $contact['first_name'] ?? '',
            '[contact:lastName]' => $contact['last_name'] ?? '',
            '[site:title]' => $site['title'] ?? '',
            '[activation_link]' => '<a href="' . ($contact['activation_link'] ?? '#') . '">',
            '[/activation_link]' => '</a>',
            '[site:homeURL]' => $site['home_url'] ?? '',
        ];

        $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);

        return nl2br($content);
    }
}
