<?php

namespace MailerPress\Core;

use DOMNodeList;
use WP_Query;
use WP_Post;
use DOMDocument;
use DOMXPath;

class DynamicPostRenderer
{
    protected string $html;
    protected array $excludedPostIds = [];
    protected array $usedPostIds = [];
    protected ?int $campaignId = null;
    protected bool $skipIfNoNewContent = false;

    protected array $postTypeMap = [
        'posts' => 'post',
        'pages' => 'page',
    ];

    public function __construct(string $html)
    {
        $this->html = $html;
    }

    public function setExcludedPostIds(array $ids): self
    {
        $this->excludedPostIds = $ids;
        return $this;
    }

    public function setCampaignId(int $id): self
    {
        $this->campaignId = $id;
        $this->excludedPostIds = array_unique(array_merge(
            $this->excludedPostIds,
            get_option("mailerpress_processed_post_ids_{$id}", [])
        ));
        return $this;
    }

    public function setSkipIfNoNewContent(bool $skip): self
    {
        $this->skipIfNoNewContent = $skip;
        return $this;
    }

    public function render(): string
    {
        preg_match_all(
            '/(<!-- START query block:\s*(\{.*?\})\s*-->)(.*?)(<!-- END query block -->)/is',
            $this->html,
            $blocks,
            PREG_SET_ORDER
        );

        $allBlocksEmpty = true;

        foreach ($blocks as $block) {
            $fullMatch = $block[0];
            $queryStartComment = $block[1];
            $queryJson = $block[2];
            $queryInnerHtml = $block[3];
            $queryEndComment = $block[4];

            $queryArgs = $this->parseQueryArgs($queryJson, array_merge($this->excludedPostIds, $this->usedPostIds));
            if (!$queryArgs) {
                $this->html = str_replace($fullMatch, '', $this->html);
                continue;
            }

            if ($this->skipIfNoNewContent && !empty($this->excludedPostIds)) {
                $checkArgs = $this->parseQueryArgs($queryJson, []);
                if ($checkArgs) {
                    $checkArgs['posts_per_page'] = 1;
                    $newestPosts = $this->fetchPosts($checkArgs);
                    if (!empty($newestPosts) && in_array($newestPosts[0]->ID, array_merge($this->excludedPostIds, $this->usedPostIds), true)) {
                        $this->html = str_replace($fullMatch, '', $this->html);
                        continue;
                    }
                }
            }

            $posts = $this->fetchPosts($queryArgs);
            if (empty($posts)) {
                $this->html = str_replace($fullMatch, '', $this->html);
                continue;
            }

            $allBlocksEmpty = false;
            $postGlobalIndex = 0;

            // 1️⃣ Extract the container wrapper (div or table) from the original HTML
            if (preg_match('/(<div[^>]+class="[^"]*node-client-[^"]*"[^>]*>)/is', $queryInnerHtml, $containerMatch)) {
                $containerOpenTag = $containerMatch[1];
                $containerCloseTag = '</div>';
                $innerContent = preg_replace('/^' . preg_quote(
                    $containerOpenTag,
                    '/'
                ) . '|' . preg_quote($containerCloseTag, '/') . '$/s', '', $queryInnerHtml);
            } else {
                // fallback: no wrapper found
                $containerOpenTag = '';
                $containerCloseTag = '';
                $innerContent = $queryInnerHtml;
            }

            // 2️⃣ Process GRID blocks
            if (preg_match_all(
                '/<!-- GRID post -->(.*?)<!-- \/GRID post -->/is',
                $innerContent,
                $gridMatches,
                PREG_SET_ORDER
            )) {
                foreach ($gridMatches as $gridMatch) {
                    $gridBlockHtml = $gridMatch[0];
                    $gridInnerHtml = $gridMatch[1];

                    preg_match_all(
                        '/(<!-- START post -->(.*?)<!-- END post -->)/is',
                        $gridInnerHtml,
                        $postWrappers,
                        PREG_SET_ORDER
                    );
                    $wrapperCount = count($postWrappers);

                    if ($wrapperCount > 0) {
                        $renderedGridHtml = $gridInnerHtml;

                        for ($i = 0; $i < $wrapperCount; $i++) {
                            $fullPostWrapper = $postWrappers[$i][1];

                            if ($postGlobalIndex >= count($posts)) {
                                $renderedGridHtml = str_replace($fullPostWrapper, '', $renderedGridHtml);
                                continue;
                            }

                            $post = $posts[$postGlobalIndex];
                            $postTemplate = $postWrappers[$i][2];

                            $renderedContent = $this->renderPostTemplate($post, $postTemplate);
                            $replacement = "<!-- START post -->{$renderedContent}<!-- END post -->";

                            $renderedGridHtml = preg_replace(
                                '/' . preg_quote($fullPostWrapper, '/') . '/',
                                $replacement,
                                $renderedGridHtml,
                                1
                            );

                            $this->usedPostIds[] = $post->ID;
                            $postGlobalIndex++;
                        }

                        $updatedGridHtml = '<!-- GRID post -->' . $renderedGridHtml . '<!-- /GRID post -->';
                        $innerContent = str_replace($gridBlockHtml, $updatedGridHtml, $innerContent);
                    }
                }
            } else {
                // 3️⃣ No grid, just single posts
                preg_match_all(
                    '/<!-- START post -->(.*?)<!-- END post -->/is',
                    $innerContent,
                    $postWrappers,
                    PREG_SET_ORDER
                );
                if (!empty($postWrappers)) {
                    $renderedPosts = '';
                    $wrapperCount = count($postWrappers);

                    foreach ($posts as $post) {
                        $wrapperIndex = count($this->usedPostIds) % $wrapperCount;
                        $postTemplate = $postWrappers[$wrapperIndex][1];
                        $renderedContent = $this->renderPostTemplate($post, $postTemplate);
                        $renderedPosts .= "<!-- START post -->{$renderedContent}<!-- END post -->";

                        $this->usedPostIds[] = $post->ID;
                    }

                    $innerContent = $renderedPosts;
                } else {
                    $this->html = str_replace($fullMatch, '', $this->html);
                    continue;
                }
            }

            // 4️⃣ Wrap back the original container
            $replacementBlock = $queryStartComment . $containerOpenTag . $innerContent . $containerCloseTag . $queryEndComment;
            $this->html = str_replace($fullMatch, $replacementBlock, $this->html);
        }

        if ($allBlocksEmpty) {
            return '';
        }

        $this->storeUsedPostIds();

        return $this->html;
    }

