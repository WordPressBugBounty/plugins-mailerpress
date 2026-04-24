<?php

declare(strict_types=1);

namespace MailerPress\Actions\Api\Response;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Filter;

class Posts
{
    #[Filter('rest_prepare_post', priority: 10, acceptedArgs: 3)]
    public function response(\WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request): \WP_REST_Response
    {
        if ('view' === $request['context']) {
            if (!empty($response->data['categories'])) {
                $response->data['categories'] = get_the_category($post);
            }

            $response->data['images'] = [
                'thumbnail' => get_the_post_thumbnail_url($post, 'thumbnail'),
                'medium' => get_the_post_thumbnail_url($post, 'medium'),
                'medium_large' => get_the_post_thumbnail_url($post, 'medium_large'),
                'large' => get_the_post_thumbnail_url($post, 'large'),
                'full' => get_the_post_thumbnail_url($post, 'full'),
            ];
        }

        return $response;
    }
}
