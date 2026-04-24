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

        // Get reminder settings
        $signupConfirmationOption = get_option('mailerpress_signup_confirmation', wp_json_encode([
            'enableReminders' => false,
            'reminderIntervalDays' => 7,
        ]));

        if (is_string($signupConfirmationOption)) {
            $signupConfirmationOption = json_decode($signupConfirmationOption, true);
        }

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
            $globalSender = get_option('mailerpress_global_email_senders');
            if (is_string($globalSender)) {
                $globalSender = json_decode($globalSender, true);
            }
            $config['conf']['default_email'] = $globalSender['fromAddress'] ?? '';
            $config['conf']['default_name'] = $globalSender['fromName'] ?? '';
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

        $emailContent = $signupConfirmationOption['emailContent'] ?? '';
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

        // Replace dynamic variables
        $placeholders = [
            '[contact:email]' => $contactData['email'],
            '[contact:firstName]' => $contactData['first_name'],
            '[contact:lastName]' => $contactData['last_name'],
            '[site:title]' => $site['title'],
            '[activation_link]' => '<a href="' . $contactData['activation_link'] . '">',
            '[/activation_link]' => '</a>',
            '[site:homeURL]' => $site['home_url'],
        ];

        $body = str_replace(array_keys($placeholders), array_values($placeholders), $emailContent);
        $body = nl2br($body);
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $emailSubject);

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
    }
}
