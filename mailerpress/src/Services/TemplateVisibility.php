<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

/**
 * Decides which templates the site may list and open.
 *
 * User templates have internal = 0. Premium templates (internal = 1) ship with an extension such as
 * MailerPress Pro, which makes them available through the mailerpress_premium_templates_available filter.
 */
final class TemplateVisibility
{
    /**
     * @return int[] Values of the internal column that can be shown.
     */
    public static function internalValues(): array
    {
        $values = [0];

        if (true === apply_filters('mailerpress_premium_templates_available', false)) {
            $whiteLabel = apply_filters('mailerpress_white_label_options', []);
            if (empty($whiteLabel['white_label_active']) || empty($whiteLabel['hide_premium_templates'])) {
                $values[] = 1;
            }
        }

        return $values;
    }

    public static function isVisible(array $template): bool
    {
        return \in_array((int) ($template['internal'] ?? 0), self::internalValues(), true);
    }

    /** SQL list of the visible internal values, safe to inline (integers only). */
    public static function sqlList(): string
    {
        return implode(',', array_map('intval', self::internalValues()));
    }
}
