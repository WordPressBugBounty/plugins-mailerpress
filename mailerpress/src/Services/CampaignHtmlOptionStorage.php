<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

class CampaignHtmlOptionStorage
{
    public static function getOptionKey(int $campaignId): string
    {
        return 'mailerpress_batch_' . $campaignId . '_html';
    }

    public static function store(int $campaignId, mixed $html, array $context = []): array
    {
        global $wpdb;

        $optionKey = self::getOptionKey($campaignId);
        $baseContext = array_merge(
            $context,
            [
                'campaign_id' => $campaignId,
                'option_key' => $optionKey,
            ]
        );

        if ($campaignId <= 0) {
            return self::fail(
                'invalid_campaign_id',
                'Campaign HTML option storage failed: invalid campaign id.',
                $baseContext,
                422
            );
        }

        if (!is_string($html)) {
            return self::fail(
                'invalid_html_type',
                'Campaign HTML option storage failed: HTML payload is not a string.',
                array_merge($baseContext, ['html_type' => get_debug_type($html)]),
                422
            );
        }

        $rawLength = strlen($html);
        $rawFourByteCount = self::countFourByteCharacters($html);
        $rawFourByteSamples = self::getFourByteCodepointSamples($html);

        if (trim($html) === '') {
            return self::fail(
                'empty_html',
                'Campaign HTML option storage failed: HTML payload is empty.',
                array_merge(
                    $baseContext,
                    [
                        'raw_length' => $rawLength,
                    ]
                ),
                422
            );
        }

        try {
            $sanitizedHtml = self::sanitize($html);
        } catch (\Throwable $e) {
            Logger::exception(
                $e,
                array_merge(
                    $baseContext,
                    [
                        'raw_length' => $rawLength,
                        'raw_four_byte_count' => $rawFourByteCount,
                        'raw_four_byte_samples' => $rawFourByteSamples,
                    ]
                )
            );

            return [
                'success' => false,
                'code' => 'sanitize_failed',
                'message' => 'Campaign HTML sanitization failed.',
                'status' => 500,
                'option_key' => $optionKey,
            ];
        }

        $sanitizedLength = strlen($sanitizedHtml);
        $sanitizedFourByteCount = self::countFourByteCharacters($sanitizedHtml);

        if (trim($sanitizedHtml) === '') {
            return self::fail(
                'sanitized_html_empty',
                'Campaign HTML option storage failed: sanitized HTML is empty.',
                array_merge(
                    $baseContext,
                    [
                        'raw_length' => $rawLength,
                        'sanitized_length' => $sanitizedLength,
                        'raw_four_byte_count' => $rawFourByteCount,
                        'raw_four_byte_samples' => $rawFourByteSamples,
                    ]
                ),
                422
            );
        }

        $storedHtml = self::encodeFourByteCharacters($sanitizedHtml);
        $storedLength = strlen($storedHtml);
        $storedFourByteCount = self::countFourByteCharacters($storedHtml);

        $updateResult = update_option($optionKey, $storedHtml, false);
        $lastError = (string)($wpdb->last_error ?? '');
        $readBack = get_option($optionKey, null);
        $storageVerified = is_string($readBack) && $readBack === $storedHtml;

        $storageContext = array_merge(
            $baseContext,
            [
                'raw_length' => $rawLength,
                'sanitized_length' => $sanitizedLength,
                'stored_length' => $storedLength,
                'read_back_length' => is_string($readBack) ? strlen($readBack) : null,
                'raw_four_byte_count' => $rawFourByteCount,
                'sanitized_four_byte_count' => $sanitizedFourByteCount,
                'stored_four_byte_count' => $storedFourByteCount,
                'raw_four_byte_samples' => $rawFourByteSamples,
                'update_result' => $updateResult,
                'wpdb_last_error' => $lastError,
                'database' => self::getDatabaseContext(),
            ]
        );

        if (!$storageVerified) {
            return self::fail(
                'option_write_failed',
                'Campaign HTML option storage failed: stored value could not be verified.',
                $storageContext,
                500
            );
        }

        if ($rawFourByteCount > 0 || $sanitizedFourByteCount > 0) {
            Logger::info(
                'Campaign HTML option stored after encoding four-byte characters.',
                $storageContext
            );
        }

        return [
            'success' => true,
            'code' => 'stored',
            'message' => 'Campaign HTML option stored.',
            'status' => 200,
            'option_key' => $optionKey,
            'raw_length' => $rawLength,
            'sanitized_length' => $sanitizedLength,
            'stored_length' => $storedLength,
            'encoded_four_byte_count' => $sanitizedFourByteCount,
        ];
    }

