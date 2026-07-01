<?php

namespace MailerPress\Core\Workflows\Services;

class TriggerConditionChecker
{
    /**
     * Check trigger-specific conditions.
     *
     * @param string $triggerKey The trigger key
     * @param array $settings The trigger settings
     * @param int $userId The user ID
     * @param array $context The trigger context
     * @return bool Whether conditions pass
     */
    public function check(string $triggerKey, array $settings, int $userId, array $context): bool
    {
        return match ($triggerKey) {
            'woocommerce_order_status_changed' => $this->checkWoocommerceOrderStatusChanged($settings, $context),
            'fluentcart_order_created' => $this->checkFluentCartOrderCreated($settings, $context),
            'fluentcart_order_refunded' => $this->checkFluentCartOrderRefunded($settings, $context),
            'surecart_subscription_status_changed' => $this->checkSurecartSubscriptionStatusChanged($settings, $context),
            'mailerpress_contact_optin' => $this->checkMailerpressContactOptin($settings, $context),
            'post_published' => $this->checkPostPublished($settings, $context),
            'user_role_changed' => $this->checkUserRoleChanged($settings, $context),
            'user_meta_updated' => $this->checkUserMetaUpdated($settings, $context),
            'comment_posted' => $this->checkCommentPosted($settings, $context),
            'contact_subscribed', 'user_login', 'profile_updated' => $this->checkUserRoleFilter($settings, $context),
            'woocommerce_abandoned_cart' => $this->checkWoocommerceAbandonedCart($settings, $context),
            'tag_added' => $this->checkTagAdded($settings, $context),
            'list_added' => $this->checkListAdded($settings, $context),
            'woocommerce_subscription_status_changed' => $this->checkWoocommerceSubscriptionStatusChanged($settings, $context),
            'contact_custom_field_updated' => $this->checkContactCustomFieldUpdated($settings, $context),
            'surecart_order_created' => $this->checkSurecartOrderCreated($settings, $context),
            'woocommerce_product_purchased' => $this->checkWoocommerceProductPurchased($settings, $context),
            'optin_form_submitted', 'optin_lead_magnet_delivered' => $this->checkOptinFormTrigger($settings, $context),
            default => true,
        };
    }

    private function checkOptinFormTrigger(array $settings, array $context): bool
    {
        $formId = $settings['form_id'] ?? null;

        if (is_array($formId)) {
            $formId = reset($formId);
        }

        if ($formId !== null && $formId !== '' && isset($context['form_id'])) {
            return (int) $context['form_id'] === (int) $formId;
        }

        return true;
    }

    private function checkWoocommerceOrderStatusChanged(array $settings, array $context): bool
    {
        $orderStatus = $settings['order_status'] ?? null;
        if ($orderStatus && isset($context['order_status'])) {
            if ($context['order_status'] !== $orderStatus) {
                return false;
            }
        }
        return true;
    }

    private function checkFluentCartOrderCreated(array $settings, array $context): bool
    {
        $orderStatus = $settings['order_status'] ?? null;
        if ($orderStatus && isset($context['order_status'])) {
            if ($context['order_status'] !== $orderStatus) {
                return false;
            }
        }

        $paymentStatus = $settings['payment_status'] ?? null;
        if ($paymentStatus && isset($context['payment_status'])) {
            if ($context['payment_status'] !== $paymentStatus) {
                return false;
            }
        }

        return true;
    }

    private function checkFluentCartOrderRefunded(array $settings, array $context): bool
    {
        $refundType = $settings['refund_type'] ?? null;
        if ($refundType && isset($context['refund_type'])) {
            if ($context['refund_type'] !== $refundType) {
                return false;
            }
        }
        return true;
    }

    private function checkSurecartSubscriptionStatusChanged(array $settings, array $context): bool
    {
        $newStatus = $settings['new_status'] ?? null;
        if ($newStatus && !empty($newStatus) && isset($context['subscription_status'])) {
            if ($context['subscription_status'] !== $newStatus) {
                return false;
            }
        }
        return true;
    }

    private function checkMailerpressContactOptin(array $settings, array $context): bool
    {
        // Check subscription status filter
        $subscriptionStatus = $settings['subscription_status'] ?? null;
        if ($subscriptionStatus && isset($context['subscription_status'])) {
            if ($context['subscription_status'] !== $subscriptionStatus) {
                return false;
            }
        }

        // Check lists filter
        if (!$this->checkArrayIntersection($settings, 'lists', $context, 'lists')) {
            return false;
        }

        // Check tags filter
        if (!$this->checkArrayIntersection($settings, 'tags', $context, 'tags')) {
            return false;
        }

        return true;
    }

