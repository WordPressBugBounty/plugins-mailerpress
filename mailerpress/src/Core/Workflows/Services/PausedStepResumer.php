<?php

declare(strict_types=1);

namespace MailerPress\Core\Workflows\Services;

\defined('ABSPATH') || exit;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Handlers\StepHandlerRegistry;

/**
 * Contacts reaching a step whose handler is unavailable (e.g. a MailerPress Pro step while Pro is inactive)
 * are paused on that step. This class remembers those steps and resumes the contacts once the handler is
 * registered again.
 *
 * The same applies to a step whose handler exists but whose settings need a feature provided by an extension
 * (e.g. a date read from a custom field): the extension declares the feature through the
 * "mailerpress_workflow_available_features" filter.
 */
final class PausedStepResumer
{
    private const ERROR_PREFIX = 'missing_step_handler:';
    private const FEATURE_PREFIX = 'missing_feature:';
    private const OPTION = 'mailerpress_paused_step_keys';
    private const FEATURE_OPTION = 'mailerpress_paused_features';
    private const BATCH = 100;

    /** Reason stored on a job paused on the step key. */
    public static function reason(string $stepKey): string
    {
        return self::ERROR_PREFIX . $stepKey;
    }

    public static function remember(string $stepKey): void
    {
        self::add(self::OPTION, $stepKey);
    }

    /** Reason stored on a job paused until the feature is available. */
    public static function featureReason(string $feature): string
    {
        return self::FEATURE_PREFIX . $feature;
    }

    public static function rememberFeature(string $feature): void
    {
        self::add(self::FEATURE_OPTION, $feature);
    }

    /** Whether an active extension provides the feature. */
    public static function isFeatureAvailable(string $feature): bool
    {
        return \in_array($feature, (array) apply_filters('mailerpress_workflow_available_features', []), true);
    }

    private static function add(string $option, string $key): void
    {
        $keys = (array) get_option($option, []);
        if (!\in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option($option, array_values($keys), true);
        }
    }

    /** Resumes the paused contacts whose step handler or feature is available again, by batches. */
    public static function resume(StepHandlerRegistry $registry): void
    {
        if (!\function_exists('as_schedule_single_action')) {
            return;
        }

        self::resumeKeys(
            self::OPTION,
            static fn (string $key): bool => (bool) $registry->getHandler($key),
            [self::class, 'reason']
        );
        self::resumeKeys(
            self::FEATURE_OPTION,
            [self::class, 'isFeatureAvailable'],
            [self::class, 'featureReason']
        );
    }

    private static function resumeKeys(string $option, callable $isAvailable, callable $reason): void
    {
        $keys = (array) get_option($option, []);
        if (!$keys) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_JOBS;
        $remaining = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            if (!$isAvailable($key)) {
                $remaining[] = $key;
                continue;
            }

            $jobIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$table} WHERE status = 'PAUSED' AND last_error = %s ORDER BY id ASC LIMIT %d",
                $reason($key),
                self::BATCH
            )));

            foreach ($jobIds as $jobId) {
                $resumed = $wpdb->update(
                    $table,
                    ['status' => 'ACTIVE', 'last_error' => null, 'updated_at' => current_time('mysql')],
                    ['id' => $jobId, 'status' => 'PAUSED']
                );
                if ($resumed) {
                    ActionSchedulerManager::scheduleContinue(time(), $jobId);
                }
            }

            // A full batch means more contacts may be waiting: continue on the next request.
            if (self::BATCH === \count($jobIds)) {
                $remaining[] = $key;
            }
        }

        if ($remaining !== array_values($keys)) {
            update_option($option, $remaining, true);
        }
    }
}
