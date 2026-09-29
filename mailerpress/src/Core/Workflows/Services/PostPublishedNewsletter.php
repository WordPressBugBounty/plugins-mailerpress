<?php

namespace MailerPress\Core\Workflows\Services;

use MailerPress\Api\Campaigns;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Services\CampaignHtmlOptionStorage;
use MailerPress\Actions\ActionScheduler\Processors\MailerPressEmailBatch;

/** Creates a separate newsletter using the normal campaign and batch pipeline. */
class PostPublishedNewsletter
{
	public function handle( Step $step, AutomationJob $job, array $context ): StepResult
	{
		global $wpdb;
		$post = get_post( (int) ( $context['post_id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return StepResult::failed( __( 'The published post is no longer available.', 'mailerpress' ), false );
		}

		$table = Tables::get( Tables::MAILERPRESS_CAMPAIGNS );
		$template_id = (int) ( $step->getSettings()['template_id'] ?? 0 );
		$template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d", $template_id ) );
		$config = $template ? json_decode( $template->config ?? '', true ) : null;
		if ( ! $template || ! is_array( $config ) || empty( $config['publicationNewsletterReady'] ) ) {
			return StepResult::failed( __( 'Open this workflow email, choose its newsletter audience and save the newsletter setup first.', 'mailerpress' ), false );
		}
		$config['lists'] = $this->audienceIds( $config['lists'] ?? [], 'list_id' );
		$config['tags'] = $this->audienceIds( $config['tags'] ?? [], 'tag_id' );
		$config['segment'] = is_array( $config['segment'] ?? null )
			? array_values( array_filter( $config['segment'], static fn( $name ) => is_string( $name ) && '' !== trim( $name ) ) ) : [];
		$mode = $config['publicationNewsletterMode'] ?? 'send';
		$targeting = $config['recipientTargeting'] ?? 'classic';
		if ( ! in_array( $mode, [ 'draft', 'send' ], true ) || ! in_array( $targeting, [ 'classic', 'segment' ], true ) ||
			( 'segment' === $targeting ? empty( $config['segment'] ) : ( empty( $config['lists'] ) && empty( $config['tags'] ) ) ) ) {
			return StepResult::failed( __( 'Choose a newsletter audience before enabling publication emails.', 'mailerpress' ), false );
		}

		$event_key = hash( 'sha256', $job->getAutomationId() . ':' . $step->getStepId() . ':' . $post->ID );
		$lock = 'mp_post_newsletter_' . substr( $event_key, 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return StepResult::failed( __( 'This publication newsletter is already being prepared.', 'mailerpress' ) );
		}

		try {
			// A unique event row also serializes retries until the workflow transaction commits.
			$event_option = 'mailerpress_publication_' . $event_key;
			$reserved = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')",
				$event_option
			) );
			if ( false === $reserved ) {
				return StepResult::failed( __( 'Could not reserve this publication newsletter.', 'mailerpress' ) );
			}
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", $event_option
			) );
			$existing = $existing_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d FOR UPDATE", $existing_id ) ) : null;
			if ( $existing_id && ! $existing ) {
				return StepResult::failed( __( 'The newsletter for this publication was deleted and will not be recreated automatically.', 'mailerpress' ), false );
			}
			$campaign_id = $existing ? (int) $existing->campaign_id : 0;
			if ( $existing && ( 'draft' !== $existing->status || MailerPressEmailBatch::getBlockingBatchId( $campaign_id ) > 0 || MailerPressEmailBatch::hasScheduledAction( $campaign_id ) ) ) {
				return StepResult::success( $step->getNextStepId(), [ 'newsletter_campaign_id' => $campaign_id ] );
			}

