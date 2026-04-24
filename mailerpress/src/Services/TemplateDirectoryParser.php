<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

class TemplateDirectoryParser
{
    public function import(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $file_path = $file->getPathname();
                $json_content = file_get_contents($file_path);
                $data = json_decode($json_content, true);

                if (!$data) {
                    continue;
                }

                $this->importTemplate($data, $file_path);
            }
        }
    }


    private function importTemplate(array $data, string $file_path): void
    {
        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_TEMPLATES);

        $category_name = $data['category'] ?? 'Uncategorized';
        $category_id = $this->get_or_create_category($category_name);

        $json_version = $data['version'] ?? '1.0.0';

        // Get usage_type from JSON data, default to 'newsletter' for backward compatibility
        $usage_type = $data['usage_type'] ?? 'newsletter';
        if (!in_array($usage_type, ['newsletter', 'automation'], true)) {
            $usage_type = 'newsletter';
        }

        $existing_template = $this->get_template_by_path($file_path, $data['name'] ?? 'Unknown');

        if ($existing_template) {
            $db_version = $existing_template->version ?? '1.0.0';

            if (version_compare($json_version, $db_version, '>')) {
                $wpdb->update(
                    $table_name,
                    [
                        'name' => $data['name'] ?? 'Unknown',
                        'content' => $data['json'] ?? '',
                        'description' => $data['description'] ?? '',
                        'updated_at' => current_time('mysql'),
                        'version' => $json_version,
                        'cat_id' => $category_id,
                        'path' => $file_path,
                        'usage_type' => $usage_type,
                    ],
                    ['id' => $existing_template->id]
                );
            }
        } else {
            $wpdb->insert(
                $table_name,
                [
                    'name' => $data['name'] ?? 'Unknown',
                    'content' => $data['json'] ?? '',
                    'description' => $data['description'] ?? '',
                    'path' => $file_path,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'internal' => 1,
                    'cat_id' => $category_id,
                    'version' => $json_version,
                    'usage_type' => $usage_type,
                ]
            );
        }
    }

    private function get_or_create_category(string $name): int
    {
        global $wpdb;

        $table_name = Tables::get(Tables::MAILERPRESS_CATEGORIES);
        $slug = sanitize_title($name);

        $category_id = (int)$wpdb->get_var(
            $wpdb->prepare("SELECT category_id FROM {$table_name} WHERE slug = %s AND type = 'template' LIMIT 1", $slug)
        );

        if ($category_id) {
            return $category_id;
        }

        $wpdb->insert(
            $table_name,
            [
                'name' => $name,
                'slug' => $slug,
                'type' => 'template',
                'created_at' => current_time('mysql'),
            ]
        );

        return (int)$wpdb->insert_id;
    }

    private function get_template_by_path(string $file_path, string $name)
    {
        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_TEMPLATES);

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE path = %s OR name = %s LIMIT 1",
                $file_path,
                $name
            )
        );
    }
}
