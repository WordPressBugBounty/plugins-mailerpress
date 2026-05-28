<?php

namespace MailerPress\Core\Promotions;

defined('ABSPATH') || exit;

use MailerPress\Core\Capabilities;
use MailerPress\Core\ExternalLinks;

class PromotionRegistry
{
    public static function getVisiblePromotions(array $context = []): array
    {
        $context = self::normalizeContext($context);
        $promotions = array_merge(
            //self::getCorePromotions(),
            self::getOptionPromotions(),
            self::getRemotePromotions($context)
        );
        $promotions = apply_filters('mailerpress_promotions', $promotions, $context);

        if (!is_array($promotions)) {
            return [];
        }

        $visible = [];

        foreach ($promotions as $promotion) {
            if (!is_array($promotion)) {
                continue;
            }

            $promotion = self::normalizePromotion($promotion);

            if (!$promotion || !self::matchesContext($promotion, $context)) {
                continue;
            }

            if ($promotion['dismissible'] && PromotionStorage::isDismissed($promotion)) {
                continue;
            }

            unset(
                $promotion['condition'],
                $promotion['capabilities'],
                $promotion['starts_at'],
                $promotion['ends_at'],
                $promotion['audiences'],
                $promotion['target_domains'],
                $promotion['placements'],
                $promotion['screens'],
                $promotion['dismiss_duration_days'],
                $promotion['max_dismissals']
            );

            $visible[] = $promotion;
        }

        usort($visible, static function (array $a, array $b): int {
            return ($b['priority'] ?? 10) <=> ($a['priority'] ?? 10);
        });

        $limit = max(1, (int) ($context['limit'] ?? 1));

        return array_slice($visible, 0, $limit);
    }

    private static function getRemotePromotions(array $context): array
    {
        try {
            return RemotePromotionFeed::getPromotions($context);
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private static function normalizeContext(array $context): array
    {
        $context = apply_filters('mailerpress_promotion_context', $context);

        return [
            'placement' => self::normalizeIdentifier((string) ($context['placement'] ?? 'screen.content.before'), true),
            'screen' => sanitize_key((string) ($context['screen'] ?? 'dashboard')),
            'path' => sanitize_text_field((string) ($context['path'] ?? '')),
            'active_view' => sanitize_key((string) ($context['active_view'] ?? '')),
            'view' => sanitize_key((string) ($context['view'] ?? '')),
            'is_pro' => self::isPluginActive('mailerpress-pro/mailerpress-pro.php'),
            'site_domain' => self::getSiteDomain(),
            'site_url' => esc_url_raw(home_url('/')),
            'limit' => (int) ($context['limit'] ?? 1),
        ];
    }

    private static function getSiteDomain(): string
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (!is_string($host)) {
            return '';
        }

        return self::normalizeDomainPattern($host, false);
    }

    private static function getCorePromotions(): array
    {
        $isProActive = self::isPluginActive('mailerpress-pro/mailerpress-pro.php');
        $isOptinActive = self::isPluginActive('mailerpress-optin/mailerpress-optin.php');
        $isTurboActive = self::isPluginActive('mailerpress-turbo/mailerpress-turbo.php');

        $promotions = [];

        $promotions[] = [
            'id' => 'core-limited-time-v2-coupon',
            'placements' => ['app.top'],
            'screens' => ['*'],
            'badge' => __('Limited time', 'mailerpress'),
            'badge_type' => 'warning',
            'title' => __('Save 30% on MailerPress with coupon mailerpress_v2', 'mailerpress'),
            'message' => __('Use coupon code mailerpress_v2 at checkout to get 30% off for a limited time.', 'mailerpress'),
            'type' => 'upsell',
            'priority' => 95,
            'dismissible' => true,
            'capabilities' => self::getInterfaceCapabilities(),
            'audiences' => ['free'],
            'utm' => [
                'source' => 'mailerpress_app',
                'medium' => 'promotion_banner',
                'campaign' => 'mailerpress_v2_coupon',
                'content' => 'screen_header_after',
            ],
            'action' => [
                'label' => __('Claim 30% off now', 'mailerpress'),
                'url' => ExternalLinks::get('pricing'),
                'target' => '_blank',
            ],
        ];



        $promotions[] = [
            'id' => 'core-pro-upgrade',
            'placements' => ['screen.content.before'],
            'screens' => ['*'],
            'badge' => __('Recommended', 'mailerpress'),
            'badge_type' => 'pro',
            'title' => __('Unlock more growth tools with MailerPress Pro', 'mailerpress'),
            'message' => __('Unlock AI email creation, premium sending providers, automations, segmentation, webhooks, and WordPress/WooCommerce email customization.', 'mailerpress'),
            'type' => 'upsell',
            'priority' => 80,
            'dismissible' => true,
            'audiences' => ['free'],
            'capabilities' => self::getInterfaceCapabilities(),
            'utm' => [
                'source' => 'mailerpress_app',
                'medium' => 'promotion_banner',
                'campaign' => 'pro_upgrade',
                'content' => 'screen_content_before',
            ],
            'action' => [
                'label' => __('View plans', 'mailerpress'),
                'url' => ExternalLinks::get('pricing'),
                'target' => '_blank',
            ],
        ];
        

        return $promotions;
    }

    private static function getInterfaceCapabilities(): array
    {
        return array_merge(['edit_posts'], Capabilities::get_capabilities());
    }

    private static function isPluginActive(string $pluginFile): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active($pluginFile);
    }

