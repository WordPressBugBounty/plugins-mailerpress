<?php

namespace MailerPress\Core\Workflows\Services;

/**
 * Generates WooCommerce coupons dynamically for automation emails
 */
class CouponGenerator
{
    /**
     * Generate a unique WooCommerce coupon
     *
     * @param array $settings Coupon generation settings from block config
     * @param array $context Workflow context (user_id, email, etc.)
     * @return string|null Generated coupon code or null on failure
     */
    public function generate(array $settings, array $context): ?string
    {
        if (!$this->isWooCommerceActive()) {
            return null;
        }

        $defaults = [
            'discountType' => 'percent',
            'discountAmount' => 10,
            'freeShipping' => false,
            'expiryDays' => 30,
            'minimumAmount' => 0,
            'maximumAmount' => 0,
            'individualUse' => true,
            'excludeSaleItems' => false,
            'usageLimit' => 1,
            'usageLimitPerUser' => 1,
            'prefix' => 'AUTO',
            'allowedEmails' => true, // Restrict to recipient email
            'restrictToProducts' => false,
            'couponProductIds' => [],
            'useSubscriptionCoupon' => false,
            'subscriptionDiscountTarget' => 'first_payment',
            'subscriptionDiscountType' => 'percent',
            'subscriptionPaymentCount' => 1,
        ];

        $settings = array_merge($defaults, $settings);

        if ($this->isTruthy($settings['useSubscriptionCoupon']) && !$this->isWooCommerceSubscriptionsActive()) {
            return null;
        }

        $settings = $this->normalizeSubscriptionCouponSettings($settings);

        // Generate unique coupon code
        $couponCode = $this->generateCouponCode($settings['prefix'], $context);

        // Create WooCommerce coupon
        $couponId = $this->createWooCommerceCoupon($couponCode, $settings, $context);

        if (!$couponId) {
            return null;
        }

        // Store coupon metadata for tracking
        $this->storeCouponMetadata($couponId, $context);

        return $couponCode;
    }

    /**
     * Expiry date of a coupon valid for the given number of days, in the site timezone.
     */
    public static function getExpiryDate(int $expiryDays): \DateTimeImmutable
    {
        return current_datetime()->modify('+' . $expiryDays . ' days');
    }

    /**
     * Generate unique coupon code
     */
    private function generateCouponCode(string $prefix, array $context): string
    {
        $timestamp = time();
        $randomPart = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

        // Include user-specific component if available
        $userPart = '';
        if (isset($context['user_id']) && $context['user_id']) {
            $userPart = substr(md5($context['user_id']), 0, 3);
        }

        $code = $prefix . $randomPart . $userPart;

        // Ensure uniqueness
        $counter = 1;
        $originalCode = $code;
        while ($this->couponCodeExists($code)) {
            $code = $originalCode . $counter;
            $counter++;
        }

        return strtoupper($code);
    }

    /**
     * Check if coupon code already exists
     */
    private function couponCodeExists(string $code): bool
    {
        $existingCoupon = get_page_by_title($code, OBJECT, 'shop_coupon');
        return !empty($existingCoupon);
    }