    private function checkPostPublished(array $settings, array $context): bool
    {
        // Check post type filter
        $postType = $settings['post_type'] ?? null;
        if ($postType && isset($context['post_type'])) {
            if ($context['post_type'] !== $postType) {
                return false;
            }
        }

        // Check post category filter
        $categoryId = $settings['post_category'] ?? null;
        if ($categoryId && isset($context['post_categories'])) {
            $categoryId = (int) $categoryId;
            if (!in_array($categoryId, $context['post_categories'], true)) {
                return false;
            }
        }

        // Check post meta filters
        $metaKey = $settings['post_meta_key'] ?? null;
        $metaValue = $settings['post_meta_value'] ?? null;
        if ($metaKey && isset($context['post_meta'])) {
            $postMeta = $context['post_meta'];
            if (!isset($postMeta[$metaKey])) {
                return false;
            }
            if ($metaValue !== null && $metaValue !== '') {
                $actualValue = $postMeta[$metaKey];
                if (is_array($actualValue)) {
                    $actualValue = $actualValue[0] ?? '';
                }
                if ($actualValue != $metaValue) {
                    return false;
                }
            }
        }

        return true;
    }

    private function checkUserRoleChanged(array $settings, array $context): bool
    {
        $roleFilter = $settings['role'] ?? null;
        if ($roleFilter && isset($context['new_role'])) {
            if ($context['new_role'] !== $roleFilter) {
                return false;
            }
        }
        return true;
    }

    private function checkUserMetaUpdated(array $settings, array $context): bool
    {
        $metaKeyFilter = $settings['meta_key'] ?? null;
        if ($metaKeyFilter && isset($context['meta_key'])) {
            if ($context['meta_key'] !== $metaKeyFilter) {
                return false;
            }
        }
        return true;
    }

    private function checkCommentPosted(array $settings, array $context): bool
    {
        $postIdFilter = $settings['post_id'] ?? null;
        if ($postIdFilter && isset($context['post_id'])) {
            $filterIds = is_array($postIdFilter)
                ? array_map('intval', $postIdFilter)
                : [(int) $postIdFilter];
            if (!in_array((int) $context['post_id'], $filterIds, true)) {
                return false;
            }
        }
        return true;
    }

    private function checkUserRoleFilter(array $settings, array $context): bool
    {
        $roleFilter = $settings['user_role'] ?? null;
        if ($roleFilter && isset($context['user_role'])) {
            if ($context['user_role'] !== $roleFilter) {
                return false;
            }
        }
        return true;
    }

    private function checkWoocommerceAbandonedCart(array $settings, array $context): bool
    {
        $minimumCartValue = $settings['minimum_cart_value'] ?? null;
        if ($minimumCartValue && isset($context['cart_total'])) {
            $cartTotal = (float) $context['cart_total'];
            $minimumValue = (float) $minimumCartValue;
            if ($cartTotal < $minimumValue) {
                return false;
            }
        }

        $requireEmail = $settings['require_email'] ?? false;
        if ($requireEmail && empty($context['customer_email'])) {
            return false;
        }

        return true;
    }

    private function checkTagAdded(array $settings, array $context): bool
    {
        $requiredTagId = $settings['tag_id'] ?? null;
        if ($requiredTagId && isset($context['tag_id'])) {
            $requiredTagId = (int) $requiredTagId;
            $contextTagId = (int) $context['tag_id'];
            if ($contextTagId !== $requiredTagId) {
                return false;
            }
        }
        return true;
    }

    private function checkListAdded(array $settings, array $context): bool
    {
        $requiredListId = $settings['list_id'] ?? null;
        if ($requiredListId && isset($context['list_id'])) {
            $requiredListId = (int) $requiredListId;
            $contextListId = (int) $context['list_id'];
            if ($contextListId !== $requiredListId) {
                return false;
            }
        }
        return true;
    }

    private function checkWoocommerceSubscriptionStatusChanged(array $settings, array $context): bool
    {
        $subscriptionStatus = $settings['subscription_status'] ?? null;
        if ($subscriptionStatus && isset($context['subscription_status'])) {
            if ($context['subscription_status'] !== $subscriptionStatus) {
                return false;
            }
        }
        return true;
    }

    private function checkContactCustomFieldUpdated(array $settings, array $context): bool
    {
        $requiredFieldKey = $settings['field_key'] ?? null;
        if ($requiredFieldKey && isset($context['field_key'])) {
            if ($context['field_key'] !== $requiredFieldKey) {
                return false;
            }
        }
        return true;
    }

