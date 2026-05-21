<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;

class MergeTagBuilder
{
    /**
     * Build all merge tag variables for the email.
     */
    public function build(ContactResult $contactResult, Step $step, AutomationJob $job, int $templateId, array $context): array
    {
        $variables = $this->buildBaseVariables($contactResult, $step, $job, $templateId);

        if ($contactResult->isContact && $contactResult->contactId) {
            $this->addCustomFields($variables, $contactResult->contactId);
        }

        if (isset($context['order_id'])) {
            $this->buildOrderVariables($variables, $contactResult, $job, $context);
        }

        $this->addContextVariables($variables, $contactResult->email, $context);

        return $variables;
    }

    private function buildBaseVariables(ContactResult $contactResult, Step $step, AutomationJob $job, int $templateId): array
    {
        $batchId = '';
        $contactId = $contactResult->isContact ? $contactResult->contactId : $job->getUserId();
        $contactName = \trim($contactResult->firstName . ' ' . $contactResult->lastName) ?: $contactResult->displayName;

        if ($contactResult->isContact) {
            $contact = $contactResult->contact;
            $unsubscribeToken = $contact->unsubscribe_token ?? '';
            $accessToken = $contact->access_token ?? '';

            $variables = [
                'TRACK_CLICK' => \home_url('/'),
                'CONTACT_ID' => $contactResult->contactId,
                'CAMPAIGN_ID' => $templateId,
                'JOB_ID' => $job->getId(),
                'STEP_ID' => (string) $step->getStepId(),
                'UNSUB_LINK' => \wp_unslash(
                    \sprintf(
                        '%s&data=%s&cid=%s&batchId=%s',
                        mailerpress_get_page('unsub_page'),
                        \esc_attr($unsubscribeToken),
                        \esc_attr($accessToken),
                        $batchId
                    )
                ),
                'MANAGE_SUB_LINK' => \wp_unslash(
                    \sprintf(
                        '%s&cid=%s',
                        mailerpress_get_page('manage_page'),
                        \esc_attr($accessToken)
                    )
                ),
                'CONTACT_NAME' => \esc_html($contactName),
                'TRACK_OPEN' => $this->buildTrackOpenUrl($contactResult->contactId, $templateId, $batchId, $job, $step),
                'contact_name' => \esc_html($contactName),
                'contact_email' => \esc_html($contact->email ?? $contactResult->email),
                'contact_first_name' => \esc_html($contactResult->firstName),
                'contact_last_name' => \esc_html($contactResult->lastName),
                'campaign_online_url' => '',
            ];
        } else {
            $variables = [
                'TRACK_CLICK' => \home_url('/'),
                'CONTACT_ID' => $contactId,
                'CAMPAIGN_ID' => $templateId,
                'JOB_ID' => $job->getId(),
                'STEP_ID' => (string) $step->getStepId(),
                'UNSUB_LINK' => \home_url('/'),
                'MANAGE_SUB_LINK' => \home_url('/'),
                'CONTACT_NAME' => \esc_html($contactName),
                'TRACK_OPEN' => $this->buildTrackOpenUrl($contactId, $templateId, null, $job, $step),
                'contact_name' => \esc_html($contactName),
                'contact_email' => \esc_html($contactResult->email),
                'contact_first_name' => \esc_html($contactResult->firstName),
                'contact_last_name' => \esc_html($contactResult->lastName),
                'campaign_online_url' => '',
            ];
        }

        return $variables;
    }

    private function buildTrackOpenUrl(?int $contactId, int $templateId, ?string $batchId, AutomationJob $job, Step $step): string
    {
        if (empty($contactId) || empty($templateId) || !$job->getId()) {
            return '';
        }

        $batchIdInt = !empty($batchId) ? (int) $batchId : null;

        return \MailerPress\Core\HtmlParser::generateTrackOpenUrl(
            $contactId,
            $templateId,
            $batchIdInt,
            (int) $job->getId(),
            (string) $step->getStepId()
        );
    }

