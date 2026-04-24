<?php

declare(strict_types=1);

namespace MailerPress\Actions\Api\Response;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class Search
{
    #[Action('rest_api_init')]
    public function addSearchData(): void
    {
        register_rest_field('search', 'excerpt', [
            'get_callback' => static function ($post_arr) {
                return get_the_excerpt($post_arr['id']);
            },
        ]);
    }
}
