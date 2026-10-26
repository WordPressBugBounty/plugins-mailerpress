<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Workflows\Services\ActionSchedulerManager;
use MailerPress\Core\Workflows\Services\PausedStepResumer;

class WaitUntilDateStepHandler implements StepHandlerInterface
{
	public function supports(string $key): bool
	{
		return 'wait_until_date' === $key;
	}

	public function getDefinition(): array
	{
		// Extensions add date sources, e.g. MailerPress Pro reads the date from a contact custom field.
		return (array) apply_filters('mailerpress_workflow_wait_until_date_definition', [
			'key' => 'wait_until_date',
			'label' => __('Wait Until Date', 'mailerpress'),
			'description' => __('Pause the workflow until a specific date, with an optional offset.', 'mailerpress'),
			'icon' => 'calendar',
			'category' => 'timing',
			'type' => 'DELAY',
			'settings_schema' => [
				[
					'key' => 'date_source',
					'label' => __('Date source', 'mailerpress'),
					'type' => 'select',
					'required' => true,
					'default' => 'fixed',
					'options' => [
						['value' => 'fixed', 'label' => __('Fixed date', 'mailerpress')],
					],
				],
				[
					'key' => 'fixed_date',
					'label' => __('Date', 'mailerpress'),
					'type' => 'datetime',
					'required' => true,
					'visible_when' => ['date_source' => 'fixed'],
				],
				[
					'key' => 'offset_value',
					'label' => __('Offset', 'mailerpress'),
					'type' => 'number',
					'required' => false,
					'default' => 0,
					'min' => 0,
					'row' => 'offset',
				],
				[
					'key' => 'offset_unit',
					'label' => __('Unit', 'mailerpress'),
					'type' => 'select',
					'required' => false,
					'default' => 'days',
					'options' => [
						['value' => 'hours', 'label' => __('Hours', 'mailerpress')],
						['value' => 'days', 'label' => __('Days', 'mailerpress')],
						['value' => 'weeks', 'label' => __('Weeks', 'mailerpress')],
					],
					'row' => 'offset',
				],
				[
					'key' => 'offset_direction',
					'label' => __('Direction', 'mailerpress'),
					'type' => 'select',
					'required' => false,
					'default' => 'before',
					'options' => [
						['value' => 'before', 'label' => __('Before the date', 'mailerpress')],
						['value' => 'after', 'label' => __('After the date', 'mailerpress')],
					],
				],
				[
					'key' => 'past_date_behavior',
					'label' => __('If date is in the past', 'mailerpress'),
					'type' => 'select',
					'required' => false,
					'default' => 'skip',
					'options' => [
						['value' => 'skip', 'label' => __('Skip and continue', 'mailerpress')],
						['value' => 'fail', 'label' => __('Stop the workflow', 'mailerpress')],
					],
				],
			],
		]);
	}

	public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
	{
		$settings = $step->getSettings() ?? [];

		$dateSource = $settings['date_source'] ?? 'fixed';
		$targetDateString = null;

		if ( 'fixed' === $dateSource ) {
			$targetDateString = $settings['fixed_date'] ?? null;
		} else {
			// Other sources belong to an extension: without it, the contact waits on this step.
			$feature = 'wait_until_date_source:' . $dateSource;
			if ( ! PausedStepResumer::isFeatureAvailable( $feature ) ) {
				return StepResult::paused( $feature );
			}

			$target = apply_filters( 'mailerpress_workflow_wait_until_date_target', null, $dateSource, $settings, (int) $job->getUserId(), $context );
			$targetDateString = is_scalar( $target ) && '' !== (string) $target ? (string) $target : null;
		}

		if ( ! $targetDateString ) {
			$pastBehavior = $settings['past_date_behavior'] ?? 'skip';
			if ( 'fail' === $pastBehavior ) {
				return StepResult::failed(
					__( 'Wait Until Date: no target date found.', 'mailerpress' ),
					false
				);
			}
			return StepResult::success( $step->getNextStepId(), [
				'wait_until_date_skipped' => true,
				'wait_until_date_reason' => 'no_date_found',
			] );
		}

		try {
			// Dates without an explicit timezone use the WordPress site timezone.
			$targetTimestamp = ( new \DateTimeImmutable( $targetDateString, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $exception ) {
			return StepResult::failed(
				sprintf(
					/* translators: %s: the invalid date string */
					__( 'Wait Until Date: invalid date format "%s".', 'mailerpress' ),
					$targetDateString
				),
				false
			);
		}

		$offsetValue = absint( $settings['offset_value'] ?? 0 );
		$offsetUnit = $settings['offset_unit'] ?? 'days';
		$offsetDirection = $settings['offset_direction'] ?? 'before';

		if ( $offsetValue > 0 ) {
			$offsetSeconds = $this->calculateOffsetSeconds( $offsetValue, $offsetUnit );
			if ( 'before' === $offsetDirection ) {
				$targetTimestamp -= $offsetSeconds;
			} else {
				$targetTimestamp += $offsetSeconds;
			}
		}

		$now = time();

		if ( $targetTimestamp <= $now ) {
			$pastBehavior = $settings['past_date_behavior'] ?? 'skip';
			if ( 'fail' === $pastBehavior ) {
				return StepResult::failed(
					__( 'Wait Until Date: the computed date is in the past.', 'mailerpress' ),
					false
				);
			}
			return StepResult::success( $step->getNextStepId(), [
				'wait_until_date_skipped' => true,
				'wait_until_date_reason' => 'date_in_past',
				'wait_until_date_target' => wp_date( 'Y-m-d H:i:s', $targetTimestamp ),
			] );
		}

		ActionSchedulerManager::scheduleContinue(
			$targetTimestamp,
			(int) $job->getId(),
			$step->getNextStepId()
		);

		$job->setScheduledAt( gmdate( 'Y-m-d H:i:s', $targetTimestamp ) );
		$job->setNextStepId( $step->getNextStepId() );

		return StepResult::success( $step->getNextStepId(), [
			'wait_until_date' => wp_date( 'Y-m-d H:i:s', $targetTimestamp ),
			'wait_until_date_source' => $dateSource,
			'wait_until_date_original' => $targetDateString,
			'wait_until_date_offset' => $offsetValue > 0
				? sprintf( '%d %s %s', $offsetValue, $offsetUnit, $offsetDirection )
				: 'none',
		] );
	}

	private function calculateOffsetSeconds(int $value, string $unit): int
	{
		return match ( $unit ) {
			'hours' => $value * HOUR_IN_SECONDS,
			'days' => $value * DAY_IN_SECONDS,
			'weeks' => $value * WEEK_IN_SECONDS,
			default => $value * DAY_IN_SECONDS,
		};
	}
}