    public static function sanitize(string $html): string
    {
        if (empty($html)) {
            return $html;
        }

        $html = self::normalizeInlineFontFamilyQuotes($html);

        $protectedComments = [];
        $protectedConditionals = [];
        $html = self::protectMailerPressConditionalBlocks($html, $protectedConditionals);
        $protectedBackgroundStyles = [];
        $html = self::protectEmailConditionalComments($html, $protectedComments);
        $html = self::protectEmailBackgroundStyles($html, $protectedBackgroundStyles);

        $html = self::sanitizeEmailHtmlFragment($html, self::getEmailAllowedHtml());
        $html = strtr($html, $protectedBackgroundStyles);

        return strtr(strtr($html, $protectedComments), $protectedConditionals);
    }

    private static function normalizeInlineFontFamilyQuotes(string $html): string
    {
        $result = preg_replace_callback(
            '/(^|[\s<])style\s*=\s*(["\'])(.*?)\2/is',
            static function (array $matches): string {
                $style = self::normalizeStyleFontFamilyDeclarations($matches[3]);

                return $matches[1] . 'style=' . $matches[2] . $style . $matches[2];
            },
            $html
        );

        return $result ?? $html;
    }

    private static function normalizeStyleFontFamilyDeclarations(string $style): string
    {
        $result = preg_replace_callback(
            '/(^|;)\s*font-family\s*:\s*([^;]*)/i',
            static function (array $matches): string {
                $value = trim($matches[2]);
                if ('' === $value) {
                    return $matches[0];
                }

                return $matches[1] . 'font-family:' . self::normalizeFontFamilyValue($value);
            },
            $style
        );

        return $result ?? $style;
    }

    private static function normalizeFontFamilyValue(string $value): string
    {
        $families = array_map(
            static function (string $family): string {
                $family = trim($family);
                $decodedFamily = html_entity_decode($family, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (
                    preg_match('/^([\'"])(.*)\1$/', $decodedFamily, $matches)
                    && self::isSafeUnquotedFontFamily($matches[2])
                ) {
                    return trim($matches[2]);
                }

                return $family;
            },
            explode(',', $value)
        );

        $families = array_filter($families, static fn(string $family): bool => '' !== $family);

        return implode(', ', $families);
    }

    private static function isSafeUnquotedFontFamily(string $family): bool
    {
        return '' !== trim($family) && (bool)preg_match('/^[\p{L}\p{N} _.-]+$/u', $family);
    }

    private static function encodeFourByteCharacters(string $html): string
    {
        if (function_exists('wp_encode_emoji')) {
            $html = wp_encode_emoji($html);
        }

        return preg_replace_callback(
            '/[\x{10000}-\x{10FFFF}]/u',
            static function (array $matches): string {
                $codepoint = self::getUnicodeCodepoint($matches[0]);

                if ($codepoint <= 0) {
                    return $matches[0];
                }

                return '&#x' . strtoupper(dechex($codepoint)) . ';';
            },
            $html
        ) ?? $html;
    }

    private static function countFourByteCharacters(string $html): int
    {
        $matched = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $html, $matches);

        return false === $matched ? 0 : (int)$matched;
    }

