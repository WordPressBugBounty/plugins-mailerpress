<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

class DeactivatePro
{
    /**
     * Flag pour éviter les appels multiples
     *
     * @var bool
     */
    private static bool $deactivationInProgress = false;

    /**
     * Désactive automatiquement le plugin Pro lors de la désactivation du plugin principal.
     *
     * @return void
     */
    public function deactivateProPlugin(): void
    {
        // Protection contre les appels multiples
        if (self::$deactivationInProgress) {
            return;
        }

        self::$deactivationInProgress = true;

        $proPluginPath = 'mailerpress-pro/mailerpress-pro.php';

        // Vérifier si le plugin Pro existe
        if (!file_exists(\WP_PLUGIN_DIR . '/' . $proPluginPath)) {
            self::$deactivationInProgress = false;
            return;
        }

        // Inclure le fichier nécessaire pour les fonctions WordPress
        if (!function_exists('is_plugin_active')) {
            require_once \ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Vérifier si le plugin Pro est actif
        if (!\is_plugin_active($proPluginPath)) {
            self::$deactivationInProgress = false;
            return;
        }

        // Méthode 1: Utiliser deactivate_plugins() si disponible
        if (function_exists('deactivate_plugins')) {
            \deactivate_plugins($proPluginPath, true);
        }

        // Méthode 2: Mettre à jour directement via update_option() et flush
        $activePlugins = \get_option('active_plugins', []);

        if (is_array($activePlugins)) {
            $key = array_search($proPluginPath, $activePlugins, true);

            if ($key !== false) {
                unset($activePlugins[$key]);
                $activePlugins = array_values($activePlugins);

                // Mettre à jour l'option
                \update_option('active_plugins', $activePlugins);

                // Forcer le flush du cache
                \wp_cache_flush();
            }
        }

        // Méthode 3: Mettre à jour directement dans la base de données pour garantir
        global $wpdb;

        $optionValue = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                'active_plugins'
            )
        );

        if ($optionValue) {
            $activePlugins = \maybe_unserialize($optionValue);

            if (is_array($activePlugins)) {
                $key = array_search($proPluginPath, $activePlugins, true);

                if ($key !== false) {
                    unset($activePlugins[$key]);
                    $activePlugins = array_values($activePlugins);
                    $serialized = \maybe_serialize($activePlugins);

                    // Mettre à jour directement dans la base de données avec REPLACE pour forcer
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                            $serialized,
                            'active_plugins'
                        )
                    );

                    // Nettoyer tous les caches
                    \wp_cache_flush();
                }
            }
        }

        // Pour les multisites
        if (\is_multisite()) {
            $networkPlugins = \get_site_option('active_sitewide_plugins', []);

            if (is_array($networkPlugins) && isset($networkPlugins[$proPluginPath])) {
                unset($networkPlugins[$proPluginPath]);
                \update_site_option('active_sitewide_plugins', $networkPlugins);
            }

            // Mettre à jour aussi directement dans la base de données
            $networkOptionValue = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s",
                    \get_current_network_id(),
                    'active_sitewide_plugins'
                )
            );

            if ($networkOptionValue) {
                $networkPlugins = \maybe_unserialize($networkOptionValue);

                if (is_array($networkPlugins) && isset($networkPlugins[$proPluginPath])) {
                    unset($networkPlugins[$proPluginPath]);
                    $serialized = \maybe_serialize($networkPlugins);

                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$wpdb->sitemeta} SET meta_value = %s WHERE site_id = %d AND meta_key = %s",
                            $serialized,
                            \get_current_network_id(),
                            'active_sitewide_plugins'
                        )
                    );

                    \wp_cache_flush();
                }
            }
        }

        self::$deactivationInProgress = false;
    }
}
