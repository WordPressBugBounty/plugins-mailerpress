<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\FluentCart;

\defined('ABSPATH') || exit;

/**
 * Fluent Cart Subscription Expired (End of Term) Trigger
 *
 * Fires when a subscription reaches end of term and expires in Fluent Cart.
 * Hook: fluent_cart/subscription_eot
 * Parameter: array{ subscription: Subscription, order: Order, customer: ?Customer }
 *
 * @since 1.3.0
 */
class SubscriptionExpired
{
    public const TRIGGER_KEY = 'fluentcart_subscription_expired';

    public static function register($manager): void
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $definition = [
            'label' => __('Subscription Expired', 'mailerpress'),
            'description' => __('Triggered when a subscription reaches end of term and expires in Fluent Cart. Perfect for sending reactivation offers, win-back campaigns, or final notifications.', 'mailerpress'),
            'icon' => 'fluentcart',
            'category' => 'fluentcart',
            'settings_schema' => [],
            'output_fields' => SubscriptionActivated::getSubscriptionOutputFields(),
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'fluent_cart/subscription_eot',
            [self::class, 'contextBuilder'],
            $definition
        );
    }

    public static function contextBuilder($eventData): array
    {
        if (empty($eventData) || !is_array($eventData)) {
            return [];
        }

        return SubscriptionActivated::buildSubscriptionContext($eventData);
    }
}
