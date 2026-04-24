<?php

declare(strict_types=1);

namespace MailerPress\Models;

\defined('ABSPATH') || exit;

class Posts
{
    /**
     * @return int[]|void|\WP_Post[]
     */
    public static function getLatest()
    {
        $query = new \WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 3,
            // ✅ Optimisation: Supprimer les filtres pour éviter les hooks lourds de WooCommerce et autres plugins
            'suppress_filters' => true,
            'no_found_rows' => true, // Pas besoin de compter le total
        ]);

        $posts = $query->have_posts() ? $query->posts : [];
        wp_reset_postdata(); // Nettoyer les données globales
        return $posts;
    }
}