    private function checkSurecartOrderCreated(array $settings, array $context): bool
    {
        $requiredProductId = $settings['product_id'] ?? null;
        if (!$requiredProductId || empty($requiredProductId)) {
            return true;
        }

        $purchasedProducts = $context['purchased_products'] ?? [];
        $lineItems = $context['line_items'] ?? [];

        if (empty($purchasedProducts) && empty($lineItems)) {
            return false;
        }

        // Collect all product IDs from the order
        $orderProductIds = [];
        foreach ($purchasedProducts as $product) {
            $productId = $product['product_id'] ?? '';
            if (!empty($productId)) {
                $orderProductIds[] = (string) $productId;
            }
        }
        foreach ($lineItems as $item) {
            $productId = $item['product_id'] ?? '';
            if (!empty($productId) && !in_array((string) $productId, $orderProductIds, true)) {
                $orderProductIds[] = (string) $productId;
            }
        }

        // Also check the single product_id field for backward compatibility
        $contextProductId = $context['product_id'] ?? '';
        if (!empty($contextProductId) && !in_array((string) $contextProductId, $orderProductIds, true)) {
            $orderProductIds[] = (string) $contextProductId;
        }

        $orderProductIds = array_filter($orderProductIds);
        if (empty($orderProductIds)) {
            return false;
        }

        // SureCart product IDs are strings
        $requiredProductIds = is_array($requiredProductId)
            ? array_map('strval', $requiredProductId)
            : [(string) $requiredProductId];

        $requiredProductIds = array_filter($requiredProductIds);
        if (empty($requiredProductIds)) {
            return false;
        }

        foreach ($orderProductIds as $orderProductId) {
            if (in_array($orderProductId, $requiredProductIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function checkWoocommerceProductPurchased(array $settings, array $context): bool
    {
        $requiredProducts = $settings['products'] ?? null;
        $requiredCategories = $settings['product_categories'] ?? null;
        $requiredTags = $settings['product_tags'] ?? null;

        // If no filters are configured, allow all products
        if (empty($requiredProducts) && empty($requiredCategories) && empty($requiredTags)) {
            return true;
        }

        $purchasedProducts = $context['purchased_products'] ?? [];
        $orderItems = $context['order_items'] ?? [];

        if (empty($purchasedProducts) && empty($orderItems)) {
            return false;
        }

        // Collect all product IDs from the order
        $orderProductIds = [];
        foreach ($purchasedProducts as $product) {
            $orderProductIds[] = (int) ($product['product_id'] ?? 0);
        }
        foreach ($orderItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId && !in_array($productId, $orderProductIds, true)) {
                $orderProductIds[] = $productId;
            }
        }

        $orderProductIds = array_filter($orderProductIds);
        if (empty($orderProductIds)) {
            return false;
        }

        $matchesFilter = false;

        // Check if any product matches the required products
        if (!empty($requiredProducts)) {
            $requiredProductIds = $this->extractTokenIds($requiredProducts);

            foreach ($orderProductIds as $productId) {
                if (in_array($productId, $requiredProductIds, true)) {
                    $matchesFilter = true;
                    break;
                }
            }
        }

        // Check if any product matches the required categories
        if (!$matchesFilter && !empty($requiredCategories)) {
            $requiredCategoryIds = $this->extractTokenIds($requiredCategories);

            foreach ($orderProductIds as $productId) {
                $productCategories = \wp_get_post_terms($productId, 'product_cat', ['fields' => 'ids']);
                if (!is_wp_error($productCategories)) {
                    foreach ($productCategories as $categoryId) {
                        if (in_array($categoryId, $requiredCategoryIds, true)) {
                            $matchesFilter = true;
                            break 2;
                        }
                    }
                }
            }
        }

        // Check if any product matches the required tags
        if (!$matchesFilter && !empty($requiredTags)) {
            $requiredTagIds = $this->extractTokenIds($requiredTags);

            foreach ($orderProductIds as $productId) {
                $productTags = \wp_get_post_terms($productId, 'product_tag', ['fields' => 'ids']);
                if (!is_wp_error($productTags)) {
                    foreach ($productTags as $tagId) {
                        if (in_array($tagId, $requiredTagIds, true)) {
                            $matchesFilter = true;
                            break 2;
                        }
                    }
                }
            }
        }

        if (!$matchesFilter) {
            return false;
        }

        return true;
    }

    /**
     * Helper: extract integer IDs from a token field value.
     * Token fields store items as either plain IDs or objects with a 'value' key
     * e.g. [{'value': '780', 'label': 'Magret de canard'}] or ['780'] or [780]
     */
    private function extractTokenIds($items): array
    {
        if (!is_array($items)) {
            return [(int) $items];
        }

        $ids = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $ids[] = (int) ($item['value'] ?? $item['id'] ?? 0);
            } else {
                $ids[] = (int) $item;
            }
        }

        return array_filter($ids);
    }

    /**
     * Helper: check array intersection between settings and context.
     * Returns false if settings requires values but context doesn't match.
     */
    private function checkArrayIntersection(array $settings, string $settingsKey, array $context, string $contextKey): bool
    {
        $required = $settings[$settingsKey] ?? null;
        if (!$required || empty($required)) {
            return true;
        }

        $actual = $context[$contextKey] ?? [];
        if (empty($actual)) {
            return false;
        }

        if (!is_array($required)) {
            $required = [$required];
        }
        if (!is_array($actual)) {
            $actual = [$actual];
        }

        $required = array_map('intval', $required);
        $actual = array_map('intval', $actual);

        return !empty(array_intersect($required, $actual));
    }
}
