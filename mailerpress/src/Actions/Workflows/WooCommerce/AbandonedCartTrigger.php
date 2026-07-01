<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * WooCommerce Abandoned Cart Trigger
 *
 * Fires when a cart is updated in WooCommerce.
 * This trigger captures cart events and extracts relevant
 * cart data to be used in workflow automations for abandoned cart recovery.
 *
 * The trigger listens to multiple WooCommerce cart hooks:
 * - woocommerce_add_to_cart: When an item is added to cart
 * - woocommerce_after_cart_item_quantity_update: When cart item quantity is updated
 * - woocommerce_checkout_update_order_review: When checkout data is updated
 * - woocommerce_store_api_cart_update_customer_from_request: When Checkout Block customer data is updated
 * - woocommerce_store_api_checkout_order_processed: When Checkout Block creates an order
 *
 * Usage in workflows:
 * 1. Add this trigger to detect cart updates
 * 2. Add a DELAY step (e.g., wait 1 hour or 24 hours)
 * 3. Add a CONDITION step to check if order was created (if order_id exists, cart was completed)
 * 4. If condition fails (no order), send abandoned cart email
 *
 * Data available in the workflow context:
 * - user_id: The WordPress user ID (0 for guests)
 * - customer_email: The customer's email (from session or user account)
 * - customer_first_name: The customer's first name
 * - customer_last_name: The customer's last name
 * - cart_items: Array of cart items with product details
 * - cart_total: The total amount in the cart
 * - cart_currency: The currency used
 * - cart_updated_at: Timestamp when cart was last updated
 * - cart_item_count: Number of items in cart
 * - cart_hash: Unique hash for this cart session
 *
 * @since 1.2.0
 */
class AbandonedCartTrigger
{
    private const GUEST_USER_ID_OFFSET = 2147483648;

    /**
     * Trigger key - unique identifier for this trigger
     */
    public const TRIGGER_KEY = 'woocommerce_abandoned_cart';

