<?php

declare(strict_types=1);

namespace MailerPress\Core\Migration;

\defined('ABSPATH') || exit;

/**
 * Custom fields are a MailerPress Pro feature: migrations only create custom field definitions and import
 * their values when an extension enables it through the mailerpress_migration_import_custom_fields filter.
 */
final class CustomFieldsImport
{
    public static function enabled(): bool
    {
        return true === apply_filters('mailerpress_migration_import_custom_fields', false);
    }

    /** Result of a custom fields chunk created while the extension was active, processed without it. */
    public static function skippedChunk(): array
    {
        return [
            'processed' => 0,
            'skipped' => 1,
            'errors' => 0,
            'skipped_chunk' => true,
            'message' => __('Custom fields are imported with MailerPress Pro.', 'mailerpress'),
        ];
    }
}
