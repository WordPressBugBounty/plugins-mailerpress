<?php

declare(strict_types=1);

namespace MailerPress\Actions\Setup;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Core\Migrations\Manager;

class TableManager
{
    /**
     * Run migrations early on 'init' hook with high priority
     * This ensures migrations run before triggers are registered (which happens on 'init' at default priority)
     * Priority 1 ensures it runs before most other init hooks
     */
    #[Action('init', priority: 1)]
    public function createOrUpdateTable(): void
    {
        $current_version = defined('MAILERPRESS_VERSION_DEV')
            ? MAILERPRESS_VERSION_DEV   // dev override
            : MAILERPRESS_VERSION;       // release version

        $installedVersion = get_option('mailerpress_plugin_version');
        $versionChanged = $installedVersion !== $current_version;

        // Always check migrations if version changed (production updates)
        // In development mode (WP_DEBUG), also check even if version unchanged
        // to allow testing migration file changes without modifying wp-config
        $isDevelopment = defined('WP_DEBUG') && WP_DEBUG;

        // Skip migration check ONLY if:
        // - Version hasn't changed AND
        // - Not in development mode
        // This optimizes performance in production while allowing easy local testing
        // In dev mode, only run migration checks in admin context (not on frontend)
        if (!$versionChanged && (!$isDevelopment || !is_admin())) {
            // Version unchanged - skip migration check
            // But still add default data
            add_action('init', [$this, 'addDefaultData'], 20);
            return;
        }

        // Migrations will run if:
        // 1. Version changed (normal production flow) OR
        // 2. WP_DEBUG is enabled (local development convenience)
        // The Manager will use file_hash to detect changed files automatically

        // Drop old automation tables ONLY if they exist and new ones don't exist yet
        // This is a one-time migration to replace old table structure with new one
        $tablesToDrop = $this->getOldTablesToDrop();

        // Run migrations with error handling
        // FORCE MODE: When version changes, always validate against actual DB state
        // This ensures migrations run even if tracking is corrupted
        $forceMode = $versionChanged; // Force validation on version change

        try {
            $manager = new Manager(
                Kernel::$config['root'] . '/src/Core/Migrations/migrations',
                $tablesToDrop,
                $forceMode
            );
            $manager->run();

            // Update stored version only if migrations succeeded
            if ($versionChanged) {
                update_option('mailerpress_plugin_version', $current_version);
            }
        } catch (\Throwable $e) {
            // Log error but don't crash the plugin
            // Still update version to prevent infinite retry loops (only if version changed)
            if ($versionChanged) {
                update_option('mailerpress_plugin_version', $current_version);
            }

            // Optionally show admin notice (if in admin)
            if (is_admin() && current_user_can('manage_options')) {
                add_action('admin_notices', function () use ($e) {
                    $recoveryUrl = admin_url('admin.php?page=mailerpress&action=migrations-recovery');
                    echo '<div class="notice notice-error is-dismissible"><p>';
                    echo '<strong>MailerPress:</strong> ' . esc_html__('Database migration failed. Please check error logs.', 'mailerpress');
                    echo ' <a href="' . esc_url($recoveryUrl) . '">' . esc_html__('Recover now', 'mailerpress') . '</a>';
                    echo ' | <code>wp mailerpress migrations:reset-failed</code>';
                    echo '</p></div>';
                });
            }
        }

        // Add default data - delay to init to ensure translations are loaded
        add_action('init', [$this, 'addDefaultData'], 20);
    }

    /**
     * Add default data (lists, categories)
     * Called at init to ensure translations are loaded
     */
    public function addDefaultData(): void
    {
        // Ensure textdomain is loaded before using __() (WordPress 6.7.0+ requirement)
        if (!is_textdomain_loaded('mailerpress') && function_exists('load_plugin_textdomain')) {
            $plugin_file = defined('MAILERPRESS_PLUGIN_DIR_PATH')
                ? MAILERPRESS_PLUGIN_DIR_PATH . '../mailerpress.php'
                : __FILE__;
            load_plugin_textdomain('mailerpress', false, dirname(plugin_basename($plugin_file)) . '/languages');
        }

        $this->addDefaultList();
        $this->addDefaultCategories();
    }