    /**
     * Create WooCommerce coupon
     */
    private function createWooCommerceCoupon(string $couponCode, array $settings, array $context): ?int
    {
        $coupon = new \WC_Coupon();

        // Basic settings
        $coupon->set_code($couponCode);
        $coupon->set_discount_type((string)$settings['discountType']);
        $coupon->set_amount((float)$settings['discountAmount']);
        $coupon->set_individual_use($this->isTruthy($settings['individualUse']));
        $coupon->set_usage_limit(max(1, (int)$settings['usageLimit']));
        $coupon->set_usage_limit_per_user(max(1, (int)$settings['usageLimitPerUser']));
        $coupon->set_free_shipping($this->isTruthy($settings['freeShipping']));
        $coupon->set_exclude_sale_items($this->isTruthy($settings['excludeSaleItems']));

        // Expiry date
        if ((int)$settings['expiryDays'] > 0) {
            // WC_Coupon::set_date_expires() only accepts a WC_DateTime, a timestamp or a date string:
            // a plain \DateTime is rejected as an invalid date and the coupon never expires.
            $coupon->set_date_expires(self::getExpiryDate((int)$settings['expiryDays'])->getTimestamp());
        }

        // Minimum/maximum amounts
        if ((float)$settings['minimumAmount'] > 0) {
            $coupon->set_minimum_amount((float)$settings['minimumAmount']);
        }
        if ((float)$settings['maximumAmount'] > 0) {
            $coupon->set_maximum_amount((float)$settings['maximumAmount']);
        }

        // Restrict to recipient email
        if ($this->isTruthy($settings['allowedEmails']) && isset($context['email']) && !empty($context['email'])) {
            $coupon->set_email_restrictions([$context['email']]);
        }

        $productIds = $this->normalizeCouponProductIds($settings['couponProductIds'] ?? []);
        if ($this->isTruthy($settings['restrictToProducts']) && !empty($productIds)) {
            $coupon->set_product_ids($productIds);
        }

        // Description
        $description = sprintf(
            __('Auto-generated coupon for %s via MailerPress automation', 'mailerpress'),
            $context['email'] ?? 'customer'
        );
        $coupon->set_description($description);

        try {
            $couponId = $coupon->save();
            if ($couponId) {
                $this->storeSubscriptionCouponMetadata($couponId, $settings);
                if (!empty($productIds)) {
                    update_post_meta($couponId, '_mailerpress_coupon_product_ids', $productIds);
                }
            }
            return $couponId ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Map MailerPress subscription settings to WooCommerce Subscriptions coupon types.
     */
    private function normalizeSubscriptionCouponSettings(array $settings): array
    {
        if (!$this->isTruthy($settings['useSubscriptionCoupon'])) {
            return $settings;
        }

        $target = in_array($settings['subscriptionDiscountTarget'], [
            'first_payment',
            'all_payments',
            'limited_payments',
            'sign_up_fee',
        ], true) ? $settings['subscriptionDiscountTarget'] : 'first_payment';

        $isFixedAmount = 'fixed' === $settings['subscriptionDiscountType'];

        if ('sign_up_fee' === $target) {
            $settings['discountType'] = $isFixedAmount ? 'sign_up_fee' : 'sign_up_fee_percent';
            $settings['subscriptionPaymentCount'] = 0;

            return $settings;
        }

        $settings['discountType'] = $isFixedAmount ? 'recurring_fee' : 'recurring_percent';

        if ('first_payment' === $target) {
            $settings['subscriptionPaymentCount'] = 1;
        } elseif ('all_payments' === $target) {
            $settings['subscriptionPaymentCount'] = 0;
        } else {
            $settings['subscriptionPaymentCount'] = max(1, (int)$settings['subscriptionPaymentCount']);
        }

        return $settings;
    }

    /**
     * Store WooCommerce Subscriptions metadata expected by its limited recurring coupon manager.
     */
    private function storeSubscriptionCouponMetadata(int $couponId, array $settings): void
    {
        if (!$this->isTruthy($settings['useSubscriptionCoupon'])) {
            return;
        }

        update_post_meta($couponId, '_mailerpress_subscription_coupon', true);
        update_post_meta($couponId, '_mailerpress_subscription_discount_target', $settings['subscriptionDiscountTarget']);
        update_post_meta($couponId, '_mailerpress_subscription_discount_type', $settings['subscriptionDiscountType']);

        if ($this->isRecurringSubscriptionDiscountType((string)$settings['discountType'])) {
            update_post_meta($couponId, '_wcs_number_payments', (string)max(0, (int)$settings['subscriptionPaymentCount']));
        } else {
            delete_post_meta($couponId, '_wcs_number_payments');
        }
    }

    private function isRecurringSubscriptionDiscountType(string $discountType): bool
    {
        return in_array($discountType, ['recurring_fee', 'recurring_percent'], true);
    }

    private function isTruthy($value): bool
    {
        return true === $value || 'true' === $value || 1 === $value || '1' === $value;
    }

    private function normalizeCouponProductIds($value): array
    {
        if (!is_array($value)) {
            $value = explode(',', (string)$value);
        }

        $ids = array_map('absint', $value);
        $ids = array_filter($ids, static function ($id) {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    /**
     * Store metadata for tracking and analytics
     */
    private function storeCouponMetadata(int $couponId, array $context): void
    {
        update_post_meta($couponId, '_mailerpress_generated', true);
        update_post_meta($couponId, '_mailerpress_generation_date', current_time('mysql'));

        if (isset($context['automation_id'])) {
            update_post_meta($couponId, '_mailerpress_automation_id', $context['automation_id']);
        }

        if (isset($context['user_id'])) {
            update_post_meta($couponId, '_mailerpress_user_id', $context['user_id']);
        }

        if (isset($context['email'])) {
            update_post_meta($couponId, '_mailerpress_recipient_email', $context['email']);
        }
    }

    /**
     * Check if WooCommerce is active
     */
    private function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }

    private function isWooCommerceSubscriptionsActive(): bool
    {
        return class_exists('WC_Subscriptions_Coupon');
    }

    /**
     * Get or generate coupon for a specific user in a workflow execution
     * This ensures one coupon per user per automation execution
     *
     * @param array $settings Coupon settings
     * @param array $context Workflow context
     * @return string|null Coupon code
     */
    public function getOrGenerateCoupon(array $settings, array $context): ?string
    {
        // Check if we already generated a coupon for this execution
        if (isset($context['job_id'])) {
            $existingCoupon = $this->getExistingCouponForJob($context['job_id']);
            if ($existingCoupon) {
                return $existingCoupon;
            }
        }

        // Generate new coupon
        $couponCode = $this->generate($settings, $context);

        // Store reference for this job
        if ($couponCode && isset($context['job_id'])) {
            $this->storeCouponForJob($context['job_id'], $couponCode);
        }

        return $couponCode;
    }

    /**
     * Get existing coupon for a job by checking coupon post meta
     */
    private function getExistingCouponForJob(int $jobId): ?string
    {
        global $wpdb;

        // Query coupons with this job_id in meta
        $couponId = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_coupon'
            AND pm.meta_key = '_mailerpress_job_id'
            AND pm.meta_value = %d
            LIMIT 1",
            $jobId
        ));

        if ($couponId) {
            $coupon = new \WC_Coupon($couponId);
            return $coupon->get_code();
        }

        return null;
    }

    /**
     * Store coupon code reference using coupon post meta
     */
    private function storeCouponForJob(int $jobId, string $couponCode): void
    {
        $couponId = $this->getCouponIdByCode($couponCode);
        if ($couponId) {
            update_post_meta($couponId, '_mailerpress_job_id', $jobId);
        }
    }

    /**
     * Get coupon ID by code
     */
    private function getCouponIdByCode(string $code): ?int
    {
        $couponPost = get_page_by_title($code, OBJECT, 'shop_coupon');
        return $couponPost ? $couponPost->ID : null;
    }
}