    private function addCustomFields(array &$variables, int $contactId): void
    {
        global $wpdb;

        $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
        $customFields = $wpdb->get_results($wpdb->prepare(
            "SELECT field_key, field_value FROM {$customFieldsTable} WHERE contact_id = %d",
            $contactId
        ));

        if ($customFields) {
            foreach ($customFields as $customField) {
                $variables[$customField->field_key] = \esc_html($customField->field_value ?? '');
            }
        }
    }

    private function buildOrderVariables(array &$variables, ContactResult $contactResult, AutomationJob $job, array &$context): void
    {
        $orderId = (int) ($context['order_id'] ?? 0);
        $userId = $job->getUserId();
        $userEmail = $contactResult->email;

        $logMessage = function ($message) use ($orderId) {
            if (function_exists('wc_get_logger')) {
                $logger = \call_user_func('wc_get_logger');
                $logger->info($message, ['source' => 'mailerpress-workflow']);
            } else {
                \MailerPress\Services\Logger::info($message, ['order_id' => $orderId]);
            }
        };

        $logMessage("SendEmailStepHandler: Order ID found in context: {$orderId}");

        // Verify order_id matches the job's user email
        if ($orderId > 0 && function_exists('wc_get_order')) {
            $order = \call_user_func('wc_get_order', $orderId);
            if ($order) {
                $orderEmail = $order->get_billing_email();
                $orderCustomerId = $order->get_customer_id();

                $emailMatches = !empty($userEmail) && strtolower($orderEmail) === strtolower($userEmail);
                $customerIdMatches = ($orderCustomerId > 0 && $orderCustomerId == $userId) || ($orderCustomerId == 0 && $userId < 0);

                if (!$emailMatches && !$customerIdMatches) {
                    $logMessage("SendEmailStepHandler: WARNING - Order #{$orderId} email ({$orderEmail}) does not match job user email ({$userEmail})");

                    if (function_exists('wc_get_orders')) {
                        $orders = \call_user_func('wc_get_orders', [
                            'limit' => 1,
                            'orderby' => 'date',
                            'order' => 'DESC',
                            'customer' => $userEmail,
                            'status' => ['completed', 'processing'],
                        ]);

                        if (!empty($orders)) {
                            $correctOrder = $orders[0];
                            $orderId = $correctOrder->get_id();
                            $order = $correctOrder;
                            $logMessage("SendEmailStepHandler: Found correct order #{$orderId} for user email {$userEmail}");
                        }
                    }
                }
            }
        }

        $variables['order_id'] = \esc_html($orderId);
        $variables['order_number'] = \esc_html($context['order_number'] ?? '');
        $variables['order_total'] = \esc_html($context['order_total'] ?? '');
        $variables['order_currency'] = \esc_html($context['order_currency'] ?? '');
        $variables['order_date'] = \esc_html($context['order_date'] ?? '');
        $variables['order_status'] = \esc_html($context['order_status'] ?? '');
        $variables['customer_first_name'] = \esc_html($context['customer_first_name'] ?? '');
        $variables['customer_last_name'] = \esc_html($context['customer_last_name'] ?? '');
        $variables['customer_email'] = \esc_html($context['customer_email'] ?? '');

        // Fetch order items
        $orderItems = $this->fetchOrderItems($orderId, $context, $logMessage);

        if (!empty($orderItems) && is_array($orderItems)) {
            $context['order_items'] = $orderItems;
            $variables['order_items'] = $this->buildOrderItemsTableHtml($orderItems, $context['order_currency'] ?? 'EUR');
            $this->buildProductReviewLinks($variables, $orderItems, $orderId, $logMessage);
        } else {
            $variables['product_review_links'] = '';
            $variables['first_product_review_link'] = '';
            $variables['first_product_name'] = '';
            $variables['product_review_links_count'] = '0';
        }

        if (isset($context['billing_address'])) {
            $billing = $context['billing_address'];
            $variables['billing_address'] = \esc_html(
                \trim(
                    ($billing['first_name'] ?? '') . ' ' .
                    ($billing['last_name'] ?? '') . "\n" .
                    ($billing['address_1'] ?? '') . "\n" .
                    ($billing['address_2'] ?? '') . "\n" .
                    ($billing['city'] ?? '') . ', ' .
                    ($billing['state'] ?? '') . ' ' .
                    ($billing['postcode'] ?? '') . "\n" .
                    ($billing['country'] ?? '')
                )
            );
        }

        if (isset($context['shipping_address'])) {
            $shipping = $context['shipping_address'];
            $variables['shipping_address'] = \esc_html(
                \trim(
                    ($shipping['first_name'] ?? '') . ' ' .
                    ($shipping['last_name'] ?? '') . "\n" .
                    ($shipping['address_1'] ?? '') . "\n" .
                    ($shipping['address_2'] ?? '') . "\n" .
                    ($shipping['city'] ?? '') . ', ' .
                    ($shipping['state'] ?? '') . ' ' .
                    ($shipping['postcode'] ?? '') . "\n" .
                    ($shipping['country'] ?? '')
                )
            );
        }
    }

