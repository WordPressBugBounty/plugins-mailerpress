<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Core\HtmlParser;
use MailerPress\Core\Kernel;
use MailerPress\Core\DynamicOrderRenderer;
use MailerPress\Core\Workflows\Services\CouponGenerator;

class EmailContentRenderer
{
    /**
     * Render final HTML content by applying order rendering, cart rendering, merge tags, and HTML parsing.
     */
    public function render(string $htmlContent, array $variables, array $context): string
    {
        // Render WooCommerce order blocks
        if (isset($context['order_id']) && !empty($context['order_id'])) {
            $htmlContent = $this->renderOrderBlocks($htmlContent, $context);
        }

        // Render abandoned cart blocks
        $htmlContent = $this->renderCartBlocks($htmlContent, $context);

        // Render published post blocks
        if (isset($context['post_id']) && !empty($context['post_id'])) {
            $htmlContent = $this->renderPostBlocks($htmlContent, $context);
        }

        // Render coupon code blocks
        $htmlContent = $this->renderCouponCodeBlocks($htmlContent, $context);

        // Render user profile card blocks
        $htmlContent = $this->renderUserProfileCardBlocks($htmlContent, $context);

        // Render comment content blocks
        $htmlContent = $this->renderCommentContentBlocks($htmlContent, $context);

        // Render subscription details card blocks
        $htmlContent = $this->renderSubscriptionDetailsCardBlocks($htmlContent, $context);

        // Render cart recovery button blocks
        $htmlContent = $this->renderCartRecoveryButtonBlocks($htmlContent, $context);

        // Render product showcase blocks
        $htmlContent = $this->renderProductShowcaseBlocks($htmlContent, $context);

        // Render product review blocks
        if (isset($context['order_id']) && !empty($context['order_id'])) {
            $htmlContent = $this->renderProductReviewBlocks($htmlContent, $context);
        }

        // Decode HTML entities and URL-encoded merge tags
        $htmlContent = $this->decodeMergeTags($htmlContent);

        // Parse merge tags via HtmlParser
        $htmlParser = Kernel::getContainer()->get(HtmlParser::class);
        return $htmlParser->init($htmlContent, $variables)->replaceVariables();
    }

    private function renderOrderBlocks(string $htmlContent, array $context): string
    {
        try {
            $orderRenderer = new DynamicOrderRenderer($htmlContent, $context);
            $renderedHtml = $orderRenderer->render();

            if (!empty($renderedHtml) && $renderedHtml !== $htmlContent) {
                return $renderedHtml;
            }
        } catch (\Throwable $e) {
            // Continue with original HTML
        } catch (\Exception $e) {
            // Continue with original HTML
        }

        return $htmlContent;
    }

    private function renderCartBlocks(string $htmlContent, array $context): string
    {
        $cartData = $this->resolveCartData($context);

        if (!$cartData || empty($cartData['cart_items']) || !is_array($cartData['cart_items'])) {
            return $htmlContent;
        }

        try {
            return $this->renderAbandonedCartItems($htmlContent, $cartData);
        } catch (\Throwable $e) {
            // Continue with original HTML
        } catch (\Exception $e) {
            // Continue with original HTML
        }

        return $htmlContent;
    }