    private static function getFourByteCodepointSamples(string $html, int $limit = 5): array
    {
        $matched = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $html, $matches);
        if (false === $matched || empty($matches[0])) {
            return [];
        }

        $samples = [];
        foreach ($matches[0] as $character) {
            $codepoint = self::getUnicodeCodepoint($character);
            if ($codepoint <= 0) {
                continue;
            }

            $samples['U+' . strtoupper(dechex($codepoint))] = true;
            if (count($samples) >= $limit) {
                break;
            }
        }

        return array_keys($samples);
    }

    private static function getUnicodeCodepoint(string $character): int
    {
        if (function_exists('mb_ord')) {
            return (int)mb_ord($character, 'UTF-8');
        }

        $bytes = array_values(unpack('C*', $character) ?: []);
        $count = count($bytes);

        if ($count === 1) {
            return $bytes[0];
        }

        if ($count === 2) {
            return (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F);
        }

        if ($count === 3) {
            return (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F);
        }

        if ($count === 4) {
            return (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F);
        }

        return 0;
    }

    private static function fail(string $code, string $message, array $context, int $status): array
    {
        Logger::error($message, array_merge($context, ['code' => $code, 'status' => $status]));

        return [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'status' => $status,
            'option_key' => $context['option_key'] ?? '',
        ];
    }

    private static function getDatabaseContext(): array
    {
        global $wpdb;

        $context = [
            'options_table' => $wpdb->options ?? '',
        ];

        if (empty($wpdb->options)) {
            return $context;
        }

        $table = esc_sql($wpdb->options);
        $column = $wpdb->get_row("SHOW FULL COLUMNS FROM `{$table}` LIKE 'option_value'", ARRAY_A);
        if (is_array($column)) {
            $context['option_value_type'] = $column['Type'] ?? null;
            $context['option_value_collation'] = $column['Collation'] ?? null;
        }

        return $context;
    }

    private static function sanitizeEmailHtmlFragment(string $html, array $allowedHtml): string
    {
        $cssFilter = static function (array $properties): array {
            return array_values(
                array_unique(
                    array_merge(
                        $properties,
                        [
                            '-ms-interpolation-mode',
                            '-ms-text-size-adjust',
                            '-webkit-text-size-adjust',
                            'box-sizing',
                            'mso-line-height-alt',
                            'mso-line-height-rule',
                            'mso-padding-alt',
                            'mso-table-lspace',
                            'mso-table-rspace',
                            'outline',
                            'text-size-adjust',
                            'word-break',
                            'word-wrap',
                        ]
                    )
                )
            );
        };

        add_filter('safe_style_css', $cssFilter);
        try {
            return wp_kses(
                $html,
                $allowedHtml,
                self::getEmailAllowedProtocols()
            );
        } finally {
            remove_filter('safe_style_css', $cssFilter);
        }
    }

    private static function protectEmailBackgroundStyles(string $html, array &$protectedStyles): string
    {
        $result = preg_replace_callback(
            '/(^|[\s<])style\s*=\s*(["\'])(.*?)\2/is',
            static function (array $matches) use (&$protectedStyles): string {
                $style = $matches[3];
                $updatedStyle = preg_replace_callback(
                    '/(^|;)\s*(background(?:-image)?)\s*:\s*([^;]*url\([^)]+\)[^;]*)/i',
                    static function (array $declarationMatches) use (&$protectedStyles): string {
                        $prefix = $declarationMatches[1];
                        $property = $declarationMatches[2];
                        $value = trim($declarationMatches[3]);

                        if (!self::isSafeEmailBackgroundCssValue($value)) {
                            return $declarationMatches[0];
                        }

                        $safeDeclaration = safecss_filter_attr($property . ':' . $value);
                        if ('' === $safeDeclaration || !preg_match('/^\s*' . preg_quote($property, '/') . '\s*:\s*(.+)$/i', $safeDeclaration, $safeMatches)) {
                            return $declarationMatches[0];
                        }

                        $token = 'var(--mailerpress-email-background-' . count($protectedStyles) . ')';
                        $protectedStyles[$token] = trim($safeMatches[1]);

                        return $prefix . $property . ':' . $token;
                    },
                    $style
                );

                if (null === $updatedStyle) {
                    return $matches[0];
                }

                return $matches[1] . 'style=' . $matches[2] . $updatedStyle . $matches[2];
            },
            $html
        );

        return $result ?? $html;
    }

    private static function isSafeEmailBackgroundCssValue(string $value): bool
    {
        if (!preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $value, $matches)) {
            return false;
        }

        foreach ($matches[2] as $url) {
            $decodedUrl = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ('' === $decodedUrl || wp_kses_bad_protocol($decodedUrl, self::getEmailAllowedProtocols()) !== $decodedUrl) {
                return false;
            }
        }

        return true;
    }

    private static function protectEmailConditionalComments(string $html, array &$protectedComments): string
    {
        $offset = 0;
        $result = '';

        while (false !== ($start = strpos($html, '<!--', $offset))) {
            $end = strpos($html, '-->', $start + 4);
            if (false === $end) {
                break;
            }

            $comment = substr($html, $start, $end - $start + 3);
            $result .= substr($html, $offset, $start - $offset);

            if (self::isEmailConditionalComment($comment)) {
                $comment = self::sanitizeEmailConditionalComment($comment);
                $token = '%%MAILERPRESS_EMAIL_CONDITIONAL_' . count($protectedComments) . '_' . md5($comment) . '%%';
                $protectedComments[$token] = $comment;
                $result .= $token;
            } else {
                $result .= $comment;
            }

            $offset = $end + 3;
        }

        return $result . substr($html, $offset);
    }

    private static function protectMailerPressConditionalBlocks(string $html, array &$protectedConditionals): string
    {
        if (false === strpos($html, '%%MP_COND_START%%')) {
            return $html;
        }

        return preg_replace_callback(
            '/%%MP_COND_START%%(.+?)%%MP_COND_START%%(.*?)%%MP_COND_END%%/s',
            static function (array $matches) use (&$protectedConditionals): string {
                $meta = self::sanitizeMailerPressConditionalMeta($matches[1]);
                $innerHtml = $matches[2];

                $protectedComments = [];
                $innerHtml = self::protectEmailConditionalComments($innerHtml, $protectedComments);
                $innerHtml = self::sanitizeEmailHtmlFragment($innerHtml, self::getEmailAllowedHtml());
                $innerHtml = strtr($innerHtml, $protectedComments);

                $block = '%%MP_COND_START%%' . $meta . '%%MP_COND_START%%' . $innerHtml . '%%MP_COND_END%%';
                $token = 'MAILERPRESS_CONDITIONAL_BLOCK_' . count($protectedConditionals) . '_' . md5($block);
                $protectedConditionals[$token] = $block;

                return $token;
            },
            $html
        ) ?? $html;
    }

    private static function sanitizeMailerPressConditionalMeta(string $encodedMeta): string
    {
        $decodedMeta = rawurldecode($encodedMeta);
        $variants = json_decode($decodedMeta, true);

        if (!is_array($variants)) {
            return rawurlencode(wp_strip_all_tags($decodedMeta));
        }

        return rawurlencode(wp_json_encode($variants));
    }

    private static function isEmailConditionalComment(string $comment): bool
    {
        $inner = strtolower(trim(substr($comment, 4, -3)));

        return str_starts_with($inner, '[if ')
            || str_starts_with($inner, '[if !')
            || str_starts_with($inner, '<![endif]');
    }

    private static function sanitizeEmailConditionalComment(string $comment): string
    {
        $inner = substr($comment, 4, -3);
        $openMarkerEnd = strpos($inner, ']>');
        $closeMarkerStart = stripos($inner, '<![endif]');

        if (false === $openMarkerEnd || false === $closeMarkerStart || $closeMarkerStart < $openMarkerEnd) {
            return $comment;
        }

        $contentStart = $openMarkerEnd + 2;
        $prefix = substr($inner, 0, $contentStart);
        $content = substr($inner, $contentStart, $closeMarkerStart - $contentStart);
        $suffix = substr($inner, $closeMarkerStart);

        $content = self::encodeEmailNamespacedTagsForKses($content);
        $content = self::sanitizeEmailHtmlFragment($content, self::getEmailConditionalAllowedHtml());
        $content = self::decodeEmailNamespacedTagsFromKses($content);

        return '<!--' . $prefix . $content . $suffix . '-->';
    }

    private static function encodeEmailNamespacedTagsForKses(string $html): string
    {
        return str_ireplace(
            array_keys(self::getEmailNamespacedTagMap()),
            array_values(self::getEmailNamespacedTagMap()),
            $html
        );
    }

    private static function decodeEmailNamespacedTagsFromKses(string $html): string
    {
        return str_ireplace(
            array_values(self::getEmailNamespacedTagMap()),
            array_keys(self::getEmailNamespacedTagMap()),
            $html
        );
    }

    private static function getEmailNamespacedTagMap(): array
    {
        return [
            'o:AllowPNG' => 'mp-o-allowpng',
            'o:OfficeDocumentSettings' => 'mp-o-officedocumentsettings',
            'o:PixelsPerInch' => 'mp-o-pixelsperinch',
            'v:fill' => 'mp-v-fill',
            'v:imagedata' => 'mp-v-imagedata',
            'v:rect' => 'mp-v-rect',
            'v:roundrect' => 'mp-v-roundrect',
            'v:stroke' => 'mp-v-stroke',
            'v:textbox' => 'mp-v-textbox',
            'w:DoNotOptimizeForBrowser' => 'mp-w-donotoptimizeforbrowser',
            'w:View' => 'mp-w-view',
            'w:WordDocument' => 'mp-w-worddocument',
            'w:Zoom' => 'mp-w-zoom',
        ];
    }

    private static function getEmailAllowedHtml(): array
    {
        $globalAttrs = [
            'align' => true,
            'aria-describedby' => true,
            'aria-hidden' => true,
            'aria-label' => true,
            'aria-labelledby' => true,
            'bgcolor' => true,
            'class' => true,
            'data-*' => true,
            'dir' => true,
            'height' => true,
            'id' => true,
            'lang' => true,
            'role' => true,
            'style' => true,
            'title' => true,
            'valign' => true,
            'width' => true,
            'xml:lang' => true,
        ];

        $textAttrs = $globalAttrs;

        $allowed = array_fill_keys(
            [
                'b',
                'big',
                'center',
                'code',
                'del',
                'em',
                'figcaption',
                'figure',
                'i',
                'ins',
                'mark',
                'pre',
                's',
                'small',
                'span',
                'strike',
                'strong',
                'sub',
                'sup',
                'u',
            ],
            $textAttrs
        );

        $allowed += [
            'html' => array_merge(
                $globalAttrs,
                [
                    'xmlns' => true,
                    'xmlns:o' => true,
                    'xmlns:v' => true,
                    'xmlns:w' => true,
                ]
            ),
            'head' => [],
            'body' => array_merge(
                $globalAttrs,
                [
                    'background' => true,
                    'leftmargin' => true,
                    'marginheight' => true,
                    'marginwidth' => true,
                    'topmargin' => true,
                ]
            ),
            'title' => [],
            'meta' => [
                'charset' => true,
                'content' => true,
                'http-equiv' => [
                    'values' => [
                        'content-type',
                        'x-ua-compatible',
                    ],
                ],
                'name' => true,
                'property' => true,
            ],
            'link' => [
                'href' => true,
                'media' => true,
                'rel' => true,
                'type' => true,
            ],
            'style' => [
                'media' => true,
                'type' => true,
            ],
            'div' => $globalAttrs,
            'p' => $globalAttrs,
            'br' => [],
            'hr' => array_merge(
                $globalAttrs,
                [
                    'noshade' => true,
                    'size' => true,
                ]
            ),
            'h1' => $globalAttrs,
            'h2' => $globalAttrs,
            'h3' => $globalAttrs,
            'h4' => $globalAttrs,
            'h5' => $globalAttrs,
            'h6' => $globalAttrs,
            'a' => array_merge(
                $globalAttrs,
                [
                    'href' => true,
                    'name' => true,
                    'rel' => true,
                    'target' => true,
                ]
            ),
            'img' => array_merge(
                $globalAttrs,
                [
                    'alt' => true,
                    'border' => true,
                    'hspace' => true,
                    'src' => true,
                    'vspace' => true,
                ]
            ),
            'table' => array_merge(
                $globalAttrs,
                [
                    'background' => true,
                    'border' => true,
                    'cellpadding' => true,
                    'cellspacing' => true,
                    'summary' => true,
                ]
            ),
            'thead' => $globalAttrs,
            'tbody' => $globalAttrs,
            'tfoot' => $globalAttrs,
            'tr' => array_merge(
                $globalAttrs,
                [
                    'background' => true,
                ]
            ),
            'td' => array_merge(
                $globalAttrs,
                [
                    'background' => true,
                    'colspan' => true,
                    'nowrap' => true,
                    'rowspan' => true,
                ]
            ),
            'th' => array_merge(
                $globalAttrs,
                [
                    'background' => true,
                    'colspan' => true,
                    'nowrap' => true,
                    'rowspan' => true,
                    'scope' => true,
                ]
            ),
            'caption' => $globalAttrs,
            'colgroup' => array_merge(
                $globalAttrs,
                [
                    'span' => true,
                ]
            ),
            'col' => array_merge(
                $globalAttrs,
                [
                    'span' => true,
                ]
            ),
            'ul' => array_merge(
                $globalAttrs,
                [
                    'type' => true,
                ]
            ),
            'ol' => array_merge(
                $globalAttrs,
                [
                    'start' => true,
                    'type' => true,
                ]
            ),
            'li' => array_merge(
                $globalAttrs,
                [
                    'type' => true,
                    'value' => true,
                ]
            ),
            'blockquote' => array_merge(
                $globalAttrs,
                [
                    'cite' => true,
                ]
            ),
            'font' => array_merge(
                $globalAttrs,
                [
                    'color' => true,
                    'face' => true,
                    'size' => true,
                ]
            ),
        ];

        return $allowed;
    }

    private static function getEmailConditionalAllowedHtml(): array
    {
        $allowed = self::getEmailAllowedHtml();
        $vmlAttrs = [
            'align' => true,
            'alt' => true,
            'arcsize' => true,
            'class' => true,
            'color' => true,
            'coordorigin' => true,
            'coordsize' => true,
            'fill' => true,
            'fillcolor' => true,
            'href' => true,
            'id' => true,
            'inset' => true,
            'o:allowincell' => true,
            'o:href' => true,
            'opacity' => true,
            'src' => true,
            'strokecolor' => true,
            'strokeweight' => true,
            'style' => true,
            'type' => true,
            'v:text-anchor' => true,
            'xmlns:o' => true,
            'xmlns:v' => true,
            'xmlns:w' => true,
        ];

        foreach (array_values(self::getEmailNamespacedTagMap()) as $tag) {
            $allowed[$tag] = $vmlAttrs;
        }

        $allowed['xml'] = [];

        return $allowed;
    }

    private static function getEmailAllowedProtocols(): array
    {
        return [
            'cid',
            'ftp',
            'ftps',
            'http',
            'https',
            'mailto',
            'news',
            'tel',
        ];
    }
}
