<?php

declare(strict_types=1);

namespace MailerPress\Core;

use MailerPress\Models\Contacts;

\defined('ABSPATH') || exit;

final class MergeTags
{
    private array $groups = [];

    /**
     * Register a namespaced group. Callbacks return raw text or a URL.
     *
     * @param array $tags Map of keys to labels or arrays with label and type (text/url).
     * @param callable $callback Receives the tag key, recipient contact object, and rendering context.
     */
    public function registerGroup(string $group, string $label, array $tags, callable $callback): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $group) || trim($label) === '' || !$tags || isset($this->groups[$group])) {
            return false;
        }

        $definitions = [];
        foreach ($tags as $key => $definition) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]*$/D', $key)) {
                return false;
            }
            $definition = is_string($definition) ? ['label' => $definition] : $definition;
            if (!is_array($definition) || !is_string($definition['label'] ?? null) || trim($definition['label']) === '') {
                return false;
            }
            $type = $definition['type'] ?? 'text';
            if (!in_array($type, ['text', 'url'], true)) {
                return false;
            }
            $definitions[$key] = ['label' => $definition['label'], 'type' => $type];
        }

        $this->groups[$group] = ['label' => $label, 'tags' => $definitions, 'callback' => $callback];
        return true;
    }

    /** Return editor metadata only; never execute callbacks or expose contact values. */
    public function getEditorGroups(): array
    {
        $groups = [];
        foreach ($this->groups as $group => $definition) {
            $items = [];
            foreach ($definition['tags'] as $key => $tag) {
                $items[] = [
                    'label' => $tag['label'],
                    'value' => $group . '.' . $key,
                    'type' => $tag['type'],
                ];
            }
            $groups[] = ['label' => $definition['label'], 'type' => 'registered_' . $group, 'data' => $items];
        }
        return $groups;
    }

    /**
     * Resolve only referenced tags, once per field and recipient. Existing variables win.
     */
    public function getVariables(string $content, array $variables, array $context = []): array
    {
        if (!$this->groups) {
            return $variables;
        }

        preg_match_all('/\{\{\s*([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)(?=\s|\}\})|%([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)%/', $content, $matches, PREG_SET_ORDER);
        $contactLoaded = false;
        $context += [
            'contact_id' => (int) ($variables['CONTACT_ID'] ?? 0),
            'campaign_id' => (int) ($variables['CAMPAIGN_ID'] ?? 0),
            'email' => html_entity_decode((string) ($variables['contact_email'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'variables' => $variables,
        ];
        $contact = (object) [
            'contact_id' => null,
            'email' => (string) $context['email'],
            'first_name' => html_entity_decode((string) ($variables['contact_first_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'last_name' => html_entity_decode((string) ($variables['contact_last_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];

        foreach ($matches as $match) {
            $name = $match[1] !== '' ? $match[1] : $match[2];
            if (array_key_exists($name, $variables)) {
                continue;
            }
            [$group, $key] = explode('.', $name, 2);
            $definition = $this->groups[$group] ?? null;
            if (!isset($definition['tags'][$key])) {
                continue;
            }

            $variables[$name] = '';
            try {
                if (!$contactLoaded) {
                    $contactLoaded = true;
                    if ((int) $context['contact_id'] > 0) {
                        $contact = Kernel::getContainer()->get(Contacts::class)->get((int) $context['contact_id']) ?? $contact;
                    }
                }
                $value = ($definition['callback'])($key, $contact, $context);
                if (is_scalar($value)) {
                    $variables[$name] = $definition['tags'][$key]['type'] === 'url'
                        ? esc_url((string) $value, ['http', 'https'])
                        : esc_html((string) $value);
                }
            } catch (\Throwable $exception) {
                // A failed integration must not interrupt the remaining recipients.
                do_action('mailerpress_merge_tag_error', $name, $exception);
            }
        }

        return $variables;
    }
}
