<?php

namespace MailerPress\Actions\Rest;

use MailerPress\Core\Attributes\Action;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

\defined('ABSPATH') || exit;

class PreparePost
{
    #[Action('init')]
    public function mailerpressPreparePost()
    {
        // Get public post types as objects
        $post_types = get_post_types(
            [
                'public' => true,
                'show_in_rest' => true,
            ],
            'names'
        );
        foreach ($post_types as $post_type) {
            add_filter("mailerpress_rest_prepare_{$post_type}", [$this, 'add_custom_data'], 10, 3);
        }
    }

    public function add_custom_data($response, $post, $request)
    {
        if (is_array($post)) {
            $post = (object) $post;
        }

        if (!$post instanceof \WP_Post) {
            return $response;
        }


        $featured_image_id = get_post_thumbnail_id($post->ID);

        $image_sizes = [];



        if ($featured_image_id) {
            $metadata = wp_get_attachment_metadata($featured_image_id);

            if (!empty($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $data) {
                    $image = wp_get_attachment_image_src($featured_image_id, $size);
                    if (!empty($image[0])) {
                        $image_sizes[$size] = [
                            'url' => $image[0],
                            'width' => $image[1],
                            'height' => $image[2],
                        ];
                    }
                }
            }

            $full = wp_get_attachment_image_src($featured_image_id, 'full');
            if (!empty($full[0])) {
                $image_sizes['full'] = [
                    'url' => $full[0],
                    'width' => $full[1],
                    'height' => $full[2],
                ];
            }
        }

        // Get response data as array and add custom fields
        $data = (array) $response->get_data();
        $data['featured_image_src'] = $image_sizes ?: null;
        $data['subType'] = get_post_type($post);
        $data['category_names'] = \MailerPress\Helpers\getPostCategoryNames($post);

        if ($post->post_type === 'product' && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $data['price'] = $product->get_price();
                $data['regular_price'] = $product->get_regular_price();
                $data['sale_price'] = $product->get_sale_price();
                $data['price_html'] = $product->get_price_html();
                $data['currency'] = get_woocommerce_currency_symbol();
            }
        }

        $response->set_data($data);

        return apply_filters('mailerpress/query-rest-data', $response, $post, $request);
    }
}