    private static function getOptionPromotions(): array
    {
        $promotions = get_option('mailerpress_promotions', []);

        return is_array($promotions) ? $promotions : [];
    }

    private static function normalizePromotion(array $promotion): ?array
    {
        $id = sanitize_key((string) ($promotion['id'] ?? ''));
        if (!$id) {
            return null;
        }

        $utm = self::normalizeUtm($promotion['utm'] ?? []);
        $action = $promotion['action'] ?? null;
        if (is_array($action)) {
            $utm = array_merge($utm, self::normalizeUtm($action['utm'] ?? []));
            $actionUrl = esc_url_raw((string) ($action['url'] ?? ''));

            $action = [
                'label' => sanitize_text_field((string) ($action['label'] ?? '')),
                'url' => self::addUtmToUrl($actionUrl, $utm),
                'target' => sanitize_key((string) ($action['target'] ?? '')),
                'variant' => self::normalizeActionVariant((string) ($action['variant'] ?? '')),
                'icon' => self::normalizeActionIcon((string) ($action['icon'] ?? $action['iconName'] ?? '')),
                'icon_position' => self::normalizeActionIconPosition((string) ($action['icon_position'] ?? $action['iconPosition'] ?? '')),
            ];
        } else {
            $action = null;
        }

        return [
            'id' => $id,
            'placements' => self::normalizePlacementList($promotion['placements'] ?? $promotion['placement'] ?? ['screen.content.before']),
            'screens' => self::normalizeStringList($promotion['screens'] ?? $promotion['screen'] ?? ['*']),
            'title' => sanitize_text_field((string) ($promotion['title'] ?? '')),
            'message' => wp_kses_post((string) ($promotion['message'] ?? '')),
            'badge' => sanitize_text_field((string) ($promotion['badge'] ?? '')),
            'badge_type' => sanitize_key((string) ($promotion['badge_type'] ?? $promotion['badgeType'] ?? '')),
            'icon_svg' => self::sanitizeSvg((string) ($promotion['icon_svg'] ?? $promotion['iconSvg'] ?? '')),
            'type' => sanitize_key((string) ($promotion['type'] ?? 'info')),
            'priority' => (int) ($promotion['priority'] ?? 10),
            'dismissible' => !isset($promotion['dismissible']) || (bool) $promotion['dismissible'],
            'dismiss_duration_days' => max(0, (int) ($promotion['dismiss_duration_days'] ?? $promotion['dismissDurationDays'] ?? 0)),
            'max_dismissals' => max(0, (int) ($promotion['max_dismissals'] ?? $promotion['maxDismissals'] ?? 0)),
            'capabilities' => self::normalizeStringList($promotion['capabilities'] ?? []),
            'audiences' => self::normalizeStringList($promotion['audiences'] ?? $promotion['audience'] ?? ['all']),
            'target_domains' => self::normalizeDomainList($promotion['target_domains'] ?? $promotion['targetDomains'] ?? $promotion['domains'] ?? []),
            'starts_at' => sanitize_text_field((string) ($promotion['starts_at'] ?? '')),
            'ends_at' => sanitize_text_field((string) ($promotion['ends_at'] ?? '')),
            'action' => $action,
            'condition' => $promotion['condition'] ?? null,
        ];
    }

