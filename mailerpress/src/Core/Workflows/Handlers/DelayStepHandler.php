<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Workflows\Services\ActionSchedulerManager;

class DelayStepHandler implements StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'delay' || $key === 'wait';
    }

    public function getDefinition(): array
    {
        return [
            'key' => 'delay',
            'label' => __('Wait', 'mailerpress'),
            'description' => __('Pause the workflow for a specified duration before continuing. Perfect for creating delays between actions, such as waiting a few hours before sending a follow-up email.', 'mailerpress'),
            'icon' => 'clock',
            'category' => 'timing',
            'type' => 'DELAY',
            'settings_schema' => [
                [
                    'key' => 'delay.value',
                    'label' => __('Duration', 'mailerpress'),
                    'type' => 'number',
                    'required' => true,
                    'default' => 1,
                ],
                [
                    'key' => 'delay.unit',
                    'label' => __('Unit', 'mailerpress'),
                    'type' => 'select',
                    'required' => true,
                    'default' => 'minutes',
                    'options' => [
                        ['value' => 'minutes', 'label' => __('Minutes', 'mailerpress')],
                        ['value' => 'hours', 'label' => __('Hours', 'mailerpress')],
                        ['value' => 'days', 'label' => __('Days', 'mailerpress')],
                        ['value' => 'weeks', 'label' => __('Weeks', 'mailerpress')],
                    ],
                ],
            ],
        ];
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        $settings = $step->getSettings() ?? [];

        // Support both formats: nested array ['delay' => ['value' => ...]] 
        // and flat keys ['delay.value' => ..., 'delay.unit' => ...]
        $value = 1;
        $unit = 'minutes';

        if (isset($settings['delay']) && is_array($settings['delay'])) {
            // Nested format: ['delay' => ['value' => 1, 'unit' => 'days']]
            $delay = $settings['delay'];
            $value = $delay['value'] ?? 1;
            $unit = $delay['unit'] ?? 'minutes';
        } else {
            // Flat format: ['delay.value' => 1, 'delay.unit' => 'days']
            $value = isset($settings['delay.value'])
                ? $settings['delay.value']
                : (isset($settings['duration']) ? $settings['duration'] : 1);
            $unit = $settings['delay.unit'] ?? $settings['unit'] ?? 'minutes';
        }

        // Ensure value is a positive integer (no decimals allowed)
        $value = (int) floor((float) $value);
        if ($value <= 0) {
            $value = 1;
        }

        $timestamp = $this->calculateTimestamp($value, $unit);

        ActionSchedulerManager::scheduleContinue(
            $timestamp,
            (int) $job->getId(),
            $step->getNextStepId()
        );

        $job->setScheduledAt(gmdate('Y-m-d H:i:s', $timestamp));
        $job->setNextStepId($step->getNextStepId());

        return StepResult::success($step->getNextStepId(), [
            'delayed_until' => wp_date('Y-m-d H:i:s', $timestamp),
            'delay_value' => $value,
            'delay_unit' => $unit,
        ]);
    }

    private function calculateTimestamp(int $value, string $unit): int
    {
        $now = time();

        $seconds = match ($unit) {
            'seconds' => $value,
            'minutes' => $value * MINUTE_IN_SECONDS,
            'hours' => $value * HOUR_IN_SECONDS,
            'days' => $value * DAY_IN_SECONDS,
            'weeks' => $value * WEEK_IN_SECONDS,
            default => DAY_IN_SECONDS, // fallback if something unexpected
        };

        $timestamp = $now + $seconds;

        return $timestamp;
    }
}
