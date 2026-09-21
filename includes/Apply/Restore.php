<?php
/**
 * Puts a field group back the way it was.
 *
 * This is one of the three places acf_import_field_group() may be called
 * (ARCHITECTURE-REVIEW B.5), because whole-group replacement is exactly the
 * semantic wanted here: the snapshot IS the desired end state, and fields created
 * since the snapshot SHOULD be removed.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

use ACFJP\Acf\TreeReader;
use ACFJP\Exceptions\ApplyException;
use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Journal\SnapshotStore;

defined( 'ABSPATH' ) || exit;

final class Restore {

	public function __construct(
		private readonly SnapshotStore $snapshots,
		private readonly TreeReader $reader,
	) {}

	/**
	 * @throws ApplyException
	 */
	public function fromHash( string $hash ): void {
		$snapshot = $this->snapshots->get( $hash );

		if ( null === $snapshot ) {
			throw new ApplyException(
				ErrorCodes::SNAPSHOT_NOT_FOUND,
				__( 'The saved snapshot could not be found, so this cannot be rolled back automatically.', 'wp-acf-json-pro' ),
				array( 'hash' => $hash )
			);
		}

		$this->fromArray( $snapshot );
	}

	/**
	 * @param array<string,mixed> $snapshot Native ACF field-group export.
	 * @throws ApplyException
	 */
	public function fromArray( array $snapshot ): void {
		if ( empty( $snapshot['key'] ) ) {
			throw new ApplyException(
				ErrorCodes::RESTORE_FAILED,
				__( 'The snapshot is missing its field group key and cannot be restored.', 'wp-acf-json-pro' )
			);
		}

		$groupKey = (string) $snapshot['key'];

		// The snapshot holds no post IDs; supplying the current one makes ACF update
		// in place, preserving the group's own ID and any references to it.
		$existing = acf_get_raw_field_group( $groupKey );

		if ( is_array( $existing ) && ! empty( $existing['ID'] ) ) {
			$snapshot['ID'] = (int) $existing['ID'];
		}

		$restored = acf_import_field_group( $snapshot );

		if ( ! is_array( $restored ) || empty( $restored['ID'] ) ) {
			throw new ApplyException(
				ErrorCodes::RESTORE_FAILED,
				sprintf(
					/* translators: %s: field group key */
					__( 'Field group "%s" could not be restored.', 'wp-acf-json-pro' ),
					$groupKey
				),
				array( 'group_key' => $groupKey )
			);
		}

		$this->reader->forget( $groupKey );

		if ( function_exists( 'acf_get_store' ) ) {
			acf_get_store( 'fields' )?->reset();
			acf_get_store( 'field-groups' )?->reset();
		}

		/**
		 * Fires after a field group has been restored from a snapshot.
		 *
		 * @param string              $groupKey
		 * @param array<string,mixed> $snapshot
		 */
		do_action( 'acfjp/restored', $groupKey, $snapshot );
	}
}
