<?php

declare(strict_types=1);

namespace MailerPress\Models;

\defined('ABSPATH') || exit;

use MailerPress\Core\Kernel;

class Patterns
{
    /**
     * @return int[]|void|\WP_Post[]
     *
     * @throws \Exception
     */
    public static function getAll(): array
    {
        $args = [
            'post_type'      => Kernel::getContainer()->get('cpt-pattern-slug'),
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ];

        // 🔧 Allow devs to modify the query
        $args = apply_filters('mailerpress_patterns_query_args', $args);

        $query = new \WP_Query($args);

        $patterns = $query->have_posts() ? $query->posts : [];

        // 🧩 Allow devs to inject or modify patterns
        // 🧱 Ensure all patterns have a consistent structure
        return apply_filters('mailerpress_patterns', $patterns);
    }


}
