<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\MailerPress\Triggers;

\defined('ABSPATH') || exit;

/**
 * Webhook Received Trigger
 * 
 * Fires when an incoming webhook is received via the REST API endpoint.
 * This trigger allows external services to trigger workflows by sending
 * HTTP POST requests to the webhook endpoint.
 * 
 * The trigger listens to the 'mailerpress_webhook_received' hook
 * which is fired when a webhook is received at:
 * /wp-json/mailerpress/v1/webhooks/receive/{webhook_id}
 * 
 * Data available in the workflow context:
 * - webhook_id: The unique identifier of the webhook
 * - payload: The complete payload data sent in the webhook
 * - email: Email address from payload (if provided)
 * - user_id: User ID from payload (if provided)
 * - contact_id: Contact ID from payload (if provided)
 * - All other fields from the payload are available in the context
 * 
 * @since 1.2.0
 */
class WebhookReceivedTrigger
{
    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'webhook_received';

    /**
     * WordPress hook name to listen to
     */
    public const HOOK_NAME = 'mailerpress_webhook_received';

    /**
     * Register the custom trigger
     * 
     * @param mixed $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        $definition = [
            'label' => __('Webhook Received', 'mailerpress'),
            'description' => __('Triggered when an incoming webhook is received from an external service. Perfect for integrating with third-party services, forms, or custom applications. Configure the webhook ID below.', 'mailerpress'),
            'icon' => 'admin-links',
            'category' => 'integration',
            'settings_schema' => [
                [
                    'key' => 'webhook_id',
                    'label' => __('Webhook ID', 'mailerpress'),
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => __('e.g., my-custom-webhook', 'mailerpress'),
                    'help' => __('Unique identifier for this webhook. This will be used in the webhook URL. Only letters, numbers, hyphens, and underscores are allowed.', 'mailerpress'),
                ],
                [
                    'key' => 'webhook_url',
                    'label' => __('Webhook URL', 'mailerpress'),
                    'type' => 'textarea',
                    'required' => false,
                    'readonly' => true,
                    'rows' => 2,
                    'help' => __('Copy this URL and use it in your external service to send webhooks. The URL is automatically generated based on your Webhook ID.', 'mailerpress'),
                ],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            self::HOOK_NAME,
            self::contextBuilder(...),
            $definition
        );
    }

    /**
     * Build context from hook parameters
     * 
     * The hook 'mailerpress_webhook_received' is fired with:
     * - $webhookId: string - The webhook ID
     * - $payload: array - The webhook payload data
     * - $request: WP_REST_Request - The REST API request object
     * 
     * @param mixed ...$args Hook arguments passed by WordPress
     * @return array Context data for the workflow
     */
    public static function contextBuilder(...$args): array
    {
        $webhookId = $args[0] ?? '';
        $payload = $args[1] ?? [];
        $request = $args[2] ?? null;

        if (empty($webhookId) || !is_array($payload)) {
            return [];
        }

        // Extract common fields from payload
        $email = $payload['email'] ?? $payload['customer_email'] ?? $payload['user_email'] ?? '';
        $userId = $payload['user_id'] ?? $payload['customer_id'] ?? null;
        $contactId = $payload['contact_id'] ?? null;

        // Try to find or create contact if email is provided
        if (!empty($email) && empty($contactId)) {
            $contactsModel = new \MailerPress\Models\Contacts();
            $contact = $contactsModel->getContactByEmail($email);

            if ($contact) {
                $contactId = (int) $contact->contact_id;
                $userId = $userId ?: $contactId;
            }
        }

        // Build context with all payload data plus metadata
        $context = array_merge($payload, [
            'webhook_id' => $webhookId,
            'webhook_payload' => $payload,
            'email' => $email,
            'user_id' => $userId ?: 0,
            'contact_id' => $contactId,
        ]);

        // Add request metadata if available
        if ($request && method_exists($request, 'get_headers')) {
            $headers = $request->get_headers();
            $context['webhook_headers'] = $headers;
            $context['webhook_method'] = $request->get_method();
        }

        return $context;
    }
}