    private static function normalizeUtm(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowed = [
            'source' => 'utm_source',
            'medium' => 'utm_medium',
            'campaign' => 'utm_campaign',
            'term' => 'utm_term',
            'content' => 'utm_content',
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
            'utm_term' => 'utm_term',
            'utm_content' => 'utm_content',
        ];
        $utm = [];

        foreach ($allowed as $inputKey => $queryKey) {
            if (!isset($value[$inputKey])) {
                continue;
            }

            $sanitized = sanitize_text_field((string) $value[$inputKey]);
            if ($sanitized !== '') {
                $utm[$queryKey] = $sanitized;
            }
        }

        return $utm;
    }

    private static function sanitizeSvg(string $svg): string
    {
        $svg = trim($svg);

        if ($svg === '' || !str_contains(strtolower($svg), '<svg')) {
            return '';
        }

        return wp_kses($svg, [
            'svg' => [
                'xmlns' => true,
                'width' => true,
                'height' => true,
                'viewbox' => true,
                'viewBox' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
                'class' => true,
                'role' => true,
                'aria-hidden' => true,
                'focusable' => true,
            ],
            'path' => [
                'd' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
            ],
            'g' => [
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
            ],
            'circle' => [
                'cx' => true,
                'cy' => true,
                'r' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
            ],
            'rect' => [
                'x' => true,
                'y' => true,
                'width' => true,
                'height' => true,
                'rx' => true,
                'ry' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
            ],
            'line' => [
                'x1' => true,
                'y1' => true,
                'x2' => true,
                'y2' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
            ],
            'polyline' => [
                'points' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
            ],
            'polygon' => [
                'points' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linejoin' => true,
            ],
            'ellipse' => [
                'cx' => true,
                'cy' => true,
                'rx' => true,
                'ry' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
            ],
        ]);
    }

    private static function addUtmToUrl(string $url, array $utm): string
    {
        if (!$url || empty($utm)) {
            return $url;
        }

        return esc_url_raw(add_query_arg($utm, $url));
    }

    private static function normalizeActionVariant(string $variant): string
    {
        $variant = sanitize_key($variant);

        return in_array($variant, ['primary', 'secondary', 'tertiary', 'link'], true) ? $variant : '';
    }

    private static function normalizeActionIcon(string $icon): string
    {
        $icon = trim($icon);

        if ($icon === '' || strtolower($icon) === 'none') {
            return '';
        }

        $icon = preg_replace('/[^A-Za-z0-9_$]/', '', $icon);

        return is_string($icon) ? $icon : '';
    }

    private static function normalizeActionIconPosition(string $position): string
    {
        $position = sanitize_key($position);

        return in_array($position, ['left', 'right'], true) ? $position : 'left';
    }

    private static function normalizeStringList(mixed $value): array
    {
        return self::normalizeIdentifierList($value, false);
    }

