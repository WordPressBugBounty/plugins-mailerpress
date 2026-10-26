<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

class ThemeStyles
{
    /** Block styles the email editor reads from a theme; the others stay server-side. */
    private const SCRIPT_BLOCK_STYLES = ['core/heading', 'core/button'];

    /** Style properties an email can use from a theme style variation. */
    private const EMAIL_STYLE_PROPERTIES = ['color', 'typography', 'spacing', 'border'];

    public function getThemeStyles(): array
    {
        return $this->getResolvedThemeStyles();
    }

    /**
     * Theme styles for admin scripts, trimmed to the data the email editor reads.
     *
     * @param string[]|null $names Style names to keep, or null for every style.
     */
    public function getThemeStylesForScripts(?array $names = null): array
    {
        $styles = $this->getThemeStyles();
        if (null !== $names) {
            $styles = array_intersect_key($styles, array_flip($names));
        }

        foreach ($styles as $name => $style) {
            if (!is_array($style)) {
                continue;
            }
            unset($style['templateParts'], $style['customTemplates'], $style['patterns']);
            if (isset($style['styles']['blocks']) && is_array($style['styles']['blocks'])) {
                $style['styles']['blocks'] = array_intersect_key(
                    $style['styles']['blocks'],
                    array_flip(self::SCRIPT_BLOCK_STYLES)
                );
            }
            $styles[$name] = $style;
        }

        return $styles;
    }

    public function loadJsonSettings()
    {
        // A child theme file takes priority over the parent theme file.
        $jsonFile = get_theme_file_path('mailerpress/blocks.json');
        if (file_exists($jsonFile)) {
            return json_decode(file_get_contents($jsonFile), true);
        }

        return null;
    }

    private function getResolvedThemeStyles(): array
    {
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            return $this->getNeutralThemeStyles();
        }

        $result = [];
        // Parent and child theme.json: the base each style variation applies to, as in the Site Editor.
        $baseTheme = \WP_Theme_JSON_Resolver::get_merged_data('theme');
        // What the site shows: the theme styles plus the changes saved in the Site Editor.
        $siteData = \WP_Theme_JSON_Resolver::get_merged_data('custom')->get_raw_data();
        unset($siteData['title'], $siteData['description']);

        $result['Core'] = $this->prepareForEmail(array_merge(
            $siteData,
            [
                'title' => __('Default', 'mailerpress'),
                '_mailerpress' => ['variationType' => 'style'],
            ]
        ));

        $variations = \WP_Theme_JSON_Resolver::get_style_variations();
        $unique_variations = [];

        foreach ($variations as $variation) {
            $title = (string) ($variation['title'] ?? '');
            if ('' !== $title && 'Core' !== $title && !isset($unique_variations[$title])) {
                $unique_variations[$title] = $variation;
            }
        }

        foreach ($unique_variations as $title => $variation) {
            $variationType = $this->getEmailVariationType($variation);
            if ($variationType === null) {
                continue;
            }

            $resolvedVariation = clone $baseTheme;
            $resolvedVariation->merge(new \WP_Theme_JSON($variation, 'theme'));
            $resolvedVariationData = $resolvedVariation->get_raw_data();
            $resolvedVariationData['title'] = $title;
            $resolvedVariationData['_mailerpress'] = ['variationType' => $variationType];
            $result[$title] = $this->prepareForEmail($resolvedVariationData);
        }

