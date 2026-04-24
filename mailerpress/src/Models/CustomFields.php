<?php

namespace MailerPress\Models;

if (!defined('ABSPATH')) {
    exit;
}

use MailerPress\Core\Enums\Tables;

class CustomFields
{
    protected $table;

    private static ?array $cache = null;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'mailerpress_cpt_definitions';
    }

    /**
     * Get all custom field definitions
     *
     * @return array
     */
    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        global $wpdb;

        // Check if table exists before querying (cached in memory)
        if (!Tables::exists($this->table)) {
            self::$cache = [];
            return [];
        }

        $results = $wpdb->get_results(
            "SELECT id, field_key, label, type, options, required, is_editable, created_at, updated_at
             FROM {$this->table}
             ORDER BY id ASC"
        );

        if (!$results) {
            self::$cache = [];
            return [];
        }

        foreach ($results as &$field) {
            // Unserialize options if not null
            if (!empty($field->options)) {
                $field->options = is_serialized($field->options)
                    ? unserialize($field->options, ['allowed_classes' => false])
                    : $field->options;
            }
        }

        self::$cache = $results;
        return $results;
    }

    /**
     * Get a single field by key
     *
     * @param string $key
     * @return object|null
     */
    public function getByKey(string $key)
    {
        global $wpdb;

        // Check if table exists before querying (cached in memory)
        if (!Tables::exists($this->table)) {
            return null;
        }

        $field = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, field_key, label, type, options, required, is_editable, created_at, updated_at
                 FROM {$this->table}
                 WHERE field_key = %s",
                $key
            )
        );

        if ($field && !empty($field->options)) {
            $field->options = is_serialized($field->options)
                ? unserialize($field->options, ['allowed_classes' => false])
                : $field->options;
        }

        return $field;
    }

    /**
     * Sanitize a custom field value according to its type
     *
     * @param string $field_key The field key
     * @param mixed $value The raw value to sanitize
     * @return mixed|null The sanitized value, or null if empty/invalid
     */
    public static function sanitizeValue(string $field_key, $value)
    {
        // Get field definition
        $field = (new self())->getByKey($field_key);

        // If field doesn't exist, treat as text
        $field_type = $field ? $field->type : 'text';

        // Handle quoted empty strings and trim
        if (is_string($value)) {
            // Remove surrounding quotes if present
            $value = trim($value, '"\'');
            $value = trim($value);
        }

        // Handle empty values (but keep '0' and 0 as valid values)
        $is_empty = ($value === '' || $value === null);

        if ($is_empty) {
            // For checkbox, empty string should be '0', not null
            if ($field_type === 'checkbox') {
                return '0';
            }
            return null;
        }

        // Sanitize according to type
        switch ($field_type) {
            case 'number':
                // Convert string numbers to actual numbers (int or float)
                if (is_numeric($value)) {
                    // Remove any whitespace
                    $value = is_string($value) ? trim($value) : $value;

                    // Check if it's a float or int
                    $float_value = (float) $value;
                    $int_value = (int) $float_value;

                    // Return int if it's a whole number, float otherwise
                    return ($float_value == $int_value) ? $int_value : $float_value;
                }
                // Try to extract number from string (e.g., "123abc" -> 123)
                if (is_string($value) && preg_match('/^-?\d+\.?\d*/', $value, $matches)) {
                    $float_value = (float) $matches[0];
                    $int_value = (int) $float_value;
                    return ($float_value == $int_value) ? $int_value : $float_value;
                }
                return null;

            case 'checkbox':
                // Convert various representations to '1' or '0'
                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }

                // Normalize string values
                if (is_string($value)) {
                    $value = trim(strtolower($value));
                }

                // Handle numeric strings and numbers
                if (is_numeric($value)) {
                    return ((int) $value) > 0 ? '1' : '0';
                }

                // Handle string representations
                $truthy_values = ['1', 'true', 'yes', 'on', 'y', 'enabled', 'active'];
                $falsy_values = ['0', 'false', 'no', 'off', 'n', 'disabled', 'inactive', ''];

                if (in_array($value, $truthy_values, true)) {
                    return '1';
                }
                if (in_array($value, $falsy_values, true)) {
                    return '0';
                }

                // Default to '0' for unknown values
                return '0';

            case 'date':
                // Validate and format date
                if (is_string($value)) {
                    $value = trim($value);
                    // Try to parse the date
                    $timestamp = strtotime($value);
                    if ($timestamp !== false) {
                        // Format as Y-m-d H:i:s or Y-m-d depending on input
                        if (strlen($value) > 10 || strpos($value, ':') !== false) {
                            return date('Y-m-d H:i:s', $timestamp);
                        }
                        return date('Y-m-d', $timestamp);
                    }
                } elseif (is_numeric($value)) {
                    // Handle Unix timestamp
                    return date('Y-m-d H:i:s', (int) $value);
                }
                return null;

            case 'select':
                // Validate against available options
                if ($field && !empty($field->options)) {
                    $options = is_array($field->options) ? $field->options : [];
                    // Normalize value for comparison (trim and case-insensitive)
                    $normalized_value = is_string($value) ? trim($value) : $value;

                    // Check exact match first
                    if (in_array($normalized_value, $options, true)) {
                        return sanitize_text_field($normalized_value);
                    }

                    // Try case-insensitive match
                    foreach ($options as $option) {
                        if (
                            is_string($normalized_value) && is_string($option) &&
                            strtolower($normalized_value) === strtolower($option)
                        ) {
                            return sanitize_text_field($option);
                        }
                    }

                    // Value not in options, return null
                    return null;
                }
                return sanitize_text_field(is_string($value) ? trim($value) : $value);

            case 'text':
            default:
                // For text fields, preserve the value even if it's just whitespace (but sanitize it)
                $text_value = is_string($value) ? trim($value) : (string) $value;
                // Only return null if truly empty after sanitization
                if ($text_value === '') {
                    return null;
                }
                return sanitize_text_field($text_value);
        }
    }
}
