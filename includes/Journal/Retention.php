<?php
/**
 * Keeps the journal and snapshot store from growing without bound.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Journal;

defined( 'ABSPATH' ) || exit;

final class Retention {

	public const HOOK = 'acfjp_retention_sweep';

	private const DEFAULT_KEEP_PER_GROUP = 50;
	private const DEFAULT_KEEP_DAYS      = 90;

	public function __construct( private readonly Journal $journal ) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'sweep' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public function sweep(): void {
		$this->journal->prune( $this->keepPerGroup(), $this->keepDays() );
	}

	private function keepPerGroup(): int {
		$settings = (array) get_option( 'acfjp_settings', array() );

		/**
		 * How many journal entries to keep per field group.
		 *
		 * @param int $count Default 50.
		 */
		return (int) apply_filters(
			'acfjp_retention_keep_per_group',
			max( 1, (int) ( $settings['keep_per_group'] ?? self::DEFAULT_KEEP_PER_GROUP ) )
		);
	}

	private function keepDays(): int {
		$settings = (array) get_option( 'acfjp_settings', array() );

		/**
		 * How long journal entries are kept, in days.
		 *
		 * @param int $days Default 90.
		 */
		return (int) apply_filters(
			'acfjp_retention_keep_days',
			max( 1, (int) ( $settings['keep_days'] ?? self::DEFAULT_KEEP_DAYS ) )
		);
	}
}
