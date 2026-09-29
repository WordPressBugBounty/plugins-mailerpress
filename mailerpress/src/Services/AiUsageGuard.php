<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;
use WP_Error;

class AiUsageGuard
{
    private const OPTION_KEY = 'mailerpress_ai_model_settings';
    private const DEFAULT_CURRENCY = 'usd';

    public static function preflight(string $provider, string $model, string $feature, array $estimate = []): ?WP_Error
    {
        $settings = self::getSettings();
        $usageConfig = self::getUsageConfig($settings);

        if (empty($usageConfig['enabled'])) {
            return null;
        }

        if (!self::tableExists()) {
            if (($usageConfig['enforcement'] ?? 'block') !== 'block') {
                return null;
            }

            return new WP_Error(
                'ai_usage_unavailable',
                __('AI usage limits are enabled, but the usage tracking table is not available yet.', 'mailerpress'),
                ['status' => 503]
            );
        }

        $projectedTokens = (int)(
            $estimate['tokens']
            ?? $estimate['total_tokens']
            ?? ((int)($estimate['prompt_tokens'] ?? 0) + (int)($estimate['completion_tokens'] ?? 0))
        );

        $projected = [
            'requests' => max(1, (int)($estimate['requests'] ?? 1)),
            'tokens' => max(0, $projectedTokens),
            'images' => max(0, (int)($estimate['images'] ?? $estimate['image_count'] ?? 0)),
            'estimated_cost_micros' => max(0, (int)($estimate['estimated_cost_micros'] ?? 0)),
        ];

        if ($projected['estimated_cost_micros'] === 0) {
            $projected['estimated_cost_micros'] = self::calculateEstimatedCostMicros($provider, $model, [
                'prompt_tokens' => (int)($estimate['prompt_tokens'] ?? 0),
                'cached_prompt_tokens' => (int)($estimate['cached_prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($estimate['completion_tokens'] ?? 0),
                'image_count' => $projected['images'],
            ], $usageConfig);
        }

        if (
            'block' === ($usageConfig['enforcement'] ?? 'block')
            && self::hasEstimatedCostLimit($usageConfig, $provider)
            && !self::hasPricing($usageConfig, $provider, $model)
        ) {
            return new WP_Error(
                'ai_pricing_missing',
                __('Estimated spend limits require pricing values for the selected AI model.', 'mailerpress'),
                [
                    'status' => 400,
                    'provider' => $provider,
                    'model' => $model,
                ]
            );
        }

        $checks = [
            ['scope' => 'global', 'provider' => null, 'limits' => $usageConfig['global'] ?? []],
            ['scope' => 'provider', 'provider' => $provider, 'limits' => $usageConfig['providers'][$provider] ?? []],
        ];

        foreach ($checks as $check) {
            $error = self::checkLimitSet(
                $check['scope'],
                $check['provider'],
                $check['limits'],
                $projected,
                $usageConfig
            );

            if ($error instanceof WP_Error) {
                return $error;
            }
        }

        return null;
    }

    public static function recordProviderResponse(string $provider, string $model, string $feature, array $responseData): void
    {
        $usage = self::normalizeUsage($provider, $responseData);
        self::recordUsage($provider, $model, $feature, $usage, $responseData['usage'] ?? $responseData['usageMetadata'] ?? null);
    }

    public static function recordUsage(string $provider, string $model, string $feature, array $usage, mixed $rawUsage = null): void
    {
        if (!self::tableExists()) {
            return;
        }

        global $wpdb;

        $settings = self::getSettings();
        $usageConfig = self::getUsageConfig($settings);
        $costMicros = self::calculateEstimatedCostMicros($provider, $model, $usage, $usageConfig);

        $promptTokens = max(0, (int)($usage['prompt_tokens'] ?? 0));
        $cachedPromptTokens = max(0, (int)($usage['cached_prompt_tokens'] ?? 0));
        $completionTokens = max(0, (int)($usage['completion_tokens'] ?? 0));
        $totalTokens = max(0, (int)($usage['total_tokens'] ?? ($promptTokens + $completionTokens)));
        $imageCount = max(0, (int)($usage['image_count'] ?? 0));

        $wpdb->insert(
            Tables::get(Tables::MAILERPRESS_AI_USAGE_EVENTS),
            [
                'user_id' => get_current_user_id(),
                'feature' => sanitize_key($feature),
                'provider' => sanitize_key($provider),
                'model' => sanitize_text_field($model),
                'status' => 'completed',
                'prompt_tokens' => $promptTokens,
                'cached_prompt_tokens' => $cachedPromptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'image_count' => $imageCount,
                'estimated_cost_micros' => $costMicros,
                'currency' => sanitize_key($usageConfig['currency'] ?? self::DEFAULT_CURRENCY),
                'raw_usage_json' => null === $rawUsage ? null : wp_json_encode($rawUsage),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
        );
    }

    public static function normalizeUsage(string $provider, array $responseData): array
    {
        if ('anthropic' === $provider) {
            $usage = $responseData['usage'] ?? [];
            $promptTokens = (int)($usage['input_tokens'] ?? 0);
            $cachedPromptTokens = (int)($usage['cache_read_input_tokens'] ?? 0);
            $completionTokens = (int)($usage['output_tokens'] ?? 0);

            return [
                'prompt_tokens' => $promptTokens,
                'cached_prompt_tokens' => $cachedPromptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ];
        }

        if ('gemini' === $provider || 'gemini_image' === $provider) {
            $usage = $responseData['usageMetadata'] ?? [];
            $promptTokens = (int)($usage['promptTokenCount'] ?? 0);
            $completionTokens = (int)($usage['candidatesTokenCount'] ?? 0) + (int)($usage['thoughtsTokenCount'] ?? 0);
            $totalTokens = (int)($usage['totalTokenCount'] ?? ($promptTokens + $completionTokens));

            return [
                'prompt_tokens' => $promptTokens,
                'cached_prompt_tokens' => (int)($usage['cachedContentTokenCount'] ?? 0),
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'image_count' => self::countInlineImages($responseData),
            ];
        }

        $usage = $responseData['usage'] ?? [];
        $promptTokens = (int)($usage['prompt_tokens'] ?? 0);
        $cachedPromptTokens = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? $usage['prompt_cache_hit_tokens'] ?? 0);
        $completionTokens = (int)($usage['completion_tokens'] ?? 0);

        return [
            'prompt_tokens' => $promptTokens,
            'cached_prompt_tokens' => $cachedPromptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => (int)($usage['total_tokens'] ?? ($promptTokens + $completionTokens)),
        ];
    }

    public static function estimatePromptTokens(string $text): int
    {
        $text = trim(wp_strip_all_tags($text));
        if ('' === $text) {
            return 0;
        }

        return max(1, (int)ceil(strlen($text) / 4));
    }

    private static function checkLimitSet(string $scope, ?string $provider, array $limits, array $projected, array $usageConfig): ?WP_Error
    {
        if (empty($limits)) {
            return null;
        }

        $checks = [
            'daily_requests' => ['period' => 'daily', 'metric' => 'requests', 'label' => __('daily AI request limit', 'mailerpress')],
            'monthly_requests' => ['period' => 'monthly', 'metric' => 'requests', 'label' => __('monthly AI request limit', 'mailerpress')],
            'daily_tokens' => ['period' => 'daily', 'metric' => 'tokens', 'label' => __('daily AI token limit', 'mailerpress')],
            'monthly_tokens' => ['period' => 'monthly', 'metric' => 'tokens', 'label' => __('monthly AI token limit', 'mailerpress')],
            'daily_images' => ['period' => 'daily', 'metric' => 'images', 'label' => __('daily AI image limit', 'mailerpress')],
            'monthly_images' => ['period' => 'monthly', 'metric' => 'images', 'label' => __('monthly AI image limit', 'mailerpress')],
            'daily_estimated_cost' => ['period' => 'daily', 'metric' => 'estimated_cost_micros', 'label' => __('daily estimated AI spend limit', 'mailerpress'), 'money' => true],
            'monthly_estimated_cost' => ['period' => 'monthly', 'metric' => 'estimated_cost_micros', 'label' => __('monthly estimated AI spend limit', 'mailerpress'), 'money' => true],
        ];

        foreach ($checks as $limitKey => $definition) {
            $limit = (float)($limits[$limitKey] ?? 0);
            if ($limit <= 0) {
                continue;
            }

            $limitValue = !empty($definition['money']) ? (int)round($limit * 1000000) : (int)$limit;
            $totals = self::getTotals($definition['period'], $provider);
            $current = (int)($totals[$definition['metric']] ?? 0);
            $projectedValue = (int)($projected[$definition['metric']] ?? 0);

            if (($current + $projectedValue) > $limitValue && ($usageConfig['enforcement'] ?? 'block') === 'block') {
                return new WP_Error(
                    'ai_usage_limit_exceeded',
                    sprintf(
                        __('This request would exceed the %s.', 'mailerpress'),
                        $definition['label']
                    ),
                    [
                        'status' => 429,
                        'scope' => $scope,
                        'provider' => $provider,
                        'limit_type' => $limitKey,
                        'used' => $current,
                        'projected' => $projectedValue,
                        'limit' => $limitValue,
                        'reset_at' => self::getResetAt($definition['period']),
                    ]
                );
            }
        }

        return null;
    }

    private static function getTotals(string $period, ?string $provider = null): array
    {
        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_AI_USAGE_EVENTS);
        $start = self::getPeriodStart($period);
        $whereProvider = '';
        $params = [$start];

        if (null !== $provider) {
            $whereProvider = ' AND provider = %s';
            $params[] = $provider;
        }

        $sql = $wpdb->prepare(
            "SELECT
                COUNT(*) AS requests,
                COALESCE(SUM(total_tokens), 0) AS tokens,
                COALESCE(SUM(image_count), 0) AS images,
                COALESCE(SUM(estimated_cost_micros), 0) AS estimated_cost_micros
             FROM {$table}
             WHERE status = 'completed'
               AND created_at >= %s{$whereProvider}",
            ...$params
        );

        $row = $wpdb->get_row($sql, ARRAY_A);

        return [
            'requests' => (int)($row['requests'] ?? 0),
            'tokens' => (int)($row['tokens'] ?? 0),
            'images' => (int)($row['images'] ?? 0),
            'estimated_cost_micros' => (int)($row['estimated_cost_micros'] ?? 0),
        ];
    }

    private static function getPeriodStart(string $period): string
    {
        $timestamp = current_time('timestamp');

        if ('daily' === $period) {
            return date('Y-m-d 00:00:00', $timestamp);
        }

        return date('Y-m-01 00:00:00', $timestamp);
    }

    private static function getResetAt(string $period): string
    {
        $timestamp = current_time('timestamp');

        if ('daily' === $period) {
            return date('c', strtotime('tomorrow', $timestamp));
        }

        return date('c', strtotime('first day of next month 00:00:00', $timestamp));
    }

    private static function calculateEstimatedCostMicros(string $provider, string $model, array $usage, array $usageConfig): int
    {
        $pricing = $usageConfig['pricing'][$provider]['models'][$model] ?? [];
        if (empty($pricing)) {
            return 0;
        }

        $promptTokens = max(0, (int)($usage['prompt_tokens'] ?? 0));
        $cachedPromptTokens = min($promptTokens, max(0, (int)($usage['cached_prompt_tokens'] ?? 0)));
        $uncachedPromptTokens = max(0, $promptTokens - $cachedPromptTokens);
        $completionTokens = max(0, (int)($usage['completion_tokens'] ?? 0));
        $imageCount = max(0, (int)($usage['image_count'] ?? 0));

        $cost = 0.0;
        $cost += ($uncachedPromptTokens / 1000000) * (float)($pricing['input'] ?? 0);
        $cost += ($cachedPromptTokens / 1000000) * (float)($pricing['cached_input'] ?? 0);
        $cost += ($completionTokens / 1000000) * (float)($pricing['output'] ?? 0);
        $cost += $imageCount * (float)($pricing['image'] ?? 0);

        return max(0, (int)round($cost * 1000000));
    }

    private static function hasPricing(array $usageConfig, string $provider, string $model): bool
    {
        $pricing = $usageConfig['pricing'][$provider]['models'][$model] ?? [];

        return is_array($pricing) && !empty(array_filter($pricing, static fn($value) => (float)$value > 0));
    }

    private static function hasEstimatedCostLimit(array $usageConfig, string $provider): bool
    {
        $global = $usageConfig['global'] ?? [];
        $providerLimits = $usageConfig['providers'][$provider] ?? [];

        foreach (['daily_estimated_cost', 'monthly_estimated_cost'] as $key) {
            if ((float)($global[$key] ?? 0) > 0 || (float)($providerLimits[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function getSettings(): array
    {
        $settings = get_option(self::OPTION_KEY, []);
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        return is_array($settings) ? $settings : [];
    }

    private static function getUsageConfig(array $settings): array
    {
        $defaults = [
            'enabled' => false,
            'enforcement' => 'block',
            'currency' => self::DEFAULT_CURRENCY,
            'global' => [],
            'providers' => [],
            'pricing' => [],
        ];

        $config = $settings['ai_usage'] ?? [];
        if (!is_array($config)) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $config);
    }

    private static function countInlineImages(array $responseData): int
    {
        $count = 0;
        foreach ($responseData['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (!empty($part['inlineData']['data'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function tableExists(): bool
    {
        return Tables::exists(Tables::get(Tables::MAILERPRESS_AI_USAGE_EVENTS));
    }
}
