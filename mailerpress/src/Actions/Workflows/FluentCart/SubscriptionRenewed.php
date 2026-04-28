<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\FluentCart;

\defined('ABSPATH') || exit;

/**
 * Fluent Cart Subscription Renewed Trigger
 *
 * Fires when a subscription is renewed in Fluent Cart.
 * Hook: fluent_cart/subscription_renewed
 * Parameter: array{ subscription: Subscription, order: ?Order, main_order: ?Order, customer: ?Customer }
 *
 * @since 1.3.0
 */
class SubscriptionRenewed
{
    public const TRIGGER_KEY = 'fluentcart_subscription_renewed';

    public static function register($manager): void
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $definition = [
            'label' => __('Subscription Renewed', 'mailerpress'),
            'description' => __('Triggered when a subscription is renewed in Fluent Cart. Perfect for sending renewal confirmations or thank you emails for loyal subscribers.', 'mailerpress'),
            'icon' => 'fluentcart',
            'category' => 'fluentcart',
            'settings_schema' => [],
            'output_fields' => array_merge(
                SubscriptionActivated::getSubscriptionOutputFields(),
                [
                    ['key' => 'renewal_order_id', 'label' => __('Renewal Order ID', 'mailerpress'), 'type' => 'number', 'group' => 'renewal'],
                    ['key' => 'renewal_order_total', 'label' => __('Renewal Order Total', 'mailerpress'), 'type' => 'number', 'group' => 'renewal'],
                ]
            ),
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            'fluent_cart/subscription_renewed',
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

        // Add renewal order info
        $renewalOrder = $eventData['order'] ?? null;
        if ($renewalOrder) {
            $context['renewal_order_id'] = $renewalOrder->id ?? 0;
            $context['renewal_order_total'] = $renewalOrder->total_amount ?? 0;
        }

        // The main_order is the original order
        $mainOrder = $eventData['main_order'] ?? null;
        if ($mainOrder) {
            $context['order_id'] = $mainOrder->id ?? $context['order_id'];
        }

        return $context;
    }
}
