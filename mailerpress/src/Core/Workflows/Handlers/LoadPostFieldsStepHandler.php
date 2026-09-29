<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Core\Workflows\Services\ContactResolver;

class LoadPostFieldsStepHandler implements StepHandlerInterface
{
	public function supports(string $key): bool
	{
		return 'load_post_fields' === $key;
	}

	public function getDefinition(): array
	{
		return [
			'key' => 'load_post_fields',
			'label' => __('Load Post Data', 'mailerpress'),
			'description' => __('Fetch data from a page or post (title, custom fields, thumbnail…) so you can use it as personalization tags in your emails — e.g. {{event_date}}, {{zoom_link}}.', 'mailerpress'),
			'icon' => 'post',
			'category' => 'data',
			'type' => 'ACTION',
			'settings_schema' => [
				[
					'key' => 'post_id_source',
					'label' => __('Where to find the post', 'mailerpress'),
					'type' => 'select',
					'required' => true,
					'default' => 'manual',
					'options' => [
						['value' => 'manual', 'label' => __('Specific post (enter ID)', 'mailerpress')],
						['value' => 'contact_field', 'label' => __('Stored on the contact (custom field)', 'mailerpress')],
						['value' => 'context', 'label' => __('Passed by the trigger automatically', 'mailerpress')],
					],
				],
				[
					'key' => 'manual_post_id',
					'label' => __('Post ID', 'mailerpress'),
					'type' => 'number',
					'required' => false,
					'help' => __('The ID of the page or post to load. You can find it in the URL when editing the post (e.g. post=42).', 'mailerpress'),
					'visible_when' => ['post_id_source' => 'manual'],
				],
				[
					'key' => 'contact_field_key',
					'label' => __('Custom field name', 'mailerpress'),
					'type' => 'text',
					'required' => false,
					'placeholder' => 'event_post_id',
					'help' => __('Name of the contact custom field that contains the post ID.', 'mailerpress'),
					'visible_when' => ['post_id_source' => 'contact_field'],
				],
				[
					'key' => 'context_key',
					'label' => __('Context variable', 'mailerpress'),
					'type' => 'text',
					'required' => false,
					'default' => 'post_id',
					'help' => __('The trigger passes a variable containing the post ID. Default: post_id.', 'mailerpress'),
					'visible_when' => ['post_id_source' => 'context'],
				],
				[
					'key' => 'prefix',
					'label' => __('Tag prefix', 'mailerpress'),
					'type' => 'text',
					'required' => false,
					'default' => '',
					'placeholder' => 'event_',
					'help' => __('Optional. Adds a prefix to all loaded tags. E.g. with prefix "event_" the title becomes {{event_post_title}}.', 'mailerpress'),
				],
				[
					'key' => 'field_keys',
					'label' => __('Specific fields only', 'mailerpress'),
					'type' => 'text',
					'required' => false,
					'default' => '',
					'placeholder' => 'zoom_link, event_date, location',
					'help' => __('Leave empty to load all fields. Or enter specific field names separated by commas.', 'mailerpress'),
				],
			],
		];
	}

	public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
	{
		$settings = $step->getSettings() ?? [];

		$postId = $this->resolvePostId( $settings, $job, $context );

		if ( ! $postId || $postId <= 0 ) {
			return StepResult::success( $step->getNextStepId(), [
				'load_post_fields_skipped' => true,
				'load_post_fields_reason' => 'no_post_id',
			] );
		}

		$post = get_post( $postId );
		if ( ! $post ) {
			return StepResult::success( $step->getNextStepId(), [
				'load_post_fields_skipped' => true,
				'load_post_fields_reason' => 'post_not_found',
				'load_post_fields_post_id' => $postId,
			] );
		}

		$prefix = sanitize_key( $settings['prefix'] ?? '' );
		$fieldKeys = $this->parseFieldKeys( $settings['field_keys'] ?? '' );

		$fields = [];

		$fields[ $prefix . 'post_title' ] = \esc_html( $post->post_title );
		$fields[ $prefix . 'post_url' ] = \esc_url( get_permalink( $postId ) );
		$fields[ $prefix . 'post_date' ] = \esc_html( $post->post_date );
		$fields[ $prefix . 'post_excerpt' ] = \esc_html( $post->post_excerpt );

		$thumbnail = get_the_post_thumbnail_url( $postId, 'large' );
		if ( $thumbnail ) {
			$fields[ $prefix . 'post_thumbnail' ] = \esc_url( $thumbnail );
		}

		if ( empty( $fieldKeys ) ) {
			$allMeta = get_post_meta( $postId );
			if ( is_array( $allMeta ) ) {
				foreach ( $allMeta as $key => $values ) {
					if ( str_starts_with( $key, '_' ) ) {
						continue;
					}
					$value = $values[0] ?? '';
					if ( is_scalar( $value ) || ( is_string( $value ) && '' !== $value ) ) {
						$fields[ $prefix . sanitize_key( $key ) ] = \esc_html( (string) $value );
					}
				}
			}
		} else {
			foreach ( $fieldKeys as $key ) {
				$value = get_post_meta( $postId, $key, true );
				if ( '' !== $value && null !== $value && false !== $value ) {
					if ( is_scalar( $value ) ) {
						$fields[ $prefix . sanitize_key( $key ) ] = \esc_html( (string) $value );
					}
				}
			}
		}

		$fields['_loaded_post_id'] = $postId;
		$fields['_loaded_post_type'] = $post->post_type;

		return StepResult::success( $step->getNextStepId(), $fields );
	}

	private function resolvePostId(array $settings, AutomationJob $job, array $context): int
	{
		$source = $settings['post_id_source'] ?? 'context';

		if ( 'manual' === $source ) {
			return absint( $settings['manual_post_id'] ?? 0 );
		}

		if ( 'contact_field' === $source ) {
			$fieldKey = $settings['contact_field_key'] ?? '';
			if ( ! empty( $fieldKey ) ) {
				if ( isset( $context[ $fieldKey ] ) ) {
					return absint( $context[ $fieldKey ] );
				}

				global $wpdb;
				$customFieldsTable = \MailerPress\Core\Enums\Tables::get(
					\MailerPress\Core\Enums\Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS
				);
				$contact = ( new ContactResolver() )->resolve( (int) $job->getUserId(), null, $context );
				$contactId = $contact?->contactId;

				if ( $contactId ) {
					$value = $wpdb->get_var( $wpdb->prepare(
						"SELECT field_value FROM {$customFieldsTable} WHERE contact_id = %d AND field_key = %s LIMIT 1",
						$contactId,
						$fieldKey
					) );
					return absint( $value ?? 0 );
				}
			}
			return 0;
		}

		$contextKey = $settings['context_key'] ?? 'post_id';
		return absint( $context[ $contextKey ] ?? 0 );
	}

	private function parseFieldKeys(string $fieldKeysString): array
	{
		if ( '' === trim( $fieldKeysString ) ) {
			return [];
		}

		return array_filter(
			array_map( 'trim', explode( ',', $fieldKeysString ) )
		);
	}
}
