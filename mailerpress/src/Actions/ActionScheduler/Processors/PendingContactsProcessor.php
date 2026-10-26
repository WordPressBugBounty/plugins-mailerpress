<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined( 'ABSPATH' ) || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Services\PendingContactManager;

class PendingContactsProcessor {

	private const HOOK           = 'mailerpress_cleanup_pending_contacts';
	private const CHUNK_HOOK     = 'mailerpress_cleanup_pending_contacts_chunk';
	private const GROUP          = 'mailerpress';
	private const SCHEDULE_CHECK = 'mailerpress_pending_contacts_schedule_check';

	public function __construct( private readonly PendingContactManager $manager ) {
	}

	#[Action( 'init', priority: 22 )]
	public function schedule(): void {
		if ( get_transient( self::SCHEDULE_CHECK ) ) {
			return;
		}
		$this->scheduleAfterSettingsChange();
	}

	#[Action( array( 'add_option_' . PendingContactManager::OPTION_NAME, 'update_option_' . PendingContactManager::OPTION_NAME, 'delete_option_' . PendingContactManager::OPTION_NAME ), acceptedArgs: 0 )]
	public function scheduleAfterSettingsChange(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		set_transient( self::SCHEDULE_CHECK, 1, HOUR_IN_SECONDS );
		$settings = $this->manager->getSettings();
		if ( empty( $settings['automaticallyDeleteUnconfirmedContacts'] ) ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, null, self::GROUP );
				as_unschedule_all_actions( self::CHUNK_HOOK, null, self::GROUP );
			}
			return;
		}
		if ( ! as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP, true );
		}
	}

	#[Action( self::HOOK )]
	public function process(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || as_next_scheduled_action( self::CHUNK_HOOK, null, self::GROUP ) ) {
			return;
		}
		global $wpdb;
		$table = Tables::get( Tables::MAILERPRESS_CONTACT );
		$maxId = (int) $wpdb->get_var( "SELECT MAX(contact_id) FROM {$table}" );
		$this->processChunk( 0, $maxId );
	}

	#[Action( self::CHUNK_HOOK, acceptedArgs: 2 )]
	public function processChunk( int $afterId, int $maxId ): void {
		$ids = $this->manager->findCandidates( $afterId, $maxId );
		foreach ( $ids as $id ) {
			$this->manager->deleteIfExpired( (int) $id );
		}
		if ( PendingContactManager::BATCH_SIZE === count( $ids ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::CHUNK_HOOK, array( (int) end( $ids ), $maxId ), self::GROUP, true );
		}
	}
}
