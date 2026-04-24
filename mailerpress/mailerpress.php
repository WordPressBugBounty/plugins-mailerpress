<?php

declare(strict_types=1);

/**
 * Plugin Name: MailerPress
 * Plugin URI: https://mailerpress.com/
 * Description: Create beautiful emails simply inside WordPress connected to your favorite Email Service Provider
 * Version: 1.5.5
 * Author: Team MailerPress
 * License: GPLv3 or later
 * Text Domain: mailerpress
 * Domain Path: /languages
 * Requires PHP: 8.2
 * Requires at least: 6.5
 */

/*  Copyright 2025 - 2026 - Team MailerPress (email : contact@mailerpress.com)
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

defined('ABSPATH') || exit;

use MailerPress\Core\CapabilitiesManager;
use MailerPress\Core\Kernel;
use MailerPress\Core\Uninstall;
use MailerPress\Core\Workflows\Handlers\AddTagStepHandler;
use MailerPress\Core\Workflows\Handlers\SendEmailStepHandler;
use MailerPress\Core\Workflows\WorkflowSystem;
use MailerPress\Services\Activation;
use MailerPress\Services\DeactivatePro;

// Define constants
define('MAILERPRESS_VERSION', '1.5.5');
define('MAILERPRESS_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__));
define('MAILERPRESS_PLUGIN_DIR_URL', plugin_dir_url(__FILE__));
define('MAILERPRESS_ASSETS_DIR', MAILERPRESS_PLUGIN_DIR_URL . 'assets');

// Load plugin textdomain at init hook (WordPress 6.7.0+ requirement)
// Must be loaded before any code uses __() or _e()
add_action('init', function () {
    if (function_exists('load_plugin_textdomain')) {
        load_plugin_textdomain('mailerpress', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}, 0); // Priority 0 = highest priority, runs first at init

// Load dependencies
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

try {
    if (
        !class_exists('ActionScheduler') &&
        file_exists(__DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php')
    ) {
        require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php'; // Adjust the path to where it's located
    }


    // Initialize the plugin
    Kernel::execute([
        'file' => __FILE__,
        'root' => __DIR__,
        'rootUrl' => plugin_dir_url(__FILE__),
    ]);

    if (file_exists(__DIR__ . '/src/Core/Workflows/WorkflowSystem.php')) {
        require_once __DIR__ . '/src/Core/Workflows/WorkflowSystem.php';
    }


    add_action('init', function () {
        // Initialize WorkflowSystem after textdomain is loaded (textdomain loads at priority 0)
        // Priority 1 ensures it runs after textdomain (priority 0)
        // This ensures translations are available when registerDefaultTriggers() uses __()
        WorkflowSystem::init();

        $system = WorkflowSystem::getInstance();
        $manager = $system->getManager();

        // Hook pour permettre à d'autres parties du plugin d'enregistrer leurs handlers
        do_action('mailerpress_register_step_handlers', $manager);

        // Hook pour permettre à d'autres parties du plugin d'enregistrer des triggers personnalisés
        do_action('mailerpress_register_custom_triggers', $manager->getTriggerManager());

        // Enregistrer des triggers personnalisés (WooCommerce, etc.)
        //        $manager->registerTrigger(
        //            'product_purchased',
        //            'woocommerce_order_status_completed',
        //            function ($orderId) {
        //                $order = wc_get_order($orderId);
        //                return [
        //                    'user_id' => $order->get_user_id(),
        //                    'order_id' => $orderId,
        //                ];
        //            }
        //        );
    });

    // Activation hook
    register_activation_hook(__FILE__, static function (): void {
        $activation = new Activation();
        $activation->activate();
        do_action('mailerpress_activation');
    });

    // Deactivation hook
    register_deactivation_hook(__FILE__, static function (): void {
        CapabilitiesManager::removeCapabilities();

        // Désactiver automatiquement le plugin Pro si présent
        $deactivateProFile = __DIR__ . '/src/Services/DeactivatePro.php';
        if (file_exists($deactivateProFile)) {
            require_once $deactivateProFile;
            if (class_exists(DeactivatePro::class)) {
                $deactivatePro = new DeactivatePro();
                $deactivatePro->deactivateProPlugin();
            }
        }

        do_action('mailerpress_deactivation');

        // Planifier aussi la désactivation sur le hook shutdown pour garantir l'exécution
        add_action('shutdown', static function (): void {
            $deactivateProFile = __DIR__ . '/src/Services/DeactivatePro.php';
            if (file_exists($deactivateProFile)) {
                require_once $deactivateProFile;
                if (class_exists(DeactivatePro::class)) {
                    $deactivatePro = new DeactivatePro();
                    $deactivatePro->deactivateProPlugin();
                }
            }
        }, 999);
    });

    // Uninstall hook
    register_uninstall_hook(__FILE__, function (): void {
        require_once __DIR__ . '/src/Core/Uninstall.php';
        $uninstall = new Uninstall();
        $uninstall->run();
        do_action('mailerpress_uninstall');
    });
} catch (Exception $e) {
}