    private function resolveCartData(array $context): ?array
    {
        if (isset($context['cart_items']) && is_array($context['cart_items']) && count($context['cart_items']) > 0) {
            // Enrich cart items with missing thumbnail_url if needed
            $enrichedCartItems = $this->enrichCartItemsWithThumbnails($context['cart_items']);

            return [
                'cart_items' => $enrichedCartItems,
                'cart_total' => $context['cart_total'] ?? '0',
                'cart_subtotal' => $context['cart_subtotal'] ?? '0',
                'cart_currency' => $context['cart_currency'] ?? 'EUR',
                'cart_item_count' => $context['cart_item_count'] ?? count($enrichedCartItems),
            ];
        }

        if (!empty($context['user_id']) || !empty($context['cart_hash'])) {
            try {
                $cartRepo = new \MailerPress\Core\Workflows\Repositories\CartTrackingRepository();
                $activeCart = null;

                if (!empty($context['user_id'])) {
                    $activeCart = $cartRepo->getActiveCartByUserId($context['user_id']);
                } elseif (!empty($context['cart_hash'])) {
                    $activeCart = $cartRepo->getCartByHash($context['cart_hash']);
                }

                if ($activeCart && !empty($activeCart['cart_data'])) {
                    $decodedCartData = json_decode($activeCart['cart_data'], true);
                    if ($decodedCartData && isset($decodedCartData['cart_items']) && is_array($decodedCartData['cart_items']) && count($decodedCartData['cart_items']) > 0) {
                        // Enrich cart items with missing thumbnail_url if needed
                        $enrichedCartItems = $this->enrichCartItemsWithThumbnails($decodedCartData['cart_items']);

                        return [
                            'cart_items' => $enrichedCartItems,
                            'cart_total' => $decodedCartData['cart_total'] ?? '0',
                            'cart_subtotal' => $decodedCartData['cart_subtotal'] ?? '0',
                            'cart_currency' => $decodedCartData['cart_currency'] ?? 'EUR',
                            'cart_item_count' => $decodedCartData['cart_item_count'] ?? count($enrichedCartItems),
                        ];
                    }
                }
            } catch (\Throwable $e) {
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    /**
     * Enrich cart items with thumbnail_url if missing (for backward compatibility)
     * This ensures old cart data without thumbnail_url gets images added dynamically
     */
    private function enrichCartItemsWithThumbnails(array $cartItems): array
    {
        if (!function_exists('wc_get_product')) {
            return $cartItems;
        }

        $enriched = [];
        foreach ($cartItems as $item) {
            // If thumbnail_url already exists and is not empty, keep it
            if (!empty($item['thumbnail_url'])) {
                $enriched[] = $item;
                continue;
            }

            // Try to fetch thumbnail for this product
            $productId = $item['product_id'] ?? 0;
            $variationId = $item['variation_id'] ?? 0;

            // Use variation ID if available, otherwise product ID
            $idToUse = $variationId > 0 ? $variationId : $productId;

            if ($idToUse > 0) {
                try {
                    $product = wc_get_product($idToUse);
                    if ($product) {
                        $thumbnailUrl = '';
                        $thumbnailId = $product->get_image_id();

                        if ($thumbnailId) {
                            // Try different image sizes
                            $thumbnailUrl = wp_get_attachment_image_url($thumbnailId, 'woocommerce_thumbnail')
                                ?: wp_get_attachment_image_url($thumbnailId, 'medium')
                                ?: wp_get_attachment_image_url($thumbnailId, 'full');
                        }

                        // Fallback to WooCommerce placeholder if no image found
                        if (empty($thumbnailUrl) && function_exists('wc_placeholder_img_src')) {
                            $thumbnailUrl = wc_placeholder_img_src('woocommerce_thumbnail');
                        }

                        // Add thumbnail_url to item
                        $item['thumbnail_url'] = $thumbnailUrl;
                    }
                } catch (\Exception $e) {
                    // If product not found or error, leave thumbnail_url empty (will use placeholder in renderer)
                }
            }

            $enriched[] = $item;
        }

        return $enriched;
    }

    private function decodeMergeTags(string $htmlContent): string
    {
        $htmlContent = html_entity_decode($htmlContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (strpos($htmlContent, '%7B%7B') !== false || strpos($htmlContent, '%7D%7D') !== false) {
            $htmlContent = str_replace('%7B%7B', '{{', $htmlContent);
            $htmlContent = str_replace('%7D%7D', '}}', $htmlContent);
        }

        return $htmlContent;
    }

    private function renderAbandonedCartItems(string $htmlContent, array $cartData): string
    {
        if (empty($cartData['cart_items']) || !is_array($cartData['cart_items'])) {
            return $htmlContent;
        }

        // Match blocks with BLOCK_CONFIG (both card and table modes)
        preg_match_all(
            '/(<!-- START abandoned cart items table: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END abandoned cart items table -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            // Fallback: Try legacy format without BLOCK_CONFIG (old table-only format)
            return $this->renderAbandonedCartItemsLegacy($htmlContent, $cartData);
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1]; // <!-- START abandoned cart items table: BLOCK_CONFIG:
            $configJson = $block[2]; // {...}
            $endStartComment = $block[3]; // -->
            $blockContent = $block[4]; // <mj-table> or <mj-text> content
            $endComment = $block[5]; // <!-- END abandoned cart items table -->

            // Extract configuration
            $config = $this->extractCartItemsBlockConfig($configJson);
            $displayMode = $config['displayMode'] ?? 'table';

            // Generate content based on display mode
            if ($displayMode === 'card') {
                $cardsHtml = $this->generateCartItemsCards($cartData, $config);

                // Extract mj-text attributes
                $mjTextAttributes = '';
                if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                    $mjTextAttributes = $attrMatches[1];
                }

                $newMjText = '<mj-text' . $mjTextAttributes . '>' . $cardsHtml . '</mj-text>';
                $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;
            } else {
                // Table mode
                $tableRows = $this->generateCartItemsTableRows($cartData, $config);

                // Extract mj-table attributes
                $mjTableAttributes = '';
                if (preg_match('/<mj-table([^>]*)>/i', $blockContent, $attrMatches)) {
                    $mjTableAttributes = $attrMatches[1];
                }

                $newMjTable = '<mj-table' . $mjTableAttributes . '>' . $tableRows . '</mj-table>';
                $newBlock = $startComment . $configJson . $endStartComment . $newMjTable . $endComment;
            }

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    /**
     * Legacy renderer for old format without BLOCK_CONFIG
     */
    private function renderAbandonedCartItemsLegacy(string $htmlContent, array $cartData): string
    {
        preg_match_all(
            '/(<!-- START abandoned cart items table -->)(.*?)(<!-- END abandoned cart items table -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        $defaultConfig = [
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

        $tableRows = $this->generateCartItemsTableRows($cartData, $defaultConfig);

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $blockContent = $block[2];
            $endComment = $block[3];

            $mjTableAttributes = '';
            if (preg_match('/<mj-table([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTableAttributes = $attrMatches[1];
            }

            $newMjTable = '<mj-table' . $mjTableAttributes . '>' . $tableRows . '</mj-table>';
            $newBlock = $startComment . $newMjTable . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    /**
     * Extract configuration from BLOCK_CONFIG JSON string
     */
    private function extractCartItemsBlockConfig(string $jsonString): array
    {
        $defaultConfig = [
            'displayMode' => 'table',
            // Card mode defaults
            'showImage' => true,
            'imageSize' => '120px',
            'imageRadius' => '8px',
            'productNameColor' => '#333333',
            'productNameFontSize' => '16px',
            'productNameFontWeight' => 'bold',
            'metaColor' => '#666666',
            'metaFontSize' => '14px',
            'cardBackgroundColor' => '#ffffff',
            'cardPadding' => '12px',
            'cardBorderRadius' => '8px',
            'showSeparator' => true,
            'separatorColor' => '#e0e0e0',
            'separatorStyle' => 'solid',
            'separatorWidth' => '1px',
            // Table mode defaults
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

        // Decode HTML entities
        $jsonString = html_entity_decode($jsonString, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up the JSON string
        $jsonString = trim($jsonString);

        // Try to decode JSON
        $decodedConfig = json_decode($jsonString, true);

        if (is_array($decodedConfig) && !empty($decodedConfig)) {
            return array_merge($defaultConfig, $decodedConfig);
        }

        return $defaultConfig;
    }

    /**
     * Generate card-style HTML for cart items
     */
    private function generateCartItemsCards(array $cartData, array $config): string
    {
        $items = $cartData['cart_items'];
        $currency = $cartData['cart_currency'] ?? 'EUR';

        $showImage = $config['showImage'] ?? true;
        $imageSize = $config['imageSize'] ?? '120px';
        $imageRadius = $config['imageRadius'] ?? '8px';
        $productNameColor = $config['productNameColor'] ?? '#333333';
        $productNameFontSize = $config['productNameFontSize'] ?? '16px';
        $productNameFontWeight = $config['productNameFontWeight'] ?? 'bold';
        $metaColor = $config['metaColor'] ?? '#666666';
        $metaFontSize = $config['metaFontSize'] ?? '14px';
        $cardBackgroundColor = $config['cardBackgroundColor'] ?? '#ffffff';
        $cardPadding = $config['cardPadding'] ?? '12px';
        $cardBorderRadius = $config['cardBorderRadius'] ?? '8px';
        $showSeparator = $config['showSeparator'] ?? true;
        $separatorColor = $config['separatorColor'] ?? '#e0e0e0';
        $separatorStyle = $config['separatorStyle'] ?? 'solid';
        $separatorWidth = $config['separatorWidth'] ?? '1px';

        $imageSizeNum = (int) preg_replace('/[^0-9]/', '', $imageSize);

        $html = '';

        foreach ($items as $index => $item) {
            $quantity = $item['quantity'] ?? 0;

            // Calculate item total and price with fallback
            $lineTotal = (float) ($item['line_total'] ?? 0);
            $price = (float) ($item['price'] ?? 0);

            // Use line_total if available, otherwise calculate from price * quantity
            if ($lineTotal > 0) {
                $itemTotal = number_format($lineTotal, 2, '.', '');
                $itemPrice = $quantity > 0 ? number_format($lineTotal / $quantity, 2, '.', '') : '0.00';
            } else {
                // Fallback: use price field
                $itemPrice = number_format($price, 2, '.', '');
                $itemTotal = number_format($price * $quantity, 2, '.', '');
            }

            $productName = $item['product_name'] ?? '';
            $thumbnailUrl = $item['thumbnail_url'] ?? 'https://placehold.co/120x120/f0f0f0/999999?text=No+image';

            $html .= '<!-- ITEM_START -->';
            $html .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:' . \esc_attr($cardBackgroundColor) . ';border-radius:' . \esc_attr($cardBorderRadius) . ';"><tbody><tr>';

            if ($showImage) {
                $html .= '<td style="padding:' . \esc_attr($cardPadding) . ';width:' . ($imageSizeNum + 16) . 'px;vertical-align:middle;">';
                $html .= '<img src="' . \esc_url($thumbnailUrl) . '" alt="' . \esc_attr($productName) . '" width="' . $imageSizeNum . '" style="width:' . \esc_attr($imageSize) . ';height:auto;border-radius:' . \esc_attr($imageRadius) . ';display:block;" class="product-image" />';
                $html .= '</td>';
            }

            $html .= '<td style="padding:' . \esc_attr($cardPadding) . ';vertical-align:middle;">';
            $html .= '<div class="product-name" style="font-size:' . \esc_attr($productNameFontSize) . ';font-weight:' . \esc_attr($productNameFontWeight) . ';color:' . \esc_attr($productNameColor) . ';padding-bottom:4px;">' . \esc_html($productName) . '</div>';
            $html .= '<div class="product-meta" style="font-size:' . \esc_attr($metaFontSize) . ';color:' . \esc_attr($metaColor) . ';padding-bottom:2px;">' . \__('Qty:', 'mailerpress') . ' ' . \esc_html($quantity) . ' &times; ' . \esc_html($itemPrice . ' ' . $currency) . '</div>';
            $html .= '<div class="product-total" style="font-size:' . \esc_attr($metaFontSize) . ';font-weight:bold;color:' . \esc_attr($productNameColor) . ';">' . \esc_html($itemTotal . ' ' . $currency) . '</div>';
            $html .= '</td>';

            $html .= '</tr></tbody></table>';
            $html .= '<!-- ITEM_END -->';

            // Separator between items (not after last)
            if ($showSeparator && $index < count($items) - 1) {
                $html .= '<div style="border-top:' . \esc_attr($separatorWidth) . ' ' . \esc_attr($separatorStyle) . ' ' . \esc_attr($separatorColor) . ';margin:4px 0;"></div>';
            }
        }

        return $html;
    }

    private function generateCartItemsTableRows(array $cartData, array $config = []): string
    {
        $items = $cartData['cart_items'];
        $currency = $cartData['cart_currency'] ?? 'EUR';

        // Extract configuration with defaults
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

        // Header row (optional)
        if ($showHeader) {
            $rows .= '<tr style="background-color: ' . \esc_attr($headerBackgroundColor) . ';">';
            $rows .= '<td style="padding: ' . \esc_attr($cellPadding) . '; font-weight: bold; color: ' . \esc_attr($headerTextColor) . '; font-size: ' . \esc_attr($fontSize) . '; font-family: ' . \esc_attr($fontFamily) . '; border-bottom: 2px solid ' . \esc_attr($borderColor) . ';">' . \__('Product', 'mailerpress') . '</td>';
            $rows .= '<td style="padding: ' . \esc_attr($cellPadding) . '; font-weight: bold; color: ' . \esc_attr($headerTextColor) . '; font-size: ' . \esc_attr($fontSize) . '; font-family: ' . \esc_attr($fontFamily) . '; border-bottom: 2px solid ' . \esc_attr($borderColor) . '; text-align: center;">' . \__('Quantity', 'mailerpress') . '</td>';
            $rows .= '<td style="padding: ' . \esc_attr($cellPadding) . '; font-weight: bold; color: ' . \esc_attr($headerTextColor) . '; font-size: ' . \esc_attr($fontSize) . '; font-family: ' . \esc_attr($fontFamily) . '; border-bottom: 2px solid ' . \esc_attr($borderColor) . '; text-align: right;">' . \__('Price', 'mailerpress') . '</td>';
            $rows .= '<td style="padding: ' . \esc_attr($cellPadding) . '; font-weight: bold; color: ' . \esc_attr($headerTextColor) . '; font-size: ' . \esc_attr($fontSize) . '; font-family: ' . \esc_attr($fontFamily) . '; border-bottom: 2px solid ' . \esc_attr($borderColor) . '; text-align: right;">' . \__('Total', 'mailerpress') . '</td>';
            $rows .= '</tr>';
        }

        // Data rows
        foreach ($items as $index => $item) {
            $isEven = $index % 2 === 0;
            $bgColor = $isEven ? $rowBackgroundColor : $alternateRowColor;
            $quantity = $item['quantity'] ?? 0;

            // Calculate item total and price with fallback
            $lineTotal = (float) ($item['line_total'] ?? 0);
            $price = (float) ($item['price'] ?? 0);

            // Use line_total if available, otherwise calculate from price * quantity
            if ($lineTotal > 0) {
                $itemTotal = number_format($lineTotal, 2, '.', '');
                $itemPrice = $quantity > 0 ? number_format($lineTotal / $quantity, 2, '.', '') : '0.00';
            } else {
                // Fallback: use price field
                $itemPrice = number_format($price, 2, '.', '');
                $itemTotal = number_format($price * $quantity, 2, '.', '');
            }

            $productName = $item['product_name'] ?? '';

            $rows .= '<tr style="background-color: ' . \esc_attr($bgColor) . ';">';
            $rows .= '<td style="padding: ' . \esc_attr($cellPadding) . '; color: ' . \esc_attr($textColor) . '; font-size: ' . \esc_attr($fontSize) . '; font-family: ' . \esc_attr($fontFamily) . '; border-bottom: 1px solid ' . \esc_attr($borderColor) . ';">' . \esc_html($productName) . '</td>';
            $rows .= '<td style="padding: ' . \esc_attr($cellPadding) . '; color: ' . \esc_attr($textColor) . '; font-size: ' . \esc_attr($fontSize) . '; font-family: ' . \esc_attr($fontFamily) . '; border-bottom: 1px solid ' . \esc_attr($borderColor) . '; text-align: center;">' . \esc_html($quantity) . '</td>';
            $rows .= '<td style="padding: ' . \esc_attr($cellPadding) . '; color: ' . \esc_attr($textColor) . '; font-size: ' . \esc_attr($fontSize) . '; font-family: ' . \esc_attr($fontFamily) . '; border-bottom: 1px solid ' . \esc_attr($borderColor) . '; text-align: right;">' . \esc_html($itemPrice . ' ' . $currency) . '</td>';
            $rows .= '<td style="padding: ' . \esc_attr($cellPadding) . '; color: ' . \esc_attr($textColor) . '; font-size: ' . \esc_attr($fontSize) . '; font-family: ' . \esc_attr($fontFamily) . '; border-bottom: 1px solid ' . \esc_attr($borderColor) . '; text-align: right; font-weight: bold;">' . \esc_html($itemTotal . ' ' . $currency) . '</td>';
            $rows .= '</tr>';
        }

        return $rows;
    }

    // ─── Published Post Block Rendering ─────────────────────────────────────

    private function renderPostBlocks(string $htmlContent, array $context): string
    {
        preg_match_all(
            '/(<!-- START published post: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END published post -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        $postData = $this->resolvePostData($context);

        if (!$postData) {
            return $htmlContent;
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $configJson = $block[2];
            $endStartComment = $block[3];
            $blockContent = $block[4];
            $endComment = $block[5];

            $config = $this->extractPostBlockConfig($configJson);
            $renderedHtml = $this->generatePostHtml($postData, $config);

            // Extract mj-text attributes to preserve them
            $mjTextAttributes = '';
            if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTextAttributes = $attrMatches[1];
            }

            $newMjText = '<mj-text' . $mjTextAttributes . '>' . $renderedHtml . '</mj-text>';
            $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    private function resolvePostData(array $context): ?array
    {
        $postId = $context['post_id'] ?? 0;
        if (empty($postId)) {
            return null;
        }

        // If enriched fields already exist in context, use them directly
        if (!empty($context['post_title']) && !empty($context['post_url'])) {
            return [
                'post_title'         => $context['post_title'],
                'post_excerpt'       => $context['post_excerpt'] ?? '',
                'post_url'           => $context['post_url'],
                'post_thumbnail_url' => $context['post_thumbnail_url'] ?? '',
            ];
        }

        // Fallback: fetch from WordPress (for backward compat if context not enriched)
        $post = \get_post($postId);
        if (!$post) {
            return null;
        }

        $excerpt = $post->post_excerpt;
        if (empty($excerpt)) {
            $excerpt = \wp_trim_words(\wp_strip_all_tags($post->post_content), 55, '...');
        }

        $thumbnailUrl = '';
        $thumbnailId = \get_post_thumbnail_id($postId);
        if ($thumbnailId) {
            $thumbnailUrl = \wp_get_attachment_image_url($thumbnailId, 'large')
                ?: \wp_get_attachment_image_url($thumbnailId, 'full');
        }

        return [
            'post_title'         => $post->post_title,
            'post_excerpt'       => $excerpt,
            'post_url'           => \get_permalink($postId),
            'post_thumbnail_url' => $thumbnailUrl ?: '',
        ];
    }

    private function extractPostBlockConfig(string $jsonString): array
    {
        $defaultConfig = [
            'showImage'          => true,
            'showExcerpt'        => true,
            'showReadMore'       => true,
            'titleColor'         => '#333333',
            'titleFontSize'      => '22px',
            'excerptColor'       => '#666666',
            'excerptFontSize'    => '14px',
            'buttonBgColor'      => '#0073aa',
            'buttonTextColor'    => '#ffffff',
            'buttonBorderRadius' => '4px',
            'buttonText'         => \__('Read more', 'mailerpress'),
            'imageRadius'        => '0px',
        ];

        $jsonString = html_entity_decode($jsonString, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $jsonString = trim($jsonString);
        $decoded = json_decode($jsonString, true);

        if (is_array($decoded) && !empty($decoded)) {
            return array_merge($defaultConfig, $decoded);
        }

        return $defaultConfig;
    }

    private function generatePostHtml(array $postData, array $config): string
    {
        $html = '';

        // Featured image
        if ($config['showImage'] && !empty($postData['post_thumbnail_url'])) {
            $html .= '<div style="margin-bottom:16px;">';
            $html .= '<a href="' . \esc_url($postData['post_url']) . '" style="text-decoration:none;">';
            $html .= '<img src="' . \esc_url($postData['post_thumbnail_url']) . '" alt="' . \esc_attr($postData['post_title']) . '" width="100%" style="width:100%;height:auto;border-radius:' . \esc_attr($config['imageRadius']) . ';display:block;" />';
            $html .= '</a>';
            $html .= '</div>';
        }

        // Title (linked)
        $html .= '<div style="margin-bottom:12px;">';
        $html .= '<a href="' . \esc_url($postData['post_url']) . '" style="color:' . \esc_attr($config['titleColor']) . ';font-size:' . \esc_attr($config['titleFontSize']) . ';font-weight:bold;text-decoration:none;">' . \esc_html($postData['post_title']) . '</a>';
        $html .= '</div>';

        // Excerpt
        if ($config['showExcerpt'] && !empty($postData['post_excerpt'])) {
            $html .= '<div style="margin-bottom:16px;color:' . \esc_attr($config['excerptColor']) . ';font-size:' . \esc_attr($config['excerptFontSize']) . ';line-height:1.5;">';
            $html .= \esc_html($postData['post_excerpt']);
            $html .= '</div>';
        }

        // Read more button
        if ($config['showReadMore']) {
            $html .= '<div style="margin-top:8px;">';
            $html .= '<a href="' . \esc_url($postData['post_url']) . '" style="display:inline-block;background-color:' . \esc_attr($config['buttonBgColor']) . ';color:' . \esc_attr($config['buttonTextColor']) . ';padding:10px 24px;border-radius:' . \esc_attr($config['buttonBorderRadius']) . ';text-decoration:none;font-weight:bold;font-size:14px;">' . \esc_html($config['buttonText']) . '</a>';
            $html .= '</div>';
        }

        return $html;
    }

    // ─── Coupon Code Block Rendering ────────────────────────────────────────

    private function renderCouponCodeBlocks(string $htmlContent, array $context): string
    {
        preg_match_all(
            '/(<!-- START coupon code: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END coupon code -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $configJson = $block[2];
            $endStartComment = $block[3];
            $blockContent = $block[4];
            $endComment = $block[5];

            $config = $this->extractBlockConfig($configJson, [
                'couponCode' => 'WELCOME20',
                'label' => 'Your exclusive code',
                'expirationText' => '',
                'bgColor' => '#f7f7f7',
                'borderColor' => '#cccccc',
                'borderStyle' => 'dashed',
                'borderRadius' => '8px',
                'align' => 'center',
                'showBox' => true,
                // Code typography
                'code-font-family' => "'Courier New',Courier,monospace",
                'code-font-size' => '28px',
                'code-font-weight' => 'bold',
                'code-line-height' => '',
                'code-letter-spacing' => '3px',
                'code-text-decoration' => 'none',
                'code-text-transform' => 'none',
                'code-color' => '#333333',
                // Label typography
                'label-font-family' => '',
                'label-font-size' => '13px',
                'label-font-weight' => '500',
                'label-line-height' => '',
                'label-letter-spacing' => '',
                'label-text-decoration' => 'none',
                'label-text-transform' => 'none',
                'label-color' => '#666666',
                // Expiration typography
                'expiration-font-family' => '',
                'expiration-font-size' => '13px',
                'expiration-font-weight' => '',
                'expiration-line-height' => '',
                'expiration-letter-spacing' => '',
                'expiration-text-decoration' => 'none',
                'expiration-text-transform' => 'none',
                'expiration-color' => '#999999',
                // Auto-generation settings
                'autoGenerate' => false,
                'discountType' => 'percent',
                'discountAmount' => 10,
                'freeShipping' => false,
                'expiryDays' => 30,
                'minimumAmount' => 0,
                'maximumAmount' => 0,
                'individualUse' => true,
                'excludeSaleItems' => false,
                'usageLimit' => 1,
                'usageLimitPerUser' => 1,
                'prefix' => 'AUTO',
                'allowedEmails' => true,
            ]);

            $couponCode = $config['couponCode'];

            if ($config['autoGenerate'] === true || $config['autoGenerate'] === 'true') {
                $generatedCoupon = $this->generateAutoCoupon($config, $context);

                if ($generatedCoupon) {
                    $couponCode = $generatedCoupon;
                    if ($config['expiryDays'] > 0 && empty($config['expirationText'])) {
                        $expiryDate = new \DateTime();
                        $expiryDate->modify('+' . $config['expiryDays'] . ' days');
                        $config['expirationText'] = sprintf(
                            __('Expires %s', 'mailerpress'),
                            $expiryDate->format(get_option('date_format'))
                        );
                    }
                }
            }

            $codeStyle = $this->buildCouponTypoStyle($config, 'code-', [
                'font-family' => "'Courier New',Courier,monospace",
                'font-size' => '28px',
                'font-weight' => 'bold',
                'letter-spacing' => '3px',
                'color' => '#333333',
            ]);
            $labelStyle = $this->buildCouponTypoStyle($config, 'label-', [
                'font-size' => '13px',
                'font-weight' => '500',
                'color' => '#666666',
            ]);
            $expirationStyle = $this->buildCouponTypoStyle($config, 'expiration-', [
                'font-size' => '13px',
                'color' => '#999999',
            ]);

            $showBox = $config['showBox'] === true || $config['showBox'] === 'true';

            $html = '<div style="text-align:' . \esc_attr($config['align']) . ';">';

            if ($showBox) {
                $html .= '<div style="display:inline-block;background-color:' . \esc_attr($config['bgColor']) . ';border:2px ' . \esc_attr($config['borderStyle']) . ' ' . \esc_attr($config['borderColor']) . ';border-radius:' . \esc_attr($config['borderRadius']) . ';padding:16px 32px;text-align:center;">';
            }

            if (!empty($config['label'])) {
                $html .= '<div style="' . $labelStyle . ';margin-bottom:8px;">' . \esc_html($config['label']) . '</div>';
            }

            $html .= '<div style="' . $codeStyle . '">' . \esc_html($couponCode) . '</div>';

            if (!empty($config['expirationText'])) {
                $html .= '<div style="' . $expirationStyle . ';margin-top:8px;">' . \esc_html($config['expirationText']) . '</div>';
            }

            if ($showBox) {
                $html .= '</div>';
            }

            $html .= '</div>';

            $mjTextAttributes = '';
            if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTextAttributes = $attrMatches[1];
            }

            $newMjText = '<mj-text' . $mjTextAttributes . '>' . $html . '</mj-text>';
            $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    private function buildCouponTypoStyle(array $config, string $prefix, array $defaults = []): string
    {
        $props = ['font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-decoration', 'text-transform', 'color'];
        $parts = [];

        foreach ($props as $prop) {
            $val = !empty($config[$prefix . $prop]) ? $config[$prefix . $prop] : ($defaults[$prop] ?? '');
            if (empty($val) || $val === 'none' && in_array($prop, ['text-decoration', 'text-transform'], true)) {
                continue;
            }
            $parts[] = $prop . ':' . \esc_attr($val);
        }

        return implode(';', $parts);
    }

    // ─── User Profile Card Block Rendering ──────────────────────────────────

    private function renderUserProfileCardBlocks(string $htmlContent, array $context): string
    {
        preg_match_all(
            '/(<!-- START user profile card: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END user profile card -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        $userData = $this->resolveUserData($context);

        if (!$userData) {
            return $htmlContent;
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $configJson = $block[2];
            $endStartComment = $block[3];
            $blockContent = $block[4];
            $endComment = $block[5];

            $config = $this->extractBlockConfig($configJson, [
                'showAvatar' => true,
                'showEmail' => true,
                'showRole' => false,
                'showDate' => false,
                'avatarSize' => '64px',
                'avatarRadius' => '50%',
                'nameColor' => '#333333',
                'nameFontSize' => '18px',
                'emailColor' => '#666666',
                'emailFontSize' => '14px',
                'metaColor' => '#999999',
                'metaFontSize' => '12px',
                'cardBgColor' => '#ffffff',
                'cardBorderRadius' => '8px',
                'cardBorderColor' => '#e0e0e0',
                'align' => 'center',
            ]);

            $html = $this->generateUserProfileCardHtml($userData, $config);

            $mjTextAttributes = '';
            if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTextAttributes = $attrMatches[1];
            }

            $newMjText = '<mj-text' . $mjTextAttributes . '>' . $html . '</mj-text>';
            $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    private function resolveUserData(array $context): ?array
    {
        $userId = $context['user_id'] ?? 0;

        if (empty($userId) || $userId < 0) {
            // For guest users (negative IDs), use context data directly
            if (!empty($context['customer_email'])) {
                return [
                    'display_name' => trim(($context['customer_first_name'] ?? '') . ' ' . ($context['customer_last_name'] ?? '')) ?: $context['customer_email'],
                    'user_email' => $context['customer_email'],
                    'user_role' => \__('Customer', 'mailerpress'),
                    'user_registered' => '',
                ];
            }
            return null;
        }

        $user = \get_userdata($userId);
        if (!$user) {
            return null;
        }

        $roles = $user->roles;
        $role = !empty($roles) ? ucfirst($roles[0]) : '';

        return [
            'display_name' => $user->display_name ?: ($user->first_name . ' ' . $user->last_name),
            'user_email' => $user->user_email,
            'user_role' => $role,
            'user_registered' => \date_i18n(\get_option('date_format'), strtotime($user->user_registered)),
        ];
    }

    private function generateUserProfileCardHtml(array $userData, array $config): string
    {
        $avatarSizeNum = (int) preg_replace('/[^0-9]/', '', $config['avatarSize']);
        $gravatarUrl = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($userData['user_email']))) . '?s=' . ($avatarSizeNum * 2) . '&d=mp';

        $html = '<div style="text-align:' . \esc_attr($config['align']) . ';padding:16px;">';
        $html .= '<table cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;background-color:' . \esc_attr($config['cardBgColor']) . ';border:1px solid ' . \esc_attr($config['cardBorderColor']) . ';border-radius:' . \esc_attr($config['cardBorderRadius']) . ';"><tbody><tr>';

        if ($config['showAvatar']) {
            $html .= '<td style="padding:16px;vertical-align:middle;">';
            $html .= '<img src="' . \esc_url($gravatarUrl) . '" alt="" width="' . $avatarSizeNum . '" height="' . $avatarSizeNum . '" style="width:' . \esc_attr($config['avatarSize']) . ';height:' . \esc_attr($config['avatarSize']) . ';border-radius:' . \esc_attr($config['avatarRadius']) . ';display:block;" />';
            $html .= '</td>';
        }

        $html .= '<td style="padding:16px;vertical-align:middle;text-align:left;">';
        $html .= '<div style="font-size:' . \esc_attr($config['nameFontSize']) . ';font-weight:bold;color:' . \esc_attr($config['nameColor']) . ';padding-bottom:4px;">' . \esc_html($userData['display_name']) . '</div>';

        if ($config['showEmail']) {
            $html .= '<div style="font-size:' . \esc_attr($config['emailFontSize']) . ';color:' . \esc_attr($config['emailColor']) . ';padding-bottom:2px;">' . \esc_html($userData['user_email']) . '</div>';
        }

        if ($config['showRole'] && !empty($userData['user_role'])) {
            $html .= '<div style="font-size:' . \esc_attr($config['metaFontSize']) . ';color:' . \esc_attr($config['metaColor']) . ';padding-bottom:2px;">' . \esc_html($userData['user_role']) . '</div>';
        }

        if ($config['showDate'] && !empty($userData['user_registered'])) {
            $html .= '<div style="font-size:' . \esc_attr($config['metaFontSize']) . ';color:' . \esc_attr($config['metaColor']) . ';">' . \esc_html($userData['user_registered']) . '</div>';
        }

        $html .= '</td></tr></tbody></table></div>';

        return $html;
    }

    // ─── Comment Content Block Rendering ────────────────────────────────────

    private function renderCommentContentBlocks(string $htmlContent, array $context): string
    {
        preg_match_all(
            '/(<!-- START comment content: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END comment content -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        $commentData = $this->resolveCommentData($context);

        if (!$commentData) {
            return $htmlContent;
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $configJson = $block[2];
            $endStartComment = $block[3];
            $blockContent = $block[4];
            $endComment = $block[5];

            $config = $this->extractBlockConfig($configJson, [
                'showAuthor' => true,
                'showDate' => true,
                'showPostLink' => true,
                'borderLeftColor' => '#0073aa',
                'borderLeftWidth' => '4px',
                'bgColor' => '#f9f9f9',
                'contentColor' => '#333333',
                'contentFontSize' => '14px',
                'authorColor' => '#333333',
                'authorFontSize' => '13px',
                'dateColor' => '#999999',
                'postLinkColor' => '#0073aa',
                'borderRadius' => '4px',
            ]);

            $html = $this->generateCommentContentHtml($commentData, $config);

            $mjTextAttributes = '';
            if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTextAttributes = $attrMatches[1];
            }

            $newMjText = '<mj-text' . $mjTextAttributes . '>' . $html . '</mj-text>';
            $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    private function resolveCommentData(array $context): ?array
    {
        // Try enriched context fields first
        if (!empty($context['comment_content'])) {
            return [
                'comment_content' => $context['comment_content'],
                'comment_author' => $context['comment_author'] ?? '',
                'comment_date' => $context['comment_date'] ?? '',
                'post_title' => $context['post_title'] ?? '',
                'post_url' => $context['post_url'] ?? '',
            ];
        }

        // Fallback: fetch from WordPress
        $commentId = $context['comment_id'] ?? 0;
        if (empty($commentId)) {
            return null;
        }

        $comment = \get_comment($commentId);
        if (!$comment) {
            return null;
        }

        $post = \get_post($comment->comment_post_ID);

        return [
            'comment_content' => $comment->comment_content,
            'comment_author' => $comment->comment_author,
            'comment_date' => \date_i18n(\get_option('date_format') . ' ' . \get_option('time_format'), strtotime($comment->comment_date)),
            'post_title' => $post ? $post->post_title : '',
            'post_url' => $post ? \get_permalink($post->ID) : '',
        ];
    }

    private function generateCommentContentHtml(array $commentData, array $config): string
    {
        $html = '<div style="background-color:' . \esc_attr($config['bgColor']) . ';border-left:' . \esc_attr($config['borderLeftWidth']) . ' solid ' . \esc_attr($config['borderLeftColor']) . ';border-radius:' . \esc_attr($config['borderRadius']) . ';padding:16px 20px;">';

        // Comment content in italics
        $html .= '<div style="font-size:' . \esc_attr($config['contentFontSize']) . ';color:' . \esc_attr($config['contentColor']) . ';line-height:1.6;font-style:italic;margin-bottom:12px;">&ldquo;' . \esc_html($commentData['comment_content']) . '&rdquo;</div>';

        // Author and date
        $showAuthor = $config['showAuthor'] && !empty($commentData['comment_author']);
        $showDate = $config['showDate'] && !empty($commentData['comment_date']);
        $showPostLink = $config['showPostLink'] && !empty($commentData['post_url']);

        if ($showAuthor || $showDate) {
            $html .= '<div style="margin-bottom:' . ($showPostLink ? '8px' : '0') . ';">';
            if ($showAuthor) {
                $html .= '<span style="font-weight:bold;color:' . \esc_attr($config['authorColor']) . ';font-size:' . \esc_attr($config['authorFontSize']) . ';">' . \esc_html($commentData['comment_author']) . '</span>';
            }
            if ($showAuthor && $showDate) {
                $html .= ' &mdash; ';
            }
            if ($showDate) {
                $html .= '<span style="color:' . \esc_attr($config['dateColor']) . ';font-size:' . \esc_attr($config['authorFontSize']) . ';">' . \esc_html($commentData['comment_date']) . '</span>';
            }
            $html .= '</div>';
        }

        // Post link
        if ($showPostLink) {
            $postTitle = !empty($commentData['post_title']) ? $commentData['post_title'] : $commentData['post_url'];
            $html .= '<div style="font-size:' . \esc_attr($config['authorFontSize']) . ';"><a href="' . \esc_url($commentData['post_url']) . '" style="color:' . \esc_attr($config['postLinkColor']) . ';text-decoration:none;">' . \esc_html($postTitle) . '</a></div>';
        }

        $html .= '</div>';

        return $html;
    }

    // ─── Subscription Details Card Block Rendering ──────────────────────────

    private function renderSubscriptionDetailsCardBlocks(string $htmlContent, array $context): string
    {
        preg_match_all(
            '/(<!-- START subscription details card: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END subscription details card -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        $subscriptionData = $this->resolveSubscriptionData($context);

        if (!$subscriptionData) {
            return $htmlContent;
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $configJson = $block[2];
            $endStartComment = $block[3];
            $blockContent = $block[4];
            $endComment = $block[5];

            $config = $this->extractBlockConfig($configJson, [
                'showStatus' => true,
                'showBillingCycle' => true,
                'showNextPayment' => true,
                'showTotal' => true,
                'showTrialEnd' => false,
                'labelColor' => '#666666',
                'valueColor' => '#333333',
                'fontSize' => '14px',
                'bgColor' => '#ffffff',
                'borderColor' => '#e0e0e0',
                'borderRadius' => '8px',
                'statusActiveColor' => '#46b450',
                'statusCancelledColor' => '#dc3232',
            ]);

            $html = $this->generateSubscriptionDetailsHtml($subscriptionData, $config);

            $mjTextAttributes = '';
            if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTextAttributes = $attrMatches[1];
            }

            $newMjText = '<mj-text' . $mjTextAttributes . '>' . $html . '</mj-text>';
            $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    private function resolveSubscriptionData(array $context): ?array
    {
        $subscriptionId = $context['subscription_id'] ?? 0;

        if (empty($subscriptionId) || !function_exists('wcs_get_subscription')) {
            return null;
        }

        try {
            $subscription = \wcs_get_subscription($subscriptionId);
            if (!$subscription) {
                return null;
            }

            $status = $subscription->get_status();
            $billingPeriod = $subscription->get_billing_period();
            $billingInterval = $subscription->get_billing_interval();

            $billingCycle = $billingInterval > 1
                ? sprintf(\__('Every %d %ss', 'mailerpress'), $billingInterval, $billingPeriod)
                : sprintf(\__('Every %s', 'mailerpress'), $billingPeriod);

            $nextPayment = $subscription->get_date('next_payment');
            $trialEnd = $subscription->get_date('trial_end');

            return [
                'status' => ucfirst($status),
                'status_raw' => $status,
                'billing_cycle' => $billingCycle,
                'total' => $subscription->get_formatted_order_total(),
                'next_payment' => $nextPayment ? \date_i18n(\get_option('date_format'), strtotime($nextPayment)) : '',
                'trial_end' => $trialEnd ? \date_i18n(\get_option('date_format'), strtotime($trialEnd)) : '',
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function generateSubscriptionDetailsHtml(array $data, array $config): string
    {
        $rows = [];

        if ($config['showStatus']) {
            $statusColor = in_array($data['status_raw'], ['active', 'on-hold', 'pending']) ? $config['statusActiveColor'] : $config['statusCancelledColor'];
            $rows[] = [
                'label' => \__('Status', 'mailerpress'),
                'value' => '<span style="color:' . \esc_attr($statusColor) . ';font-weight:bold;">' . \esc_html($data['status']) . '</span>',
                'raw' => false,
            ];
        }

        if ($config['showBillingCycle'] && !empty($data['billing_cycle'])) {
            $rows[] = ['label' => \__('Billing Cycle', 'mailerpress'), 'value' => $data['billing_cycle']];
        }

        if ($config['showTotal'] && !empty($data['total'])) {
            $rows[] = ['label' => \__('Amount', 'mailerpress'), 'value' => $data['total'], 'raw' => false];
        }

        if ($config['showNextPayment'] && !empty($data['next_payment'])) {
            $rows[] = ['label' => \__('Next Payment', 'mailerpress'), 'value' => $data['next_payment']];
        }

        if ($config['showTrialEnd'] && !empty($data['trial_end'])) {
            $rows[] = ['label' => \__('Trial End', 'mailerpress'), 'value' => $data['trial_end']];
        }

        if (empty($rows)) {
            return '';
        }

        $html = '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:' . \esc_attr($config['bgColor']) . ';border:1px solid ' . \esc_attr($config['borderColor']) . ';border-radius:' . \esc_attr($config['borderRadius']) . ';"><tbody>';

        foreach ($rows as $index => $row) {
            $isLast = $index === count($rows) - 1;
            $borderStyle = $isLast ? '' : 'border-bottom:1px solid ' . \esc_attr($config['borderColor']) . ';';
            $valueHtml = isset($row['raw']) && $row['raw'] === false ? $row['value'] : \esc_html($row['value']);

            $html .= '<tr>';
            $html .= '<td style="padding:12px 16px;font-size:' . \esc_attr($config['fontSize']) . ';color:' . \esc_attr($config['labelColor']) . ';' . $borderStyle . 'font-weight:500;width:40%;">' . \esc_html($row['label']) . '</td>';
            $html .= '<td style="padding:12px 16px;font-size:' . \esc_attr($config['fontSize']) . ';color:' . \esc_attr($config['valueColor']) . ';' . $borderStyle . 'text-align:right;">' . $valueHtml . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    // ─── Cart Recovery Button Block Rendering ───────────────────────────────

    private function renderCartRecoveryButtonBlocks(string $htmlContent, array $context): string
    {
        preg_match_all(
            '/(<!-- START cart recovery button: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END cart recovery button -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        // Resolve recovery URL
        $recoveryUrl = $context['cart_recovery_url'] ?? '';
        if (empty($recoveryUrl) && !empty($context['cart_hash']) && function_exists('wc_get_cart_url')) {
            $recoveryUrl = \add_query_arg('recover_cart', $context['cart_hash'], \wc_get_cart_url());
        }

        if (empty($recoveryUrl)) {
            $recoveryUrl = '#';
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $configJson = $block[2];
            $endStartComment = $block[3];
            $blockContent = $block[4];
            $endComment = $block[5];

            $config = $this->extractBlockConfig($configJson, [
                'buttonText' => 'Complete your order',
                'bgColor' => '#0073aa',
                'textColor' => '#ffffff',
                'borderRadius' => '4px',
                'fontSize' => '16px',
                'paddingV' => '14px',
                'paddingH' => '32px',
                'align' => 'center',
                'fullWidth' => false,
            ]);

            $widthStyle = $config['fullWidth']
                ? 'display:block;width:100%;text-align:center;box-sizing:border-box;'
                : 'display:inline-block;';

            $html = '<div style="text-align:' . \esc_attr($config['align']) . ';padding:8px 0;">';
            $html .= '<a href="' . \esc_url($recoveryUrl) . '" style="' . $widthStyle . 'background-color:' . \esc_attr($config['bgColor']) . ';color:' . \esc_attr($config['textColor']) . ';padding:' . \esc_attr($config['paddingV']) . ' ' . \esc_attr($config['paddingH']) . ';border-radius:' . \esc_attr($config['borderRadius']) . ';text-decoration:none;font-weight:bold;font-size:' . \esc_attr($config['fontSize']) . ';font-family:Arial,sans-serif;">' . \esc_html($config['buttonText']) . '</a>';
            $html .= '</div>';

            $mjTextAttributes = '';
            if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTextAttributes = $attrMatches[1];
            }

            $newMjText = '<mj-text' . $mjTextAttributes . '>' . $html . '</mj-text>';
            $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    // ─── Product Showcase Block Rendering ───────────────────────────────────

    private function renderProductShowcaseBlocks(string $htmlContent, array $context): string
    {
        preg_match_all(
            '/(<!-- START product showcase: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END product showcase -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        $productData = $this->resolveProductData($context);

        if (!$productData) {
            return $htmlContent;
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $configJson = $block[2];
            $endStartComment = $block[3];
            $blockContent = $block[4];
            $endComment = $block[5];

            $config = $this->extractBlockConfig($configJson, [
                'showImage' => true,
                'showDescription' => true,
                'showPrice' => true,
                'showButton' => true,
                'titleColor' => '#333333',
                'titleFontSize' => '20px',
                'descriptionColor' => '#666666',
                'descriptionFontSize' => '14px',
                'priceColor' => '#0073aa',
                'priceFontSize' => '24px',
                'buttonText' => 'Shop now',
                'buttonBgColor' => '#0073aa',
                'buttonTextColor' => '#ffffff',
                'buttonBorderRadius' => '4px',
                'imageRadius' => '8px',
            ]);

            $html = $this->generateProductShowcaseHtml($productData, $config);

            $mjTextAttributes = '';
            if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTextAttributes = $attrMatches[1];
            }

            $newMjText = '<mj-text' . $mjTextAttributes . '>' . $html . '</mj-text>';
            $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    private function resolveProductData(array $context): ?array
    {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        // Try product_id from context
        $productId = $context['product_id'] ?? 0;

        // Fallback: get first product from order items
        if (empty($productId) && !empty($context['order_id'])) {
            try {
                $order = \wc_get_order($context['order_id']);
                if ($order) {
                    $items = $order->get_items();
                    foreach ($items as $item) {
                        $productId = $item->get_product_id();
                        if ($productId) {
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                // ignore
            }
        }

        // Fallback: get first product from cart items
        if (empty($productId) && !empty($context['cart_items']) && is_array($context['cart_items'])) {
            $firstItem = $context['cart_items'][0] ?? null;
            if ($firstItem) {
                $productId = $firstItem['product_id'] ?? 0;
            }
        }

        if (empty($productId)) {
            return null;
        }

        try {
            $product = \wc_get_product($productId);
            if (!$product) {
                return null;
            }

            $thumbnailUrl = '';
            $thumbnailId = $product->get_image_id();
            if ($thumbnailId) {
                $thumbnailUrl = \wp_get_attachment_image_url($thumbnailId, 'large')
                    ?: \wp_get_attachment_image_url($thumbnailId, 'full');
            }
            if (empty($thumbnailUrl) && function_exists('wc_placeholder_img_src')) {
                $thumbnailUrl = \wc_placeholder_img_src('woocommerce_thumbnail');
            }

            $currency = \get_woocommerce_currency();

            return [
                'product_name' => $product->get_name(),
                'product_price' => $product->get_price(),
                'product_price_formatted' => \wc_price($product->get_price()),
                'product_description' => \wp_trim_words(\wp_strip_all_tags($product->get_short_description() ?: $product->get_description()), 30, '...'),
                'product_url' => \get_permalink($productId),
                'product_image_url' => $thumbnailUrl,
                'product_currency' => $currency,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function generateProductShowcaseHtml(array $data, array $config): string
    {
        $html = '';

        // Product image
        if ($config['showImage'] && !empty($data['product_image_url'])) {
            $html .= '<div style="margin-bottom:16px;">';
            $html .= '<a href="' . \esc_url($data['product_url']) . '" style="text-decoration:none;">';
            $html .= '<img src="' . \esc_url($data['product_image_url']) . '" alt="' . \esc_attr($data['product_name']) . '" width="100%" style="width:100%;height:auto;border-radius:' . \esc_attr($config['imageRadius']) . ';display:block;" />';
            $html .= '</a>';
            $html .= '</div>';
        }

        // Product name
        $html .= '<div style="margin-bottom:8px;">';
        $html .= '<a href="' . \esc_url($data['product_url']) . '" style="color:' . \esc_attr($config['titleColor']) . ';font-size:' . \esc_attr($config['titleFontSize']) . ';font-weight:bold;text-decoration:none;">' . \esc_html($data['product_name']) . '</a>';
        $html .= '</div>';

        // Price
        if ($config['showPrice'] && !empty($data['product_price'])) {
            $html .= '<div style="margin-bottom:12px;font-size:' . \esc_attr($config['priceFontSize']) . ';font-weight:bold;color:' . \esc_attr($config['priceColor']) . ';">' . $data['product_price_formatted'] . '</div>';
        }

        // Description
        if ($config['showDescription'] && !empty($data['product_description'])) {
            $html .= '<div style="margin-bottom:16px;color:' . \esc_attr($config['descriptionColor']) . ';font-size:' . \esc_attr($config['descriptionFontSize']) . ';line-height:1.5;">' . \esc_html($data['product_description']) . '</div>';
        }

        // Button
        if ($config['showButton']) {
            $html .= '<div style="margin-top:8px;">';
            $html .= '<a href="' . \esc_url($data['product_url']) . '" style="display:inline-block;background-color:' . \esc_attr($config['buttonBgColor']) . ';color:' . \esc_attr($config['buttonTextColor']) . ';padding:12px 28px;border-radius:' . \esc_attr($config['buttonBorderRadius']) . ';text-decoration:none;font-weight:bold;font-size:14px;">' . \esc_html($config['buttonText']) . '</a>';
            $html .= '</div>';
        }

        return $html;
    }

    // ─── Product Review Block Rendering ────────────────────────────────────

    private function renderProductReviewBlocks(string $htmlContent, array $context): string
    {
        preg_match_all(
            '/(<!-- START product review: BLOCK_CONFIG:)(.*?)(-->)(.*?)(<!-- END product review -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        $reviewProducts = $this->resolveProductReviewData($context);

        if (empty($reviewProducts)) {
            return $htmlContent;
        }

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $configJson = $block[2];
            $endStartComment = $block[3];
            $blockContent = $block[4];
            $endComment = $block[5];

            $config = $this->extractBlockConfig($configJson, [
                'showImage' => true,
                'imageSize' => '80px',
                'imageRadius' => '8px',
                'productNameColor' => '#333333',
                'productNameFontSize' => '16px',
                'productNameFontWeight' => 'bold',
                'buttonText' => \__('Leave a Review', 'mailerpress'),
                'buttonBgColor' => '#0073aa',
                'buttonTextColor' => '#ffffff',
                'buttonBorderRadius' => '4px',
                'buttonFontSize' => '14px',
                'cardBackgroundColor' => '#ffffff',
                'cardPadding' => '12px',
                'cardBorderRadius' => '8px',
                'showSeparator' => true,
                'separatorColor' => '#e0e0e0',
                'separatorStyle' => 'solid',
                'separatorWidth' => '1px',
            ]);

            $html = $this->generateProductReviewCardsHtml($reviewProducts, $config);

            $mjTextAttributes = '';
            if (preg_match('/<mj-text([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTextAttributes = $attrMatches[1];
            }

            $newMjText = '<mj-text' . $mjTextAttributes . '>' . $html . '</mj-text>';
            $newBlock = $startComment . $configJson . $endStartComment . $newMjText . $endComment;

            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    private function resolveProductReviewData(array $context): array
    {
        $products = [];
        $processedProductIds = [];
        $orderId = $context['order_id'] ?? 0;

        if (!function_exists('wc_get_order') || empty($orderId)) {
            return $this->resolveProductReviewFromContextItems($context);
        }

        try {
            $order = \wc_get_order($orderId);
            if (!$order) {
                return $this->resolveProductReviewFromContextItems($context);
            }

            foreach ($order->get_items() as $item) {
                $productId = $item->get_product_id();
                $variationId = $item->get_variation_id();

                $resolvedId = $productId;
                if ($variationId > 0) {
                    $variation = \wc_get_product($variationId);
                    if ($variation && $variation->get_parent_id()) {
                        $resolvedId = $variation->get_parent_id();
                    }
                }

                if (in_array($resolvedId, $processedProductIds, true)) {
                    continue;
                }
                $processedProductIds[] = $resolvedId;

                $product = \wc_get_product($resolvedId);
                if (!$product) {
                    continue;
                }

                $thumbnailUrl = '';
                $thumbnailId = $product->get_image_id();
                if ($thumbnailId) {
                    $thumbnailUrl = \wp_get_attachment_image_url($thumbnailId, 'woocommerce_thumbnail')
                        ?: \wp_get_attachment_image_url($thumbnailId, 'medium')
                        ?: \wp_get_attachment_image_url($thumbnailId, 'full');
                }
                if (empty($thumbnailUrl) && function_exists('wc_placeholder_img_src')) {
                    $thumbnailUrl = \wc_placeholder_img_src('woocommerce_thumbnail');
                }

                $reviewUrl = \get_permalink($resolvedId);
                if ($reviewUrl) {
                    $reviewUrl .= '#reviews';
                }

                $products[] = [
                    'product_id' => $resolvedId,
                    'product_name' => $product->get_name(),
                    'thumbnail_url' => $thumbnailUrl ?: '',
                    'review_url' => $reviewUrl ?: '#',
                ];
            }
        } catch (\Throwable $e) {
            return $this->resolveProductReviewFromContextItems($context);
        }

        return $products;
    }

    private function resolveProductReviewFromContextItems(array $context): array
    {
        if (empty($context['order_items']) || !is_array($context['order_items'])) {
            return [];
        }

        $products = [];
        $processedProductIds = [];

        foreach ($context['order_items'] as $item) {
            $productId = $item['product_id'] ?? 0;
            if (empty($productId) || in_array($productId, $processedProductIds, true)) {
                continue;
            }
            $processedProductIds[] = $productId;

            $thumbnailUrl = $item['thumbnail_url'] ?? '';
            $reviewUrl = '#';

            if (function_exists('wc_get_product')) {
                try {
                    $product = \wc_get_product($productId);
                    if ($product) {
                        if (empty($thumbnailUrl)) {
                            $thumbnailId = $product->get_image_id();
                            if ($thumbnailId) {
                                $thumbnailUrl = \wp_get_attachment_image_url($thumbnailId, 'woocommerce_thumbnail')
                                    ?: \wp_get_attachment_image_url($thumbnailId, 'medium')
                                    ?: '';
                            }
                        }
                        $permalink = \get_permalink($productId);
                        if ($permalink) {
                            $reviewUrl = $permalink . '#reviews';
                        }
                    }
                } catch (\Throwable $e) {
                    // use context data as-is
                }
            }

            $products[] = [
                'product_id' => $productId,
                'product_name' => $item['product_name'] ?? '',
                'thumbnail_url' => $thumbnailUrl,
                'review_url' => $reviewUrl,
            ];
        }

        return $products;
    }

    private function generateProductReviewCardsHtml(array $products, array $config): string
    {
        $showImage = $config['showImage'] ?? true;
        $imageSize = $config['imageSize'] ?? '80px';
        $imageRadius = $config['imageRadius'] ?? '8px';
        $productNameColor = $config['productNameColor'] ?? '#333333';
        $productNameFontSize = $config['productNameFontSize'] ?? '16px';
        $productNameFontWeight = $config['productNameFontWeight'] ?? 'bold';
        $buttonText = $config['buttonText'] ?? \__('Leave a Review', 'mailerpress');
        $buttonBgColor = $config['buttonBgColor'] ?? '#0073aa';
        $buttonTextColor = $config['buttonTextColor'] ?? '#ffffff';
        $buttonBorderRadius = $config['buttonBorderRadius'] ?? '4px';
        $buttonFontSize = $config['buttonFontSize'] ?? '14px';
        $cardBackgroundColor = $config['cardBackgroundColor'] ?? '#ffffff';
        $cardPadding = $config['cardPadding'] ?? '12px';
        $cardBorderRadius = $config['cardBorderRadius'] ?? '8px';
        $showSeparator = $config['showSeparator'] ?? true;
        $separatorColor = $config['separatorColor'] ?? '#e0e0e0';
        $separatorStyle = $config['separatorStyle'] ?? 'solid';
        $separatorWidth = $config['separatorWidth'] ?? '1px';

        $imageSizeNum = (int) preg_replace('/[^0-9]/', '', $imageSize);

        $html = '';

        foreach ($products as $index => $item) {
            $productName = $item['product_name'] ?? '';
            $thumbnailUrl = $item['thumbnail_url'] ?? '';
            $reviewUrl = $item['review_url'] ?? '#';

            $html .= '<!-- ITEM_START -->';
            $html .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:' . \esc_attr($cardBackgroundColor) . ';border-radius:' . \esc_attr($cardBorderRadius) . ';"><tbody><tr>';

            if ($showImage) {
                $html .= '<td style="padding:' . \esc_attr($cardPadding) . ';width:' . ($imageSizeNum + 16) . 'px;vertical-align:middle;">';
                if (!empty($thumbnailUrl)) {
                    $html .= '<img src="' . \esc_url($thumbnailUrl) . '" alt="' . \esc_attr($productName) . '" width="' . $imageSizeNum . '" style="width:' . \esc_attr($imageSize) . ';height:auto;border-radius:' . \esc_attr($imageRadius) . ';display:block;" />';
                }
                $html .= '</td>';
            }

            $html .= '<td style="padding:' . \esc_attr($cardPadding) . ';vertical-align:middle;">';
            $html .= '<div style="font-size:' . \esc_attr($productNameFontSize) . ';font-weight:' . \esc_attr($productNameFontWeight) . ';color:' . \esc_attr($productNameColor) . ';padding-bottom:8px;">' . \esc_html($productName) . '</div>';
            $html .= '<a href="' . \esc_url($reviewUrl) . '" style="display:inline-block;background-color:' . \esc_attr($buttonBgColor) . ';color:' . \esc_attr($buttonTextColor) . ';padding:8px 20px;border-radius:' . \esc_attr($buttonBorderRadius) . ';text-decoration:none;font-weight:bold;font-size:' . \esc_attr($buttonFontSize) . ';">' . \esc_html($buttonText) . '</a>';
            $html .= '</td>';

            $html .= '</tr></tbody></table>';
            $html .= '<!-- ITEM_END -->';

            if ($showSeparator && $index < count($products) - 1) {
                $html .= '<div style="border-top:' . \esc_attr($separatorWidth) . ' ' . \esc_attr($separatorStyle) . ' ' . \esc_attr($separatorColor) . ';margin:4px 0;"></div>';
            }
        }

        return $html;
    }

    // ─── Shared Helpers ─────────────────────────────────────────────────────

    /**
     * Generic BLOCK_CONFIG JSON extractor with defaults
     */
    private function extractBlockConfig(string $jsonString, array $defaults): array
    {
        $jsonString = html_entity_decode($jsonString, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $jsonString = trim($jsonString);
        $decoded = json_decode($jsonString, true);

        if (is_array($decoded) && !empty($decoded)) {
            return array_merge($defaults, $decoded);
        }

        return $defaults;
    }

    /**
     * Generate auto coupon using CouponGenerator service
     */
    private function generateAutoCoupon(array $config, array $context): ?string
    {
        $couponGenerator = new CouponGenerator();

        $settings = [
            'discountType' => $config['discountType'] ?? 'percent',
            'discountAmount' => $config['discountAmount'] ?? 10,
            'freeShipping' => $config['freeShipping'] ?? false,
            'expiryDays' => $config['expiryDays'] ?? 30,
            'minimumAmount' => $config['minimumAmount'] ?? 0,
            'maximumAmount' => $config['maximumAmount'] ?? 0,
            'individualUse' => $config['individualUse'] ?? true,
            'excludeSaleItems' => $config['excludeSaleItems'] ?? false,
            'usageLimit' => $config['usageLimit'] ?? 1,
            'usageLimitPerUser' => $config['usageLimitPerUser'] ?? 1,
            'prefix' => $config['prefix'] ?? 'AUTO',
            'allowedEmails' => $config['allowedEmails'] ?? true,
        ];

        return $couponGenerator->getOrGenerateCoupon($settings, $context);
    }
}
