<?php

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Core\Kernel;

\defined('ABSPATH') || exit;

class DebugContainer
{
    #[Command('mailerpress debug:container')]
    public function debug(array $args, array $assoc_args): void
    {
        if (!\class_exists('\\WP_CLI')) {
            return;
        }

        \WP_CLI::log('🔍 Debug du container DI...');
        \WP_CLI::log('');

        try {
            $container = Kernel::getContainer();

            // Récupérer toutes les actions
            $actions = $container->get('actions');

            \WP_CLI::log('📦 Actions enregistrées dans le container:');
            \WP_CLI::log('Total: ' . count($actions));
            \WP_CLI::log('');

            // Filtrer pour CheckBounce
            $found = false;
            foreach ($actions as $action) {
                if (strpos($action, 'CheckBounce') !== false) {
                    \WP_CLI::success("✅ CheckBounce trouvé: {$action}");
                    $found = true;

                    // Vérifier si la classe existe
                    if (class_exists($action)) {
                        \WP_CLI::log('   ✓ Classe existe');

                        // Vérifier les hooks enregistrés
                        global $wp_filter;
                        if (isset($wp_filter['mailerpress_check_bounces'])) {
                            \WP_CLI::success('   ✓ Hook mailerpress_check_bounces enregistré');
                            \WP_CLI::log('   Callbacks: ' . count($wp_filter['mailerpress_check_bounces']->callbacks));
                        } else {
                            \WP_CLI::error('   ✗ Hook mailerpress_check_bounces NON enregistré');
                        }
                    } else {
                        \WP_CLI::error('   ✗ Classe n\'existe pas');
                    }
                }
            }

            if (!$found) {
                \WP_CLI::error('❌ CheckBounce NON trouvé dans le container');
            }

            \WP_CLI::log('');
            \WP_CLI::log('📋 Toutes les actions Processors trouvées:');
            $processorsFound = 0;
            foreach ($actions as $action) {
                if (strpos($action, 'Processors') !== false) {
                    \WP_CLI::log('   - ' . $action);
                    $processorsFound++;
                }
            }

            if ($processorsFound === 0) {
                \WP_CLI::warning('⚠️  Aucun Processor trouvé ! Le dossier Processors n\'est peut-être pas scanné.');
            }

            \WP_CLI::log('');

            // Vérifier les hooks WordPress
            \WP_CLI::log('🔗 Vérification des hooks WordPress:');
            global $wp_filter;

            if (isset($wp_filter['mailerpress_check_bounces'])) {
                \WP_CLI::success('✅ Hook mailerpress_check_bounces existe');
                \WP_CLI::log('   Callbacks enregistrés:');
                foreach ($wp_filter['mailerpress_check_bounces']->callbacks as $priority => $callbacks) {
                    \WP_CLI::log("   Priority {$priority}:");
                    foreach ($callbacks as $callback) {
                        if (is_array($callback['function'])) {
                            $class = is_object($callback['function'][0]) ? get_class($callback['function'][0]) : $callback['function'][0];
                            $method = $callback['function'][1];
                            \WP_CLI::log("      - {$class}::{$method}");
                        } else {
                            \WP_CLI::log("      - " . print_r($callback['function'], true));
                        }
                    }
                }
            } else {
                \WP_CLI::error('❌ Hook mailerpress_check_bounces n\'existe PAS');
            }
        } catch (\Exception $e) {
            \WP_CLI::error('Erreur: ' . $e->getMessage());
        }
    }
}
