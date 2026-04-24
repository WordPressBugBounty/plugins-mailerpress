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

        // Add ACF fields if ACF is active
        if (function_exists('get_fields') && function_exists('get_field_object')) {
            $acf_fields = get_fields($post->ID);
            if ($acf_fields) {
                // Format ACF fields for better frontend handling
                $formatted_fields = [];
                foreach ($acf_fields as $key => $value) {
                    $field_object = get_field_object($key, $post->ID);
                    $field_type = $field_object['type'] ?? 'text';

                    // For image fields, ensure we have proper structure with URL and sizes
                    if ($field_type === 'image' || $field_type === 'file') {
                        if (is_numeric($value)) {
                            // It's an attachment ID
                            $formatted_fields[$key] = [
                                'ID' => $value,
                                'url' => wp_get_attachment_image_url($value, 'full'),
                                'alt' => get_post_meta($value, '_wp_attachment_image_alt', true),
                                'title' => get_the_title($value),
                                'sizes' => $this->getImageSizes($value),
                            ];
                        } elseif (is_array($value)) {
                            // Already formatted by ACF, but ensure URL and sizes are present
                            if (isset($value['ID'])) {
                                if (empty($value['url'])) {
                                    $value['url'] = wp_get_attachment_image_url($value['ID'], 'full');
                                }
                                if (empty($value['sizes'])) {
                                    $value['sizes'] = $this->getImageSizes($value['ID']);
                                }
                                if (empty($value['alt'])) {
                                    $value['alt'] = get_post_meta($value['ID'], '_wp_attachment_image_alt', true);
                                }
                            }
                            $formatted_fields[$key] = $value;
                        } else {
                            $formatted_fields[$key] = $value;
                        }
                    } else {
                        // For other field types, keep the value as is
                        $formatted_fields[$key] = $value;
                    }
                }
                $data['acf_fields'] = $formatted_fields;
            }
        }

        $response->set_data($data);

        return apply_filters('mailerpress/query-rest-data', $response, $post, $request);
    }

    /**
     * Get image sizes for an attachment
     */
    private function getImageSizes($attachment_id): array
    {
        $sizes = [];
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                $image = wp_get_attachment_image_src($attachment_id, $size);
                if (!empty($image[0])) {
                    $sizes[$size] = [
                        'url' => $image[0],
                        'width' => $image[1],
                        'height' => $image[2],
                    ];
                }
            }
        }

        $full = wp_get_attachment_image_src($attachment_id, 'full');
        if (!empty($full[0])) {
            $sizes['full'] = [
                'url' => $full[0],
                'width' => $full[1],
                'height' => $full[2],
            ];
        }

        return $sizes;
    }
}
