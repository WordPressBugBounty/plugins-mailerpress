<?php

namespace MailerPress\Core\Workflows\Models;

/**
 * Typed view over the settings of a GOAL step.
 *
 * Settings arrive from the editor either nested or flattened (the settings
 * schema uses dotted keys in places), so every accessor tolerates both shapes,
 * exactly like DelayStepHandler does for its duration.
 */
class GoalSettings
{
    private array $settings;

    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
    }

    public static function fromStep(Step $step): self
    {
        return new self($step->getSettings() ?? []);
    }

    public function getMode(): string
    {
        $mode = (string) ($this->settings['goal_mode'] ?? Goal::MODE_ESSENTIAL);

        return Goal::MODE_OPTIONAL === $mode ? Goal::MODE_OPTIONAL : Goal::MODE_ESSENTIAL;
    }

    public function isEssential(): bool
    {
        return Goal::MODE_ESSENTIAL === $this->getMode();
    }

    public function isOptional(): bool
    {
        return Goal::MODE_OPTIONAL === $this->getMode();
    }

    public function allowsEntry(): bool
    {
        return $this->toBool($this->settings['goal_allow_entry'] ?? false);
    }

    public function ignoresRunOnce(): bool
    {
        return $this->toBool($this->settings['goal_ignore_run_once'] ?? false);
    }

    /**
     * Timeout in seconds, or null when the goal waits indefinitely.
     */
    public function getTimeoutSeconds(): ?int
    {
        $value = $this->settings['goal_timeout_value'] ?? null;

        if (null === $value || '' === $value) {
            return null;
        }

        $value = (int) floor((float) $value);

        if ($value <= 0) {
            return null;
        }

        $unit = (string) ($this->settings['goal_timeout_unit'] ?? 'days');

        $seconds = match ($unit) {
            'minutes' => $value * MINUTE_IN_SECONDS,
            'hours' => $value * HOUR_IN_SECONDS,
            'weeks' => $value * WEEK_IN_SECONDS,
            'days' => $value * DAY_IN_SECONDS,
            default => $value * DAY_IN_SECONDS,
        };

        return (int) $seconds;
    }

    /**
     * Settings minus the goal plumbing: what the event matcher should filter on.
     */
    public function getEventFilters(): array
    {
        $optionKeys = ['goal_mode', 'goal_allow_entry', 'goal_ignore_run_once', 'goal_timeout_value', 'goal_timeout_unit'];

        return array_diff_key($this->settings, array_flip($optionKeys));
    }

    public function toArray(): array
    {
        return $this->settings;
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