    private function fetchOrderItems(int $orderId, array $context, callable $logMessage): ?array
    {
        if ($orderId > 0 && function_exists('wc_get_order')) {
            $logMessage("SendEmailStepHandler: Fetching order items directly from WooCommerce order #{$orderId}");
            $order = \call_user_func('wc_get_order', $orderId);

            if ($order) {
                $orderItems = [];
                foreach ($order->get_items() as $itemId => $item) {
                    $product = $item->get_product();
                    $thumbnailUrl = '';
                    if ($product) {
                        $imageId = $product->get_image_id();
                        if ($imageId) {
                            $thumbnailUrl = wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail');
                            if (!$thumbnailUrl) {
                                $thumbnailUrl = wp_get_attachment_image_url($imageId, 'full');
                            }
                        }
                    }

                    $orderItems[] = [
                        'item_id' => $itemId,
                        'product_id' => $item->get_product_id(),
                        'variation_id' => $item->get_variation_id(),
                        'product_name' => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'subtotal' => $item->get_subtotal(),
                        'total' => $item->get_total(),
                        'sku' => $product ? $product->get_sku() : '',
                        'thumbnail_url' => $thumbnailUrl,
                    ];
                }
                $logMessage("SendEmailStepHandler: Successfully fetched " . count($orderItems) . " items from WooCommerce order #{$orderId}");
                return $orderItems;
            }

            $logMessage("SendEmailStepHandler: ERROR - Could not fetch WooCommerce order #{$orderId}");
        }

        return $context['order_items'] ?? null;
    }

    private function buildOrderItemsTableHtml(array $orderItems, string $currency): string
    {
        $currency = \esc_html($currency);
        $tableRows = '';

        // Header row
        $tableRows .= \sprintf(
            '<tr style="background-color: #f5f5f5;">
                <td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0;">%s</td>
                <td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: center;">%s</td>
                <td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: right;">%s</td>
                <td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: right;">%s</td>
            </tr>',
            \esc_html(\__('Product', 'mailerpress')),
            \esc_html(\__('Quantity', 'mailerpress')),
            \esc_html(\__('Price', 'mailerpress')),
            \esc_html(\__('Total', 'mailerpress'))
        );

        // Data rows
        foreach ($orderItems as $index => $item) {
            $productName = \esc_html($item['product_name'] ?? '');
            $quantity = \esc_html($item['quantity'] ?? '0');
            $itemTotal = \number_format((float) ($item['total'] ?? 0), 2, '.', '');
            $itemPrice = $quantity > 0 ? \number_format((float) ($item['total'] ?? 0) / (float) $quantity, 2, '.', '') : '0.00';

            $bgColor = ($index % 2 === 0) ? '#ffffff' : '#fafafa';

            $tableRows .= \sprintf(
                '<tr style="background-color: %s;">
                    <td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0;">%s</td>
                    <td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: center;">%s</td>
                    <td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: right;">%s %s</td>
                    <td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: right; font-weight: bold;">%s %s</td>
                </tr>',
                $bgColor,
                $productName,
                $quantity,
                $itemPrice,
                $currency,
                $itemTotal,
                $currency
            );
        }

        return $tableRows;
    }

