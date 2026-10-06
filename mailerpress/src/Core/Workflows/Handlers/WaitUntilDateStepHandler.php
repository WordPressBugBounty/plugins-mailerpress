<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Workflows\Services\ActionSchedulerManager;
use MailerPress\Core\Workflows\Services\ContactResolver;

class WaitUntilDateStepHandler implements StepHandlerInterface
{
	public function supports(string $key): bool
	{
		return 'wait_until_date' === $key;
	}

	public function getDefinition(): array
	{
		return [
			'key' => 'wait_until_date',
			'label' => __('Wait Until Date', 'mailerpress'),
			'description' => __('Pause the workflow until a specific date, or until a date stored in a contact custom field (with an optional offset). Ideal for event reminders — e.g. "3 days before event_date".', 'mailerpress'),
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
						['value' => 'custom_field', 'label' => __('Contact custom field', 'mailerpress')],
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
					'key' => 'custom_field_key',
					'label' => __('Date field key', 'mailerpress'),
					'type' => 'text',
					'required' => true,
					'placeholder' => 'event_date',
					'help' => __('The custom field key that contains a date value (e.g. event_date).', 'mailerpress'),
					'visible_when' => ['date_source' => 'custom_field'],
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
		];
	}

	public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
	{
		$settings = $step->getSettings() ?? [];

		$dateSource = $settings['date_source'] ?? 'fixed';
		$targetDateString = null;

		if ( 'fixed' === $dateSource ) {
			$targetDateString = $settings['fixed_date'] ?? null;
		} else {
			$fieldKey = $settings['custom_field_key'] ?? null;
			if ( $fieldKey ) {
				$targetDateString = $this->getContactCustomFieldDate( (int) $job->getUserId(), $fieldKey, $context );
			}
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

	private function getContactCustomFieldDate(int $userId, string $fieldKey, array $context): ?string
	{
		if ( isset( $context[ $fieldKey ] ) && ! empty( $context[ $fieldKey ] ) ) {
			return (string) $context[ $fieldKey ];
		}

		global $wpdb;
		$customFieldsTable = Tables::get( Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS );

		$contact = ( new ContactResolver() )->resolve( $userId, null, $context );
		$contactId = $contact?->contactId;

		if ( ! $contactId ) {
			return null;
		}

		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT field_value FROM {$customFieldsTable} WHERE contact_id = %d AND field_key = %s LIMIT 1",
			$contactId,
			$fieldKey
		) );

		return $value ?: null;
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
