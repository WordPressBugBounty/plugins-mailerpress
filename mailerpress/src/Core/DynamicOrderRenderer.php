<?php

namespace MailerPress\Core;

use DOMDocument;
use DOMXPath;

class DynamicOrderRenderer
{
    protected string $html;
    protected array $orderData;

    /**
     * Replace text content in block HTML using regex (simpler and more reliable than DOM)
     */
    protected function replaceTextInBlockHTML(string $blockContent, string $newContent, string $blockName): string
    {
        try {

            // Remove font-size:0px from ALL elements (td, div, etc.) - do this globally
            $blockContent = preg_replace('/(<[^>]+style="[^"]*?)font-size:\s*0px[;\s]*([^"]*")/i', '$1$2', $blockContent);
            // Also handle font-size:0px without quotes or at end of style
            $blockContent = preg_replace('/font-size:\s*0px[;\s]*/i', '', $blockContent);

            // Strategy 1: Replace content inside <div...>CONTENT</div> that has font-family in style
            // This is the most common case - the div with font-family usually has the font-size too
            $replaced = @preg_replace_callback(
                '/(<div[^>]*font-family[^>]*>)(.*?)(<\/div>)/is',
                function ($matches) use ($newContent) {
                    if (count($matches) >= 3) {
                        $divTag = $matches[1];
                        // Check if div has a valid font-size, if not add one
                        if (!preg_match('/font-size:\s*[1-9]\d*px/i', $divTag)) {
                            // Extract existing style attribute or create new one
                            if (preg_match('/style="([^"]*)"/i', $divTag, $styleMatch)) {
                                $existingStyle = $styleMatch[1];
                                // Remove any font-size:0px if present
                                $existingStyle = preg_replace('/font-size:\s*0px[;\s]*/i', '', $existingStyle);
                                // Add font-size:14px if not present
                                if (!preg_match('/font-size:/i', $existingStyle)) {
                                    $existingStyle = 'font-size:14px; ' . $existingStyle;
                                }
                                $divTag = preg_replace('/style="[^"]*"/i', 'style="' . $existingStyle . '"', $divTag);
                            } else {
                                // No style attribute, add one
                                $divTag = preg_replace('/>/', ' style="font-size:14px;">', $divTag);
                            }
                        }
                        // Preserve the opening div tag (with all its styles) and closing tag
                        return $divTag . $newContent . $matches[3];
                    }
                    return $matches[0] ?? '';
                },
                $blockContent,
                1,
                $count1
            );

            if ($count1 > 0 && $replaced !== null && $replaced !== false && $replaced !== $blockContent) {
                // Also ensure font-size is not 0px in the td parent
                $replaced = preg_replace('/(<td[^>]*style="[^"]*?)font-size:\s*0px[;\s]*([^"]*")/i', '$1$2', $replaced);
                // Final cleanup
                $replaced = preg_replace('/font-size:\s*0px[;\s]*/i', '', $replaced);
                return $replaced;
            }

            // Strategy 2: Replace content in any <div>CONTENT</div> within a <td>
            // This preserves the div structure
            $replaced = @preg_replace_callback(
                '/(<td[^>]*>)(<div[^>]*>)(.*?)(<\/div>)(<\/td>)/is',
                function ($matches) use ($newContent) {
                    if (count($matches) >= 5) {
                        $divTag = $matches[2];
                        // Ensure div has a valid font-size
                        if (!preg_match('/font-size:\s*[1-9]\d*px/i', $divTag)) {
                            if (preg_match('/style="([^"]*)"/i', $divTag, $styleMatch)) {
                                $existingStyle = $styleMatch[1];
                                $existingStyle = preg_replace('/font-size:\s*0px[;\s]*/i', '', $existingStyle);
                                if (!preg_match('/font-size:/i', $existingStyle)) {
                                    $existingStyle = 'font-size:14px; ' . $existingStyle;
                                }
                                $divTag = preg_replace('/style="[^"]*"/i', 'style="' . $existingStyle . '"', $divTag);
                            } else {
                                $divTag = preg_replace('/>/', ' style="font-size:14px;">', $divTag);
                            }
                        }
                        // Replace only the content inside the div, preserve div and td tags
                        return $matches[1] . $divTag . $newContent . $matches[4] . $matches[5];
                    }
                    return $matches[0] ?? '';
                },
                $blockContent,
                1,
                $count2
            );

            if ($count2 > 0 && $replaced !== null && $replaced !== false && $replaced !== $blockContent) {
                // Ensure font-size is not 0px
                $replaced = preg_replace('/(<td[^>]*style="[^"]*?)font-size:\s*0px[;\s]*([^"]*")/i', '$1$2', $replaced);
                $replaced = preg_replace('/(<div[^>]*style="[^"]*?)font-size:\s*0px[;\s]*([^"]*")/i', '$1$2', $replaced);
                $replaced = preg_replace('/font-size:\s*0px[;\s]*/i', '', $replaced);
                return $replaced;
            }

            // Strategy 3: Replace entire content between <td> and </td>
            // This is a fallback if no div structure is found
            $replaced = @preg_replace_callback(
                '/(<td[^>]*>)(.*?)(<\/td>)/is',
                function ($matches) use ($newContent) {
                    if (count($matches) < 3) {
                        return $matches[0] ?? '';
                    }
                    // Keep the td opening and closing, replace content
                    // If there's a div structure, preserve it and replace content inside
                    if (preg_match('/<div([^>]*)>.*?<\/div>/is', $matches[2])) {
                        $innerReplaced = @preg_replace_callback(
                            '/(<div[^>]*>)(.*?)(<\/div>)/is',
                            function ($divMatches) use ($newContent) {
                                if (count($divMatches) >= 3) {
                                    $divTag = $divMatches[1];
                                    // Ensure div has a valid font-size
                                    if (!preg_match('/font-size:\s*[1-9]\d*px/i', $divTag)) {
                                        if (preg_match('/style="([^"]*)"/i', $divTag, $styleMatch)) {
                                            $existingStyle = $styleMatch[1];
                                            $existingStyle = preg_replace('/font-size:\s*0px[;\s]*/i', '', $existingStyle);
                                            if (!preg_match('/font-size:/i', $existingStyle)) {
                                                $existingStyle = 'font-size:14px; ' . $existingStyle;
                                            }
                                            $divTag = preg_replace('/style="[^"]*"/i', 'style="' . $existingStyle . '"', $divTag);
                                        } else {
                                            $divTag = preg_replace('/>/', ' style="font-size:14px;">', $divTag);
                                        }
                                    }
                                    return $divTag . $newContent . $divMatches[3];
                                }
                                return $divMatches[0] ?? '';
                            },
                            $matches[2],
                            1
                        );
                        if ($innerReplaced !== null && $innerReplaced !== false) {
                            return $matches[1] . $innerReplaced . $matches[3];
                        }
                    }
                    // No div, just replace text content directly in td
                    // Ensure td has a valid font-size if it has a style attribute
                    $tdTag = $matches[1];
                    if (preg_match('/style="([^"]*)"/i', $tdTag, $tdStyleMatch)) {
                        $tdStyle = $tdStyleMatch[1];
                        $tdStyle = preg_replace('/font-size:\s*0px[;\s]*/i', '', $tdStyle);
                        if (!preg_match('/font-size:\s*[1-9]\d*px/i', $tdStyle)) {
                            $tdStyle = 'font-size:14px; ' . $tdStyle;
                        }
                        $tdTag = preg_replace('/style="[^"]*"/i', 'style="' . $tdStyle . '"', $tdTag);
                    }
                    return $tdTag . $newContent . $matches[3];
                },
                $blockContent,
                1,
                $count3
            );

            if ($count3 > 0 && $replaced !== null && $replaced !== false && $replaced !== $blockContent) {
                // Ensure font-size is not 0px
                $replaced = preg_replace('/(<td[^>]*style="[^"]*?)font-size:\s*0px[;\s]*([^"]*")/i', '$1$2', $replaced);
                $replaced = preg_replace('/font-size:\s*0px[;\s]*/i', '', $replaced);
                return $replaced;
            }
            // Fallback: simple placeholder replacement and also replace any visible text
            $blockContent = str_replace('{{order_date}}', $newContent, $blockContent);
            $blockContent = str_replace('{{shipping_address}}', $newContent, $blockContent);
            $blockContent = str_replace('{{billing_address}}', $newContent, $blockContent);

            // Also try to replace any text content that might be visible (like example values)
            // This is a last resort to ensure content is replaced
            if (preg_match('/<div[^>]*>.*?<\/div>/is', $blockContent)) {
                $blockContent = preg_replace_callback(
                    '/(<div[^>]*>)([^<]*?)(<\/div>)/is',
                    function ($matches) use ($newContent) {
                        // Only replace if the content looks like placeholder text or is very short
                        $content = trim($matches[2]);
                        if (empty($content) || strlen($content) < 50 || preg_match('/\{\{|\d{4}-\d{2}-\d{2}|John|Doe/i', $content)) {
                            return $matches[1] . $newContent . $matches[3];
                        }
                        return $matches[0];
                    },
                    $blockContent,
                    1
                );
            }

            // Final cleanup: remove any remaining font-size:0px
            $blockContent = preg_replace('/font-size:\s*0px[;\s]*/i', '', $blockContent);

            return $blockContent;
        } catch (\Throwable $e) {
            // Return original block content if replacement fails
            return $blockContent;
        }
    }

