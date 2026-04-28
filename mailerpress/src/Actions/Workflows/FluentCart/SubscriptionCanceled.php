<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\FluentCart;

\defined('ABSPATH') || exit;

/**
 * Fluent Cart Subscription Canceled Trigger
 *
 * Fires when a subscription is canceled in Fluent Cart.
 * Hook: fluent_cart/subscription_canceled
 * Parameter: array{ subscription: Subscription, order: ?Order, customer: ?Customer, reason: string }
 *
 * @since 1.3.0
 */
class SubscriptionCanceled
{
    public const TRIGGER_KEY = 'fluentcart_subscription_canceled';

    public static function register($manager): void
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $definition = [
            'label' => __('Subscription Canceled', 'mailerpress'),
            'description' => __('Triggered when a subscription is canceled in Fluent Cart. Perfect for sending cancellation confirmations, feedback requests, or retention offers.', 'mailerpress'),
            'icon' => 'fluentcart',
            'category' => 'fluentcart',
            'settings_schema' => [],
            'output_fields' => array_merge(
                SubscriptionActivated::getSubscriptionOutputFields(),
                [
                    ['key' => 'cancellation_reason', 'label' => __('Cancellation Reason', 'mailerpress'), 'type' => 'string', 'group' => 'subscription'],
                ]
            ),
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'fluent_cart/subscription_canceled',
            [self::class, 'contextBuilder'],
            $definition
        );
    }

    public static function contextBuilder($eventData): array
    {
        if (empty($eventData) || !is_array($eventData)) {
            return [];
        }

        $context = SubscriptionActivated::buildSubscriptionContext($eventData);

        if (empty($context)) {
            return [];
        }

        $context['cancellation_reason'] = $eventData['reason'] ?? '';

        return $context;
    }
}
