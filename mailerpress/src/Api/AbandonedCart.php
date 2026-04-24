<?php

namespace MailerPress\Api;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Workflows\Repositories\CartTrackingRepository;
use MailerPress\Api\Permissions;

class AbandonedCart
{
    #[Endpoint(
        'abandoned-cart/preview',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canManageCampaign'],
    )]
    public function getPreviewCart(): \WP_REST_Response
    {
        // Get the most recent active cart for preview purposes
        $cartRepo = new CartTrackingRepository();

        // Try to get the most recent active cart
        global $wpdb;
        $table = $wpdb->prefix . 'mailerpress_track_cart';

        $cart = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE status = 'ACTIVE' ORDER BY updated_at DESC LIMIT 1",
            ARRAY_A
        );

        if (!$cart) {
            return new \WP_REST_Response([
                'cart' => null,
                'message' => 'No active cart found',
            ], 200);
        }

        // Decode cart_data JSON
        $cartData = json_decode($cart['cart_data'], true);

        if (!$cartData) {
            $cartData = [];
        }

        // Format cart data for the block
        $formattedCart = [
            'cart_items' => $cartData['cart_items'] ?? [],
            'cart_total' => $cartData['cart_total'] ?? '0',
            'cart_subtotal' => $cartData['cart_subtotal'] ?? '0',
            'cart_currency' => $cartData['cart_currency'] ?? get_woocommerce_currency(),
            'cart_item_count' => $cartData['cart_item_count'] ?? 0,
        ];

        return new \WP_REST_Response([
            'cart' => $formattedCart,
        ], 200);
    }
}
