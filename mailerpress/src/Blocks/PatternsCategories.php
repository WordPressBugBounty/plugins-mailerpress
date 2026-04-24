<?php

declare(strict_types=1);

namespace MailerPress\Blocks;

use MailerPress\Core\Enums\Tables;

\defined('ABSPATH') || exit;

final class PatternsCategories
{
    private array $categories = [];

    public function __construct()
    {
        $this->categories = [
            'core/header' => [
                'label' => __('Header', 'mailerpress'),
            ],
            'core/footer' => [
                'label' => __('Footer', 'mailerpress'),
            ],
            'core/text' => [
                'label' => __('Text', 'mailerpress'),
            ],
            'core/banners' => [
                'label' => __('Banners', 'mailerpress'),
            ],
            'core/call-to-action' => [
                'label' => __('Call to action', 'mailerpress'),
            ],
        ];
    }

    public function registerCategory(array $category): void
    {
        $this->categories = array_merge($this->categories, $category);
    }

    public function getCategories(): array
    {
        global $wpdb;

        $categoriesTable = Tables::get(Tables::MAILERPRESS_CATEGORIES);

        $categories = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT category_id, name AS label, slug 
                 FROM {$categoriesTable}
                 WHERE type = %s
                 ORDER BY name ASC",
                'pattern'
            ),
            ARRAY_A
        );

        // Convert to associative array: category_id => [label => ...]
        $result = [];
        foreach ($categories as $category) {
            $result[$category['slug']] = [
                'label' => $category['label'],
            ];
        }

        return $result;
    }

}