    private function buildProductReviewLinks(array &$variables, array $orderItems, int $orderId, callable $logMessage): void
    {
        $productReviewLinks = [];
        $firstProductReviewLink = '';
        $firstProductName = '';
        $processedProductIds = [];

        foreach ($orderItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $variationId = (int) ($item['variation_id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $reviewProductId = $productId;

            if ($variationId > 0 && function_exists('wc_get_product')) {
                $variationProduct = \call_user_func('wc_get_product', $variationId);
                if ($variationProduct && $variationProduct->is_type('variation')) {
                    $parentId = $variationProduct->get_parent_id();
                    if ($parentId > 0) {
                        $reviewProductId = $parentId;
                    }
                }
            }

            if (in_array($reviewProductId, $processedProductIds, true)) {
                continue;
            }

            $processedProductIds[] = $reviewProductId;

            $productName = \esc_html($item['product_name'] ?? '');
            $productUrl = \get_permalink($reviewProductId);

            if ($productUrl) {
                $reviewUrl = $productUrl . '#reviews';
                $reviewLink = \sprintf(
                    '<a href="%s" style="color: #0073aa; text-decoration: underline;">%s</a>',
                    \esc_url($reviewUrl),
                    $productName
                );

                $productReviewLinks[] = $reviewLink;

                if (empty($firstProductReviewLink)) {
                    $firstProductReviewLink = \esc_url($reviewUrl);
                    $firstProductName = $productName;
                }
            }
        }

        $productReviewLinksHtml = '';
        if (!empty($productReviewLinks)) {
            $productReviewLinksHtml = '<ul style="list-style: none; padding: 0; margin: 0;">';
            foreach ($productReviewLinks as $link) {
                $productReviewLinksHtml .= \sprintf('<li style="margin-bottom: 10px; padding: 0;">%s</li>', $link);
            }
            $productReviewLinksHtml .= '</ul>';
        }

        $variables['product_review_links'] = $productReviewLinksHtml;
        $variables['first_product_review_link'] = $firstProductReviewLink;
        $variables['first_product_name'] = $firstProductName;
        $variables['product_review_links_count'] = (string) \count($productReviewLinks);
    }

    private function addContextVariables(array &$variables, string $userEmail, array $context): void
    {
        if (!empty($context['email']) && !isset($variables['email'])) {
            $variables['email'] = \esc_html($context['email']);
        }
        if (!empty($userEmail) && !isset($variables['user_email'])) {
            $variables['user_email'] = \esc_html($userEmail);
        }

        $reservedKeys = [
            'TRACK_CLICK', 'CONTACT_ID', 'CAMPAIGN_ID', 'UNSUB_LINK', 'MANAGE_SUB_LINK',
            'CONTACT_NAME', 'TRACK_OPEN', 'hook_name', 'hook_arguments', 'hook_arguments_count',
            'user_id', 'contact_id', 'custom_data', 'parameter_1_custom_data', 'parameter_2_custom_data',
            'arg_0', 'arg_1', 'arg_2', 'arg_3', 'arg_4', 'arg_5', 'arg_6', 'arg_7', 'arg_8', 'arg_9',
        ];

        foreach ($context as $key => $value) {
            if (!is_scalar($value)) {
                if (is_array($value) && !empty($value)) {
                    $this->flattenArrayForMergeTags($value, $variables, $key);
                }
                continue;
            }

            if (in_array(strtoupper($key), array_map('strtoupper', $reservedKeys))) {
                continue;
            }

            if (!isset($variables[$key])) {
                $variables[$key] = \esc_html((string) $value);
            }
        }
    }

    private function flattenArrayForMergeTags(array $data, array &$variables, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $newKey = $prefix ? $prefix . '.' . $key : $key;

            if (is_scalar($value)) {
                if (!isset($variables[$newKey])) {
                    $variables[$newKey] = \esc_html((string) $value);
                }
            } elseif (is_array($value) && !empty($value)) {
                $this->flattenArrayForMergeTags($value, $variables, $newKey);
            }
        }
    }
}