        return $result;
    }

    /**
     * Sends palette colors as hex: Outlook and other email clients ignore rgba() and hsl().
     */
    private function prepareForEmail(array $data): array
    {
        if (isset($data['settings']['color']['palette']) && \is_array($data['settings']['color']['palette'])) {
            $data['settings']['color']['palette'] = $this->normalizePaletteColors($data['settings']['color']['palette']);
        }

        return $data;
    }

    private function normalizePaletteColors(array $palette): array
    {
        foreach ($palette as $key => $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            if (isset($entry['slug'], $entry['color']) && \is_string($entry['color'])) {
                $palette[$key]['color'] = $this->toHexColor($entry['color']) ?? $entry['color'];
                continue;
            }

            $palette[$key] = $this->normalizePaletteColors($entry);
        }

        return $palette;
    }

    /**
     * Converts hex, rgb() and hsl() colors to #rrggbb, blending transparency over white.
     * Returns null for colors it cannot convert, such as named colors or CSS variables.
     */
    private function toHexColor(string $color): ?string
    {
        $color = strtolower(trim($color));
        $alpha = 1.0;

        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $color, $matches)) {
            $hex = $matches[1];
            if (\strlen($hex) <= 4) {
                $hex = implode('', array_map(static fn(string $digit): string => $digit . $digit, str_split($hex)));
            }
            $channels = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
            if (8 === \strlen($hex)) {
                $alpha = hexdec(substr($hex, 6, 2)) / 255;
            }
        } elseif (preg_match('/^(rgba?|hsla?)\((.*)\)$/', $color, $matches)) {
            $parts = preg_split('/[\s,\/]+/', trim($matches[2]), -1, PREG_SPLIT_NO_EMPTY);
            if (!\is_array($parts) || \count($parts) < 3 || \count($parts) > 4) {
                return null;
            }

            $numbers = [];
            foreach ($parts as $part) {
                if (!preg_match('/^(-?(?:\d+\.?\d*|\.\d+))(%|deg|rad|turn|grad)?$/', $part, $number)) {
                    return null;
                }
                $numbers[] = ['value' => (float) $number[1], 'unit' => $number[2] ?? ''];
            }

            if (isset($numbers[3])) {
                $alpha = '%' === $numbers[3]['unit'] ? $numbers[3]['value'] / 100 : $numbers[3]['value'];
            }

            if (0 === strpos($matches[1], 'rgb')) {
                $channels = array_map(
                    static fn(array $number): float => '%' === $number['unit'] ? $number['value'] * 2.55 : $number['value'],
                    \array_slice($numbers, 0, 3)
                );
            } else {
                $channels = $this->hslToRgb($numbers[0], $numbers[1]['value'] / 100, $numbers[2]['value'] / 100);
            }
        } else {
            return null;
        }

        $alpha = max(0.0, min(1.0, (float) $alpha));
        if ($alpha <= 0) {
            return null;
        }

        return vsprintf('#%02x%02x%02x', array_map(
            static fn($channel): int => (int) round(max(0, min(255, (float) $channel)) * $alpha + 255 * (1 - $alpha)),
            $channels
        ));
    }

    /**
     * @param array{value: float, unit: string} $hue
     * @return float[]
     */
    private function hslToRgb(array $hue, float $saturation, float $lightness): array
    {
        $degrees = $hue['value'];
        if ('turn' === $hue['unit']) {
            $degrees *= 360;
        } elseif ('rad' === $hue['unit']) {
            $degrees = rad2deg($degrees);
        } elseif ('grad' === $hue['unit']) {
            $degrees *= 0.9;
        }

        $saturation = max(0.0, min(1.0, $saturation));
        $lightness = max(0.0, min(1.0, $lightness));
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $sector = fmod(fmod($degrees, 360) + 360, 360) / 60;
        $second = $chroma * (1 - abs(fmod($sector, 2) - 1));
        $match = $lightness - $chroma / 2;
        $rgb = [[$chroma, $second, 0], [$second, $chroma, 0], [0, $chroma, $second], [0, $second, $chroma], [$second, 0, $chroma], [$chroma, 0, $second]][(int) floor($sector) % 6];

        return array_map(static fn(float $channel): float => ($channel + $match) * 255, $rgb);
    }

    private function getNeutralThemeStyles(): array
    {
        $fontFamily = 'Helvetica, Arial, sans-serif';

        return [
            'Core' => [
                'version' => 3,
                'title' => __('Neutral', 'mailerpress'),
                '_mailerpress' => [
                    'variationType' => 'style',
                    'source' => 'neutral',
                ],
                'settings' => [
                    'color' => [
                        'palette' => [
                            [
                                'name' => __('White', 'mailerpress'),
                                'slug' => 'base',
                                'color' => '#ffffff',
                            ],
                            [
                                'name' => __('Black', 'mailerpress'),
                                'slug' => 'contrast',
                                'color' => '#1e1e1e',
                            ],
                            [
                                'name' => __('Light gray', 'mailerpress'),
                                'slug' => 'neutral',
                                'color' => '#f0f0f0',
                            ],
                        ],
                    ],
                    'layout' => [
                        'contentSize' => '600px',
                        'wideSize' => '600px',
                    ],
                ],
                'styles' => [
                    'color' => [
                        'background' => '#ffffff',
                        'text' => '#1e1e1e',
                    ],
                    'typography' => [
                        'fontFamily' => $fontFamily,
                        'fontSize' => '16px',
                        'fontWeight' => '400',
                        'lineHeight' => '24px',
                    ],
                    'elements' => [
                        'link' => [
                            'color' => [
                                'text' => '#1e1e1e',
                            ],
                            'typography' => [
                                'textDecoration' => 'underline',
                            ],
                        ],
                        'heading' => [
                            'color' => [
                                'text' => '#1e1e1e',
                            ],
                            'typography' => [
                                'fontFamily' => $fontFamily,
                                'fontWeight' => '600',
                            ],
                        ],
                        'h1' => [
                            'typography' => [
                                'fontSize' => '40px',
                                'lineHeight' => '48px',
                            ],
                        ],
                        'h2' => [
                            'typography' => [
                                'fontSize' => '32px',
                                'lineHeight' => '38px',
                            ],
                        ],
                        'h3' => [
                            'typography' => [
                                'fontSize' => '28px',
                                'lineHeight' => '34px',
                            ],
                        ],
                        'h4' => [
                            'typography' => [
                                'fontSize' => '24px',
                                'lineHeight' => '29px',
                            ],
                        ],
                        'h5' => [
                            'typography' => [
                                'fontSize' => '20px',
                                'lineHeight' => '24px',
                            ],
                        ],
                        'h6' => [
                            'typography' => [
                                'fontSize' => '16px',
                                'lineHeight' => '20px',
                            ],
                        ],
                        'button' => [
                            'border' => [
                                'radius' => '4px',
                            ],
                            'color' => [
                                'background' => '#1e1e1e',
                                'text' => '#ffffff',
                            ],
                            'spacing' => [
                                'padding' => [
                                    'top' => '12px',
                                    'right' => '24px',
                                    'bottom' => '12px',
                                    'left' => '24px',
                                ],
                            ],
                            'typography' => [
                                'fontFamily' => $fontFamily,
                                'fontSize' => '16px',
                                'fontWeight' => '600',
                                'lineHeight' => '20px',
                                'textDecoration' => 'none',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Groups variations like the Site Editor: color-only and typography-only variations
     * are offered separately from full style variations.
     */
    private function getEmailVariationType(array $variation): ?string
    {
        $config = $this->withoutEmptyValues(array_intersect_key($variation, array_flip(['settings', 'styles'])));

        if ([] === $this->filterByProperties($config, self::EMAIL_STYLE_PROPERTIES)) {
            return null;
        }

        if ($this->filterByProperties($config, ['color']) == $config) {
            return 'color';
        }

        if ($this->filterByProperties($config, ['typography', 'spacing']) == $config) {
            return 'typography';
        }

        return 'style';
    }

    /**
     * Keeps the given properties wherever they appear, with the keys that lead to them.
     */
    private function filterByProperties(array $data, array $properties): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if (\in_array($key, $properties, true)) {
                $filtered[$key] = $value;
            } elseif (\is_array($value)) {
                $nested = $this->filterByProperties($value, $properties);
                if ([] !== $nested) {
                    $filtered[$key] = $nested;
                }
            }
        }

        return $filtered;
    }

    private function withoutEmptyValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $value = $this->withoutEmptyValues($value);
                $data[$key] = $value;
            }

            if ([] === $value || null === $value) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
