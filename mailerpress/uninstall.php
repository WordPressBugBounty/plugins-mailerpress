<?php
/**
 * Uninstall MailerPress
 *
 * Deletes all plugin data if cleanup option is enabled
 *
 * @package MailerPress
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Get the cleanup setting
$settings = get_option('mailerpress_default_settings');
$cleanup_enabled = false;

if (is_string($settings)) {
    $settings = json_decode($settings, true);
}

if (is_array($settings) && isset($settings['cleanupOnDelete'])) {
    $cleanup_enabled = (bool) $settings['cleanupOnDelete'];
}

// If cleanup is not enabled, do nothing
if (!$cleanup_enabled) {
    return;
}

/**
 * CLEANUP ENABLED - Proceed with complete data removal
 */

// Load the Tables class
require_once plugin_dir_path(__FILE__) . 'src/Core/Enums/Tables.php';

use MailerPress\Core\Enums\Tables;

// 1. DROP ALL CUSTOM TABLES
// Get all table names from the Tables enum
$custom_tables = Tables::getAll();

// Disable foreign key checks to avoid constraint issues
$wpdb->query("SET FOREIGN_KEY_CHECKS = 0");

foreach ($custom_tables as $table) {
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));
}

// Re-enable foreign key checks
$wpdb->query("SET FOREIGN_KEY_CHECKS = 1");

// 2. DELETE ALL OPTIONS
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mailerpress_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cf7_mailerpress_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'custom_table_mailerpress_%'");

// 3. DELETE ALL POSTS FROM CUSTOM POST TYPES
// Use the correct post type slugs from container-config.php
$post_types = ['mailpress-campaigns', 'mailerpress-patterns', 'mailpress-pages'];

foreach ($post_types as $post_type) {
    $posts = get_posts([
        'post_type' => $post_type,
        'numberposts' => -1,
        'post_status' => 'any',
    ]);

    foreach ($posts as $post) {
        wp_delete_post($post->ID, true); // Force delete, skip trash
    }
}

// 4. DELETE ALL USER META
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'mailerpress_setup_completed'");

// 5. DELETE ALL TRANSIENTS
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mailerpress_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mailerpress_%'");

// 6. REMOVE CAPABILITIES
// Load Capabilities class
require_once plugin_dir_path(__FILE__) . 'src/Core/Capabilities.php';
require_once plugin_dir_path(__FILE__) . 'src/Core/CapabilitiesManager.php';

use MailerPress\Core\CapabilitiesManager;

// Remove all MailerPress capabilities
CapabilitiesManager::removeCapabilities();

// 7. CLEAR SCHEDULED CRON JOBS
// Clear WordPress native cron
wp_clear_scheduled_hook('mailerpress_cleanup');

// Clear Action Scheduler jobs (mailerpress_ab_test_send_winner and others are handled by AS)
// Delete all Action Scheduler actions related to MailerPress
$wpdb->query(
    "DELETE FROM {$wpdb->prefix}actionscheduler_actions
    WHERE hook LIKE 'mailerpress_%'"
);

// Delete MailerPress group from Action Scheduler
$wpdb->query(
    "DELETE FROM {$wpdb->prefix}actionscheduler_groups
    WHERE slug = 'mailerpress'"
);

// 8. DELETE PRO PLUGIN IF IT EXISTS
$pro_plugin = 'mailerpress-pro/mailerpress-pro.php';

// Include necessary WordPress files
if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!function_exists('delete_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

// Check if Pro plugin exists
$all_plugins = get_plugins();
if (isset($all_plugins[$pro_plugin])) {
    // Deactivate if active
    if (is_plugin_active($pro_plugin)) {
        deactivate_plugins($pro_plugin, true);
    }

    // Delete the plugin
    delete_plugins([$pro_plugin]);
}

// 9. FLUSH REWRITE RULES
flush_rewrite_rules();

// 10. CLEAR ANY OBJECT CACHE
wp_cache_flush();
