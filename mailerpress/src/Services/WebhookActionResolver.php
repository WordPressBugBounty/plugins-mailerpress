<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

class WebhookActionResolver
{
    /**
     * Return enabled webhook actions normalized to:
     * create_contact, update_contact, add_tag, add_to_list.
     *
     * @return array<string, bool>
     */
    public function getEnabledActions(string $webhookId): array
    {
        $config = $this->getWebhookConfig($webhookId);
        if (empty($config)) {
            return [];
        }

        $actions = [];
        foreach (['actions', 'enabled_actions', 'enabledActions', 'selected_actions', 'selectedActions', 'actions_to_execute', 'actionsToExecute'] as $key) {
            if (isset($config[$key])) {
                $actions = array_merge($actions, $this->normalizeActions($config[$key]));
            }
        }

        foreach (['create_contact', 'createContact', 'update_contact', 'updateContact', 'add_tag', 'addTag', 'add_to_list', 'addToList'] as $key) {
            if (array_key_exists($key, $config) && filter_var($config[$key], FILTER_VALIDATE_BOOLEAN)) {
                $action = $this->normalizeActionKey($key);
                if ($action) {
                    $actions[$action] = true;
                }
            }
        }

        return $actions;
    }

    public function isEnabled(string $webhookId, string $action): bool
    {
        $actions = $this->getEnabledActions($webhookId);
        return !empty($actions[$action]);
    }

    private function getWebhookConfig(string $webhookId): array
    {
        $configs = get_option('mailerpress_webhook_configs', []);
        if (is_string($configs)) {
            $decoded = json_decode($configs, true);
            $configs = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($configs)) {
            return [];
        }

        if (isset($configs[$webhookId]) && is_array($configs[$webhookId])) {
            return $configs[$webhookId];
        }

        foreach ($configs as $config) {
            if (!is_array($config)) {
                continue;
            }

            $id = (string) ($config['id'] ?? $config['webhook_id'] ?? '');
            if ($id === $webhookId) {
                return $config;
            }
        }

        return [];
    }

    private function normalizeActions(mixed $actions): array
    {
        if (is_string($actions)) {
            $decoded = json_decode($actions, true);
            $actions = is_array($decoded) ? $decoded : array_map('trim', explode(',', $actions));
        }

        if (!is_array($actions)) {
            return [];
        }

        $normalized = [];
        foreach ($actions as $key => $value) {
            if (is_string($key)) {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    $action = $this->normalizeActionKey($key);
                    if ($action) {
                        $normalized[$action] = true;
                    }
                }
                continue;
            }

            if (is_string($value)) {
                $action = $this->normalizeActionKey($value);
                if ($action) {
                    $normalized[$action] = true;
                }
                continue;
            }

            if (is_array($value)) {
                $enabled = !array_key_exists('enabled', $value) || filter_var($value['enabled'], FILTER_VALIDATE_BOOLEAN);
                $action = $this->normalizeActionKey((string) ($value['id'] ?? $value['key'] ?? $value['action'] ?? ''));
                if ($enabled && $action) {
                    $normalized[$action] = true;
                }
            }
        }

        return $normalized;
    }

    private function normalizeActionKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = str_replace(['-', ' '], '_', $key);

        return match ($key) {
            'create_contact', 'createcontact', 'create', 'contact_create' => 'create_contact',
            'update_contact', 'updatecontact', 'update', 'contact_update' => 'update_contact',
            'add_tag', 'addtag', 'tag', 'add_to_tag' => 'add_tag',
            'add_to_list', 'addtolist', 'add_list', 'addlist', 'list', 'add_contact_to_list' => 'add_to_list',
            default => '',
        };
    }
}
