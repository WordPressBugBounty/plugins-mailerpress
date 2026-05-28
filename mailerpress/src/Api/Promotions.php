<?php

namespace MailerPress\Api;

defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Promotions\PromotionRegistry;
use MailerPress\Core\Promotions\RemotePromotionFeed;
use MailerPress\Core\Promotions\PromotionStorage;

class Promotions
{
    #[Endpoint(
        'promotions',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canViewMailerPress'],
    )]
    public function getPromotions(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $context = [
            'placement' => $request->get_param('placement'),
            'screen' => $request->get_param('screen'),
            'path' => $request->get_param('path'),
            'active_view' => $request->get_param('active_view'),
            'view' => $request->get_param('view'),
            'limit' => $request->get_param('limit'),
        ];

        $promotions = PromotionRegistry::getVisiblePromotions($context);

        return rest_ensure_response([
            'success' => true,
            'data' => $promotions,
            'count' => count($promotions),
        ]);
    }

    #[Endpoint(
        'promotions/dismiss',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canViewMailerPress'],
    )]
    public function dismissPromotion(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $promotion_id = sanitize_key((string) $request->get_param('promotion_id'));

        if (!$promotion_id) {
            return new \WP_Error('missing_promotion_id', 'Missing promotion_id', ['status' => 400]);
        }

        $success = PromotionStorage::dismiss($promotion_id);

        return rest_ensure_response([
            'success' => $success,
            'dismissed_id' => $promotion_id,
        ]);
    }

    #[Endpoint(
        'promotions/dismiss',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canViewMailerPress'],
    )]
    public function clearDismissedPromotion(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $promotion_id = sanitize_key((string) $request->get_param('promotion_id'));

        if (!$promotion_id) {
            return new \WP_Error('missing_promotion_id', 'Missing promotion_id', ['status' => 400]);
        }

        $success = PromotionStorage::clearDismissedPromotion($promotion_id);

        return rest_ensure_response([
            'success' => $success,
            'cleared_id' => $promotion_id,
        ]);
    }

    #[Endpoint(
        'promotions/cache',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canViewMailerPress'],
    )]
    public function clearPromotionCache(): \WP_REST_Response
    {
        RemotePromotionFeed::clearCache();

        return rest_ensure_response([
            'success' => true,
        ]);
    }
}
