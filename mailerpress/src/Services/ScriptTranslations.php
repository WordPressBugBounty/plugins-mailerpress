<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

final class ScriptTranslations
{
    /** Uploads subdirectory holding the merged catalogs, served as static files. */
    private const DIRECTORY = 'mailerpress-i18n';

    /** Catalogs replaced since this long are deleted: pages rendered just before keep loading. */
    private const STALE_AFTER = DAY_IN_SECONDS;

    /** Catalog script handle per domain and locale, or '' when printed inline. */
    private static array $loaded = [];

    /**
     * Webpack loads async chunks outside WordPress's script registry. Load their
     * catalogs with wp-i18n so translations also exist during module evaluation.
     *
     * The merged catalog is written once to a file named after its content, so
     * browsers cache it; it falls back to an inline script when uploads are not writable.
     */
    public static function enqueue(string $handle, string $domain, string $path): void
    {
        $locale = determine_locale();
        $key = $domain . ':' . $locale;
        if (isset(self::$loaded[$key])) {
            if ('' !== self::$loaded[$key]) {
                self::addDependency($handle, self::$loaded[$key]);
            }
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
        $script = 'wp.i18n.setLocaleData(' . wp_json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP) . ', '
            . wp_json_encode($domain) . ');';

        $url = self::catalogUrl($domain, $locale, $script);
        $catalogHandle = $domain . '-translations';
        if (null !== $url
            // No version: the file name already changes with its content.
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            && wp_register_script($catalogHandle, $url, ['wp-i18n'], null, ['in_footer' => true])
            && self::addDependency($handle, $catalogHandle)
        ) {
            self::$loaded[$key] = $catalogHandle;
            return;
        }

        wp_enqueue_script('wp-i18n');
        if (wp_add_inline_script($handle, $script, 'before')) {
            self::$loaded[$key] = '';
        }
    }

    /** Writes the catalog under uploads if needed and returns its URL, or null on failure. */
    private static function catalogUrl(string $domain, string $locale, string $script): ?string
    {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return null;
        }

        $directory = trailingslashit($uploads['basedir']) . self::DIRECTORY;
        $prefix = sanitize_file_name($domain . '-' . $locale);
        $name = $prefix . '-' . md5($script) . '.js';
        $file = $directory . '/' . $name;

        if (!is_file($file)) {
            if (!wp_mkdir_p($directory)) {
                return null;
            }

            // Write then rename, so a concurrent request never serves a partial file.
            $temporary = $file . '.' . wp_generate_password(8, false) . '.tmp';
            // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
            if (false === @file_put_contents($temporary, $script) || !@rename($temporary, $file)) {
                wp_delete_file($temporary);
                return null;
            }

            foreach (glob($directory . '/' . $prefix . '-*.js') ?: [] as $previous) {
                if ($previous !== $file && (int) filemtime($previous) < time() - self::STALE_AFTER) {
                    wp_delete_file($previous);
                }
            }
        }

        return set_url_scheme(trailingslashit($uploads['baseurl']) . self::DIRECTORY . '/' . $name);
    }

    /** Makes the entry script wait for the catalog. */
    private static function addDependency(string $handle, string $dependency): bool
    {
        $script = wp_scripts()->query($handle, 'registered');
        if (!$script) {
            return false;
        }
        if (!in_array($dependency, $script->deps, true)) {
            $script->deps[] = $dependency;
        }
        return true;
    }
}