    private function addDefaultList(): void
    {
        if (get_option('mailerpress_default_list_added')) {
            return;
        }

        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_LIST);

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return;
        }

        // Check if is_default column exists
        $has_is_default_column = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'is_default'");
        if (!$has_is_default_column) {
            // Column doesn't exist yet, skip - migration will handle it
            return;
        }

        // Check if a default list already exists (is_default = 1)
        $existing_default = $wpdb->get_var("SELECT list_id FROM {$table_name} WHERE is_default = 1");

        if (!$existing_default) {
            // Check if any lists exist
            $list_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

            if ($list_count === 0) {
                // Create the default list with is_default = 1
                $default_list_name = __('Default list', 'mailerpress');
                $wpdb->insert(
                    $table_name,
                    [
                        'name' => $default_list_name,
                        'is_default' => 1,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ],
                    ['%s', '%d', '%s', '%s']
                );
            } else {
                // If lists exist but none are marked as default, set the first one as default
                $first_list = $wpdb->get_var("SELECT list_id FROM {$table_name} ORDER BY created_at ASC LIMIT 1");
                if ($first_list) {
                    $wpdb->update(
                        $table_name,
                        ['is_default' => 1],
                        ['list_id' => $first_list],
                        ['%d'],
                        ['%d']
                    );
                }
            }
        }

        add_option('mailerpress_default_list_added', true);
    }

    private function addDefaultCategories(): void
    {
        global $wpdb;

        if (get_option('mailerpress_default_categories_added')) {
            return;
        }

        $categoriesTable = Tables::get(Tables::MAILERPRESS_CATEGORIES);

        // Define your default categories for each type
        $defaultCategories = [
            'template' => [
                'Ecommerce',
                'Newsletter',
            ],
            'pattern' => [
                'Header',
                'Footer',
                'Call to action',
                'Banners',
                'Text',
            ],
        ];

        foreach ($defaultCategories as $type => $categories) {
            foreach ($categories as $categoryName) {
                // Check if category already exists to avoid duplicates
                $exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT category_id FROM {$categoriesTable} WHERE name = %s AND type = %s LIMIT 1",
                        $categoryName,
                        $type
                    )
                );

                if (!$exists) {
                    $wpdb->insert(
                        $categoriesTable,
                        [
                            'name' => $categoryName,
                            'slug' => sanitize_title($categoryName),
                            'type' => $type,
                            'created_at' => current_time('mysql'),
                        ],
                        [
                            '%s',
                            '%s',
                            '%s',
                            '%s'
                        ]
                    );
                }
            }
        }

        add_option('mailerpress_default_categories_added', true);
    }

    /**
     * Get list of old tables to drop (one-time migration)
     * Only drops old automation tables if they exist AND new ones don't exist yet
     * 
     * @return array List of table names (without prefix) to drop
     */
    protected function getOldTablesToDrop(): array
    {
        global $wpdb;

        // Old automation tables that need to be replaced
        $oldTables = [
            'mailerpress_automations',
            'mailerpress_automations_contact',
            'mailerpress_automations_logs',
            'mailerpress_automations_queue',
            'mailerpress_automations_steps',
            'mailerpress_automations_steps_links',
        ];

        // New automation tables (from migration 2025_10_30_062719_automations.php)
        $newTables = [
            'mailerpress_automations', // Same name but different structure
            'mailerpress_automations_steps',
            'mailerpress_automations_branches',
            'mailerpress_automations_jobs',
            'mailerpress_automations_log',
            'mailerpress_automations_meta',
        ];

        // Check if old tables migration has already been completed
        $oldTablesDropped = get_option('mailerpress_old_automation_tables_dropped', false);

        if ($oldTablesDropped) {
            // Already dropped, don't drop again
            return [];
        }

        // Check if any old tables exist
        $oldTablesExist = false;
        foreach ($oldTables as $table) {
            $fullTableName = $wpdb->prefix . $table;
            $exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($fullTableName))
            );
            if ($exists === $fullTableName) {
                $oldTablesExist = true;
                break;
            }
        }

        // Check if new tables already exist
        $newTablesExist = false;
        foreach ($newTables as $table) {
            $fullTableName = $wpdb->prefix . $table;
            $exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($fullTableName))
            );
            if ($exists === $fullTableName) {
                $newTablesExist = true;
                break;
            }
        }

        // Force drop old tables if they exist, regardless of new tables status
        // This ensures clean migration even if previous migration partially failed
        if ($oldTablesExist) {
            // Check if migration has been successfully completed by checking migration tracker
            global $wpdb;
            $migrationFile = '2025_10_30_062719_automations.php';
            $trackerTable = $wpdb->prefix . 'mailerpress_migrations';

            // Check if migration tracking table exists
            $trackerExists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($trackerTable))
            ) === $trackerTable;

            if ($trackerExists) {
                // Check if automation migration was completed successfully
                $migrationCompleted = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$trackerTable} WHERE migration_file LIKE %s AND status = 'completed'",
                        '%' . $wpdb->esc_like($migrationFile)
                    )
                );

                // If migration is completed, mark old tables as dropped
                if ($migrationCompleted > 0) {
                    update_option('mailerpress_old_automation_tables_dropped', true);
                    return [];
                }
            }

            // Old tables exist and migration not completed - drop them
            return $oldTables;
        }

        // Old tables don't exist - mark as completed
        update_option('mailerpress_old_automation_tables_dropped', true);
        return [];
    }
}
