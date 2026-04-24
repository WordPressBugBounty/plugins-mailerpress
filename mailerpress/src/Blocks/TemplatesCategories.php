<?php

declare(strict_types=1);

namespace MailerPress\Blocks;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;

final class TemplatesCategories
{
    private array $categories = [];

    public function __construct()
    {
        $this->categories = [];
    }

    public function registerCategory(array $category): void
    {
        $this->categories = array_merge($this->categories, $category);
    }

    public function getCategories(?string $usage_type = null): array
    {
        global $wpdb;

        $categoriesTable = Tables::get(Tables::MAILERPRESS_CATEGORIES);
        $templatesTable = Tables::get(Tables::MAILERPRESS_TEMPLATES);

        // Build query to get categories filtered by usage_type if provided
        if (!empty($usage_type) && in_array($usage_type, ['newsletter', 'automation'], true)) {
            $categories = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT c.category_id, c.name AS label
                     FROM {$categoriesTable} c
                     INNER JOIN {$templatesTable} t ON c.category_id = t.cat_id
                     WHERE c.type = %s AND t.usage_type = %s
                     ORDER BY c.name ASC",
                    'template',
                    $usage_type
                ),
                ARRAY_A
            );
        } else {
            $categories = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT category_id, name AS label
                     FROM {$categoriesTable}
                     WHERE type = %s
                     ORDER BY name ASC",
                    'template'
                ),
                ARRAY_A
            );
        }

        // Convert to associative array: category_id => [label => ...]
        $result = [];
        foreach ($categories as $category) {
            $result[$category['category_id']] = [
                'label' => $category['label'],
            ];
        }

        return $result;
    }

    public function getTemplatesGroupByCategories(?string $usage_type = null): array
    {
        global $wpdb;

        $templatesTable = Tables::get(Tables::MAILERPRESS_TEMPLATES);
        $categoriesTable = Tables::get(Tables::MAILERPRESS_CATEGORIES);

        // Fetch all categories where type = 'template', optionally filtered by usage_type
        if (!empty($usage_type) && in_array($usage_type, ['newsletter', 'automation'], true)) {
            $categories = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT c.category_id, c.name AS label 
                     FROM {$categoriesTable} c
                     INNER JOIN {$templatesTable} t ON c.category_id = t.cat_id
                     WHERE c.type = %s AND t.usage_type = %s",
                    'template',
                    $usage_type
                ),
                OBJECT_K
            );

            // Fetch counts of templates grouped by cat_id, filtered by usage_type
            $entries = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT cat_id, COUNT(*) AS total_entries
                     FROM {$templatesTable}
                     WHERE usage_type = %s
                     GROUP BY cat_id",
                    $usage_type
                ),
                OBJECT_K
            );
        } else {
            $categories = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT category_id, name AS label 
                     FROM {$categoriesTable}
                     WHERE type = %s",
                    'template'
                ),
                OBJECT_K
            );

            // Fetch counts of templates grouped by cat_id
            $entries = $wpdb->get_results(
                "SELECT cat_id, COUNT(*) AS total_entries
                 FROM {$templatesTable}
                 GROUP BY cat_id",
                OBJECT_K
            );
        }

        // Build result array
        $result = [];

        // "All templates" count
        $totalAll = array_reduce($entries, static function ($carry, $item) {
            return $carry + (int) $item->total_entries;
        }, 0);

        $result[''] = [
            'label' => __('All templates', 'mailerpress'),
            'total_entries' => $totalAll,
        ];

        $translations = [
            'ecommerce' => __('Ecommerce', 'mailerpress'),
            'newsletter' => __('Newsletter', 'mailerpress'),
            'thank-you' => __('Thank you', 'mailerpress'),
            'welcome' => __('Welcome', 'mailerpress'),
        ];

        foreach ($categories as $category_id => $category) {
            $count = isset($entries[$category_id]) ? (int) $entries[$category_id]->total_entries : 0;

            if ($count > 0) {
                $label = $translations[$category->label] ?? $category->label;

                $result[$category_id] = [
                    'label' => $label,
                    'total_entries' => $count,
                ];
            }
        }

        return $result;
    }
}
