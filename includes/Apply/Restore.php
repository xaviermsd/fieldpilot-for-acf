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
			$e = new ApplyException(
				ErrorCodes::SNAPSHOT_NOT_FOUND,
				__( 'The saved snapshot could not be found, so this cannot be rolled back automatically.', 'fieldpilot-for-acf' ),
				array( 'hash' => $hash )
			);
			throw $e;
		}

		$this->fromArray( $snapshot );
	}

	/**
	 * @param array<string,mixed> $snapshot Native ACF field-group export.
	 * @throws ApplyException
	 */
	public function fromArray( array $snapshot ): void {
		if ( empty( $snapshot['key'] ) ) {
			$e = new ApplyException(
				ErrorCodes::RESTORE_FAILED,
				__( 'The snapshot is missing its field group key and cannot be restored.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		$groupKey = (string) $snapshot['key'];

		// The snapshot holds no post IDs; supplying the current one makes ACF update
		// in place, preserving the group's own ID and any references to it.
		$existing = acf_get_raw_field_group( $groupKey );

		if ( is_array( $existing ) && ! empty( $existing['ID'] ) ) {
			$snapshot['ID'] = (int) $existing['ID'];
		}

		// acf_import_field_group() unslashes what it is given; the snapshot is plain
		// data, so add the layer it removes or every backslash is lost.
		$restored = acf_import_field_group( wp_slash( $snapshot ) );

		if ( ! is_array( $restored ) || empty( $restored['ID'] ) ) {
			$e = new ApplyException(
				ErrorCodes::RESTORE_FAILED,
				sprintf(
					/* translators: %s: field group key */
					__( 'Field group "%s" could not be restored.', 'fieldpilot-for-acf' ),
					$groupKey
				),
				array( 'group_key' => $groupKey )
			);
			throw $e;
		}

		$this->reader->forget( $groupKey );

		if ( function_exists( 'acf_get_store' ) ) {
			acf_get_store( 'fields' )?->reset();
			acf_get_store( 'field-groups' )?->reset();
		}

		$this->assertRestored( $groupKey, $snapshot );

		/**
		 * Fires after a field group has been restored from a snapshot.
		 *
		 * @param string              $groupKey
		 * @param array<string,mixed> $snapshot
		 */
		do_action( 'acfjp_restored', $groupKey, $snapshot );
	}

	/**
	 * After a restore, every field the snapshot holds must exist again and no
	 * other field may remain. Skipped for snapshots containing a Clone field:
	 * snapshots taken before 1.1.0 came from acf_get_fields(), which can splice in
	 * fields that belong to other groups.
	 *
	 * @param array<string,mixed> $snapshot
	 * @throws ApplyException
	 */
	private function assertRestored( string $groupKey, array $snapshot ): void {
		$expected = array();
		$hasClone = false;

		$collect = static function ( array $fields ) use ( &$collect, &$expected, &$hasClone ): void {
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				if ( ! empty( $field['key'] ) ) {
					$expected[] = (string) $field['key'];
				}

				if ( 'clone' === ( $field['type'] ?? '' ) ) {
					$hasClone = true;
				}

				$collect( is_array( $field['sub_fields'] ?? null ) ? $field['sub_fields'] : array() );

				foreach ( is_array( $field['layouts'] ?? null ) ? $field['layouts'] : array() as $layout ) {
					$collect( is_array( $layout['sub_fields'] ?? null ) ? $layout['sub_fields'] : array() );
				}
			}
		};

		$collect( is_array( $snapshot['fields'] ?? null ) ? $snapshot['fields'] : array() );

		if ( $hasClone ) {
			return;
		}

		$actual  = array_map( 'strval', array_keys( $this->reader->readRaw( $groupKey, true )->all() ) );
		$missing = array_diff( $expected, $actual );
		$extra   = array_diff( $actual, $expected );

		if ( array() === $missing && array() === $extra ) {
			return;
		}

		$e = new ApplyException(
			ErrorCodes::RESTORE_FAILED,
			sprintf(
				/* translators: 1: number of missing fields, 2: number of unexpected fields */
				__( 'The field group was restored, but it does not match the snapshot. Missing fields: %1$d. Unexpected fields: %2$d.', 'fieldpilot-for-acf' ),
				count( $missing ),
				count( $extra )
			),
			array(
				'group_key' => $groupKey,
				'missing'   => array_values( $missing ),
				'extra'     => array_values( $extra ),
			)
		);
		throw $e;
	}
}
