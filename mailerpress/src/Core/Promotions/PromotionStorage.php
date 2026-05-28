<?php

namespace MailerPress\Core\Promotions;

defined('ABSPATH') || exit;

class PromotionStorage
{
    private const OPTION_NAME = 'mailerpress_dismissed_promotions';

    public static function getDismissedPromotions(): array
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return [];
        }

        $all_dismissed = get_option(self::OPTION_NAME, []);
        if (!is_array($all_dismissed)) {
            return [];
        }

        return isset($all_dismissed[$user_id]) && is_array($all_dismissed[$user_id])
            ? $all_dismissed[$user_id]
            : [];
    }

    public static function isDismissed(string|array $promotion): bool
    {
        $promotion_id = is_array($promotion) ? (string) ($promotion['id'] ?? '') : $promotion;
        if ($promotion_id === '') {
            return false;
        }

        $dismissed = self::getDismissedPromotions();
        if (!isset($dismissed[$promotion_id]) || !is_array($dismissed[$promotion_id])) {
            return false;
        }

        $record = $dismissed[$promotion_id];
        $dismissed_at = (int) ($record['dismissed_at'] ?? 0);
        $dismiss_count = max(1, (int) ($record['dismiss_count'] ?? 1));
        $dismiss_duration_days = is_array($promotion) ? max(0, (int) ($promotion['dismiss_duration_days'] ?? 0)) : 0;
        $max_dismissals = is_array($promotion) ? max(0, (int) ($promotion['max_dismissals'] ?? 0)) : 0;

        if ($max_dismissals > 0 && $dismiss_count >= $max_dismissals) {
            return true;
        }

        if ($dismiss_duration_days <= 0) {
            return true;
        }

        return $dismissed_at > 0 && (time() - $dismissed_at) < ($dismiss_duration_days * DAY_IN_SECONDS);
    }

    public static function dismiss(string $promotion_id): bool
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $all_dismissed = get_option(self::OPTION_NAME, []);
        if (!is_array($all_dismissed)) {
            $all_dismissed = [];
        }

        if (!isset($all_dismissed[$user_id]) || !is_array($all_dismissed[$user_id])) {
            $all_dismissed[$user_id] = [];
        }

        $previous = isset($all_dismissed[$user_id][$promotion_id]) && is_array($all_dismissed[$user_id][$promotion_id])
            ? $all_dismissed[$user_id][$promotion_id]
            : [];

        $all_dismissed[$user_id][$promotion_id] = [
            'dismissed_at' => time(),
            'dismiss_count' => ((int) ($previous['dismiss_count'] ?? 0)) + 1,
        ];

        return update_option(self::OPTION_NAME, $all_dismissed);
    }

    public static function clearDismissedPromotion(string $promotion_id, ?int $user_id = null): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id || $promotion_id === '') {
            return false;
        }

        $all_dismissed = get_option(self::OPTION_NAME, []);
        if (!is_array($all_dismissed) || empty($all_dismissed[$user_id]) || !is_array($all_dismissed[$user_id])) {
            return true;
        }

        unset($all_dismissed[$user_id][$promotion_id]);

        return update_option(self::OPTION_NAME, $all_dismissed);
    }
}
