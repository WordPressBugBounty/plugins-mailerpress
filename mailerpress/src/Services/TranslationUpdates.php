<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

final class TranslationUpdates
{
    public static function useLatestAvailable($updates)
    {
        if (!is_object($updates) || !isset($updates->checked['mailerpress/mailerpress.php'])) {
            return $updates;
        }

        $plugin = $updates->response['mailerpress/mailerpress.php']
            ?? $updates->no_update['mailerpress/mailerpress.php']
            ?? null;
        $publicVersion = is_object($plugin) ? ($plugin->new_version ?? null) : null;
        if (!is_string($publicVersion) || $publicVersion === $updates->checked['mailerpress/mailerpress.php']) {
            return $updates;
        }

        $locale = get_locale();
        $cacheKey = 'mailerpress_translations_' . md5($publicVersion . ':' . $locale);
        $available = get_site_transient($cacheKey);
        if (false === $available) {
            $args = [$publicVersion, $locale];
            if (!wp_next_scheduled('mailerpress_refresh_translation_updates', $args)) {
                wp_schedule_single_event(time(), 'mailerpress_refresh_translation_updates', $args);
            }
            return $updates;
        }
        if (!is_array($available)) {
            return $updates;
        }

        foreach ($available as $translation) {
            if (($translation['language'] ?? null) !== $locale || empty($translation['package']) || empty($translation['updated'])) {
                continue;
            }

            $installed = wp_get_installed_translations('plugins')['mailerpress'][$locale]['PO-Revision-Date'] ?? '';
            $latestDate = strtotime($translation['updated']);
            $installedDate = strtotime($installed);
            $updates->translations = array_values(array_filter(
                $updates->translations ?? [],
                static fn (array $item): bool => ($item['slug'] ?? null) !== 'mailerpress'
                    || ($item['language'] ?? null) !== $locale
            ));
            if (false !== $latestDate && (false === $installedDate || $latestDate > $installedDate)) {
                $translation['type'] = 'plugin';
                $translation['slug'] = 'mailerpress';
                $updates->translations[] = $translation;
            }
            break;
        }

        return $updates;
    }

    public static function refresh(string $publicVersion, string $locale): void
    {
        if ($locale !== get_locale()) {
            return;
        }

        if (!function_exists('translations_api')) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }
        $result = translations_api('plugins', ['slug' => 'mailerpress', 'version' => $publicVersion]);
        if (is_wp_error($result) || !isset($result['translations']) || !is_array($result['translations'])) {
            return;
        }

        $cacheKey = 'mailerpress_translations_' . md5($publicVersion . ':' . $locale);
        set_site_transient($cacheKey, $result['translations'], 12 * HOUR_IN_SECONDS);

        $updates = get_site_transient('update_plugins');
        if (is_object($updates)) {
            set_site_transient('update_plugins', $updates);
        }
    }
}