    protected function storeUsedPostIds(): void
    {
        if ($this->campaignId === null || empty($this->usedPostIds)) {
            return;
        }

        $optionKey = "mailerpress_processed_post_ids_{$this->campaignId}";
        $existing = get_option($optionKey, []);
        $merged = array_unique(array_merge($existing, $this->usedPostIds));
        update_option($optionKey, $merged);
    }

    protected function parseQueryArgs(string $json, array $excludeIds = []): ?array
    {
        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            return null;
        }

        $postTypeRaw = strtolower($parsed['postType'] ?? 'post');
        $postType = $this->postTypeMap[$postTypeRaw] ?? $postTypeRaw;

        $orderby = 'date';
        $order = 'DESC';

        if (!empty($parsed['order']) && is_string($parsed['order'])) {
            [$maybeOrderby, $maybeOrder] = array_pad(explode('/', strtolower($parsed['order'])), 2, null);
            if (in_array($maybeOrderby, ['date', 'title', 'modified', 'rand'])) {
                $orderby = $maybeOrderby;
            }
            if (in_array($maybeOrder, ['asc', 'desc'])) {
                $order = strtoupper($maybeOrder);
            }
        }

        $postsPerPage = isset($parsed['per_page']) ? intval($parsed['per_page']) : 1;
        $offset = isset($parsed['offset']) ? intval($parsed['offset']) : 0;

        $query = [
            'post_type' => sanitize_key($postType),
            'post_status' => 'publish',
            'posts_per_page' => $postsPerPage > 0 ? $postsPerPage : 1,
            'offset' => $offset,
            'orderby' => $orderby,
            'order' => $order,
            'post__not_in' => $excludeIds,
        ];

        if (!empty($parsed['search'])) {
            $query['s'] = sanitize_text_field($parsed['search']);
        }

        $taxQuery = [];

        $taxonomies = get_object_taxonomies($postType, 'objects');