    /**
     * Format order date, ensuring it's not accidentally set to order_id
     */
    protected function formatOrderDate(?string $date, ?int $orderId = null): string
    {
        // If date is empty, try to fetch from WooCommerce if order_id is available
        if (empty($date) && $orderId && function_exists('wc_get_order')) {
            $wcOrder = wc_get_order($orderId);
            if ($wcOrder && $wcOrder->get_date_created()) {
                $date = $wcOrder->get_date_created()->format('Y-m-d H:i:s');
                return $date;
            }
        }

        if (empty($date)) {
            return '';
        }

        // Check if date is actually order_id
        if ($orderId && $date === (string)$orderId) {
            if (function_exists('wc_get_order')) {
                $wcOrder = wc_get_order($orderId);
                if ($wcOrder && $wcOrder->get_date_created()) {
                    $correctDate = $wcOrder->get_date_created()->format('Y-m-d H:i:s');
                    return $correctDate;
                }
            }
            return '';
        }

        // Validate date format (should be Y-m-d H:i:s)
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $date)) {
            return $date;
        }

        // Try to parse and reformat
        try {
            $dateTime = new \DateTime($date);
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // If parsing fails, try to fetch from WooCommerce
            if ($orderId && function_exists('wc_get_order')) {
                $wcOrder = wc_get_order($orderId);
                if ($wcOrder && $wcOrder->get_date_created()) {
                    $correctDate = $wcOrder->get_date_created()->format('Y-m-d H:i:s');
                    return $correctDate;
                }
            }
            return '';
        }
    }

    public function __construct(string $html, array $orderData = [])
    {
        $this->html = $html;
        $this->orderData = $orderData;

        // If we have order_id but missing other data, try to fetch from WooCommerce
        if (!empty($orderData['order_id'])) {
            $hasOrderNumber = !empty($orderData['order_number']);
            $hasOrderItems = !empty($orderData['order_items']) && is_array($orderData['order_items']) && count($orderData['order_items']) > 0;
            $hasOrderDate = !empty($orderData['order_date']) && preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $orderData['order_date']);

            if (!$hasOrderNumber || !$hasOrderItems || !$hasOrderDate) {
                $this->orderData = $this->fetchOrderData($orderData['order_id'], $orderData);
            }
        }
    }

    protected function fetchOrderData(int $orderId, array $existingData = []): array
    {
        if (!function_exists('wc_get_order')) {
            return $existingData;
        }

        try {
            $order = wc_get_order($orderId);
            if (!$order) {
                return $existingData;
            }

            // Merge with existing data, but existing data takes precedence
            $orderItems = [];
            foreach ($order->get_items() as $itemId => $item) {
                $product = $item->get_product();

                // Get product thumbnail/image URL
                $thumbnailUrl = '';
                if ($product) {
                    $imageId = $product->get_image_id();
                    if ($imageId) {
                        $thumbnailUrl = wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail');
                        if (!$thumbnailUrl) {
                            // Fallback to full size if thumbnail not available
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

            $billingAddress = [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'company' => $order->get_billing_company(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ];

            $shippingAddress = [
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'company' => $order->get_shipping_company(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ];

            $customerId = $order->get_customer_id();

            $fetchedData = [
                'user_id' => $customerId ?: 0,
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'customer_email' => $order->get_billing_email(),
                'customer_first_name' => $order->get_billing_first_name(),
                'customer_last_name' => $order->get_billing_last_name(),
                'customer_id' => $customerId,
                'order_total' => $order->get_total(),
                'order_currency' => $order->get_currency(),
                'order_date' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : '',
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'order_items' => $orderItems,
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                // Always use the current order status from WooCommerce, not the cached one
                'order_status' => $order->get_status(),
                'order_key' => $order->get_order_key(),
            ];

            // Merge: existing data takes precedence over fetched data, EXCEPT for order_status
            // We always want the current order status from WooCommerce
            $merged = array_merge($fetchedData, $existingData);
            $merged['order_status'] = $order->get_status(); // Force current status
            return $merged;
        } catch (\Exception $e) {
            return $existingData;
        }
    }

    public function render(): string
    {
        try {
            if (empty($this->orderData) || !isset($this->orderData['order_id'])) {
                // No order data, remove WooCommerce order blocks
                $this->html = preg_replace(
                    '/<!-- START woocommerce order block -->.*?<!-- END woocommerce order block -->/is',
                    '',
                    $this->html
                );
                return $this->html;
            }

            // Find all WooCommerce order blocks
            preg_match_all(
                '/(<!-- START woocommerce order block -->)(.*?)(<!-- END woocommerce order block -->)/is',
                $this->html,
                $blocks,
                PREG_SET_ORDER
            );

            foreach ($blocks as $index => $block) {
                try {
                    $fullMatch = $block[0];
                    $orderStartComment = $block[1];
                    $orderInnerHtml = $block[2];
                    $orderEndComment = $block[3];
                    // Check if HTML contains order placeholders
                    $hasPlaceholders = preg_match('/\{\{order_\w+\}\}/', $orderInnerHtml);

                    // Check if HTML contains order block comments
                    $hasOrderComments = preg_match('/<!-- START order/', $orderInnerHtml);

                    // Extract the container wrapper
                    $containerOpenTag = '';
                    $containerCloseTag = '';
                    $innerContent = $orderInnerHtml;

                    if (preg_match('/(<div[^>]+class="[^"]*node-client-[^"]*"[^>]*>)/is', $orderInnerHtml, $containerMatch)) {
                        $containerOpenTag = $containerMatch[1];
                        $containerCloseTag = '</div>';
                        $innerContent = preg_replace(
                            '/^' . preg_quote($containerOpenTag, '/') . '|' . preg_quote($containerCloseTag, '/') . '$/s',
                            '',
                            $orderInnerHtml
                        );
                    }

                    // Render order template with order data
                    $renderedContent = $this->renderOrderTemplate($innerContent);

                    // Always replace {{order_*}} placeholders as a fallback
                    // This ensures all placeholders are replaced even if blocks weren't found
                    $renderedContent = $this->replacePlaceholders($renderedContent);


                    // Wrap back the original container
                    $replacementBlock = $orderStartComment . $containerOpenTag . $renderedContent . $containerCloseTag . $orderEndComment;
                    $this->html = str_replace($fullMatch, $replacementBlock, $this->html);
                } catch (\Exception $e) {
                   // Continue with next block or return original HTML for this block
                }
            }
            return $this->html;
        } catch (\Exception $e) {
           // Return original HTML to prevent breaking the email
            return $this->html;
        }
    }

    protected function renderOrderTemplate(string $template): string
    {
        $order = $this->orderData;


        // Find all order blocks in the template (including nested ones)
        // Updated regex to handle BLOCK_CONFIG in START comment: <!-- START block: BLOCK_CONFIG:{...} -->
        preg_match_all('/<!-- START ([a-zA-Z0-9-_ ]+)(?::\s*BLOCK_CONFIG:[^>]*)?\s*-->(.*?)<!-- END \1 -->/is', $template, $allBlocks, PREG_SET_ORDER);

        // Replace order-specific blocks recursively
        // Process multiple times to handle nested blocks
        $maxIterations = 10;
        $iteration = 0;
        $previousContent = '';
        $currentContent = $template;

        while ($iteration < $maxIterations && $currentContent !== $previousContent) {
            $previousContent = $currentContent;
            $currentContent = $this->replaceOrderBlocks($currentContent, $order);
            $iteration++;
        }

        return $currentContent;
    }

    protected function replaceOrderBlocks(string $content, array $order): string
    {
        // List of order block names to process (excluding "order" which is handled separately)
        $orderBlockNames = [
            'order number',
            'order total',
            'order date',
            'order status',
            'order items',
            'order items table',
            'order billing address',
            'order shipping address',
            'customer name',
        ];

        // Log order data for debugging

        // Replace order-specific blocks
        return preg_replace_callback(
            '/<!-- START ([a-zA-Z0-9-_ ]+)(?::\s*BLOCK_CONFIG:.*?)?\s*-->(.*?)<!-- END \1 -->/is',
            function ($blockMatches) use ($order, $orderBlockNames) {
                // Find the complete START comment by searching for it in the original content
                $blockName = trim($blockMatches[1]);
                $blockContent = $blockMatches[2];

                // Extract BLOCK_CONFIG from the full match if present
                // The regex captures the START comment, but we need to extract the complete JSON
                $fullMatch = $blockMatches[0];
                $blockConfigInComment = '';

                // Find the START comment in the full match
                $startCommentPos = strpos($fullMatch, '<!-- START');
                if ($startCommentPos !== false && strpos($fullMatch, 'BLOCK_CONFIG:', $startCommentPos) !== false) {
                    // Find where BLOCK_CONFIG starts
                    $configStart = strpos($fullMatch, 'BLOCK_CONFIG:', $startCommentPos);
                    $jsonStart = strpos($fullMatch, '{', $configStart);

                    if ($jsonStart !== false) {
                        // Extract JSON using brace counting - search until we find -->
                        // But use brace counting to get the complete JSON first
                        $remainingContent = substr($fullMatch, $jsonStart);
                        $blockConfigInComment = $this->extractJsonFromString($remainingContent);
                    }
                }

                // Helper function to reconstruct START comment with BLOCK_CONFIG
                $getStartComment = function () use ($blockName, $blockConfigInComment) {
                    if (!empty($blockConfigInComment)) {
                        return '<!-- START ' . $blockName . ': BLOCK_CONFIG:' . $blockConfigInComment . ' -->';
                    }
                    return '<!-- START ' . $blockName . ' -->';
                };

                // Normalize block name (handle both "order number" and "order-number" formats)
                $normalizedBlockName = strtolower(str_replace(['-', '_'], ' ', $blockName));

                // For "order" wrapper block, process nested blocks recursively
                if ($normalizedBlockName === 'order') {
                    // Find nested blocks in the order wrapper
                    preg_match_all('/<!-- START ([a-zA-Z0-9-_ ]+) -->/is', $blockContent, $nestedBlocks);
                    // Recursively process nested blocks inside the "order" block
                    $processedContent = $this->replaceOrderBlocks($blockContent, $order);
                    return '<!-- START order -->' . $processedContent . '<!-- END order -->';
                }

                // Only process if it's an order-related block
                $isOrderBlock = false;
                foreach ($orderBlockNames as $orderBlockName) {
                    if ($normalizedBlockName === $orderBlockName) {
                        $isOrderBlock = true;
                        break;
                    }
                }

                if (!$isOrderBlock) {
                    return $blockMatches[0];
                }

                switch ($normalizedBlockName) {
                    case 'order number':
                        $value = $order['order_number'] ?? '';
                        // Use regex-based text replacement
                        $newBlockContent = $this->replaceTextInBlockHTML($blockContent, \esc_html($value), $blockName);
                        return "<!-- START {$blockName} -->{$newBlockContent}<!-- END {$blockName} -->";
                        break;

                    case 'order total':
                        $total = $order['order_total'] ?? '0';
                        $currency = $order['order_currency'] ?? 'EUR';
                        $value = $total . ' ' . $currency;
                        // Use regex-based text replacement
                        $newBlockContent = $this->replaceTextInBlockHTML($blockContent, \esc_html($value), $blockName);
                        return "<!-- START {$blockName} -->{$newBlockContent}<!-- END {$blockName} -->";
                        break;

                    case 'order date':
                        // Get date from order data, ensuring it's properly formatted
                        $value = $this->formatOrderDate($order['order_date'] ?? '', $order['order_id'] ?? null);
                        // Use regex-based text replacement
                        $newBlockContent = $this->replaceTextInBlockHTML($blockContent, \esc_html($value), $blockName);
                        return "<!-- START {$blockName} -->{$newBlockContent}<!-- END {$blockName} -->";
                        break;

                    case 'order status':
                        // Get status from order data, but if not available, try to fetch from WooCommerce directly
                        $status = $order['order_status'] ?? '';

                        // If status is empty or seems incorrect, try to fetch current status from WooCommerce
                        if (empty($status) && !empty($order['order_id']) && function_exists('wc_get_order')) {
                            $wcOrder = wc_get_order($order['order_id']);
                            if ($wcOrder) {
                                $status = $wcOrder->get_status();
                                // Update the order data with the current status
                                $order['order_status'] = $status;
                            }
                        }

                        // Translate status to readable format
                        $statusLabels = [
                            'pending' => \__('Pending', 'mailerpress'),
                            'processing' => \__('Processing', 'mailerpress'),
                            'on-hold' => \__('On Hold', 'mailerpress'),
                            'completed' => \__('Completed', 'mailerpress'),
                            'cancelled' => \__('Cancelled', 'mailerpress'),
                            'refunded' => \__('Refunded', 'mailerpress'),
                            'failed' => \__('Failed', 'mailerpress'),
                        ];
                        $value = $statusLabels[$status] ?? $status;

                        // Use regex-based text replacement
                        $newBlockContent = $this->replaceTextInBlockHTML($blockContent, \esc_html($value), $blockName);
                        return "<!-- START {$blockName} -->{$newBlockContent}<!-- END {$blockName} -->";
                        break;

                    case 'order items':
                    case 'order items table':
                        // Check if we have order items to render
                        if (empty($order['order_items']) || !is_array($order['order_items'])) {
                            return $blockMatches[0];
                        }

                        // Extract block configuration from the HTML
                        // First try to get it from the start comment (new format) or full block match
                        $blockConfig = [];
                        if (!empty($blockConfigInComment)) {
                            // Try to extract from the START comment first (new format)
                            $blockConfig = $this->extractOrderItemsBlockConfigFromComment($blockConfigInComment);
                        }
                        // If not found in comment, try full block match (old format)
                        if (empty($blockConfig)) {
                            $fullBlockContent = $blockMatches[0] ?? $blockContent;
                            $blockConfig = $this->extractOrderItemsBlockConfig($fullBlockContent);
                        }

                        // Helper function to create container td attributes with padding from config
                        $getContainerTdAttrs = function ($defaultAttrs = '') use ($blockConfig) {
                            // Get padding values from config
                            $paddingTop = $blockConfig['paddingTop'] ?? '10px';
                            $paddingRight = $blockConfig['paddingRight'] ?? '25px';
                            $paddingBottom = $blockConfig['paddingBottom'] ?? '10px';
                            $paddingLeft = $blockConfig['paddingLeft'] ?? '25px';

                            // Build padding style string
                            $paddingStyle = sprintf('padding:%s %s %s %s', $paddingTop, $paddingRight, $paddingBottom, $paddingLeft);

                            // If defaultAttrs contains a style attribute, update it; otherwise add it
                            if (preg_match('/style="([^"]*)"/i', $defaultAttrs, $styleMatch)) {
                                $existingStyle = $styleMatch[1];
                                // Remove ALL existing padding declarations (both shorthand and individual)
                                $existingStyle = preg_replace('/padding[^;]*;?/i', '', $existingStyle);
                                $existingStyle = preg_replace('/padding-top[^;]*;?/i', '', $existingStyle);
                                $existingStyle = preg_replace('/padding-right[^;]*;?/i', '', $existingStyle);
                                $existingStyle = preg_replace('/padding-bottom[^;]*;?/i', '', $existingStyle);
                                $existingStyle = preg_replace('/padding-left[^;]*;?/i', '', $existingStyle);
                                // Clean up multiple semicolons
                                $existingStyle = preg_replace('/;;+/', ';', $existingStyle);
                                $existingStyle = trim($existingStyle, '; ');
                                // Add the new padding style
                                $existingStyle = $paddingStyle . ($existingStyle ? '; ' . $existingStyle : '');
                                return preg_replace('/style="[^"]*"/i', 'style="' . $existingStyle . '"', $defaultAttrs);
                            } else {
                                // No style attribute, add one
                                if (empty($defaultAttrs)) {
                                    return 'align="left" class="email-block node-type-order-items" style="font-size:0px;' . $paddingStyle . ';word-break:break-word;"';
                                }
                                return $defaultAttrs . ' style="' . $paddingStyle . '"';
                            }
                        };

                        // Generate the table rows with actual order items (includes header + data rows)
                        $orderItemsTableRows = $this->generateOrderItemsTableRows($order, $blockConfig);

                        if (empty($orderItemsTableRows)) {
                            return $blockMatches[0];
                        }

                        // Verify that generated content contains real order data, not preview
                        $hasPreviewContent = preg_match('/(?:Premium\s+T-Shirt|Classic\s+Jeans|Leather\s+Belt|placehold\.co)/i', $orderItemsTableRows);
                        // Clean blockContent: remove any START/END comments that might be inside
                        $blockContent = preg_replace('/<!--\s*START\s+order\s+items\s+table\s*-->|<!--\s*END\s+order\s+items\s+table\s*-->/is', '', $blockContent);

                        // The block content has nested structure:
                        // Structure 1: <tr><td><table cellpadding="0"...><tbody>...existing rows...</tbody></table></td></tr>
                        // Structure 2: <tbody><!-- START --><tr><td><table><tbody>...</tbody></table></td></tr><tr>...</tr><!-- END --></tbody>
                        // We need to find the innermost table's tbody and replace its content, OR replace the entire outer structure

                        // Strategy 0: If blockContent starts with <tbody>, replace the ENTIRE tbody content
                        // This handles: <tbody><!-- START --><tr>...</tr><tr>...</tr><!-- END --></tbody>
                        // We need to match the entire tbody from start to end, including all nested content
                        $replaced = null;
                        $trimmedContent = trim($blockContent);
                        if (preg_match('/^<tbody[^>]*>/i', $trimmedContent)) {

                            // Simple approach: find the first <tbody> and the LAST </tbody> (the outer one)
                            // Count all </tbody> tags to find the last one
                            $tbodyClosePositions = [];
                            $pos = 0;
                            while (($pos = strpos($trimmedContent, '</tbody>', $pos)) !== false) {
                                $tbodyClosePositions[] = $pos;
                                $pos += 8; // length of '</tbody>'
                            }

                            if (!empty($tbodyClosePositions)) {
                                // Get the last </tbody> position (the outer one)
                                $lastClosePos = end($tbodyClosePositions);

                                // Extract opening tag
                                if (preg_match('/^(<tbody[^>]*>)/i', $trimmedContent, $openMatch)) {
                                    $openTag = $openMatch[1];
                                    $contentStart = strlen($openTag);
                                    $contentEnd = $lastClosePos;

                                    $oldTbodyContent = substr($trimmedContent, $contentStart, $contentEnd - $contentStart);
                                    // Extract td attributes from the first td if it exists to preserve styling
                                    $tdAttrs = 'align="left" class="email-block node-type-order-items" style="font-size:0px;word-break:break-word;"';
                                    if (preg_match('/<td([^>]*)>/i', $oldTbodyContent, $tdMatch)) {
                                        $tdAttrs = $tdMatch[1];
                                    }
                                    // Apply padding from config (this will replace any existing padding)
                                    $tdAttrs = $getContainerTdAttrs($tdAttrs);

                                    // Wrap the new rows in a tr/td/table structure to match the expected format
                                    // IMPORTANT: This replaces ALL content, including any example items
                                    // Use ONLY the generated order items, completely ignoring any preview content
                                    $wrappedContent = '<tr><td ' . $tdAttrs . '><table cellpadding="0" cellspacing="0" width="100%" border="0" style="color:#333333;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:14px;line-height:1.5;table-layout:auto;width:100%;border:none;"><tbody>' . $orderItemsTableRows . '</tbody></table></td></tr>';

                                    $replaced = $openTag . $wrappedContent . '</tbody>';
                               }
                            }
                        }

                        // Strategy 1: Find the innermost table's tbody (with cellpadding) and replace its entire content
                        // This handles the nested structure: <tr><td><table><tbody>...</tbody></table></td></tr>
                        // Only try if Strategy 0 didn't work (check if replaced is different from original)
                        $strategy0Worked = ($replaced !== null && $replaced !== trim($blockContent) && $replaced !== $blockContent);
                        if (!$strategy0Worked) {
                            $replaced = preg_replace_callback(
                                '/<table([^>]*cellpadding[^>]*)>(.*?<tbody[^>]*>)(.*?)(<\/tbody>.*?<\/table>)/is',
                                function ($tableMatches) use ($orderItemsTableRows) {
                                    $tableAttrs = $tableMatches[1];
                                    $beforeTbody = $tableMatches[2]; // Includes <tbody> opening tag
                                    $oldTbodyContent = $tableMatches[3];
                                    $afterTbody = $tableMatches[4]; // </tbody> and </table>
                                    // Replace entire tbody content with new rows (includes header + data rows)
                                    // IMPORTANT: Completely replace ALL preview content with real order data
                                    return '<table' . $tableAttrs . '>' . $beforeTbody . $orderItemsTableRows . $afterTbody;
                                },
                                $blockContent,
                                1
                            );
                        }

                        // Strategy 1b: If no cellpadding table found, try any tbody inside a table
                        if ($replaced === $blockContent || $replaced === null) {
                            $replaced = preg_replace_callback(
                                '/<table([^>]*)>(.*?<tbody[^>]*>)(.*?)(<\/tbody>.*?<\/table>)/is',
                                function ($tableMatches) use ($orderItemsTableRows) {
                                    $tableAttrs = $tableMatches[1];
                                    $beforeTbody = $tableMatches[2];
                                    $oldTbodyContent = $tableMatches[3];
                                    $afterTbody = $tableMatches[4];
                                    // Replace entire tbody content with new rows
                                    return '<table' . $tableAttrs . '>' . $beforeTbody . $orderItemsTableRows . $afterTbody;
                                },
                                $blockContent,
                                1
                            );
                        }

                        // Strategy 1c: If still no match, try to replace the entire <tr> content
                        // This handles cases where the structure is: <tr><td><table>...</table></td><td>...</td></tr>
                        // We need to replace ALL content of the tr, not just the first td
                        if ($replaced === $blockContent || $replaced === null) {
                            // Find a <tr> that contains a table and replace ALL its content
                            $replaced = preg_replace_callback(
                                '/(<tr[^>]*>)(.*?)(<\/tr>)/is',
                                function ($matches) use ($orderItemsTableRows, $getContainerTdAttrs) {
                                    $trOpen = $matches[1];
                                    $trContent = $matches[2];
                                    $trClose = $matches[3];

                                    // Check if this tr contains a table
                                    if (preg_match('/<table[^>]*>/i', $trContent)) {
                                        // Extract the first <td> attributes if it exists to preserve styling
                                        $tdAttrs = '';
                                        if (preg_match('/<td([^>]*)>/i', $trContent, $tdAttrMatch)) {
                                            $tdAttrs = $tdAttrMatch[1];
                                        }
                                        // Apply padding from config (this will replace any existing padding)
                                        $tdAttrs = $getContainerTdAttrs($tdAttrs);

                                        // Replace ALL tr content with a single td containing the new table
                                        $newTdContent = '<td' . $tdAttrs . '><table cellpadding="0" cellspacing="0" width="100%" border="0" style="color:#333333;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:14px;line-height:1.5;table-layout:auto;width:100%;border:none;"><tbody>' . $orderItemsTableRows . '</tbody></table></td>';
                                        return $trOpen . $newTdContent . $trClose;
                                    }

                                    // Not a tr with table, return unchanged
                                    return $matches[0];
                                },
                                $blockContent,
                                1
                            );
                        }

                        // Strategy 1d: If still no match, try direct tbody replacement (for outer tbody)
                        if ($replaced === $blockContent || $replaced === null) {
                            $replaced = preg_replace_callback(
                                '/(<tbody[^>]*>)(.*?)(<\/tbody>)/is',
                                function ($matches) use ($orderItemsTableRows, $getContainerTdAttrs) {
                                    $openingTbody = $matches[1];
                                    $oldTbodyContent = $matches[2];
                                    $closingTbody = $matches[3];
                                    // Wrap in a tr/td structure if needed
                                    $tdAttrs = $getContainerTdAttrs();
                                    $wrappedContent = '<tr><td ' . $tdAttrs . '><table cellpadding="0" cellspacing="0" width="100%" border="0" style="color:#333333;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:14px;line-height:1.5;table-layout:auto;width:100%;border:none;"><tbody>' . $orderItemsTableRows . '</tbody></table></td></tr>';

                                    return $openingTbody . $wrappedContent . $closingTbody;
                                },
                                $blockContent,
                                1
                            );
                        }


                        // Strategy 2: If Strategy 1 failed, try to find any table's tbody and replace
                        if ($replaced === $blockContent || $replaced === null) {
                            $replaced = preg_replace_callback(
                                '/<table([^>]*)>(.*?<tbody[^>]*>)(.*?)(<\/tbody>.*?<\/table>)/is',
                                function ($tableMatches) use ($orderItemsTableRows) {
                                    $tableAttrs = $tableMatches[1];
                                    $beforeTbody = $tableMatches[2];
                                    $oldTbodyContent = $tableMatches[3];
                                    $afterTbody = $tableMatches[4];

                                    // Extract header row from old content if it exists
                                    if (preg_match('/(<tr[^>]*style="[^"]*background-color:\s*#f5f5f5[^"]*"[^>]*>.*?<\/tr>)/is', $oldTbodyContent, $headerMatch)) {
                                        $headerRow = $headerMatch[1];
                                        // Extract data rows from generated rows (skip header)
                                        $dataRows = preg_replace('/<tr[^>]*style="[^"]*background-color:\s*#f5f5f5[^"]*"[^>]*>.*?<\/tr>/is', '', $orderItemsTableRows, 1);
                                        return '<table' . $tableAttrs . '>' . $beforeTbody . $headerRow . $dataRows . $afterTbody;
                                    } else {
                                        return '<table' . $tableAttrs . '>' . $beforeTbody . $orderItemsTableRows . $afterTbody;
                                    }
                                },
                                $blockContent,
                                1
                            );
                        }

                        // Strategy 3: Replace tbody content directly (if table structure is different)
                        if ($replaced === $blockContent || $replaced === null) {
                            $replaced = preg_replace_callback(
                                '/<tbody[^>]*>(.*?)<\/tbody>/is',
                                function ($tbodyMatches) use ($orderItemsTableRows) {
                                    $oldTbodyContent = $tbodyMatches[1];

                                    // Extract header row from old content if it exists
                                    if (preg_match('/(<tr[^>]*style="[^"]*background-color:\s*#f5f5f5[^"]*"[^>]*>.*?<\/tr>)/is', $oldTbodyContent, $headerMatch)) {
                                        $headerRow = $headerMatch[1];
                                        // Extract data rows from generated rows (skip header)
                                        $dataRows = preg_replace('/<tr[^>]*style="[^"]*background-color:\s*#f5f5f5[^"]*"[^>]*>.*?<\/tr>/is', '', $orderItemsTableRows, 1);
                                        return '<tbody>' . $headerRow . $dataRows . '</tbody>';
                                    } else {
                                        return '<tbody>' . $orderItemsTableRows . '</tbody>';
                                    }
                                },
                                $blockContent,
                                1
                            );
                        }

                        // Strategy 4: Replace all data rows (tr) after the header row
                        if ($replaced === $blockContent || $replaced === null) {
                            // Find header row and replace everything after it until </tbody> or </table>
                            $replaced = preg_replace_callback(
                                '/(<tr[^>]*style="[^"]*background-color:\s*#f5f5f5[^"]*"[^>]*>.*?<\/tr>)(.*?)(<\/tbody>|<\/table>)/is',
                                function ($matches) use ($orderItemsTableRows) {
                                    $headerRow = $matches[1];
                                    $oldDataRows = $matches[2];
                                    $closingTag = $matches[3];

                                    // Extract data rows from generated rows (skip header)
                                    $dataRows = preg_replace('/<tr[^>]*style="[^"]*background-color:\s*#f5f5f5[^"]*"[^>]*>.*?<\/tr>/is', '', $orderItemsTableRows, 1);

                                    return $headerRow . $dataRows . $closingTag;
                                },
                                $blockContent,
                                1
                            );
                        }

                        // Check if any strategy succeeded
                        $originalBlockContent = $blockMatches[2]; // Original blockContent before cleaning
                        $success = false;

                        if ($replaced && $replaced !== $blockContent && $replaced !== trim($blockContent) && $replaced !== null) {
                            // Remove any START/END comments that might be in the replaced content
                            $replaced = preg_replace('/<!--\s*START\s+order\s+items\s+table\s*-->|<!--\s*END\s+order\s+items\s+table\s*-->/is', '', $replaced);

                            // Remove example product rows (Premium T-Shirt, Classic Jeans, Leather Belt, etc.)
                            // These are template examples that should be completely removed
                            $exampleProducts = [
                                'Premium T-Shirt',
                                'Classic Jeans',
                                'Leather Belt',
                                'Premium T-Shirt|Classic Jeans|Leather Belt', // Combined pattern
                            ];

                            foreach ($exampleProducts as $exampleProduct) {
                                // Remove entire <tr> rows that contain example product names
                                // Match: <tr>...Premium T-Shirt...</tr> or similar
                                $pattern = '/<tr[^>]*>.*?' . preg_quote($exampleProduct, '/') . '.*?<\/tr>/is';
                                $replaced = preg_replace($pattern, '', $replaced);
                            }

                            // Also remove any <tr> rows that contain example product names (more flexible pattern)
                            // This catches variations like "Premium T-Shirt", "Classic Jeans", etc.
                            $replaced = preg_replace('/<tr[^>]*>.*?(?:Premium\s+T-Shirt|Classic\s+Jeans|Leather\s+Belt).*?<\/tr>/is', '', $replaced);

                            // Clean up: ensure there's only one <tr> in the outer tbody
                            // If the structure is <tbody><tr>...</tr><tr>...</tr></tbody>, keep only the first <tr> that contains a table
                            if (preg_match('/^(<tbody[^>]*>)(.*?)(<\/tbody>)$/is', $replaced, $tbodyMatch)) {
                                $tbodyContent = $tbodyMatch[2];

                                // Find the first <tr> that contains a <table> (this is our generated content)
                                if (preg_match('/(<tr[^>]*>.*?<table[^>]*>.*?<\/table>.*?<\/tr>)/is', $tbodyContent, $tableTrMatch)) {
                                    $validTr = $tableTrMatch[1];
                                    // Remove any other <tr> elements that don't contain a table (these are leftover example rows)
                                    $cleanedContent = preg_replace('/<tr[^>]*>(?!.*?<table).*?<\/tr>/is', '', $tbodyContent);
                                    // Ensure we have our valid <tr> with table
                                    if (strpos($cleanedContent, $validTr) === false) {
                                        $cleanedContent = $validTr;
                                    }
                                    $replaced = $tbodyMatch[1] . $cleanedContent . $tbodyMatch[3];
                                }
                            }

                            // Final cleanup: remove any duplicate <tr> elements that might be outside the table structure
                            // Pattern: match the complete table structure, then remove any following <tr> elements
                            $replaced = preg_replace('/(<tbody[^>]*>.*?<\/tbody>.*?<\/table>.*?<\/td>.*?<\/tr>)(<tr[^>]*>.*?<\/tr>)+/is', '$1', $replaced);
                            $success = true;
                        }

                        if ($success) {
                            // Final verification: ensure no preview content remains
                            $hasRemainingPreview = preg_match('/(?:Premium\s+T-Shirt|Classic\s+Jeans|Leather\s+Belt|placehold\.co)/i', $replaced);
                            if ($hasRemainingPreview) {
                                // Remove any remaining preview rows
                                $replaced = preg_replace('/<tr[^>]*>.*?(?:Premium\s+T-Shirt|Classic\s+Jeans|Leather\s+Belt|placehold\.co).*?<\/tr>/is', '', $replaced);
                            }
                            return $getStartComment() . $replaced . "<!-- END {$blockName} -->";
                        } else {
                            // Final fallback: Extract the wrapper structure and replace only the inner table content
                            // The structure is: <tr><td><table><tbody>...</tbody></table></td></tr>
                            if (preg_match('/(<tr[^>]*>.*?<td([^>]*)>.*?<table[^>]*>.*?<tbody[^>]*>)(.*?)(<\/tbody>.*?<\/table>.*?<\/td>.*?<\/tr>)/is', $blockContent, $fullMatch)) {
                                $beforeTbody = $fullMatch[1];
                                $tdAttrs = $fullMatch[2];
                                $oldTbodyContent = $fullMatch[3];
                                $afterTbody = $fullMatch[4];

                                // Apply padding from config
                                $tdAttrs = $getContainerTdAttrs($tdAttrs);

                                // Replace the tbody content with new rows
                                // IMPORTANT: Completely replace ALL preview content with real order data
                                $wrappedContent = $beforeTbody . $orderItemsTableRows . $afterTbody;
                                // Update td attributes with correct padding
                                $wrappedContent = preg_replace('/(<tr[^>]*>.*?<td)([^>]*>)/i', '$1 ' . $tdAttrs . '$2', $wrappedContent, 1);
                                // Remove any START/END comments
                                $wrappedContent = preg_replace('/<!--\s*START\s+order\s+items\s+table\s*-->|<!--\s*END\s+order\s+items\s+table\s*-->/is', '', $wrappedContent);
                                // Remove any remaining preview content
                                $wrappedContent = preg_replace('/<tr[^>]*>.*?(?:Premium\s+T-Shirt|Classic\s+Jeans|Leather\s+Belt|placehold\.co).*?<\/tr>/is', '', $wrappedContent);

                                return $getStartComment() . $wrappedContent . "<!-- END {$blockName} -->";
                            }

                            // Alternative: Look for mj-table or regular table and replace its content
                            if (preg_match('/<mj-table[^>]*>/i', $blockContent) || preg_match('/<table[^>]*>/i', $blockContent)) {
                                // Wrap the generated rows in a proper table structure if needed
                                // IMPORTANT: Use ONLY real order data, completely ignoring preview content
                                $wrappedContent = '<table cellpadding="0" cellspacing="0" width="100%" border="0" style="color:#333333;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:14px;line-height:1.5;table-layout:auto;width:100%;border:none;"><tbody>' . $orderItemsTableRows . '</tbody></table>';

                                // Try to preserve the original wrapper structure
                                if (preg_match('/(<tr[^>]*>.*?<td([^>]*)>)(.*?)(<\/td>.*?<\/tr>)/is', $blockContent, $wrapperMatch)) {
                                    $tdAttrs = $wrapperMatch[2];
                                    // Apply padding from config
                                    $tdAttrs = $getContainerTdAttrs($tdAttrs);
                                    $wrappedContent = $wrapperMatch[1] . $wrappedContent . $wrapperMatch[4];
                                    // Update td attributes with correct padding
                                    $wrappedContent = preg_replace('/(<tr[^>]*>.*?<td)([^>]*>)/i', '$1 ' . $tdAttrs . '$2', $wrappedContent, 1);
                                }

                                // Remove any START/END comments
                                $wrappedContent = preg_replace('/<!--\s*START\s+order\s+items\s+table\s*-->|<!--\s*END\s+order\s+items\s+table\s*-->/is', '', $wrappedContent);
                                // Remove any remaining preview content
                                $wrappedContent = preg_replace('/<tr[^>]*>.*?(?:Premium\s+T-Shirt|Classic\s+Jeans|Leather\s+Belt|placehold\.co).*?<\/tr>/is', '', $wrappedContent);

                                return $getStartComment() . $wrappedContent . "<!-- END {$blockName} -->";
                            }

                            // Last resort: return the generated rows wrapped in a basic structure
                            // IMPORTANT: Use ONLY real order data, completely ignoring preview content
                            $tdAttrs = $getContainerTdAttrs();
                            $basicTable = '<tr><td ' . $tdAttrs . '><table cellpadding="0" cellspacing="0" width="100%" border="0" style="color:#333333;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:14px;line-height:1.5;table-layout:auto;width:100%;border:none;"><tbody>' . $orderItemsTableRows . '</tbody></table></td></tr>';
                            // Ensure no preview content
                            $basicTable = preg_replace('/<tr[^>]*>.*?(?:Premium\s+T-Shirt|Classic\s+Jeans|Leather\s+Belt|placehold\.co).*?<\/tr>/is', '', $basicTable);
                            return $getStartComment() . $basicTable . "<!-- END {$blockName} -->";
                        }
                        break;

                    case 'order billing address':
                        if (!isset($order['billing_address'])) {
                            break;
                        }

                        $billing = $order['billing_address'];
                        // Build address parts, filtering empty values
                        $addressParts = [];
                        $name = \trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
                        if (!empty($name)) {
                            $addressParts[] = $name;
                        }
                        if (!empty($billing['address_1'])) {
                            $addressParts[] = $billing['address_1'];
                        }
                        if (!empty($billing['address_2'])) {
                            $addressParts[] = $billing['address_2'];
                        }
                        $cityLine = \trim(
                            ($billing['city'] ?? '') .
                                (!empty($billing['city']) && (!empty($billing['state']) || !empty($billing['postcode'])) ? ', ' : '') .
                                ($billing['state'] ?? '') .
                                (!empty($billing['state']) && !empty($billing['postcode']) ? ' ' : '') .
                                ($billing['postcode'] ?? '')
                        );
                        if (!empty($cityLine)) {
                            $addressParts[] = $cityLine;
                        }
                        if (!empty($billing['country'])) {
                            $addressParts[] = $billing['country'];
                        }

                        $address = \implode("\n", $addressParts);

                        // Convert newlines to <br> for HTML and escape
                        $addressHtml = \nl2br(\esc_html($address));

                        // Use regex-based text replacement
                        $newBlockContent = $this->replaceTextInBlockHTML($blockContent, $addressHtml, $blockName);
                        return "<!-- START {$blockName} -->{$newBlockContent}<!-- END {$blockName} -->";
                        break;

                    case 'order shipping address':
                        if (!isset($order['shipping_address'])) {
                            break;
                        }

                        $shipping = $order['shipping_address'];
                        // Build address parts, filtering empty values
                        $addressParts = [];
                        $name = \trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));
                        if (!empty($name)) {
                            $addressParts[] = $name;
                        }
                        if (!empty($shipping['address_1'])) {
                            $addressParts[] = $shipping['address_1'];
                        }
                        if (!empty($shipping['address_2'])) {
                            $addressParts[] = $shipping['address_2'];
                        }
                        $cityLine = \trim(
                            ($shipping['city'] ?? '') .
                                (!empty($shipping['city']) && (!empty($shipping['state']) || !empty($shipping['postcode'])) ? ', ' : '') .
                                ($shipping['state'] ?? '') .
                                (!empty($shipping['state']) && !empty($shipping['postcode']) ? ' ' : '') .
                                ($shipping['postcode'] ?? '')
                        );
                        if (!empty($cityLine)) {
                            $addressParts[] = $cityLine;
                        }
                        if (!empty($shipping['country'])) {
                            $addressParts[] = $shipping['country'];
                        }

                        $address = \implode("\n", $addressParts);

                        // Convert newlines to <br> for HTML and escape
                        $addressHtml = \nl2br(\esc_html($address));

                        // Use regex-based text replacement
                        $newBlockContent = $this->replaceTextInBlockHTML($blockContent, $addressHtml, $blockName);
                        return "<!-- START {$blockName} -->{$newBlockContent}<!-- END {$blockName} -->";
                        break;

                    case 'customer name':
                        $firstName = $order['customer_first_name'] ?? '';
                        $lastName = $order['customer_last_name'] ?? '';
                        $value = \trim($firstName . ' ' . $lastName);

                        // Use regex-based text replacement
                        $newBlockContent = $this->replaceTextInBlockHTML($blockContent, \esc_html($value), $blockName);
                        return "<!-- START {$blockName} -->{$newBlockContent}<!-- END {$blockName} -->";
                        break;

                    default:
                        return $blockMatches[0];
                }
            },
            $content
        );
    }

    protected function replacePlaceholders(string $html): string
    {
        $order = $this->orderData;

        // Ensure we have the current order status from WooCommerce if order_id is available
        $orderStatus = $order['order_status'] ?? '';
        if (!empty($order['order_id']) && function_exists('wc_get_order')) {
            $wcOrder = wc_get_order($order['order_id']);
            if ($wcOrder) {
                $currentStatus = $wcOrder->get_status();
                if ($currentStatus !== $orderStatus) {
                    $orderStatus = $currentStatus;
                    $order['order_status'] = $currentStatus;
                }
            }
        }

        // Check if there are any placeholders to replace
        $hasPlaceholders = preg_match('/\{\{order_\w+\}\}|\{\{customer_\w+\}\}|\{\{billing_address\}\}|\{\{shipping_address\}\}/', $html);

        // Replace all {{order_*}} placeholders with actual values
        $replacements = [
            '{{order_id}}' => (string)($order['order_id'] ?? ''),
            '{{order_number}}' => $order['order_number'] ?? '',
            '{{order_total}}' => $order['order_total'] ?? '0',
            '{{order_currency}}' => $order['order_currency'] ?? 'EUR',
            '{{order_date}}' => $this->formatOrderDate($order['order_date'] ?? '', $order['order_id'] ?? null),
            '{{order_status}}' => $orderStatus,
            '{{customer_first_name}}' => $order['customer_first_name'] ?? '',
            '{{customer_last_name}}' => $order['customer_last_name'] ?? '',
            '{{customer_email}}' => $order['customer_email'] ?? '',
        ];

        foreach ($replacements as $placeholder => $value) {
            if (strpos($html, $placeholder) !== false) {
                $html = str_replace($placeholder, \esc_html($value), $html);
            }
        }

        // Also replace example values that might be in the HTML from preview
        // Example order number patterns like "#12345" or "12345"
        if (!empty($order['order_number']) && preg_match('/#?\d{4,}/', $html)) {
            // Replace example order numbers (like #12345) with actual order number
            $html = preg_replace('/#?\d{4,}/', $order['order_number'], $html, 1);
        }

        // Replace example order total (like "149.99 EUR")
        if (!empty($order['order_total']) && !empty($order['order_currency'])) {
            $exampleTotalPattern = '/\d+\.\d{2}\s+EUR/i';
            $actualTotal = $order['order_total'] . ' ' . $order['order_currency'];
            if (preg_match($exampleTotalPattern, $html)) {
                $html = preg_replace($exampleTotalPattern, $actualTotal, $html, 1);
            }
        }

        // Replace example customer names (like "John Doe")
        if (!empty($order['customer_first_name']) || !empty($order['customer_last_name'])) {
            $customerName = \trim(($order['customer_first_name'] ?? '') . ' ' . ($order['customer_last_name'] ?? ''));
            if (!empty($customerName) && preg_match('/John\s+Doe/i', $html)) {
                $html = preg_replace('/John\s+Doe/i', $customerName, $html);
            }
        }

        // Replace example dates (like "2024-01-15 14:30:00")
        if (!empty($order['order_date']) && preg_match('/2024-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', $html)) {
            $html = preg_replace('/2024-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', $order['order_date'], $html, 1);
        }

        // Fix any order_id that might have been mistakenly placed in order_date field
        // This can happen if order_date was accidentally set to order_id
        if (!empty($order['order_id'])) {
            $orderIdStr = (string)$order['order_id'];
            $orderDate = $this->formatOrderDate($order['order_date'] ?? '', $order['order_id']);

            // Replace order_id that appears in date-like contexts (within divs that should contain dates)
            // Look for patterns like: <div...>338</div> where 338 is order_id but should be date
            if (preg_match('/<div[^>]*class="[^"]*order-date[^"]*"[^>]*>.*?\b' . preg_quote($orderIdStr, '/') . '\b.*?<\/div>/is', $html)) {
                $html = preg_replace(
                    '/(<div[^>]*class="[^"]*order-date[^"]*"[^>]*>.*?)\b' . preg_quote($orderIdStr, '/') . '\b(.*?<\/div>)/is',
                    '$1' . \esc_html($orderDate) . '$2',
                    $html
                );
            }

            // Also replace in node-type-order-date blocks
            if (preg_match('/<[^>]*node-type-order-date[^>]*>.*?\b' . preg_quote($orderIdStr, '/') . '\b.*?<\/[^>]*>/is', $html)) {
                $html = preg_replace(
                    '/(<[^>]*node-type-order-date[^>]*>.*?)\b' . preg_quote($orderIdStr, '/') . '\b(.*?<\/[^>]*>)/is',
                    '$1' . \esc_html($orderDate) . '$2',
                    $html
                );
            }
        }

        // Handle order total with currency (combined format)
        if (isset($order['order_total']) && isset($order['order_currency'])) {
            $combinedValue = $order['order_total'] . ' ' . $order['order_currency'];
            $html = str_replace('{{order_total}} {{order_currency}}', \esc_html($combinedValue), $html);
        }

        // Handle customer name
        $customerName = \trim(($order['customer_first_name'] ?? '') . ' ' . ($order['customer_last_name'] ?? ''));
        if ($customerName) {
            $html = str_replace('{{customer_name}}', \esc_html($customerName), $html);
        }

        // Handle billing address
        if (isset($order['billing_address']) && strpos($html, '{{billing_address}}') !== false) {
            $billing = $order['billing_address'];
            $address = \trim(
                ($billing['first_name'] ?? '') . ' ' .
                    ($billing['last_name'] ?? '') . "\n" .
                    ($billing['address_1'] ?? '') . "\n" .
                    ($billing['address_2'] ?? '') . "\n" .
                    ($billing['city'] ?? '') . ', ' .
                    ($billing['state'] ?? '') . ' ' .
                    ($billing['postcode'] ?? '') . "\n" .
                    ($billing['country'] ?? '')
            );
            $html = str_replace('{{billing_address}}', \esc_html($address), $html);
        }

        // Handle shipping address
        if (isset($order['shipping_address']) && strpos($html, '{{shipping_address}}') !== false) {
            $shipping = $order['shipping_address'];
            $address = \trim(
                ($shipping['first_name'] ?? '') . ' ' .
                    ($shipping['last_name'] ?? '') . "\n" .
                    ($shipping['address_1'] ?? '') . "\n" .
                    ($shipping['address_2'] ?? '') . "\n" .
                    ($shipping['city'] ?? '') . ', ' .
                    ($shipping['state'] ?? '') . ' ' .
                    ($shipping['postcode'] ?? '') . "\n" .
                    ($shipping['country'] ?? '')
            );
            $html = str_replace('{{shipping_address}}', \esc_html($address), $html);
        }

        // Handle order_items - replace with table HTML if available
        if (isset($order['order_items']) && is_array($order['order_items']) && !empty($order['order_items']) && strpos($html, '{{order_items}}') !== false) {
            $orderItemsTable = $this->generateOrderItemsTableRows($order);
            // Wrap in table structure if needed
            $fullTable = '<table cellpadding="0" cellspacing="0" width="100%" border="0" style="border-collapse: collapse;">
    <tbody>' . $orderItemsTable . '</tbody>
</table>';
            $html = str_replace('{{order_items}}', $fullTable, $html);
        }

        return $html;
    }

    /**
     * Extract JSON from a string starting with {
     * Uses brace counting to handle nested objects and strings correctly
     * 
     * @param string $str String that starts with JSON object
     * @return string The complete JSON string
     */
    protected function extractJsonFromString(string $str): string
    {
        $jsonStart = strpos($str, '{');
        if ($jsonStart === false) {
            return '';
        }

        $braceCount = 0;
        $jsonEnd = $jsonStart;
        $foundEnd = false;
        $inString = false;
        $escapeNext = false;

        for ($i = $jsonStart; $i < strlen($str) && $i < $jsonStart + 2000; $i++) {
            $char = $str[$i];

            // Handle string escaping
            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            if ($char === '\\' && $inString) {
                $escapeNext = true;
                continue;
            }

            // Toggle string state
            if ($char === '"' && !$escapeNext) {
                $inString = !$inString;
                continue;
            }

            // Only count braces when not in a string
            if (!$inString) {
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($braceCount === 0) {
                        $jsonEnd = $i + 1;
                        $foundEnd = true;
                        break;
                    }
                }
            }
        }

        if ($foundEnd && $jsonEnd > $jsonStart) {
            return substr($str, $jsonStart, $jsonEnd - $jsonStart);
        }

        return '';
    }

    /**
     * Extract block configuration from START comment
     * New format: <!-- START order items table: BLOCK_CONFIG:{"showHeader":false,...} -->
     * 
     * @param string $configString The BLOCK_CONFIG string from the comment
     * @return array The extracted configuration
     */
    protected function extractOrderItemsBlockConfigFromComment(string $configString): array
    {
        $config = [
            'showHeader' => true,
            'headerBackgroundColor' => '#f5f5f5',
            'headerTextColor' => '#333333',
            'borderColor' => '#e0e0e0',
            'rowBackgroundColor' => '#ffffff',
            'alternateRowColor' => '#fafafa',
            'cellPadding' => '12px',
            'fontSize' => '14px',
            'fontFamily' => 'Arial, sans-serif',
            'textColor' => '#333333',
        ];

        // Extract JSON from the config string
        $jsonConfig = $this->extractJsonFromString($configString);

        if (empty($jsonConfig)) {
            return $config;
        }

        // Decode HTML entities
        $jsonConfig = html_entity_decode($jsonConfig, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Decode JSON
        $decodedConfig = json_decode($jsonConfig, true);

        if (is_array($decodedConfig) && !empty($decodedConfig)) {
            $config = array_merge($config, $decodedConfig);
        }

        return $config;
    }

    /**
     * Extract block configuration from HTML content
     * Improved version with better mj-raw and comment handling
     * 
     * @param string $blockContent The block content to search in (can be full block match or just content)
     */
    protected function extractOrderItemsBlockConfig(string $blockContent): array
    {
        $config = [
            'showHeader' => true, // Default to true
            'headerBackgroundColor' => '#f5f5f5',
            'headerTextColor' => '#333333',
            'borderColor' => '#e0e0e0',
            'rowBackgroundColor' => '#ffffff',
            'alternateRowColor' => '#fafafa',
            'cellPadding' => '12px',
            'fontSize' => '14px',
            'fontFamily' => 'Arial, sans-serif',
            'textColor' => '#333333',
        ];

        // First, decode any HTML entities in the entire content
        // This handles cases where the comment might be HTML-encoded
        $decodedContent = html_entity_decode($blockContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Strategy 1: Look for BLOCK_CONFIG in mj-raw tags first
        // Pattern: <mj-raw>...<!-- BLOCK_CONFIG:{...} -->...</mj-raw>
        if (preg_match('/<mj-raw[^>]*>(.*?)<\/mj-raw>/is', $decodedContent, $mjRawMatch)) {
            $mjRawContent = $mjRawMatch[1];

            // Decode again in case mj-raw content has additional encoding
            $mjRawContent = html_entity_decode($mjRawContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Use brace counting for nested objects (most robust)
            if (strpos($mjRawContent, 'BLOCK_CONFIG:') !== false) {
                $startPos = strpos($mjRawContent, 'BLOCK_CONFIG:');
                $jsonStart = strpos($mjRawContent, '{', $startPos);

                if ($jsonStart !== false) {
                    $braceCount = 0;
                    $jsonEnd = $jsonStart;
                    $foundEnd = false;
                    $inString = false;
                    $escapeNext = false;

                    for ($i = $jsonStart; $i < strlen($mjRawContent) && $i < $jsonStart + 2000; $i++) {
                        $char = $mjRawContent[$i];

                        // Handle string escaping
                        if ($escapeNext) {
                            $escapeNext = false;
                            continue;
                        }

                        if ($char === '\\' && $inString) {
                            $escapeNext = true;
                            continue;
                        }

                        // Toggle string state
                        if ($char === '"' && !$escapeNext) {
                            $inString = !$inString;
                            continue;
                        }

                        // Only count braces when not in a string
                        if (!$inString) {
                            // Stop if we hit --> (end of comment) and braces are balanced
                            if ($i + 2 < strlen($mjRawContent) && substr($mjRawContent, $i, 3) === '-->') {
                                if ($braceCount === 0) {
                                    $jsonEnd = $i;
                                    $foundEnd = true;
                                    break;
                                }
                            }

                            if ($char === '{') {
                                $braceCount++;
                            } elseif ($char === '}') {
                                $braceCount--;
                                if ($braceCount === 0) {
                                    $jsonEnd = $i + 1;
                                    $foundEnd = true;
                                    break;
                                }
                            }
                        }
                    }

                    if ($foundEnd && $jsonEnd > $jsonStart) {
                        $jsonConfig = substr($mjRawContent, $jsonStart, $jsonEnd - $jsonStart);

                        $jsonConfig = html_entity_decode($jsonConfig, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $decodedConfig = json_decode($jsonConfig, true);

                        if (is_array($decodedConfig) && !empty($decodedConfig)) {
                            $config = array_merge($config, $decodedConfig);
                            return $config;
                        }
                    }
                }
            }
        }

        // Strategy 2: Look for BLOCK_CONFIG anywhere in the content (not just in mj-raw)
        // Use brace counting method (most robust for nested JSON objects)

        // Pattern 2: BLOCK_CONFIG with brace counting (most robust)
        if (strpos($decodedContent, 'BLOCK_CONFIG:') !== false) {
            $startPos = strpos($decodedContent, 'BLOCK_CONFIG:');
            $jsonStart = strpos($decodedContent, '{', $startPos);

            if ($jsonStart !== false) {
                $braceCount = 0;
                $jsonEnd = $jsonStart;
                $foundEnd = false;
                $inString = false;
                $escapeNext = false;

                for ($i = $jsonStart; $i < strlen($decodedContent) && $i < $jsonStart + 2000; $i++) {
                    $char = $decodedContent[$i];

                    // Handle string escaping
                    if ($escapeNext) {
                        $escapeNext = false;
                        continue;
                    }

                    if ($char === '\\' && $inString) {
                        $escapeNext = true;
                        continue;
                    }

                    // Toggle string state
                    if ($char === '"' && !$escapeNext) {
                        $inString = !$inString;
                        continue;
                    }

                    // Only count braces when not in a string
                    if (!$inString) {
                        // Stop if we hit --> (end of comment) and braces are balanced
                        if ($i + 2 < strlen($decodedContent) && substr($decodedContent, $i, 3) === '-->') {
                            if ($braceCount === 0) {
                                $jsonEnd = $i;
                                $foundEnd = true;
                                break;
                            }
                        }

                        if ($char === '{') {
                            $braceCount++;
                        } elseif ($char === '}') {
                            $braceCount--;
                            if ($braceCount === 0) {
                                $jsonEnd = $i + 1;
                                $foundEnd = true;
                                break;
                            }
                        }
                    }
                }

                if ($foundEnd && $jsonEnd > $jsonStart) {
                    $jsonConfig = substr($decodedContent, $jsonStart, $jsonEnd - $jsonStart);
                    // Decode HTML entities (handles &#45;&#45; and other encoded characters)
                    $jsonConfig = html_entity_decode($jsonConfig, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    // Clean up any remaining HTML comments or whitespace
                    $jsonConfig = preg_replace('/<!--.*?-->/s', '', $jsonConfig);
                    $jsonConfig = trim($jsonConfig);

                    // Try to decode JSON
                    $decodedConfig = json_decode($jsonConfig, true);

                    if (is_array($decodedConfig) && !empty($decodedConfig)) {
                        $config = array_merge($config, $decodedConfig);
                        return $config;
                    }
                }
            }
        }

        // Strategy 3: Search in the original (non-decoded) content as fallback
        if (strpos($blockContent, 'BLOCK_CONFIG:') !== false) {
            $startPos = strpos($blockContent, 'BLOCK_CONFIG:');
            $jsonStart = strpos($blockContent, '{', $startPos);

            if ($jsonStart !== false) {
                $braceCount = 0;
                $jsonEnd = $jsonStart;
                $foundEnd = false;
                $inString = false;
                $escapeNext = false;

                for ($i = $jsonStart; $i < strlen($blockContent) && $i < $jsonStart + 2000; $i++) {
                    $char = $blockContent[$i];

                    // Handle string escaping
                    if ($escapeNext) {
                        $escapeNext = false;
                        continue;
                    }

                    if ($char === '\\' && $inString) {
                        $escapeNext = true;
                        continue;
                    }

                    // Toggle string state
                    if ($char === '"' && !$escapeNext) {
                        $inString = !$inString;
                        continue;
                    }

                    // Only count braces when not in a string
                    if (!$inString) {
                        // Stop if we hit --> (end of comment) and braces are balanced
                        if ($i + 2 < strlen($blockContent) && substr($blockContent, $i, 3) === '-->') {
                            if ($braceCount === 0) {
                                $jsonEnd = $i;
                                $foundEnd = true;
                                break;
                            }
                        }

                        if ($char === '{') {
                            $braceCount++;
                        } elseif ($char === '}') {
                            $braceCount--;
                            if ($braceCount === 0) {
                                $jsonEnd = $i + 1;
                                $foundEnd = true;
                                break;
                            }
                        }
                    }
                }

                if ($foundEnd && $jsonEnd > $jsonStart) {
                    $jsonConfig = substr($blockContent, $jsonStart, $jsonEnd - $jsonStart);

                    // Decode HTML entities (handles &#45;&#45; and other encoded characters)
                    $jsonConfig = html_entity_decode($jsonConfig, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    // Try to fix common encoding issues
                    $jsonConfig = str_replace('&#45;', '-', $jsonConfig);
                    $jsonConfig = str_replace('&quot;', '"', $jsonConfig);

                    // Clean up any remaining HTML comments or whitespace
                    $jsonConfig = preg_replace('/<!--.*?-->/s', '', $jsonConfig);
                    $jsonConfig = trim($jsonConfig);

                    $decodedConfig = json_decode($jsonConfig, true);

                    if (is_array($decodedConfig) && !empty($decodedConfig)) {
                        $config = array_merge($config, $decodedConfig);
                        return $config;
                    }
                }
            }
        }

        // Fallback: Parse from HTML styles if BLOCK_CONFIG not found
        // Check if header row exists in the template
        if (!preg_match('/<tr[^>]*style="[^"]*background-color:\s*#f5f5f5[^"]*"[^>]*>.*?(?:Image|Product|Quantity|Price|Total).*?<\/tr>/is', $blockContent)) {
            $config['showHeader'] = false;
        }

        // Extract styling from existing HTML...
        // (Keep existing HTML parsing logic as fallback)

        return $config;
    }

    protected function generateOrderItemsTableRows(array $order, array $config = []): string
    {
        $items = $order['order_items'] ?? [];
        $currency = $order['order_currency'] ?? 'EUR';

        if (empty($items)) {
            return '';
        }

        // Use config values or defaults
        $showHeader = $config['showHeader'] ?? true;
        $headerBackgroundColor = $config['headerBackgroundColor'] ?? '#f5f5f5';
        $headerTextColor = $config['headerTextColor'] ?? '#333333';
        $borderColor = $config['borderColor'] ?? '#e0e0e0';
        $rowBackgroundColor = $config['rowBackgroundColor'] ?? '#ffffff';
        $alternateRowColor = $config['alternateRowColor'] ?? '#fafafa';
        $cellPadding = $config['cellPadding'] ?? '12px';
        $fontSize = $config['fontSize'] ?? '14px';
        $fontFamily = $config['fontFamily'] ?? 'Arial, sans-serif';
        $textColor = $config['textColor'] ?? '#333333';

        $rows = '';

        // Header row (only if showHeader is true)
        if ($showHeader) {
            $rows .= sprintf(
                '<tr style="background-color: %s;">
                    <td style="padding: %s; font-weight: bold; color: %s; font-size: %s; font-family: %s; border-bottom: 2px solid %s; width: 80px;">%s</td>
                    <td style="padding: %s; font-weight: bold; color: %s; font-size: %s; font-family: %s; border-bottom: 2px solid %s;">%s</td>
                    <td style="padding: %s; font-weight: bold; color: %s; font-size: %s; font-family: %s; border-bottom: 2px solid %s; text-align: center;">%s</td>
                    <td style="padding: %s; font-weight: bold; color: %s; font-size: %s; font-family: %s; border-bottom: 2px solid %s; text-align: right;">%s</td>
                    <td style="padding: %s; font-weight: bold; color: %s; font-size: %s; font-family: %s; border-bottom: 2px solid %s; text-align: right;">%s</td>
                </tr>',
                $headerBackgroundColor,
                $cellPadding,
                $headerTextColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                \esc_html(\__('Image', 'mailerpress')),
                $cellPadding,
                $headerTextColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                \esc_html(\__('Product', 'mailerpress')),
                $cellPadding,
                $headerTextColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                \esc_html(\__('Quantity', 'mailerpress')),
                $cellPadding,
                $headerTextColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                \esc_html(\__('Price', 'mailerpress')),
                $cellPadding,
                $headerTextColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                \esc_html(\__('Total', 'mailerpress'))
            );
        }

        // Data rows
        foreach ($items as $index => $item) {
            $productName = \esc_html($item['product_name'] ?? '');
            $quantity = \esc_html($item['quantity'] ?? '0');
            $itemTotal = \number_format((float)($item['total'] ?? 0), 2, '.', '');
            $itemPrice = $quantity > 0
                ? \number_format((float)($item['total'] ?? 0) / (float)$quantity, 2, '.', '')
                : '0.00';

            $bgColor = ($index % 2 === 0) ? $rowBackgroundColor : $alternateRowColor;

            // Get thumbnail image
            $thumbnailUrl = $item['thumbnail_url'] ?? '';
            $thumbnailHtml = '';
            if (!empty($thumbnailUrl)) {
                $thumbnailHtml = sprintf(
                    '<img src="%s" alt="%s" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px; display: block;" />',
                    \esc_url($thumbnailUrl),
                    \esc_attr($productName)
                );
            } else {
                // Placeholder if no image
                $thumbnailHtml = '<div style="width: 60px; height: 60px; background-color: #f0f0f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 10px;">' . \esc_html(\__('No image', 'mailerpress')) . '</div>';
            }

            $rows .= sprintf(
                '<tr style="background-color: %s;">
                    <td style="padding: %s; border-bottom: 1px solid %s; vertical-align: middle;">%s</td>
                    <td style="padding: %s; color: %s; font-size: %s; font-family: %s; border-bottom: 1px solid %s;">%s</td>
                    <td style="padding: %s; color: %s; font-size: %s; font-family: %s; border-bottom: 1px solid %s; text-align: center;">%s</td>
                    <td style="padding: %s; color: %s; font-size: %s; font-family: %s; border-bottom: 1px solid %s; text-align: right;">%s %s</td>
                    <td style="padding: %s; color: %s; font-size: %s; font-family: %s; border-bottom: 1px solid %s; text-align: right; font-weight: bold;">%s %s</td>
                </tr>',
                $bgColor,
                $cellPadding,
                $borderColor,
                $thumbnailHtml,
                $cellPadding,
                $textColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                $productName,
                $cellPadding,
                $textColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                $quantity,
                $cellPadding,
                $textColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                $itemPrice,
                $currency,
                $cellPadding,
                $textColor,
                $fontSize,
                $fontFamily,
                $borderColor,
                $itemTotal,
                $currency
            );
        }

        return $rows;
    }
}
