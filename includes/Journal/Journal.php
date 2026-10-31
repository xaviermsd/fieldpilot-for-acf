<?php
/**
 * The record of everything this plugin has changed.
 *
 * The journal IS the audit log. A separate audit table would duplicate the same
 * rows with less detail; instead, every mutation writes one row here, and
 * non-mutating security events fire the `acfjp/audit` action so a site with an
 * existing audit plugin gets integration for free and a site without one pays for
 * no table it will never read.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Journal;

use ACFJP\Diff\ChangeSet;
use ACFJP\Exceptions\ApplyException;
use ACFJP\Exceptions\ErrorCodes;

defined( 'ABSPATH' ) || exit;

final class Journal {

	private string $table;

	public function __construct( private readonly SnapshotStore $snapshots ) {
		global $wpdb;

		$this->table = $wpdb->prefix . 'acfjp_journal';
	}

	/**
	 * Record a batch.
	 *
	 * @throws ApplyException When the row cannot be written. An apply that cannot
	 *                        be journaled cannot be rolled back, so it must not stand.
	 */
	public function record(
		string $batchId,
		ChangeSet $changeSet,
		string $beforeHash,
		?string $afterHash,
		string $source,
		string $status = Entry::STATUS_APPLIED,
		?string $message = null
	): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$this->table,
			array(
				'batch_id'     => $batchId,
				'created_at'   => current_time( 'mysql', true ),
				'user_id'      => get_current_user_id(),
				'source'       => $source,
				'operation'    => $changeSet->operation,
				'group_key'    => $changeSet->groupKey,
				'group_title'  => $changeSet->groupTitle,
				'before_hash'  => $beforeHash,
				'after_hash'   => $afterHash,
				'changeset'    => (string) wp_json_encode( $changeSet->jsonSerialize() ),
				'change_count' => $changeSet->count(),
				'status'       => $status,
				'message'      => $message,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		if ( false === $inserted || 0 === $id ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				__( 'The change could not be recorded in History, so it was not kept.', 'fieldpilot-for-acf' ),
				array( 'db_error' => $wpdb->last_error )
			);
			throw $e;
		}

		/**
		 * Fires after a batch has been journaled.
		 *
		 * @param int       $id        Journal row id.
		 * @param ChangeSet $changeSet What was applied.
		 * @param string    $status    applied|failed|rolled_back
		 */
		do_action( 'acfjp_journal_recorded', $id, $changeSet, $status );

		return $id;
	}

	public function get( int $id ): ?Entry {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return null === $row ? null : Entry::fromRow( $row );
	}

	/**
	 * @param array{group_key?:string|null,status?:string|null,limit?:int,offset?:int} $args
	 * @return list<Entry>
	 */
	public function find( array $args = array() ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, (int) ( $args['limit'] ?? 25 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		if ( ! empty( $args['group_key'] ) && ! empty( $args['status'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE group_key = %s AND status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$args['group_key'],
					$args['status'],
					$limit,
					$offset
				),
				ARRAY_A
			);
		} elseif ( ! empty( $args['group_key'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE group_key = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$args['group_key'],
					$limit,
					$offset
				),
				ARRAY_A
			);
		} elseif ( ! empty( $args['status'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$args['status'],
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		return array_values(
			array_map(
				static fn( array $row ): Entry => Entry::fromRow( $row ),
				is_array( $rows ) ? $rows : array()
			)
		);
	}

	public function countAll( ?string $groupKey = null ): int {
		global $wpdb;

		if ( null === $groupKey ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE group_key = %s", $groupKey )
		);
	}

	public function markRolledBack( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$this->table,
			array(
				'status'         => Entry::STATUS_ROLLED_BACK,
				'rolled_back_at' => current_time( 'mysql', true ),
				'rolled_back_by' => get_current_user_id(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Changes to a group made after a given row that are still in effect: applied
	 * changes, and failed batches whose automatic undo did not finish. Newest
	 * first. Rollback rows are a record only, so they never count.
	 *
	 * @return list<int>
	 */
	public function laterChanges( string $groupKey, int $afterId ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$this->table} WHERE group_key = %s AND id > %d AND status IN ( %s, %s ) ORDER BY id DESC",
				$groupKey,
				$afterId,
				Entry::STATUS_APPLIED,
				Entry::STATUS_INCOMPLETE
			)
		);

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	public function latestFor( string $groupKey ): ?Entry {
		$entries = $this->find(
			array(
				'group_key' => $groupKey,
				'limit'     => 1,
			)
		);

		return $entries[0] ?? null;
	}

	/**
	 * Trim history beyond the retention policy and release the snapshots it held.
	 *
	 * @return int Rows removed.
	 */
	public function prune( int $keepPerGroup, int $keepDays ): int {
		global $wpdb;

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $keepDays * DAY_IN_SECONDS ) );
		$removed = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groupKeys = $wpdb->get_col( "SELECT DISTINCT group_key FROM {$this->table}" );

		foreach ( (array) $groupKeys as $groupKey ) {
			// Keep the newest $keepPerGroup rows whatever their age, and every row
			// newer than the cutoff; remove only rows that are both older and beyond
			// that count.
			$threshold = 0;

			if ( $keepPerGroup > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$threshold = (int) $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT id FROM {$this->table} WHERE group_key = %s ORDER BY id DESC LIMIT 1 OFFSET %d",
						$groupKey,
						$keepPerGroup - 1
					)
				);

				if ( 0 === $threshold ) {
					// Fewer rows than the limit: nothing to trim.
					continue;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$stale = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, before_hash, after_hash FROM {$this->table} WHERE group_key = %s AND created_at < %s AND ( %d = 0 OR id < %d )",
					$groupKey,
					$cutoff,
					$threshold,
					$threshold
				),
				ARRAY_A
			);

			foreach ( (array) $stale as $row ) {
				foreach ( array( $row['before_hash'], $row['after_hash'] ) as $hash ) {
					if ( ! empty( $hash ) ) {
						$this->snapshots->release( (string) $hash );
					}
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $this->table, array( 'id' => (int) $row['id'] ), array( '%d' ) );

				++$removed;
			}
		}

		$this->snapshots->collectGarbage();

		return $removed;
	}

	/**
	 * Record a non-mutating security or validation event. Deliberately fires an
	 * action rather than writing a row.
	 *
	 * @param array<string,mixed> $context
	 */
	public static function audit( string $event, array $context = array() ): void {
		/**
		 * Fires on a security-relevant event that made no changes.
		 *
		 * @param string              $event   Event slug.
		 * @param array<string,mixed> $context Detail.
		 * @param int                 $userId  Acting user.
		 */
		do_action( 'acfjp_audit', $event, $context, get_current_user_id() );
	}
}
