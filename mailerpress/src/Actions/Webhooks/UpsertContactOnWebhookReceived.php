<?php

declare(strict_types=1);

namespace MailerPress\Actions\Webhooks;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Services\ContactUpsertService;
use MailerPress\Services\WebhookActionResolver;
use MailerPress\Models\Contacts as ContactsModel;

class UpsertContactOnWebhookReceived
{
    #[Action('mailerpress_webhook_received', priority: 5, acceptedArgs: 3)]
    public function handle($webhookId, $payload, $request = null): void
    {
        if (!is_array($payload)) {
            if (is_object($payload) && method_exists($payload, 'get_json_params')) {
                $payload = $payload->get_json_params();
            } elseif (is_object($request) && method_exists($request, 'get_json_params')) {
                $payload = $request->get_json_params();
            } else {
                $payload = [];
            }
        }

        $email = $payload['email'] ?? $payload['customer_email'] ?? $payload['user_email'] ?? '';
        if (empty($email)) {
            return;
        }

        $webhookId = (string) $webhookId;
        $actions = (new WebhookActionResolver())->getEnabledActions($webhookId);
        if (empty($actions)) {
            return;
        }

        $existingContact = (new ContactsModel())->getContactByEmail(sanitize_email((string) $email));
        if ($existingContact && empty($actions['update_contact']) && empty($actions['add_tag']) && empty($actions['add_to_list'])) {
            return;
        }

        if (!$existingContact && empty($actions['create_contact'])) {
            return;
        }

        $upsertPayload = $payload;
        if ($existingContact && empty($actions['update_contact'])) {
            $upsertPayload = ['email' => $email];

            if (!empty($actions['add_tag'])) {
                foreach (['tags', 'contact_tags', 'tag_ids', 'tag_id', 'tag', 'tag_name'] as $key) {
                    if (array_key_exists($key, $payload)) {
                        $upsertPayload[$key] = $payload[$key];
                    }
                }
            }

            if (!empty($actions['add_to_list'])) {
                foreach (['lists', 'contact_lists', 'list_ids', 'list_id', 'list', 'list_name'] as $key) {
                    if (array_key_exists($key, $payload)) {
                        $upsertPayload[$key] = $payload[$key];
                    }
                }
            }
        }

        if (empty($actions['add_tag']) && empty($actions['update_contact'])) {
            unset($upsertPayload['tags'], $upsertPayload['contact_tags'], $upsertPayload['tag_ids'], $upsertPayload['tag_id'], $upsertPayload['tag'], $upsertPayload['tag_name']);
        }

        if (empty($actions['add_to_list']) && empty($actions['update_contact']) && $existingContact) {
            unset($upsertPayload['lists'], $upsertPayload['contact_lists'], $upsertPayload['list_ids'], $upsertPayload['list_id'], $upsertPayload['list'], $upsertPayload['list_name']);
        }

        $result = (new ContactUpsertService())->upsert(array_merge($upsertPayload, [
            'email' => $email,
            'update_existing' => true,
            'update_contact_fields' => !empty($actions['update_contact']) || !$existingContact,
            'assign_default_list' => !$existingContact,
            'auto_map_custom_fields' => true,
            'opt_in_source' => 'webhook',
            'optin_details' => [
                'webhook_id' => $webhookId,
            ],
        ]));

        if (!empty($result['success'])) {
            do_action('mailerpress_webhook_contact_upserted', $webhookId, $result, $payload, $request);
        }
    }
}
