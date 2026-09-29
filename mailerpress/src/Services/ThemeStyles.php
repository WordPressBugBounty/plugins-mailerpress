<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

class ThemeStyles
{
    public function getThemeStyles(): array
    {
        return $this->getResolvedThemeStyles();
    }

    public function loadJsonSettings()
    {
        $jsonFile = get_template_directory() . '/mailerpress/blocks.json';
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
        $baseTheme = \WP_Theme_JSON_Resolver::get_merged_data('theme');
        $baseThemeData = $baseTheme->get_raw_data();

        $result['Core'] = array_merge(
            [
                'title' => 'Default',
                '_mailerpress' => ['variationType' => 'style'],
            ],
            $baseThemeData
        );

        $variations = \WP_Theme_JSON_Resolver::get_style_variations();
        $unique_variations = [];

        foreach ($variations as $variation) {
            $title = $variation['title'] ?? '';
            if (!isset($unique_variations[$title])) {
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
            $resolvedVariationData['_mailerpress'] = ['variationType' => $variationType];
            $result[$title] = $resolvedVariationData;
        }

        return $result;
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

    private function getEmailVariationType(array $variation): ?string
    {
        $hasColor = $this->containsStyleProperty($variation, 'color');
        $hasTypography = $this->containsStyleProperty($variation, 'typography');
        $hasSpacing = $this->containsStyleProperty($variation, 'spacing');
        $hasBorder = $this->containsStyleProperty($variation, 'border');

        if (!$hasColor && !$hasTypography && !$hasSpacing && !$hasBorder) {
            return null;
        }

        if ($hasColor && !$hasTypography && !$hasSpacing && !$hasBorder) {
            return 'color';
        }

        if ($hasTypography && !$hasColor && !$hasSpacing && !$hasBorder) {
            return 'typography';
        }

        return 'style';
    }

    private function containsStyleProperty(array $data, string $property): bool
    {
        if (array_key_exists($property, $data)) {
            return true;
        }

        foreach ($data as $value) {
            if (\is_array($value) && $this->containsStyleProperty($value, $property)) {
                return true;
            }
        }

        return false;
    }
}