    private static function normalizeIdentifierList(mixed $value, bool $allowDot): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item) use ($allowDot): string {
            return self::normalizeIdentifier((string) $item, $allowDot);
        }, $value)));
    }

    private static function normalizePlacementList(mixed $value): array
    {
        return self::normalizeIdentifierList($value, true);
    }

    private static function normalizeIdentifier(string $value, bool $allowDot): string
    {
        if (trim($value) === '*') {
            return '*';
        }

        $pattern = $allowDot ? '/[^a-z0-9_.-]/' : '/[^a-z0-9_-]/';

        return preg_replace($pattern, '', strtolower($value)) ?: '';
    }

    private static function normalizeDomainList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item): string {
            return self::normalizeDomainPattern((string) $item);
        }, $value)));
    }

    private static function normalizeDomainPattern(string $value, bool $allowWildcard = true): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $host = wp_parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : '';
        } else {
            $value = preg_replace('/[\/?#].*$/', '', $value);
        }

        $value = preg_replace('/^www\./', '', (string) $value);
        $value = trim($value, ". \t\n\r\0\x0B");

        if ($allowWildcard && str_starts_with($value, '*.')) {
            $domain = self::normalizeDomainPattern(substr($value, 2), false);

            return $domain !== '' ? '*.' . $domain : '';
        }

        $value = preg_replace('/:\d+$/', '', $value);
        $value = preg_replace('/[^a-z0-9.-]/', '', (string) $value);
        $value = trim((string) $value, '.');

        if ($value === '' || !str_contains($value, '.')) {
            return '';
        }

        return preg_replace('/\.+/', '.', $value) ?: '';
    }

    private static function matchesContext(array $promotion, array $context): bool
    {
        if (!self::matchesList($promotion['placements'], $context['placement'])) {
            return false;
        }

        if (!self::matchesList($promotion['screens'], $context['screen'])) {
            return false;
        }

        if (!self::matchesCapabilities($promotion['capabilities'])) {
            return false;
        }

        if (!self::matchesAudience($promotion['audiences'], (bool) $context['is_pro'])) {
            return false;
        }

        if (!self::matchesDomain($promotion['target_domains'], (string) $context['site_domain'], (string) $context['site_url'])) {
            return false;
        }

        if (!self::isInDateWindow($promotion)) {
            return false;
        }

        if (is_callable($promotion['condition'])) {
            return (bool) call_user_func($promotion['condition'], $promotion, $context);
        }

        if (is_bool($promotion['condition'])) {
            return $promotion['condition'];
        }

        return true;
    }

    private static function matchesList(array $values, string $needle): bool
    {
        return in_array('*', $values, true) || in_array($needle, $values, true);
    }

    private static function matchesCapabilities(array $capabilities): bool
    {
        if (empty($capabilities)) {
            return true;
        }

        foreach ($capabilities as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesAudience(array $audiences, bool $isPro): bool
    {
        if (empty($audiences) || in_array('all', $audiences, true) || in_array('*', $audiences, true)) {
            return true;
        }

        return $isPro
            ? in_array('pro', $audiences, true)
            : in_array('free', $audiences, true);
    }

    private static function matchesDomain(array $targetDomains, string $siteDomain, string $siteUrl): bool
    {
        if (empty($targetDomains)) {
            return true;
        }

        $siteDomain = self::normalizeDomainPattern($siteDomain, false);

        if ($siteDomain === '' && $siteUrl !== '') {
            $siteDomain = self::normalizeDomainPattern($siteUrl, false);
        }

        if ($siteDomain === '') {
            return false;
        }

        foreach ($targetDomains as $targetDomain) {
            if (str_starts_with($targetDomain, '*.')) {
                $suffix = substr($targetDomain, 2);
                if ($siteDomain !== $suffix && str_ends_with($siteDomain, '.' . $suffix)) {
                    return true;
                }

                continue;
            }

            if ($siteDomain === $targetDomain) {
                return true;
            }
        }

        return false;
    }

    private static function isInDateWindow(array $promotion): bool
    {
        $now = current_time('timestamp');

        if (!empty($promotion['starts_at']) && strtotime($promotion['starts_at']) > $now) {
            return false;
        }

        if (!empty($promotion['ends_at']) && strtotime($promotion['ends_at']) < $now) {
            return false;
        }

        return true;
    }
}
