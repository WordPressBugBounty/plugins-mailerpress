<?php

/**
 * Add a UTC reference instant to the goals table.
 *
 * The median time-to-convert was computed as
 * TIMESTAMPDIFF(SECOND, created_at, achieved_at), which mixes two different
 * time references: `created_at` is a TIMESTAMP (MySQL converts it with the
 * session timezone on read) while `achieved_at` is a DATETIME (stored
 * verbatim). The result was off by the timezone offset, and could even come out
 * negative.
 *
 * `started_at` mirrors `expires_at`: a DATETIME always written in UTC, so both
 * ends of the duration live in the same reference frame whatever the site or
 * MySQL session timezone.
 */

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\CustomTableManager;
use MailerPress\Core\Migrations\SchemaBuilder;

return function (SchemaBuilder $schema) {
    $schema->table(Tables::MAILERPRESS_AUTOMATIONS_GOALS, function (CustomTableManager $table) {
        $table->datetime('started_at')->nullable();
    });

    // Existing rows keep a NULL started_at: their created_at cannot be
    // converted back to UTC reliably (the session timezone at write time is
    // unknown), and the median query skips rows without it rather than
    // reporting a wrong duration.
};
