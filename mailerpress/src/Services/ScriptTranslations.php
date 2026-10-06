<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

final class ScriptTranslations
{
    private static array $loaded = [];

    /**
     * Webpack loads async chunks outside WordPress's script registry. Load their
     * catalogs with wp-i18n so translations also exist during module evaluation.
     */
    public static function enqueue(string $handle, string $domain, string $path): void
    {
        $locale = determine_locale();
        $key = $domain . ':' . $locale;
        if (isset(self::$loaded[$key])) {
            return;
        }

        $messages = [];
        // Installed language packs take precedence over bundled translations.
        foreach ([$path, WP_LANG_DIR . '/plugins'] as $directory) {
            foreach (glob($directory . '/' . $domain . '-' . $locale . '-*.json') ?: [] as $file) {
                $json = load_script_translations($file, $handle, $domain);
                if (!is_string($json)) {
                    continue;
                }
                $catalog = json_decode($json, true);
                $data = $catalog['locale_data'][$domain] ?? $catalog['locale_data']['messages'] ?? null;
                if (!is_array($data) || !isset($data['']) || !is_array($data[''])) {
                    continue;
                }
                foreach ($data as $id => $translation) {
                    // Older packs may only contain a singular translation.
                    $messages[$id] = isset($messages[$id]) && is_array($messages[$id]) && is_array($translation)
                        ? array_replace($messages[$id], $translation)
                        : $translation;
                }
            }
        }

        if ([] === $messages) {
            wp_set_script_translations($handle, $domain, $path);
            return;
        }

        $messages['']['domain'] = $domain;
        wp_enqueue_script('wp-i18n');
        $added = wp_add_inline_script(
            $handle,
            'wp.i18n.setLocaleData(' . wp_json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP) . ', '
                . wp_json_encode($domain) . ');',
            'before'
        );
        if ($added) {
            self::$loaded[$key] = true;
        }
    }
}
