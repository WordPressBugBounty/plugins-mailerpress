<?php

namespace MailerPress\Core\Workflows;

use MailerPress\Core\Workflows\Services\WorkflowManager;
use MailerPress\Core\Workflows\Conditions\WooCommerceConditionProvider;
use MailerPress\Core\Workflows\Conditions\WooCommerceSubscriptionsConditionProvider;
use MailerPress\Core\Workflows\Conditions\MailerPressConditionProvider;
use MailerPress\Core\Workflows\Conditions\UserConditionProvider;

class WorkflowSystem
{
    private static ?WorkflowSystem $instance = null;
    private WorkflowManager $manager;

    private function __construct()
    {
        $this->manager = new WorkflowManager();
        $this->initialize();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initialize(): void
    {
        $this->manager->getTriggerManager()->registerDefaultTriggers();

        // Register condition providers (extensible conditions)
        new UserConditionProvider();
        new MailerPressConditionProvider();

        // Only register WooCommerce condition provider if WooCommerce is active
        if (function_exists('wc_get_customer_total_spent')) {
            new WooCommerceConditionProvider();
        }

        // Only register WooCommerce Subscriptions condition provider if WooCommerce Subscriptions is active
        if (class_exists('WC_Subscriptions')) {
            new WooCommerceSubscriptionsConditionProvider();
        }
    }

    public function getManager(): WorkflowManager
    {
        return $this->manager;
    }

    public static function init(): void
    {
        self::getInstance();
    }
}

// Don't call init() directly - let it be called via WordPress hooks
// This ensures translations are loaded before registerDefaultTriggers() uses __()