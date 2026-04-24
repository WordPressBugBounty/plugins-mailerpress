<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;

\defined('ABSPATH') || exit;

/**
 * Fix legacy queries that use trigger_type column which doesn't exist anymore
 * Triggers are now stored in mailerpress_automations_steps table
 * 
 * This class intercepts SQL queries from MailerPressPro and converts them
 * to use the new structure with automations_steps table
 * 
 * Uses error suppression and query correction to handle legacy queries
 */
class FixLegacyTriggerTypeQueries
{
    #[Action('plugins_loaded', priority: 1)]
    public function fixQueries(): void
    {
        // Intercept queries via query filter (works for some queries)
        add_filter('query', [$this, 'convertLegacyQuery'], 999, 1);

        // Intercept errors at shutdown and correct queries
        add_action('shutdown', [$this, 'handleErrors'], 999);

        // Also monitor wpdb errors during execution
        add_action('init', [$this, 'monitorWpdbErrors'], 1);
    }

    /**
     * Monitor wpdb errors during execution
     */
    public function monitorWpdbErrors(): void
    {
        // Check for errors on every request
        add_action('wp_loaded', [$this, 'checkAndFixErrors'], 999);
    }

    /**
     * Check for errors and fix them
     */
    public function checkAndFixErrors(): void
    {
        global $wpdb;

        // Check if there's an error about trigger_type
        if (!empty($wpdb->last_error) && strpos($wpdb->last_error, "Unknown column 'trigger_type'") !== false) {
            $lastQuery = $wpdb->last_query ?? '';

            if (!empty($lastQuery) && strpos($lastQuery, 'trigger_type') !== false) {
                // Convert the query
                $correctedQuery = self::convertQuery($lastQuery);

                if ($correctedQuery !== $lastQuery) {
                    // Clear the error
                    $wpdb->last_error = '';

                    // Execute the corrected query
                    $wpdb->suppress_errors(true);
                    $wpdb->query($correctedQuery);
                    $wpdb->suppress_errors(false);
                }
            }
        }
    }



    /**
     * Convert legacy query via query filter
     */
    public function convertLegacyQuery($query)
    {
        if (!is_string($query)) {
            return $query;
        }

        return self::convertQuery($query);
    }

    /**
     * Handle errors at shutdown and correct queries
     */
    public function handleErrors(): void
    {
        global $wpdb;

        // Check if there's an error about trigger_type
        if (!empty($wpdb->last_error) && strpos($wpdb->last_error, "Unknown column 'trigger_type'") !== false) {
            // Get the last query that failed
            $lastQuery = $wpdb->last_query ?? '';

            if (!empty($lastQuery) && strpos($lastQuery, 'trigger_type') !== false) {
                // Convert the query
                $correctedQuery = self::convertQuery($lastQuery);

                if ($correctedQuery !== $lastQuery) {
                    // Clear error
                    $wpdb->last_error = '';

                    // Execute corrected query silently
                    $wpdb->suppress_errors(true);
                    $wpdb->query($correctedQuery);
                    $wpdb->suppress_errors(false);
                }
            }
        }
    }

