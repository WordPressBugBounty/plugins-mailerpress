<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

/**
 * Normalizes the standard contact fields read from an imported file row.
 */
class ContactImportFields
{
    private const DATE_FORMATS = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
        'Y-m-d\\TH:i:sP',
        'Y-m-d\\TH:i:s.uP',
        'Y-m-d\\TH:i:s',
        'Y-m-d\\TH:i',
        'Y/m/d H:i:s',
        'Y/m/d',
    ];

    /**
     * Returns the first and last names present and non-empty in the row.
     *
     * Unmapped or empty columns are omitted so existing values are kept on update.
     *
     * @return array<string, string>
     */
    public static function names(array $contact): array
    {
        $fields = [];

        foreach (['first_name', 'last_name'] as $key) {
            if (!isset($contact[$key]) || !is_string($contact[$key])) {
                continue;
            }

            $value = sanitize_text_field(trim($contact[$key], ' "\''));
            if ('' !== $value) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Returns the valid created_at and updated_at dates of the row as MySQL datetimes in the site timezone.
     *
     * @return array<string, string>
     */
    public static function dates(array $contact): array
    {
        $fields = [];

        foreach (['created_at', 'updated_at'] as $key) {
            if (!isset($contact[$key]) || !is_string($contact[$key])) {
                continue;
            }

            $date = self::parseDate($contact[$key]);
            if (null !== $date) {
                $fields[$key] = $date;
            }
        }

        return $fields;
    }

    private static function parseDate(string $value): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ('' === $value) {
            return null;
        }

        $timezone = wp_timezone();

        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
            if (false === $date) {
                continue;
            }

            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            $date = $date->setTimezone($timezone);
            $year = (int) $date->format('Y');
            if ($year < 1000 || $year > 9999) {
                return null;
            }

            return $date->format('Y-m-d H:i:s');
        }

        return null;
    }
}