        $taxonomyMap = [];
        foreach ($taxonomies as $taxonomy) {
            // Special mapping for standard taxonomies
            if ($taxonomy->name === 'category') {
                $taxonomyMap['categories'] = $taxonomy;
            } elseif ($taxonomy->name === 'post_tag') {
                $taxonomyMap['tags'] = $taxonomy;
            }

            $restBase = $taxonomy->rest_base ?? $taxonomy->name;
            $taxonomyMap[$restBase] = $taxonomy;

            $taxonomyMap[$taxonomy->name] = $taxonomy;
        }

        // Loop through all parameters to find those that match taxonomies
        foreach ($parsed as $paramKey => $paramValue) {
            // Ignore already processed or non-taxonomy parameters
            if (in_array($paramKey, ['postType', 'per_page', 'order', 'author', 'search', 'metaFilters', 'metaRelation'])) {
                continue;
            }

            // Check if this parameter corresponds to a taxonomy
            if (isset($taxonomyMap[$paramKey]) && !empty($paramValue) && is_array($paramValue)) {
                $taxonomy = $taxonomyMap[$paramKey];

                // Convert value to array of IDs
                $termIds = array_filter(array_map('intval', $paramValue));

                if (!empty($termIds)) {
                    $taxQuery[] = [
                        'taxonomy' => $taxonomy->name,
                        'field' => 'term_id',
                        'terms' => $termIds,
                    ];
                }
            }
        }

        if (!empty($taxQuery)) {
            $query['tax_query'] = $taxQuery;
        }

        if (!empty($parsed['author'])) {
            $query['author__in'] = array_map('intval', $parsed['author']);
        }

