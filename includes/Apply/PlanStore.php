<?php
/**
 * Short-lived storage for previewed plans.
 *
 * Transients, not a table: a plan is worthless once the tree moves, so it has a
 * natural TTL and nothing is lost if object caching evicts it early - the caller
 * simply previews again.
 *
 * Plans are bound to the user who created them. A plan id is not a capability, but
 * it should not be usable by a second account either.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

use ACFJP\Exceptions\ApplyException;
use ACFJP\Exceptions\ErrorCodes;

defined( 'ABSPATH' ) || exit;

final class PlanStore {

	private const PREFIX = 'acfjp_plan_';
	private const TTL    = 900; // 15 minutes.

	public function put( Plan $plan ): void {
		set_transient(
			self::PREFIX . $plan->id,
			array(
				'user_id'   => get_current_user_id(),
				'created'   => time(),
				'plan'      => wp_json_encode( $plan->jsonSerialize() ),
				'changeset' => $plan->changeSet,
				'report'    => $plan->report,
				'state'     => $plan->stateHash,
				'meta'      => $plan->meta,
			),
			$this->ttl()
		);
	}

	/**
	 * @throws ApplyException
	 */
	public function get( string $planId ): Plan {
		$stored = get_transient( self::PREFIX . $planId );

		if ( ! is_array( $stored ) ) {
			throw new ApplyException(
				ErrorCodes::PLAN_EXPIRED,
				__( 'This preview has expired. Generate it again to see the current changes.', 'wp-acf-json-pro' ),
				array( 'plan_id' => $planId )
			);
		}

		if ( (int) ( $stored['user_id'] ?? 0 ) !== get_current_user_id() ) {
			throw new ApplyException(
				ErrorCodes::PLAN_NOT_FOUND,
				__( 'That preview belongs to a different user.', 'wp-acf-json-pro' ),
				array( 'plan_id' => $planId )
			);
		}

		return new Plan(
			id: $planId,
			changeSet: $stored['changeset'],
			report: $stored['report'],
			stateHash: (string) ( $stored['state'] ?? '' ),
			meta: is_array( $stored['meta'] ?? null ) ? $stored['meta'] : array(),
		);
	}

	public function forget( string $planId ): void {
		delete_transient( self::PREFIX . $planId );
	}

	public static function newId(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private function ttl(): int {
		/**
		 * How long a preview stays applicable, in seconds.
		 *
		 * @param int $seconds Default 900.
		 */
		return (int) apply_filters( 'acfjp/plan_ttl', self::TTL );
	}
}