    /**
     * Convert legacy query with trigger_type to new structure
     */
    private static function convertQuery(string $query): string
    {
        global $wpdb;

        // Only process queries for automations table
        if (strpos($query, $wpdb->prefix . 'mailerpress_automations') === false) {
            return $query;
        }

        // Check if query uses trigger_type column - handle multiple patterns
        $triggerType = null;

        // Pattern 1: WHERE trigger_type = 'value'
        if (preg_match('/WHERE\s+trigger_type\s*=\s*[\'"]?([^\'"\s]+)[\'"]?/i', $query, $matches)) {
            $triggerType = $matches[1] ?? null;
        }
        // Pattern 2: WHERE trigger_type='value' (no space)
        elseif (preg_match('/WHERE\s+trigger_type[\'"]?\s*=\s*[\'"]?([^\'"\s]+)[\'"]?/i', $query, $matches)) {
            $triggerType = $matches[1] ?? null;
        }
        // Pattern 3: trigger_type = 'value' (anywhere in WHERE clause)
        elseif (preg_match('/trigger_type\s*=\s*[\'"]?([^\'"\s]+)[\'"]?/i', $query, $matches)) {
            $triggerType = $matches[1] ?? null;
        }

        if ($triggerType) {
            $automationsTable = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS;
            $stepsTable = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEPS;

            // Cache table existence checks to avoid repeated queries
            static $tableExistsCache = [];
            $cacheKey = $automationsTable . '_' . $stepsTable;

            if (!isset($tableExistsCache[$cacheKey])) {
                $automationsExists = $wpdb->get_var("SHOW TABLES LIKE '{$automationsTable}'") === $automationsTable;
                $stepsExists = $wpdb->get_var("SHOW TABLES LIKE '{$stepsTable}'") === $stepsTable;
                $tableExistsCache[$cacheKey] = $automationsExists && $stepsExists;
            }

            if (!$tableExistsCache[$cacheKey]) {
                // Tables don't exist yet, return empty result query
                return "SELECT * FROM {$automationsTable} WHERE 1=0";
            }

            // Cache column checks
            static $columnExistsCache = [];
            if (!isset($columnExistsCache[$automationsTable])) {
                $columns = $wpdb->get_col("SHOW COLUMNS FROM {$automationsTable}", 0);
                $columnExistsCache[$automationsTable] = in_array('id', $columns, true);
            }

            if (!$columnExistsCache[$automationsTable]) {
                // Column doesn't exist yet, return empty result query
                return "SELECT * FROM {$automationsTable} WHERE 1=0";
            }

            // Remove WHERE trigger_type clause completely
            $newQuery = preg_replace(
                '/\s*WHERE\s+trigger_type\s*=\s*[\'"]?[^\'"\s]+[\'"]?/i',
                '',
                $query
            );

            // Also handle cases where trigger_type is the only WHERE condition
            $newQuery = preg_replace(
                '/WHERE\s+trigger_type\s*=\s*[\'"]?[^\'"\s]+[\'"]?/i',
                '',
                $newQuery
            );

            // Add table alias if not present
            $tableName = $wpdb->prefix . 'mailerpress_automations';
            $tableNameQuoted = '`' . $tableName . '`';

            // Check if alias already exists
            $hasAlias = (strpos($newQuery, $tableName . ' a') !== false ||
                strpos($newQuery, $tableName . ' AS a') !== false ||
                strpos($newQuery, $tableNameQuoted . ' a') !== false ||
                strpos($newQuery, $tableNameQuoted . ' AS a') !== false);

            if (!$hasAlias) {
                // Add alias - handle both quoted and unquoted table names
                $newQuery = preg_replace(
                    '/FROM\s+(?:`)?' . preg_quote($tableName, '/') . '(?:`)?(?=\s|$|WHERE)/i',
                    'FROM `' . $tableName . '` a',
                    $newQuery
                );
            }

            // Add JOIN clause - use prepared statement for safety
            $joinClause = $wpdb->prepare(
                " INNER JOIN `{$stepsTable}` s ON a.id = s.automation_id AND s.type = 'TRIGGER' AND s.key = %s",
                $triggerType
            );

            // Insert JOIN after FROM clause (after alias if present)
            if (preg_match('/(FROM\s+(?:`)?[^\s`]+\s+(?:AS\s+)?a\b)/i', $newQuery, $fromMatch)) {
                $newQuery = str_replace($fromMatch[0], $fromMatch[0] . $joinClause, $newQuery);
            } else {
                // Fallback: add JOIN before WHERE if FROM clause wasn't found
                $newQuery = preg_replace(
                    '/(FROM\s+(?:`)?[^\s`]+(?:`)?)/i',
                    '$1 a' . $joinClause,
                    $newQuery,
                    1
                );
            }

            return $newQuery;
        }

        return $query;
    }
}
