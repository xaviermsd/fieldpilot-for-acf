<?php
/**
 * Onboarding tour manager.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin;

defined( 'ABSPATH' ) || exit;

final class Tour {

	public const META_TOUR_DONE = 'fieldpilot_tour_done';

	/**
	 * Tour only loads when ACF is fully active.
	 */
	public function isAcfActive(): bool {
		return function_exists( 'acf_update_field_group' ) && function_exists( 'acf_get_field_groups' );
	}

	public function isTourDone( int $userId = 0 ): bool {
		$uid = $userId > 0 ? $userId : get_current_user_id();
		if ( $uid <= 0 ) {
			return true;
		}

		return (bool) get_user_meta( $uid, self::META_TOUR_DONE, true );
	}

	public function dismissTour( int $userId = 0 ): bool {
		$uid = $userId > 0 ? $userId : get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}

		return (bool) update_user_meta( $uid, self::META_TOUR_DONE, 1 );
	}

	public function resetTour( int $userId = 0 ): bool {
		$uid = $userId > 0 ? $userId : get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}

		return (bool) delete_user_meta( $uid, self::META_TOUR_DONE );
	}
}