			$existing_html = $campaign_id ? get_option( 'mailerpress_batch_' . $campaign_id . '_html', '' ) : '';
			if ( $existing && '' !== $existing_html ) {
				$config = json_decode( $existing->config, true );
				$mode = $config['publicationNewsletterMode'] ?? 'send';
				$targeting = $config['recipientTargeting'] ?? 'classic';
				if ( 'draft' === $mode ) {
					return StepResult::success( $step->getNextStepId(), [ 'newsletter_campaign_id' => $campaign_id ] );
				}
			}
			$html = $existing_html ?: get_option( 'mailerpress_batch_' . $template_id . '_html', '' );
			if ( ! is_string( $html ) || '' === trim( $html ) || ! preg_match( '/<(?:html|body|table)\b/i', $html ) ) {
				return StepResult::failed( __( 'Save the newsletter design before publishing a post.', 'mailerpress' ), false );
			}
			if ( ! preg_match( '/%(?:UNSUB_LINK|MANAGE_SUB_LINK)%/i', rawurldecode( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) ) {
				return StepResult::failed( __( 'Add an unsubscribe or manage subscription link to the newsletter.', 'mailerpress' ), false );
			}
			// Resolve only post content. Contact merge tags and tracking are applied by the newsletter sender.
			$html = ( new EmailContentRenderer() )->renderPostBlocks( $html, [ 'post_id' => $post->ID ] );
			$replacements = [ '{{post_title}}' => wp_strip_all_tags( $post->post_title ), '{{post_url}}' => get_permalink( $post ), '{{post_id}}' => (string) $post->ID ];
			$subject = strtr( (string) ( $config['campaignSubject'] ?? $template->subject ), $replacements );
			$name = strtr( (string) $template->name, $replacements ) . ' — ' . wp_strip_all_tags( $post->post_title );
			$config['campaignSubject'] = $subject;
			$config['publicationEventKey'] = $event_key;
			$config['publicationPostId'] = $post->ID;
			$config['publicationTemplateId'] = $template_id;
			$config['sendChoice'] = 'now';
			unset( $config['publicationNewsletterReady'], $config['sendAt'] );
			$json = json_decode( $template->content_html ?? '', true );
			if ( is_array( $json ) ) {
				$json = $this->bindPost( $json, $post );
			} else {
				$json = $html;
			}

			$api = new Campaigns();
			if ( ! $campaign_id ) {
				$request = new \WP_REST_Request( 'POST' );
				$request->set_body_params( [
					'title' => $name,
					'campaign_type' => 'newsletter',
					'meta' => [ 'json' => $json, 'emailConfig' => $config ],
				] );
				$response = $api->post( $request );
				if ( is_wp_error( $response ) ) {
					return StepResult::failed( $response->get_error_message() );
				}
				$campaign_id = (int) $response->get_data();
				$recorded = $wpdb->update( $wpdb->options, [ 'option_value' => (string) $campaign_id ], [ 'option_name' => $event_option ] );
				if ( false === $recorded ) {
					$wpdb->delete( $table, [ 'campaign_id' => $campaign_id ] );
					return StepResult::failed( __( 'Could not record this publication newsletter.', 'mailerpress' ) );
				}
				// Scheduled publications have no logged-in user: retain the template owner.
				$wpdb->update( $table, [ 'user_id' => (int) $template->user_id ], [ 'campaign_id' => $campaign_id ] );
			}
			$stored = CampaignHtmlOptionStorage::store( $campaign_id, $html, [ 'source' => 'post_published' ] );
			if ( empty( $stored['success'] ) ) {
				return StepResult::failed( __( 'The publication newsletter HTML could not be saved.', 'mailerpress' ) );
			}

			if ( 'send' === $mode ) {
				$request = new \WP_REST_Request( 'POST' );
				$request->set_body_params( [
					'postEdit' => $campaign_id,
					'html' => $html,
					'sendType' => 'now',
					'config' => array_merge( $config, [ 'subject' => $subject ] ),
					'recipientTargeting' => $targeting,
					'lists' => $config['lists'] ?? [],
					'tags' => $config['tags'] ?? [],
					'segment' => $config['segment'] ?? [],
					'openTracking' => $config['openTracking'] ?? 'yes',
					'clickTracking' => $config['clickTracking'] ?? 'yes',
				] );
				$response = $api->createBatchV2( $request );
				if ( is_wp_error( $response ) ) {
					return StepResult::failed( $response->get_error_message() );
				}
			}
			return StepResult::success( $step->getNextStepId(), [ 'newsletter_campaign_id' => $campaign_id ] );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function audienceIds( mixed $items, string $key ): array
	{
		$ids = [];
		foreach ( is_array( $items ) ? $items : [] as $item ) {
			$value = is_array( $item ) ? ( $item[ $key ] ?? $item['id'] ?? null ) : $item;
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$ids[] = (int) $value;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function bindPost( array $block, \WP_Post $post ): array
	{
		if ( 'published-post' === ( $block['type'] ?? '' ) ) {
			$images = [];
			foreach ( [ 'thumbnail', 'medium', 'medium_large', 'large', 'full' ] as $size ) {
				$images[ $size ] = get_the_post_thumbnail_url( $post, $size ) ?: '';
			}
			$block['data']['post'] = array_merge( (array) $post, [
				'post_url' => get_permalink( $post ),
				'post_thumbnail_url' => $images['large'] ?: $images['full'],
				'featured_image_src' => $images,
				'category_names' => \MailerPress\Helpers\getPostCategoryNames( $post ),
				'post_excerpt' => $post->post_excerpt ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 55 ),
			] );
		}
		foreach ( $block['children'] ?? [] as $index => $child ) {
			$block['children'][ $index ] = $this->bindPost( $child, $post );
		}
		return $block;
	}
}
