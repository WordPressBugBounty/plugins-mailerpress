<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows\WooCommerce;

\defined('ABSPATH') || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Repositories\AutomationJobRepository;
use MailerPress\Core\Workflows\Repositories\AutomationRepository;
use MailerPress\Core\Workflows\Repositories\StepRepository;
use MailerPress\Core\Workflows\WorkflowSystem;
use MailerPress\Models\Contacts;
use MailerPress\Services\ContactUpsertService;

class CustomerInactiveTrigger
{
    public const TRIGGER_KEY = 'woocommerce_customer_inactive';
    public const HOOK_NAME = 'mailerpress_check_woocommerce_inactive_customers';
    private const IMMEDIATE_HOOK_NAME = 'mailerpress_check_woocommerce_inactive_customers_now';

    private const DEFAULT_INACTIVITY_DAYS = 90;
    private const DEFAULT_BATCH_SIZE = 200;
    private const BOOTSTRAP_VERSION = '1.0.1';
    private const BOOTSTRAP_VERSION_OPTION = 'mailerpress_woocommerce_customer_inactive_bootstrap_version';

    public static function register($manager): void
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $definition = [
            'label' => __('Customer Inactive (Daily)', 'mailerpress'),
            'description' => __('Runs daily to find WooCommerce customers whose latest matching order is older than the configured number of days. Use it for win-back automations and coupon emails.', 'mailerpress'),
            'icon' => 'woocommerce',
            'category' => 'woocommerce',
            'settings_schema' => [
                [
                    'key' => 'inactivity_days',
                    'label' => __('Inactive After Days', 'mailerpress'),
                    'type' => 'number',
                    'required' => true,
                    'default' => self::DEFAULT_INACTIVITY_DAYS,
                    'min' => 1,
                    'step' => 1,
                    'help' => __('Start the workflow when the latest matching order is at least this many days old.', 'mailerpress'),
                ],
                [
                    'key' => 'order_statuses',
                    'label' => __('Order Statuses', 'mailerpress'),
                    'type' => 'multiselect',
                    'required' => false,
                    'default' => self::getDefaultOrderStatuses(),
                    'options' => self::getOrderStatusOptions(),
                    'help' => __('Only orders with these statuses are considered. Leave unchanged to use Processing and Completed orders.', 'mailerpress'),
                ],
            ],
            'output_fields' => [
                ['key' => 'customer_email', 'label' => __('Customer Email', 'mailerpress'), 'type' => 'email', 'group' => 'customer'],
                ['key' => 'customer_first_name', 'label' => __('Customer First Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_last_name', 'label' => __('Customer Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'customer'],
                ['key' => 'customer_id', 'label' => __('Customer ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
                ['key' => 'user_id', 'label' => __('User ID', 'mailerpress'), 'type' => 'number', 'group' => 'customer'],
                ['key' => 'last_order_id', 'label' => __('Last Order ID', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'last_order_number', 'label' => __('Last Order Number', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'last_order_total', 'label' => __('Last Order Total', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'last_order_currency', 'label' => __('Last Order Currency', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'last_order_date', 'label' => __('Last Order Date', 'mailerpress'), 'type' => 'date', 'group' => 'order'],
                ['key' => 'last_order_status', 'label' => __('Last Order Status', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'days_since_last_order', 'label' => __('Days Since Last Order', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'inactivity_days', 'label' => __('Configured Inactivity Days', 'mailerpress'), 'type' => 'number', 'group' => 'order'],
                ['key' => 'payment_method_title', 'label' => __('Payment Method', 'mailerpress'), 'type' => 'string', 'group' => 'order'],
                ['key' => 'billing_address.first_name', 'label' => __('Billing First Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.last_name', 'label' => __('Billing Last Name', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.email', 'label' => __('Billing Email', 'mailerpress'), 'type' => 'email', 'group' => 'billing'],
                ['key' => 'billing_address.phone', 'label' => __('Billing Phone', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.city', 'label' => __('Billing City', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
                ['key' => 'billing_address.country', 'label' => __('Billing Country', 'mailerpress'), 'type' => 'string', 'group' => 'billing'],
            ],
        ];

        $manager->registerTrigger(
            self::TRIGGER_KEY,
            self::HOOK_NAME,
            null,
            $definition
        );

        if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action(self::HOOK_NAME)) {
            $firstRun = strtotime('today 02:30');
            if ($firstRun < time()) {
                $firstRun = strtotime('tomorrow 02:30');
            }

            as_schedule_recurring_action(
                $firstRun ?: (time() + DAY_IN_SECONDS),
                DAY_IN_SECONDS,
                self::HOOK_NAME,
                [],
                'mailerpress'
            );
        }

        add_action(self::HOOK_NAME, [self::class, 'checkInactiveCustomers'], 10, 0);
        add_action(self::IMMEDIATE_HOOK_NAME, [self::class, 'checkInactiveCustomers'], 10, 0);
        self::maybeScheduleInitialCheck();
    }

    public static function checkInactiveCustomers(): void
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $workflowManager = WorkflowSystem::getInstance()->getManager();
        $automationRepo = new AutomationRepository();
        $stepRepo = new StepRepository();
        $jobRepo = new AutomationJobRepository();
        $contactsModel = new Contacts();
        $automations = $automationRepo->findByStatus('ENABLED');

        foreach ($automations as $automation) {
            $trigger = $stepRepo->findTriggerByKey($automation->getId(), self::TRIGGER_KEY);
            if (!$trigger || empty($trigger->getNextStepId())) {
                continue;
            }

            $settings = $trigger->getSettings() ?? [];
            $inactivityDays = max(1, absint($settings['inactivity_days'] ?? self::DEFAULT_INACTIVITY_DAYS));
            $statuses = self::normalizeOrderStatuses($settings['order_statuses'] ?? self::getDefaultOrderStatuses());
            $batchSize = max(1, min(1000, absint(apply_filters('mailerpress_woocommerce_customer_inactive_batch_size', self::DEFAULT_BATCH_SIZE))));
            $lastEmail = '';

            do {
                $inactiveOrderIdsByEmail = self::getLatestInactiveOrderIds($statuses, $inactivityDays, $lastEmail, $batchSize);

                foreach ($inactiveOrderIdsByEmail as $emailKey => $lastOrderId) {
                    $lastEmail = (string) $emailKey;
                    $lastOrderId = (int) $lastOrderId;

                    if ($lastOrderId <= 0) {
                        continue;
                    }

                    $order = wc_get_order($lastOrderId);

                    if (!$order instanceof \WC_Order) {
                        continue;
                    }

                    $email = sanitize_email($order->get_billing_email() ?: (string) $emailKey);

                    if (!is_email($email)) {
                        continue;
                    }

                    $contact = self::ensureContactForOrder($contactsModel, $order, $email);

                    if (!$contact) {
                        continue;
                    }

                    $contactId = (int) $contact->contact_id;
                    $userId = (int) $order->get_customer_id();

                    if ($userId <= 0) {
                        $user = get_user_by('email', $email);
                        $userId = $user ? (int) $user->ID : $contactId;
                    }

                    $existingJob = $jobRepo->findActiveByAutomationAndContact(
                        $automation->getId(),
                        $contactId,
                        true
                    );

                    if ($existingJob) {
                        continue;
                    }

                    $existingUserJob = $jobRepo->findActiveByAutomationAndUser(
                        $automation->getId(),
                        $userId,
                        true
                    );

                    if ($existingUserJob) {
                        continue;
                    }

                    if (self::hasAlreadyTriggeredForLastOrder(
                        $automation->getId(),
                        $userId,
                        $contactId,
                        $lastOrderId
                    )) {
                        continue;
                    }

                    $summary = self::buildInactiveCustomerSummary($contact, $order, $inactivityDays);

                    if (!$summary) {
                        continue;
                    }

                    $context = array_merge($summary, [
                        'trigger_key' => self::TRIGGER_KEY,
                        'contact_id' => $contactId,
                        'user_id' => $userId,
                        'customer_email' => $email,
                        'contact_subscription_status' => (string) ($contact->subscription_status ?? ''),
                        'inactivity_days' => $inactivityDays,
                    ]);

                    $workflowManager->startWorkflow($automation->getId(), $userId, $context);
                }
            } while (count($inactiveOrderIdsByEmail) === $batchSize);
        }
    }

    public static function scheduleImmediateCheck(): bool
    {
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action(self::IMMEDIATE_HOOK_NAME, [], 'mailerpress')) {
                as_schedule_single_action(time() + 10, self::IMMEDIATE_HOOK_NAME, [], 'mailerpress');
            }

            return true;
        }

        if (!wp_next_scheduled(self::IMMEDIATE_HOOK_NAME)) {
            wp_schedule_single_event(time() + 10, self::IMMEDIATE_HOOK_NAME);
        }

        return true;
    }

    private static function maybeScheduleInitialCheck(): void
    {
        if (get_option(self::BOOTSTRAP_VERSION_OPTION) === self::BOOTSTRAP_VERSION) {
            return;
        }

        if (!self::hasEnabledAutomationUsingTrigger()) {
            return;
        }

        if (self::scheduleImmediateCheck()) {
            update_option(self::BOOTSTRAP_VERSION_OPTION, self::BOOTSTRAP_VERSION, false);
        }
    }

    private static function hasEnabledAutomationUsingTrigger(): bool
    {
        $automationRepo = new AutomationRepository();
        $stepRepo = new StepRepository();

        foreach ($automationRepo->findByStatus('ENABLED') as $automation) {
            if ($stepRepo->findTriggerByKey($automation->getId(), self::TRIGGER_KEY)) {
                return true;
            }
        }

        return false;
    }

    private static function ensureContactForOrder(Contacts $contactsModel, \WC_Order $order, string $email): ?object
    {
        $existingContact = $contactsModel->getContactByEmail($email);

        if ($existingContact && !in_array((string) ($existingContact->subscription_status ?? ''), ['subscribed', 'inactive'], true)) {
            return null;
        }

        $payload = [
            'email' => $email,
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'update_existing' => true,
            'update_contact_fields' => true,
            'assign_default_list' => !$existingContact,
            'suppress_contact_hooks' => true,
            'opt_in_source' => $existingContact ? (string) ($existingContact->opt_in_source ?? 'woocommerce') : 'woocommerce_customer_inactive',
            'opt_in_details' => [
                'trigger' => self::TRIGGER_KEY,
                'last_order_id' => $order->get_id(),
            ],
        ];

        if (!$existingContact) {
            $payload['subscription_status'] = 'subscribed';
        }

        $result = (new ContactUpsertService())->upsert($payload);
        $contactId = (int) ($result['contact_id'] ?? 0);

        if (empty($result['success']) || $contactId <= 0) {
            return $existingContact ?: null;
        }

        return $contactsModel->get($contactId);
    }

    private static function buildInactiveCustomerSummary(object $contact, \WC_Order $lastOrder, int $inactivityDays): ?array
    {
        $lastOrderDate = $lastOrder->get_date_created();
        if (!$lastOrderDate) {
            return null;
        }

        $daysSinceLastOrder = (int) floor(max(0, time() - $lastOrderDate->getTimestamp()) / DAY_IN_SECONDS);
        if ($daysSinceLastOrder < $inactivityDays) {
            return null;
        }

        $customerId = (int) $lastOrder->get_customer_id();
        $firstName = $lastOrder->get_billing_first_name() ?: (string) ($contact->first_name ?? '');
        $lastName = $lastOrder->get_billing_last_name() ?: (string) ($contact->last_name ?? '');

        return [
            'customer_first_name' => $firstName,
            'customer_last_name' => $lastName,
            'customer_id' => $customerId,
            'last_order_id' => $lastOrder->get_id(),
            'last_order_number' => $lastOrder->get_order_number(),
            'last_order_total' => $lastOrder->get_total(),
            'last_order_currency' => $lastOrder->get_currency(),
            'last_order_date' => $lastOrderDate->format('Y-m-d H:i:s'),
            'last_order_status' => $lastOrder->get_status(),
            'days_since_last_order' => $daysSinceLastOrder,
            'payment_method' => $lastOrder->get_payment_method(),
            'payment_method_title' => $lastOrder->get_payment_method_title(),
            'billing_address' => [
                'first_name' => $lastOrder->get_billing_first_name(),
                'last_name' => $lastOrder->get_billing_last_name(),
                'company' => $lastOrder->get_billing_company(),
                'address_1' => $lastOrder->get_billing_address_1(),
                'address_2' => $lastOrder->get_billing_address_2(),
                'city' => $lastOrder->get_billing_city(),
                'state' => $lastOrder->get_billing_state(),
                'postcode' => $lastOrder->get_billing_postcode(),
                'country' => $lastOrder->get_billing_country(),
                'email' => $lastOrder->get_billing_email(),
                'phone' => $lastOrder->get_billing_phone(),
            ],
        ];
    }

    private static function getLatestInactiveOrderIds(array $statuses, int $inactivityDays, string $afterEmail, int $limit): array
    {
        return self::isHposEnabled()
            ? self::getLatestInactiveHposOrderIds($statuses, $inactivityDays, $afterEmail, $limit)
            : self::getLatestInactiveLegacyOrderIds($statuses, $inactivityDays, $afterEmail, $limit);
    }

    private static function getLatestInactiveHposOrderIds(array $statuses, int $inactivityDays, string $afterEmail, int $limit): array
    {
        global $wpdb;

        $ordersTable = $wpdb->prefix . 'wc_orders';
        $statusPlaceholders = self::placeholders(count($statuses));
        $sqlStatuses = self::prefixOrderStatuses($statuses);
        $thresholdGmt = gmdate('Y-m-d H:i:s', time() - ($inactivityDays * DAY_IN_SECONDS));
        $afterEmail = strtolower($afterEmail);

        $query = "
            SELECT latest.email_key, MAX(o.id) AS order_id
            FROM {$ordersTable} o
            INNER JOIN (
                SELECT LOWER(billing_email) AS email_key, MAX(date_created_gmt) AS last_order_date_gmt
                FROM {$ordersTable}
                WHERE type = %s
                  AND status IN ({$statusPlaceholders})
                  AND billing_email IS NOT NULL
                  AND billing_email != ''
                GROUP BY LOWER(billing_email)
                  HAVING MAX(date_created_gmt) <= %s
                   AND email_key > %s
            ) latest
                ON LOWER(o.billing_email) = latest.email_key
               AND o.date_created_gmt = latest.last_order_date_gmt
            WHERE o.type = %s
              AND o.status IN ({$statusPlaceholders})
            GROUP BY latest.email_key
            ORDER BY latest.email_key ASC
            LIMIT %d
        ";

        return self::fetchOrderIdsByEmail($query, [
            'shop_order',
            ...$sqlStatuses,
            $thresholdGmt,
            $afterEmail,
            'shop_order',
            ...$sqlStatuses,
            $limit,
        ]);
    }

    private static function getLatestInactiveLegacyOrderIds(array $statuses, int $inactivityDays, string $afterEmail, int $limit): array
    {
        global $wpdb;

        $statusPlaceholders = self::placeholders(count($statuses));
        $sqlStatuses = self::prefixOrderStatuses($statuses);
        $thresholdGmt = gmdate('Y-m-d H:i:s', time() - ($inactivityDays * DAY_IN_SECONDS));
        $afterEmail = strtolower($afterEmail);

        $query = "
            SELECT latest.email_key, MAX(p.ID) AS order_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
                ON p.ID = pm.post_id
               AND pm.meta_key = %s
            INNER JOIN (
                SELECT LOWER(pm_inner.meta_value) AS email_key, MAX(p_inner.post_date_gmt) AS last_order_date_gmt
                FROM {$wpdb->posts} p_inner
                INNER JOIN {$wpdb->postmeta} pm_inner
                    ON p_inner.ID = pm_inner.post_id
                   AND pm_inner.meta_key = %s
                WHERE p_inner.post_type = %s
                  AND p_inner.post_status IN ({$statusPlaceholders})
                  AND pm_inner.meta_value IS NOT NULL
                  AND pm_inner.meta_value != ''
                GROUP BY LOWER(pm_inner.meta_value)
                HAVING MAX(p_inner.post_date_gmt) <= %s
                   AND email_key > %s
            ) latest
                ON LOWER(pm.meta_value) = latest.email_key
               AND p.post_date_gmt = latest.last_order_date_gmt
            WHERE p.post_type = %s
              AND p.post_status IN ({$statusPlaceholders})
            GROUP BY latest.email_key
            ORDER BY latest.email_key ASC
            LIMIT %d
        ";

        return self::fetchOrderIdsByEmail($query, [
            '_billing_email',
            '_billing_email',
            'shop_order',
            ...$sqlStatuses,
            $thresholdGmt,
            $afterEmail,
            'shop_order',
            ...$sqlStatuses,
            $limit,
        ]);
    }

    private static function fetchOrderIdsByEmail(string $query, array $args): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare($query, $args)) ?: [];
        $orderIdsByEmail = [];

        foreach ($rows as $row) {
            $emailKey = strtolower((string) ($row->email_key ?? ''));
            $orderId = absint($row->order_id ?? 0);

            if ($emailKey !== '' && $orderId > 0) {
                $orderIdsByEmail[$emailKey] = $orderId;
            }
        }

        return $orderIdsByEmail;
    }

    private static function isHposEnabled(): bool
    {
        if (!class_exists(OrderUtil::class)) {
            return false;
        }

        try {
            return OrderUtil::custom_orders_table_usage_is_enabled();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '%s'));
    }

    private static function prefixOrderStatuses(array $statuses): array
    {
        return array_values(array_map(
            static fn($status) => str_starts_with((string) $status, 'wc-') ? (string) $status : 'wc-' . (string) $status,
            $statuses
        ));
    }

    private static function hasAlreadyTriggeredForLastOrder(int $automationId, int $userId, int $contactId, int $lastOrderId): bool
    {
        global $wpdb;

        $logTable = Tables::get(Tables::MAILERPRESS_AUTOMATIONS_LOG);

        // Compare JSON values instead of LIKE-matching the serialized text: MySQL normalizes
        // JSON columns (e.g. `"last_order_id": 36`, with a space), so raw text patterns never match.
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT data
             FROM {$logTable}
             WHERE automation_id = %d
               AND (user_id = %d OR JSON_UNQUOTE(JSON_EXTRACT(data, '$.contact_id')) = %s)
               AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.trigger_key')) = %s
               AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.last_order_id')) = %s
             ORDER BY created_at DESC
             LIMIT 20",
            $automationId,
            $userId,
            (string) $contactId,
            self::TRIGGER_KEY,
            (string) $lastOrderId
        ));

        foreach ($results as $result) {
            $data = json_decode((string) ($result->data ?? ''), true);
            if (!is_array($data)) {
                continue;
            }

            if (
                ($data['trigger_key'] ?? '') === self::TRIGGER_KEY
                && (int) ($data['last_order_id'] ?? 0) === $lastOrderId
                && (int) ($data['contact_id'] ?? 0) === $contactId
            ) {
                return true;
            }
        }

        return false;
    }

    private static function getOrderStatusOptions(): array
    {
        if (!function_exists('wc_get_order_statuses')) {
            return [
                ['value' => 'processing', 'label' => __('Processing', 'mailerpress')],
                ['value' => 'completed', 'label' => __('Completed', 'mailerpress')],
            ];
        }

        $options = [];
        foreach (wc_get_order_statuses() as $statusKey => $statusLabel) {
            $options[] = [
                'value' => str_replace('wc-', '', (string) $statusKey),
                'label' => $statusLabel,
            ];
        }

        return $options;
    }

    private static function normalizeOrderStatuses(mixed $statuses): array
    {
        if (is_string($statuses)) {
            $statuses = array_filter(array_map('trim', explode(',', $statuses)));
        }

        if (!is_array($statuses)) {
            $statuses = [];
        }

        $normalized = [];
        foreach ($statuses as $status) {
            if (is_array($status)) {
                $status = $status['value'] ?? '';
            }

            $status = sanitize_key(str_replace('wc-', '', (string) $status));
            if ($status !== '') {
                $normalized[] = $status;
            }
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized ?: self::getDefaultOrderStatuses();
    }

    private static function getDefaultOrderStatuses(): array
    {
        return ['processing', 'completed'];
    }
}