        return apply_filters('mailerpress_dynamic_post_query_args', $query, $parsed);
    }

    protected function fetchPosts(array $args): array
    {
        $args['suppress_filters'] = false;
        $args['no_found_rows'] = true;
        $query = new WP_Query($args);

        if ( ! $query->have_posts() ) {
            global $wp_filter;

            $hooks_to_bypass = [ 'posts_pre_query', 'pre_get_posts', 'parse_query' ];
            $saved_hooks     = [];

            foreach ( $hooks_to_bypass as $hook ) {
                if ( isset( $wp_filter[ $hook ] ) ) {
                    $saved_hooks[ $hook ] = clone $wp_filter[ $hook ];
                    remove_all_filters( $hook );
                }
            }

            $args['suppress_filters'] = true;
            $query = new WP_Query( $args );

            foreach ( $saved_hooks as $hook => $filter_obj ) {
                $wp_filter[ $hook ] = $filter_obj;
            }
        }

        if ( ! $query->have_posts() && ! empty( $args['post_type'] ) && empty($args['tax_query']) && empty($args['meta_query']) && empty($args['author__in']) && empty($args['s']) ) {
            global $wpdb;

            $post_type = sanitize_key( $args['post_type'] );
            $per_page  = absint( $args['posts_per_page'] ?? 10 );
            $offset    = absint( $args['offset'] ?? 0 );
            $order     = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
            $orderby   = in_array( $args['orderby'] ?? 'date', [ 'date', 'title', 'modified' ], true )
                ? 'post_' . ( $args['orderby'] ?? 'date' )
                : 'post_date';

            $exclude_sql = '';
            if ( ! empty( $args['post__not_in'] ) ) {
                $ids         = implode( ',', array_map( 'absint', $args['post__not_in'] ) );
                $exclude_sql = " AND ID NOT IN ({$ids})";
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $raw = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'{$exclude_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                    $post_type,
                    $per_page,
                    $offset
                )
            );

            if ( ! empty( $raw ) ) {
                wp_reset_postdata();
                return array_map( fn( $row ) => new \WP_Post( $row ), $raw );
            }
        }

        $posts = $query->have_posts() ? $query->posts : [];
        wp_reset_postdata();
        return $posts;
    }

    protected function renderPostTemplate(WP_Post $post, string $template): string
    {
        return preg_replace_callback(
            '/<!-- START ([a-zA-Z0-9-_ :]+) -->(.*?)<!-- END ([a-zA-Z0-9-_ ]+) -->/is',
            function ($blockMatches) use ($post) {
                $blockNameWithKey = trim($blockMatches[1]);
                $blockContent = $blockMatches[2];

                // Extract block name and optional field key (for ACF fields) or block params
                $blockName = $blockNameWithKey;
                $fieldKey = null;
                $linkToPost = false;
                $blockParams = '';

                if (strpos($blockNameWithKey, 'post acf field:') === 0) {
                    // Parse: "post acf field:fieldKey:linkToPost=1" or "post acf field:fieldKey:linkToPost=0"
                    $blockName = 'post acf field';
                    // Remove "post acf field:" prefix
                    $rest = substr($blockNameWithKey, strlen('post acf field:'));
                    // Check if linkToPost is present
                    if (preg_match('/^(.+?):linkToPost=([01])$/', $rest, $matches)) {
                        $fieldKey = $matches[1];
                        $linkToPost = $matches[2] === '1';
                    } else {
                        // Old format without linkToPost (backward compatibility)
                        $fieldKey = $rest;
                    }
                } elseif (strpos($blockNameWithKey, 'post meta:') === 0) {
                    $blockName = 'post meta';
                    $rest = substr($blockNameWithKey, strlen('post meta:'));
                    if (preg_match('/^(.+?):format=(.*)$/', $rest, $fmtMatch)) {
                        $fieldKey = $fmtMatch[1];
                        $blockParams = $fmtMatch[2];
                    } else {
                        $fieldKey = $rest;
                    }
                } elseif (preg_match('/^([a-zA-Z ]+):(.+)$/', trim($blockNameWithKey), $paramMatch)) {
                    $blockName = trim($paramMatch[1]);
                    $blockParams = trim($paramMatch[2]);
                } else {
                    $blockName = trim($blockNameWithKey);
                }

                $dom = new DOMDocument();
                libxml_use_internal_errors(true);
                $uniqueId = 'wrapper-' . uniqid();

                // ✅ Load HTML preserving <figure>, <img>, <br>, etc.
                $dom->loadHTML(
                    '<?xml encoding="utf-8"?><div id="' . $uniqueId . '">' . $blockContent . '</div>',
                    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
                );
                libxml_clear_errors();

                $xpath = new DOMXPath($dom);
                $wrapper = $dom->getElementById($uniqueId);
                if (!$wrapper) {
                    return $blockMatches[0];
                }

                switch ($blockName) {
                    case 'post title':
                        $node = $xpath->query('.//div', $wrapper)->item(0);
                        if ($node) {
                            $node->nodeValue = wp_strip_all_tags($post->post_title);
                        }
                        break;

                    case 'post excerpt':
                        $wordCount = ! empty( $blockParams ) ? (int) $blockParams : 30;
                        $node = $xpath->query('.//div', $wrapper)->item(0);
                        if ( $node ) {
                            if ( $wordCount <= 0 ) {
                                $node->nodeValue = wp_strip_all_tags( $post->post_content );
                            } else {
                                $node->nodeValue = wp_trim_words( strip_tags( $post->post_content ), $wordCount );
                            }
                        }
                        break;

                    case 'post media':
                        $img = $xpath->query('.//img', $wrapper)->item(0);
                        if ( $img && has_post_thumbnail( $post ) ) {
                            $resolution = ! empty( $blockParams ) ? $blockParams : 'full';
                            $allowedSizes = [ 'thumbnail', 'medium', 'medium_large', 'large', 'full' ];
                            if ( ! in_array( $resolution, $allowedSizes, true ) ) {
                                $resolution = 'full';
                            }
                            $img->setAttribute( 'src', get_the_post_thumbnail_url( $post, $resolution ) );
                            $img->setAttribute( 'alt', get_the_title( $post ) );
                            $img->setAttribute( 'width', '100%' );
                            $img->setAttribute( 'style', 'width:100%;max-width:100%;height:auto;display:block;' );
                        }
                        break;

                    case 'post readmore':
                        $a = $xpath->query('.//a', $wrapper)->item(0);
                        if ($a) {
                            $a->setAttribute('href', get_permalink($post));
                        }
                        break;

                    case 'post acf field':
                        // Handle ACF field rendering
                        // Field key is already extracted above
                        if ($fieldKey && function_exists('get_field_object')) {
                            $fieldObject = get_field_object($fieldKey, $post->ID);
                            $fieldValue = get_field($fieldKey, $post->ID);
                            $fieldType = $fieldObject['type'] ?? 'text';

                            // Handle image fields
                            if ($fieldType === 'image' || $fieldType === 'file') {
                                $img = $xpath->query('.//img', $wrapper)->item(0);
                                if ($img) {
                                    $imageUrl = '';
                                    $imageAlt = '';

                                    if (is_numeric($fieldValue)) {
                                        // It's an attachment ID
                                        $imageUrl = wp_get_attachment_image_url($fieldValue, 'full');
                                        $imageAlt = get_post_meta($fieldValue, '_wp_attachment_image_alt', true);
                                    } elseif (is_array($fieldValue)) {
                                        // It's an array with image data (formatted by PreparePost)
                                        if (isset($fieldValue['url'])) {
                                            $imageUrl = $fieldValue['url'];
                                        } elseif (isset($fieldValue['ID'])) {
                                            $imageUrl = wp_get_attachment_image_url($fieldValue['ID'], 'full');
                                        }
                                        $imageAlt = $fieldValue['alt'] ?? $fieldValue['title'] ?? '';
                                    }

                                    if ($imageUrl) {
                                        $img->setAttribute('src', $imageUrl);
                                        if ($imageAlt) {
                                            $img->setAttribute('alt', $imageAlt);
                                        }

                                        // Set href to post permalink conditionally (if linkToPost is enabled)
                                        if ($linkToPost) {
                                            $a = $xpath->query('.//a', $wrapper)->item(0);
                                            if ($a) {
                                                $a->setAttribute('href', get_permalink($post));
                                            }
                                        }
                                    } else {
                                        // If no image URL, remove the image element
                                        $img->parentNode->removeChild($img);
                                    }
                                }
                            } else {
                                // Handle text-based fields
                                $node = $xpath->query('.//div', $wrapper)->item(0);
                                if ($node) {
                                    // Format the value based on type
                                    if (is_array($fieldValue)) {
                                        $displayValue = implode(', ', array_filter($fieldValue, 'is_scalar'));
                                    } elseif (is_object($fieldValue)) {
                                        $displayValue = json_encode($fieldValue);
                                    } else {
                                        $displayValue = (string) $fieldValue;
                                    }

                                    $node->nodeValue = $displayValue;
                                }
                            }
                        }
                        break;

                    case 'post meta':
                        if ($fieldKey) {
                            $metaValue = get_post_meta($post->ID, $fieldKey, true);
                            if ($metaValue !== '' && $metaValue !== false) {
                                $node = $xpath->query('.//div', $wrapper)->item(0);
                                if (!$node) {
                                    $node = $xpath->query('.//td', $wrapper)->item(0);
                                }
                                if ($node) {
                                    if (is_array($metaValue)) {
                                        $displayValue = implode(', ', array_filter($metaValue, 'is_scalar'));
                                    } else {
                                        $displayValue = (string) $metaValue;
                                    }

                                    // Date formatting: blockParams = "date|" or "time|" or "datetime|" or "custom|format"
                                    if (!empty($blockParams) && strtotime($displayValue) !== false) {
                                        $parts = explode('|', $blockParams, 2);
                                        $dateType = $parts[0] ?? 'date';
                                        $customFmt = $parts[1] ?? '';

                                        $wpDate = get_option('date_format', 'F j, Y');
                                        $wpTime = get_option('time_format', 'g:i a');

                                        switch ($dateType) {
                                            case 'time':
                                                $fmt = $wpTime;
                                                break;
                                            case 'datetime':
                                                $fmt = $wpDate . ' ' . $wpTime;
                                                break;
                                            case 'custom':
                                                $fmt = !empty($customFmt) ? $customFmt : $wpDate;
                                                break;
                                            default:
                                                $fmt = $wpDate;
                                                break;
                                        }
                                        $displayValue = date_i18n($fmt, strtotime($displayValue));
                                    }

                                    $node->nodeValue = esc_html($displayValue);
                                }
                            }
                        }
                        break;

                    case 'post content':
                        $td = $xpath->query('.//td', $wrapper)->item(0);
                        if ($td) {
                            $rawContent = apply_filters( 'the_content', $post->post_content );
                            $safeContent = $this->sanitizeHtmlForEmail($rawContent);

                            $styleMap = [];
                            $rows = $xpath->query('.//tr[@class]');
                            foreach ($rows as $tr) {
                                $class = $tr->getAttribute('class');
                                $tdNode = $xpath->query('./td', $tr)->item(0);
                                $aNode = $xpath->query('./a', $tdNode)->item(0);
                                if ($tdNode) {
                                    $styleMap[$class] = [
                                        'tr' => $tr->getAttribute('style') ?? '',
                                        'td' => $tdNode->getAttribute('style') ?? '',
                                        'a' => $aNode ? $aNode->getAttribute('style') : '',
                                    ];
                                }
                            }

                            $emailTableHtml = $this->wrapContentInEmailTable($safeContent, $styleMap);

                            $tmp = new DOMDocument();
                            libxml_use_internal_errors(true);
                            $tmp->loadHTML(
                                '<?xml encoding="utf-8"?><div>' . $emailTableHtml . '</div>',
                                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
                            );
                            libxml_clear_errors();

                            $div = $tmp->getElementsByTagName('div')->item(0);

                            // Clear any existing content
                            while ($td->firstChild) {
                                $td->removeChild($td->firstChild);
                            }

                            // Import real nodes
                            foreach ($div->childNodes as $child) {
                                $imported = $td->ownerDocument->importNode($child, true);
                                $td->appendChild($imported);
                            }
                        }
                        break;

                    default:
                        return $blockMatches[0];
                }

                $newHtml = '';
                foreach ($wrapper->childNodes as $child) {
                    $newHtml .= $dom->saveHTML($child);
                }

                return "<!-- START {$blockName} -->{$newHtml}<!-- END {$blockName} -->";
            },
            $template
        );
    }

    protected function wrapContentInEmailTable(string $html, array $styleMap = []): string
    {
        // Fix self-closing img tags
        $html = preg_replace_callback('/<img(.*?)>/i', function ($matches) {
            $img = trim($matches[0]);
            if (substr($img, -2) === '/>') {
                return $img;
            }
            return preg_replace('/>$/', ' />', $img);
        }, $html);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(
            mb_convert_encoding('<div>' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $images = $dom->getElementsByTagName( 'img' );
        foreach ( $images as $img ) {
            $img->setAttribute( 'width', '100%' );
            $img->setAttribute( 'style', 'width:100%;max-width:100%;height:auto;display:block;' );
        }

        $container = $dom->getElementsByTagName('div')->item(0);
        $rows = '';

        if ($container) {
            $rows = $this->processNodes($container, $styleMap);
        }

        return <<<HTML
<table cellpadding="0" cellspacing="0" width="100%" border="0" style="color:#000000;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:13px;line-height:1.5;table-layout:auto;width:100%;border:none;">
    <tbody>
        {$rows}
    </tbody>
</table>
HTML;
    }

    protected function processNodes(\DOMNode $parent, array $styleMap = []): string
    {
        $rows = [];

        $tagMap = [
            'p' => 'text-block',
            'hr' => 'text-block',
            'div' => 'text-block',
            'h1' => 'heading-block',
            'h2' => 'heading-block',
            'h3' => 'heading-block',
            'h4' => 'heading-block',
            'h5' => 'heading-block',
            'h6' => 'heading-block',
            'ul' => 'text-block',
            'ol' => 'text-block',
            'figure' => 'figure-block',
            'blockquote' => 'text-block',
        ];

        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMText) {
                if (trim($node->textContent) === '') {
                    continue;
                }
                $rows[] = '<tr><td>' . htmlspecialchars($node->textContent) . '</td></tr>';
                continue;
            }

            if (!($node instanceof \DOMElement)) {
                continue;
            }

            $anchors = $node->getElementsByTagName('a');
            foreach ($anchors as $anchor) {
                $existing = trim((string)$anchor->getAttribute('style'));
                $newStyle = ($existing ? rtrim(
                    $existing,
                    ';'
                ) . ';' : '') . 'color:inherit; text-decoration:underline;';
                $anchor->setAttribute('style', $newStyle);
            }

            $tagName = $node->nodeName;
            $category = $tagMap[$tagName] ?? null;
            if ($category) {
                if (!empty($styleMap['link-block']['a'])) {
                    $soleAnchor = $this->findSoleAnchor($node);
                    if ($soleAnchor) {
                        $aStyle = $styleMap['link-block']['a'];
                        $existing = trim((string)$soleAnchor->getAttribute('style'));
                        $soleAnchor->setAttribute(
                            'style',
                            rtrim(($existing ? $existing . ';' : '') . $aStyle, ';')
                        );
                    }
                }

                // Serialize AFTER possible mutation above
                if (in_array($tagName, ['ul', 'ol'])) {
                    $innerHtml = $node->ownerDocument->saveHTML($node);
                } elseif (in_array($tagName, ['figure', 'blockquote'])) {
                    // Serialize figure children and check for nested blockquote
                    $innerHtml = '';
                    foreach ($node->childNodes as $child) {
                        if (($child->nodeName === 'blockquote' || $child->nodeName === 'p')) {
                            $blockquoteHtml = '';
                            foreach ($child->childNodes as $bcChild) {
                                $blockquoteHtml .= $node->ownerDocument->saveHTML($bcChild);
                            }

                            $innerHtml .= '<div style="
                    padding:15px 20px;
                    border-left:4px solid #cccccc;
                ">' . $blockquoteHtml . '</div>';
                        } else {
                            $innerHtml .= $node->ownerDocument->saveHTML($child);
                        }
                    }
                } elseif ($tagName === 'hr') {
                    $innerHtml = '<tr>
        <td style="display: inline-block; width: 100%">
            <p style="border-top:solid 1px #eee;font-size:1px;margin:0px auto;width:100%;"></p>
        </td>
    </tr>';

                    // Add directly without extra wrapping
                    $rows[] = $innerHtml;
                    continue;
                } else {
                    $innerHtml = '';
                    foreach ($node->childNodes as $child) {
                        $innerHtml .= $node->ownerDocument->saveHTML($child);
                    }
                }

                $trStyle = $styleMap[$category]['tr'] ?? '';
                $tdStyle = $styleMap[$category]['td'] ?? '';

                if ( in_array( $category, [ 'text-block', 'heading-block', 'link-block' ], true ) && strpos( $tdStyle, 'padding-bottom' ) === false ) {
                    $tdStyle = rtrim( $tdStyle, '; ' ) . ( $tdStyle ? ';' : '' ) . 'padding-bottom:10px;';
                }

                $rows[] = '<tr style="' . $trStyle . '"><td style="' . $tdStyle . '">' . $innerHtml . '</td></tr>';
                continue;
            }

            if ($node->hasChildNodes()) {
                $rows[] = $this->processNodes($node, $styleMap);
            }
        }

        return implode("\n", $rows);
    }

    // Add this helper inside your class
    private
    function findSoleAnchor(
        \DOMNode $node
    ): ?\DOMElement {
        // If this node itself is <a>, treat it as sole anchor of its subtree
        if ($node instanceof \DOMElement && strtolower($node->tagName) === 'a') {
            return $node;
        }

        $elementChild = null;

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                if (trim($child->textContent) !== '') {
                    // Text outside <a> → not a sole link block
                    return null;
                }
            } elseif ($child instanceof \DOMElement) {
                // More than one element child → not a sole link block
                if ($elementChild !== null) {
                    return null;
                }
                $elementChild = $child;
            }
            // Ignore comments, etc.
        }

        if ($elementChild === null) {
            return null; // no elements, only whitespace → not a link block
        }

        // Recurse down a single chain of wrappers like <p><strong><a>…</a></strong></p>
        return $this->findSoleAnchor($elementChild);
    }


    protected
    function sanitizeHtmlForEmail(
        string $html
    ): string {
        return wp_kses($html, [
            'a' => [
                'href' => [],
                'title' => [],
                'target' => []
            ],
            'figure' => ['class' => []],
            'img' => [
                'src' => [],
                'alt' => [],
                'title' => [],
                'style' => [],
                'class' => [],
                'loading' => [],
                'decoding' => [],
                'width' => [],
                'height' => [],
                'srcset' => [],
                'sizes' => []
            ],
            'p' => [],
            'br' => [],
            'strong' => [],
            'em' => [],
            'ul' => ['style' => []],
            'ol' => ['style' => []],
            'li' => ['style' => []],
            'h1' => ['style' => []],
            'h2' => ['style' => []],
            'h3' => ['style' => []],
            'h4' => ['style' => []],
            'blockquote' => ['style' => []],
            'cite' => ['style' => []],
            'span' => ['style' => []],
            'div' => ['style' => []],
            'hr' => ['style' => []],
        ]);
    }
}
