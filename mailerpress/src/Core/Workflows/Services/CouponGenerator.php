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
        ];

        $settings = array_merge($defaults, $settings);

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
        $coupon->set_discount_type($settings['discountType']); // 'percent', 'fixed_cart', 'fixed_product'
        $coupon->set_amount($settings['discountAmount']);
        $coupon->set_individual_use($settings['individualUse']);
        $coupon->set_usage_limit($settings['usageLimit']);
        $coupon->set_usage_limit_per_user($settings['usageLimitPerUser']);
        $coupon->set_free_shipping($settings['freeShipping']);
        $coupon->set_exclude_sale_items($settings['excludeSaleItems']);

        // Expiry date
        if ($settings['expiryDays'] > 0) {
            $expiryDate = new \DateTime();
            $expiryDate->modify('+' . $settings['expiryDays'] . ' days');
            $coupon->set_date_expires($expiryDate);
        }

        // Minimum/maximum amounts
        if ($settings['minimumAmount'] > 0) {
            $coupon->set_minimum_amount($settings['minimumAmount']);
        }
        if ($settings['maximumAmount'] > 0) {
            $coupon->set_maximum_amount($settings['maximumAmount']);
        }

        // Restrict to recipient email
        if ($settings['allowedEmails'] && isset($context['email']) && !empty($context['email'])) {
            $coupon->set_email_restrictions([$context['email']]);
        }

        // Description
        $description = sprintf(
            __('Auto-generated coupon for %s via MailerPress automation', 'mailerpress'),
            $context['email'] ?? 'customer'
        );
        $coupon->set_description($description);

        try {
            $couponId = $coupon->save();
            return $couponId ?: null;
        } catch (\Exception $e) {
            return null;
        }
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
