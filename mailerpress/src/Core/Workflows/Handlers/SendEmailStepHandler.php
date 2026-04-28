<?php

namespace MailerPress\Core\Workflows\Handlers;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Workflows\Services\ContactResolver;
use MailerPress\Core\Workflows\Services\MergeTagBuilder;
use MailerPress\Core\Workflows\Services\EmailContentRenderer;

class SendEmailStepHandler implements StepHandlerInterface
{
    private ContactResolver $contactResolver;
    private MergeTagBuilder $mergeTagBuilder;
    private EmailContentRenderer $emailContentRenderer;

    public function __construct()
    {
        $this->contactResolver = new ContactResolver();
        $this->mergeTagBuilder = new MergeTagBuilder();
        $this->emailContentRenderer = new EmailContentRenderer();
    }

    public function supports(string $key): bool
    {
        return $key === 'send_email' || $key === 'send_mail';
    }

    public function getDefinition(): array
    {
        return [
            'key' => 'send_email',
            'label' => __('Send Email', 'mailerpress'),
            'description' => __('Send a personalized email using a campaign template. You can specify a custom recipient email address or leave it empty to use the contact/user email. You can customize the subject line and use all available workflow variables for dynamic content.', 'mailerpress'),
            'icon' => '<svg viewBox="-4 -4 24 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M2.73578 1.5L8 6.01219L13.2642 1.5H2.73578ZM14.5 2.41638L8.48809 7.56944L8 7.98781L7.51191 7.56944L1.5 2.41638V10C1.5 10.2761 1.72386 10.5 2 10.5H14C14.2761 10.5 14.5 10.2761 14.5 10V2.41638ZM0 2C0 0.89543 0.89543 0 2 0H14C15.1046 0 16 0.895431 16 2V10C16 11.1046 15.1046 12 14 12H2C0.895431 12 0 11.1046 0 10V2Z"></path></svg>',
            'category' => 'communication',
            'type' => 'ACTION',
            'settings_schema' => [
                [
                    'key' => 'template_id',
                    'label' => 'Email Template',
                    'type' => 'select_dynamic',
                    'data_source' => 'campaigns',
                    'hidden' => true,
                    'required' => true,
                    'help' => __('Select an email template to send', 'mailerpress'),
                ],
                [
                    'key' => 'name',
                    'label' => 'Email Name *',
                    'type' => 'text',
                    'required' => true,
                    'help' => __('Give this email a name to identify it in conditions (e.g., "Welcome Email", "Order Confirmation")', 'mailerpress'),
                ],
                [
                    'key' => 'recipient_email',
                    'label' => __('Recipient Email', 'mailerpress'),
                    'type' => 'text',
                    'required' => false,
                    'placeholder' => __('Leave empty to use contact/user email, or enter custom email', 'mailerpress'),
                    'help' => __('Email address to send to. Leave empty to use the contact/user email from the workflow. You can also use placeholders like {{customer_email}}.', 'mailerpress'),
                ],
                [
                    'key' => 'subject',
                    'label' => 'Subject *',
                    'type' => 'text',
                    'required' => true,
                    'help' => __('Override template subject (leave empty to use template default)', 'mailerpress'),
                ],
            ],
        ];
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        global $wpdb;

        $settings = $step->getSettings();
        $templateId = $settings['template_id'] ?? null;

        if (!$templateId) {
            return StepResult::failed('Template ID is required');
        }

        $templateId = (int) $templateId;

        // Resolve custom recipient email
        $customRecipientEmail = $this->resolveCustomRecipientEmail($settings, $context);

        // Resolve contact
        $contactResult = $this->contactResolver->resolve($job->getUserId(), $customRecipientEmail, $context);

        if (!$contactResult) {
            return StepResult::failed('No user ID found and no recipient email specified');
        }

        // Validate email
        $email = \sanitize_email($contactResult->email);
        if (empty($email) || !\is_email($email)) {
            return StepResult::failed(\sprintf(\__('Invalid recipient email address: %s', 'mailerpress'), $contactResult->email));
        }
        $contactResult->email = $email;

        // Load campaign template
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT campaign_id, subject, content_html, config, campaign_type, status FROM {$campaignsTable} WHERE campaign_id = %d",
                $templateId
            )
        );

        if (!$campaign) {
            return StepResult::failed('Campaign template not found');
        }

        // Load HTML content
        $htmlContent = $this->loadHtmlContent($templateId, $campaign);
        if ($htmlContent instanceof StepResult) {
            return $htmlContent;
        }

        $subject = !empty($settings['subject']) ? $settings['subject'] : ($campaign->subject ?? \__('Notification', 'mailerpress'));

        // Verify active cart for abandoned cart emails
        if (!empty($context['cart_hash']) && !empty($context['user_id'])) {
            $cartRepo = new \MailerPress\Core\Workflows\Repositories\CartTrackingRepository();
            if (!$cartRepo->hasActiveCart($context['user_id'])) {
                $job->setStatus('CANCELLED');
                $jobRepo = new \MailerPress\Core\Workflows\Repositories\AutomationJobRepository();
                $jobRepo->update($job);
                return StepResult::failed('Cart is not active - abandoned cart email cancelled');
            }
        }

        // Build merge tag variables
        $variables = $this->mergeTagBuilder->build($contactResult, $step, $job, $templateId, $context);

        // Add job_id and automation_id to context for coupon generation
        $contextWithJobInfo = array_merge($context, [
            'job_id' => $job->getId(),
            'automation_id' => $job->getAutomationId(),
            'email' => $contactResult->email,
            'user_id' => $job->getUserId(),
        ]);

        // Render final HTML
        $parsedHtml = $this->emailContentRenderer->render($htmlContent, $variables, $contextWithJobInfo);

        // Send email
        return $this->sendEmail($parsedHtml, $subject, $contactResult, $step, $job, $templateId);
    }

    private function resolveCustomRecipientEmail(array $settings, array $context): ?string
    {
        $recipientEmailSetting = $settings['recipient_email'] ?? '';
        if (empty($recipientEmailSetting)) {
            return null;
        }

        $customRecipientEmail = $this->replacePlaceholders($recipientEmailSetting, $context);
        if (empty($customRecipientEmail)) {
            return null;
        }

        $customRecipientEmail = \sanitize_email($customRecipientEmail);
        if (!\is_email($customRecipientEmail)) {
            return null;
        }

        return $customRecipientEmail;
    }

    /**
     * @return string|StepResult HTML string on success, StepResult on failure
     */
    private function loadHtmlContent(int $templateId, object $campaign)
    {
        $htmlContent = \get_option('mailerpress_batch_' . $templateId . '_html');

        // Guard: if the option contains a JSON block tree instead of compiled HTML, ignore it.
        if (!empty($htmlContent) && \is_string($htmlContent) && str_starts_with(ltrim($htmlContent), '{')) {
            $htmlContent = null;
        }

        if (!empty($htmlContent)) {
            return $htmlContent;
        }

        $campaignType = $campaign->campaign_type ?? 'newsletter';
        $campaignStatus = $campaign->status ?? 'draft';
        $isAutomationDraft = ($campaignType === 'automation' && $campaignStatus === 'draft');

        // Guard: content_html from DB is always a JSON-encoded block tree — never compiled HTML.
        // Never use it directly as email body.

        return StepResult::failed(
            $isAutomationDraft
                ? 'Campaign HTML not compiled yet. Please open the campaign in the editor and save it to generate the HTML.'
                : 'Campaign HTML content is empty. Please save the campaign first.'
        );
    }

    private function sendEmail(string $parsedHtml, string $subject, \MailerPress\Core\Workflows\Services\ContactResult $contactResult, Step $step, AutomationJob $job, int $templateId): StepResult
    {
        $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
        $config = $mailer->getConfig();

        if (
            empty($config['conf']['default_email'])
            || empty($config['conf']['default_name'])
        ) {
            $globalSender = \get_option('mailerpress_default_settings');

            if ($globalSender) {
                if (is_string($globalSender)) {
                    $globalSender = json_decode($globalSender, true);
                }

                if (is_array($globalSender)) {
                    $config['conf']['default_email'] = $globalSender['fromAddress'] ?? '';
                    $config['conf']['default_name'] = $globalSender['fromName'] ?? '';
                }
            }
        }

        try {
            $sent = $mailer->sendEmail([
                'to' => $contactResult->email,
                'html' => true,
                'body' => $parsedHtml,
                'subject' => $subject,
                'sender_name' => $config['conf']['default_name'] ?? '',
                'sender_to' => $config['conf']['default_email'] ?? '',
                'apiKey' => $config['conf']['api_key'] ?? '',
            ]);

            if (!$sent) {
                return StepResult::failed('Failed to send email via MailerPress service');
            }

            $emailSentAt = current_time('mysql');
            $finalContactId = $contactResult->isContact ? $contactResult->contactId : $job->getUserId();

            return StepResult::success($step->getNextStepId(), [
                'email_sent' => true,
                'recipient' => $contactResult->email,
                'campaign_id' => $templateId,
                'contact_id' => $finalContactId,
                'is_contact' => $contactResult->isContact,
                'email_sent_at' => $emailSentAt,
                'job_id' => $job->getId(),
                'step_id' => $step->getStepId(),
            ]);
        } catch (\Exception $e) {
            return StepResult::failed('Error sending email: ' . $e->getMessage());
        }
    }

    private function replacePlaceholders(string $template, array $context): string
    {
        if (empty($template)) {
            return '';
        }

        $aliases = [
            'email' => ['email', 'user_email', 'customer_email', 'billing_email'],
        ];

        return preg_replace_callback('/\{\{(\w+(?:\.\w+)*)\}\}/', function ($matches) use ($context, $aliases) {
            $key = $matches[1];

            if (str_contains($key, '.')) {
                $value = $this->getNestedValue($context, $key);
                if ($value !== null) {
                    return is_scalar($value) ? (string) $value : '';
                }
            }

            if (isset($context[$key]) && is_scalar($context[$key])) {
                return (string) $context[$key];
            }

            foreach ($aliases as $fieldName => $fieldAliases) {
                if ($key === $fieldName || in_array($key, $fieldAliases, true)) {
                    foreach ($fieldAliases as $alias) {
                        if (isset($context[$alias]) && is_scalar($context[$alias])) {
                            return (string) $context[$alias];
                        }
                    }
                }
            }

            return '';
        }, $template);
    }

    private function getNestedValue(array $context, string $key): mixed
    {
        $parts = explode('.', $key);
        $value = $context;

        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return null;
            }
        }

        return $value;
    }
}