    /**
     * Register the custom trigger
     *
     * @param mixed $manager The trigger manager instance
     */
    public static function register($manager): void
    {
        // Only register if WooCommerce is active
        if (!function_exists('wc_get_order_statuses')) {
            return;
        }

        $definition = [
            'key' => self::TRIGGER_KEY,
            'label' => __('Abandoned Cart', 'mailerpress'),
            'description' => __('Triggered when a cart is updated in WooCommerce. Perfect for recovering abandoned carts by sending reminder emails after a configured delay. Add a wait step followed by a condition to check if an order was placed.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'type' => 'TRIGGER',
            'settings_schema' => [
                [
                    'key' => 'minimum_cart_value',
                    'label' => __('Minimum Cart Value', 'mailerpress'),
                    'type' => 'number',
                    'required' => false,
                    'help' => __('Only trigger when cart total is above this amount (leave empty for any amount)', 'mailerpress'),
                ],
                [
                    'key' => 'require_email',
                    'label' => __('Require Email', 'mailerpress'),
                    'type' => 'checkbox',
                    'required' => false,
                    'default' => false,
                    'help' => __('Only trigger when customer email is available', 'mailerpress'),
                ],
            ],
            'output_fields' => [
                // Customer fields
                ['key' => 'customer_email', 'label' => __('Customer Email', 'mailerpress'), 'type' => 'email', 'group' => 'customer'],
                ['key' => 'customer_first_name', 'label' => __('Customer First Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_last_name', 'label' => __('Customer Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
                // Cart fields
                ['key' => 'cart_total', 'label' => __('Cart Total', 'mailerpress'), 'type' => 'number', 'group' => 'cart'],
                ['key' => 'cart_currency', 'label' => __('Cart Currency', 'mailerpress'), 'type' => 'string', 'group' => 'cart'],
                ['key' => 'cart_item_count', 'label' => __('Cart Item Count', 'mailerpress'), 'type' => 'number', 'group' => 'cart'],
                ['key' => 'cart_updated_at', 'label' => __('Cart Updated At', 'mailerpress'), 'type' => 'date', 'group' => 'cart'],
                ['key' => 'cart_hash', 'label' => __('Cart Hash', 'mailerpress'), 'type' => 'string', 'group' => 'cart'],
                ['key' => 'cart_recovery_url', 'label' => __('Cart Recovery URL', 'mailerpress'), 'type' => 'url', 'group' => 'cart'],
                // Billing fields
                ['key' => 'billing_email', 'label' => __('Billing Email', 'mailerpress'), 'type' => 'email', 'group' => 'billing'],
                ['key' => 'billing_first_name', 'label' => __('Billing First Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_last_name', 'label' => __('Billing Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_phone', 'label' => __('Billing Phone', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
            ],
        ];

        // Register the trigger definition once (first hook)
        // The definition will be stored and reused for all hooks
        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'woocommerce_add_to_cart',
            self::contextBuilder(...),
            $definition
        );

        // Register the same trigger for additional hooks (definition already stored)
        // Note: We don't register 'woocommerce_cart_item_removed' because it can fire
        // even when the cart is being emptied, which would create jobs without items
        $additionalHooks = [
            'woocommerce_after_cart_item_quantity_update',
            'woocommerce_checkout_update_order_review',
            'woocommerce_store_api_cart_update_customer_from_request',
            'woocommerce_store_api_checkout_update_customer_from_request',
            // 'woocommerce_cart_item_removed' - removed to prevent jobs when cart is emptied
        ];

        foreach ($additionalHooks as $hook) {
            $manager->registerTrigger(
                self::TRIGGER_KEY,
                $hook,
                self::contextBuilder(...),
                null // Don't pass definition again, it's already stored
            );
        }

        // Register hook to mark cart as emptied when cart is cleared
        add_action('woocommerce_cart_emptied', [self::class, 'handleCartEmptied'], 5, 1);

        // Also register for before_cart_emptied to mark cart early
        add_action('woocommerce_before_cart_emptied', [self::class, 'handleCartEmptied'], 5, 1);

        // Register hook to mark cart as completed when order is created
        add_action('woocommerce_checkout_order_processed', [self::class, 'handleOrderCreated'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'handleOrderCreated'], 10, 1);
    }

    /**
     * Handle cart emptied event - mark cart as emptied in tracking table
     *
     * @param bool $clear_persistent_cart
     */
    public static function handleCartEmptied($clear_persistent_cart = true): void
    {
        if (!function_exists('WC')) {
            return;
        }

        try {
            if (WC()->session && WC()->session->get('mailerpress_restoring_cart')) {
                return;
            }

            // Get customer identifier
            $customerId = get_current_user_id();
            $customerEmail = '';

            if ($customerId) {
                $user = get_userdata($customerId);
                if ($user) {
                    $customerEmail = $user->user_email ?? '';
                }
            } else {
                // For guests, try to get email from session
                $session = WC()->session;
                if ($session) {
                    $customerEmail = self::sanitizeEmail($session->get('billing_email'))
                        ?: self::sanitizeEmail($session->get('guest_email'))
                        ?: self::sanitizeEmail($_COOKIE['woocommerce_email_' . COOKIEHASH] ?? '');
                }
            }

            // Determine user_id for workflow tracking
            $workflowUserId = self::resolveWorkflowUserId($customerId, $customerEmail);

            if (!$workflowUserId) {
                return; // Cannot identify user/email
            }

            // Find active cart for this user and mark it as emptied
            $cartRepo = new \MailerPress\Core\Workflows\Repositories\CartTrackingRepository();
            $activeCart = $cartRepo->getActiveCartByUserId($workflowUserId);

            if ($activeCart) {
                $cartRepo->markCartEmptied($activeCart['cart_hash']);
            }

            // Clear the stored cart_hash from session
            $session = WC()->session;
            if ($session) {
                $session->__unset('mailerpress_last_cart_hash');
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Handle order created event - mark cart as completed
     *
     * @param int $orderId
     */
    public static function handleOrderCreated($orderId): void
    {
        if (!function_exists('WC')) {
            return;
        }

        try {
            $order = $orderId instanceof \WC_Order ? $orderId : wc_get_order($orderId);
            if (!$order) {
                return;
            }

            // Get customer email
            $customerEmail = self::sanitizeEmail($order->get_billing_email());
            if (empty($customerEmail)) {
                return;
            }

            // Find active carts for this customer and mark them as completed
            $cartRepo = new \MailerPress\Core\Workflows\Repositories\CartTrackingRepository();

            // Get customer ID
            $customerId = $order->get_customer_id();
            $workflowUserId = self::resolveWorkflowUserId($customerId, $customerEmail);

            // Find all active carts for this user
            global $wpdb;
            $table = $wpdb->prefix . 'mailerpress_track_cart';
            $carts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT cart_hash FROM {$table} WHERE user_id = %d AND status = 'ACTIVE'",
                    $workflowUserId
                ),
                ARRAY_A
            );

            foreach ($carts as $cart) {
                $cartRepo->markCartCompleted($cart['cart_hash']);
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Build context from hook parameters
     *
     * Note: This trigger fires immediately when cart is updated.
     * For abandoned cart recovery, you should:
     * 1. Add a DELAY step after this trigger (e.g., wait 1 hour or 24 hours)
     * 2. Add a CONDITION step to check if an order was created (if order_id exists in context, cart was completed)
     * 3. If condition fails (no order), send abandoned cart email
     *
     * @param mixed ...$args Hook arguments (varies by hook)
     * @return array Context data for the workflow
     */
    public static function contextBuilder(...$args): array
    {
        if (!function_exists('WC')) {
            return [];
        }

        try {
            // Ensure WooCommerce is loaded
            if (!did_action('woocommerce_init')) {
                return [];
            }

            $cart = WC()->cart;

            // Strict check: don't trigger if cart is empty or has no items
            if (!$cart || $cart->is_empty() || $cart->get_cart_contents_count() === 0) {
                return [];
            }

            // Double check: verify cart has actual items
            $cartContents = $cart->get_cart();
            if (empty($cartContents) || count($cartContents) === 0) {
                return [];
            }

            // Get customer information
            $customerId = get_current_user_id();
            $customerEmail = '';
            $customerFirstName = '';
            $customerLastName = '';
            $checkoutData = self::extractCheckoutData($args);

            if ($customerId) {
                $user = get_userdata($customerId);
                if ($user) {
                    $customerEmail = $user->user_email ?? '';
                    $customerFirstName = get_user_meta($customerId, 'billing_first_name', true) ?: ($user->first_name ?? '');
                    $customerLastName = get_user_meta($customerId, 'billing_last_name', true) ?: ($user->last_name ?? '');
                }
            } else {
                // For guests, try to get email from session or cookies
                $session = WC()->session;
                if ($session) {
                    $customerEmail = self::sanitizeEmail($session->get('billing_email'))
                        ?: self::sanitizeEmail($session->get('guest_email'))
                        ?: self::sanitizeEmail($_COOKIE['woocommerce_email_' . COOKIEHASH] ?? '');
                    $customerFirstName = $session->get('billing_first_name') ?: '';
                    $customerLastName = $session->get('billing_last_name') ?: '';
                }

                if (empty($customerEmail) && WC()->customer) {
                    $customerEmail = self::sanitizeEmail(WC()->customer->get_billing_email());
                    $customerFirstName = WC()->customer->get_billing_first_name() ?: $customerFirstName;
                    $customerLastName = WC()->customer->get_billing_last_name() ?: $customerLastName;
                }

                // Also try to get from checkout fields if available
                if (!empty($checkoutData['billing_email'])) {
                    $checkoutEmail = self::sanitizeEmail($checkoutData['billing_email']);
                    if (!empty($checkoutEmail)) {
                        $customerEmail = $checkoutEmail;
                        $customerFirstName = sanitize_text_field($checkoutData['billing_first_name'] ?? $customerFirstName);
                        $customerLastName = sanitize_text_field($checkoutData['billing_last_name'] ?? $customerLastName);

                        if ($session) {
                            $session->set('billing_email', $customerEmail);
                            $session->set('guest_email', $customerEmail);
                            $session->set('billing_first_name', $customerFirstName);
                            $session->set('billing_last_name', $customerLastName);
                        }
                    }
                }
            }

            // Get cart items and verify they have valid quantities
            $cartItems = [];
            $totalQuantity = 0;
            foreach ($cart->get_cart() as $cartItemKey => $cartItem) {
                $quantity = (int) ($cartItem['quantity'] ?? 0);

                // Skip items with zero or negative quantity
                if ($quantity <= 0) {
                    continue;
                }

                $product = $cartItem['data'];

                // Get product thumbnail URL with multiple fallbacks
                $thumbnailUrl = '';
                $thumbnailId = $product->get_image_id();

                if ($thumbnailId) {
                    // Try woocommerce_thumbnail size first
                    $thumbnailUrl = wp_get_attachment_image_url($thumbnailId, 'woocommerce_thumbnail');

                    // Fallback to medium size
                    if (empty($thumbnailUrl)) {
                        $thumbnailUrl = wp_get_attachment_image_url($thumbnailId, 'medium');
                    }

                    // Fallback to full size
                    if (empty($thumbnailUrl)) {
                        $thumbnailUrl = wp_get_attachment_image_url($thumbnailId, 'full');
                    }
                }

                // Final fallback: try to get any product image
                if (empty($thumbnailUrl) && method_exists($product, 'get_image')) {
                    $imageHtml = $product->get_image('woocommerce_thumbnail');
                    if (preg_match('/src="([^"]+)"/', $imageHtml, $matches)) {
                        $thumbnailUrl = $matches[1];
                    }
                }

                // If still empty, use WooCommerce placeholder
                if (empty($thumbnailUrl) && function_exists('wc_placeholder_img_src')) {
                    $thumbnailUrl = wc_placeholder_img_src('woocommerce_thumbnail');
                }

                $price = $product->get_price();
                $lineTotal = $cartItem['line_total'] ?? ((float) $price * $quantity);
                $lineSubtotal = $cartItem['line_subtotal'] ?? $lineTotal;

                $cartItems[] = [
                    'cart_item_key' => $cartItemKey,
                    'product_id' => $cartItem['product_id'],
                    'variation_id' => $cartItem['variation_id'] ?? 0,
                    'variation' => $cartItem['variation'] ?? [],
                    'product_name' => $product->get_name(),
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'line_subtotal' => $lineSubtotal,
                    'sku' => $product->get_sku(),
                    'price' => $price,
                    'thumbnail_url' => $thumbnailUrl,
                ];
                $totalQuantity += $quantity;
            }

            // Final check: if no valid items with quantity > 0, don't trigger
            if (empty($cartItems) || $totalQuantity === 0) {
                return [];
            }

            // Guest carts use the same deterministic email-based ID as the workflow job.
            $workflowUserId = self::resolveWorkflowUserId($customerId, $customerEmail);
            if (!$workflowUserId) {
                // No user_id and no email - cannot track this cart
                // Don't create jobs for anonymous users without contact info
                return [];
            }

            // Generate a unique cart hash for this specific cart state
            // This hash changes when cart content changes, but we'll update the same DB entry
            $cartHash = md5(serialize($cartItems) . $customerEmail . $cart->get_cart_hash());

            // Register/update cart in tracking table
            // This will update the existing active cart entry for this user if it exists
            $cartRepo = new \MailerPress\Core\Workflows\Repositories\CartTrackingRepository();
            $cartData = [
                'cart_items' => $cartItems,
                'cart_total' => $cart->get_total(''),
                'cart_subtotal' => $cart->get_subtotal(),
                'cart_currency' => get_woocommerce_currency(),
                'cart_item_count' => $cart->get_cart_contents_count(),
            ];
            $upsertResult = $cartRepo->upsertCart($cartHash, $workflowUserId, $customerEmail, $cartData);

            // Store in context if this is a new cart (first time detected)
            // This will be used by TriggerManager to decide if a job should be created
            $isNewCart = $upsertResult['is_new'] ?? false;

            // Get the current cart_hash from DB (after upsert) to store in session
            // This ensures we have the correct hash even if it was updated
            $currentCart = $cartRepo->getActiveCartByUserId($workflowUserId);
            $sessionCartHash = $currentCart ? $currentCart['cart_hash'] : $cartHash;

            // Store cart_hash in session so we can use it when cart is emptied
            $session = WC()->session;
            if ($session) {
                $session->set('mailerpress_last_cart_hash', $sessionCartHash);
            }

            // Additional safety check: ensure we have an email for tracking
            // This prevents creating jobs for anonymous users without any contact info
            if (empty($customerEmail) && $customerId === 0) {
                return [];
            }

            // Build cart recovery URL
            $cartRecoveryUrl = wc_get_checkout_url();
            if (!empty($cartHash)) {
                $cartRecoveryUrl = add_query_arg('recover_cart', $cartHash, $cartRecoveryUrl);
            }

            $context = [
                'user_id' => $workflowUserId,
                'customer_email' => $customerEmail,
                'customer_first_name' => $customerFirstName,
                'customer_last_name' => $customerLastName,
                'billing_email' => $customerEmail,
                'billing_first_name' => $customerFirstName,
                'billing_last_name' => $customerLastName,
                'cart_items' => $cartItems,
                'cart_total' => $cart->get_total(''),
                'cart_subtotal' => $cart->get_subtotal(),
                'cart_currency' => get_woocommerce_currency(),
                'cart_item_count' => $cart->get_cart_contents_count(),
                'cart_hash' => $cartHash,
                'cart_recovery_url' => $cartRecoveryUrl,
                'cart_updated_at' => current_time('mysql'),
                'is_new_cart' => $isNewCart, // Indicates if this is the first time this cart is detected
                // Store original customer_id for reference (0 for guests)
                'customer_id' => $customerId,
            ];

            return $context;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function resolveWorkflowUserId(int $customerId, string $customerEmail): int
    {
        if ($customerId > 0) {
            return $customerId;
        }

        if ($customerEmail === '') {
            return 0;
        }

        return self::GUEST_USER_ID_OFFSET + abs(crc32(strtolower($customerEmail)));
    }

    private static function sanitizeEmail($email): string
    {
        $email = sanitize_email((string) $email);

        return is_email($email) ? $email : '';
    }

    private static function extractCheckoutData(array $args): array
    {
        $data = [];
        $rawPostData = $args[0] ?? ($_POST['post_data'] ?? '');

        foreach ($args as $arg) {
            if ($arg instanceof \WP_REST_Request) {
                $billingAddress = (array) ($arg['billing_address'] ?? []);

                return [
                    'billing_email' => $billingAddress['email'] ?? '',
                    'billing_first_name' => $billingAddress['first_name'] ?? '',
                    'billing_last_name' => $billingAddress['last_name'] ?? '',
                ];
            }
        }

        if ($rawPostData instanceof \WC_Customer) {
            return [
                'billing_email' => $rawPostData->get_billing_email(),
                'billing_first_name' => $rawPostData->get_billing_first_name(),
                'billing_last_name' => $rawPostData->get_billing_last_name(),
            ];
        }

        if (is_array($rawPostData)) {
            $data = $rawPostData;
        } elseif (is_string($rawPostData) && $rawPostData !== '') {
            parse_str(wp_unslash($rawPostData), $data);
        }

        foreach (['billing_email', 'billing_first_name', 'billing_last_name'] as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = wp_unslash($_POST[$field]);
            }
        }

        return is_array($data) ? $data : [];
    }
}
