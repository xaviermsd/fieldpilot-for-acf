<?php
/**
 * Content-addressed storage for field-group snapshots.
 *
 * A snapshot is a native ACF export of one field group, gzipped and keyed by the
 * sha256 of its canonical JSON. Two consequences make this worth the extra table
 * (docs/ARCHITECTURE-REVIEW.md B.6):
 *
 *  - Identical states are stored once. In an editing session, batch N's "after"
 *    state IS batch N+1's "before" state, so twenty edits cost twenty-one rows
 *    rather than forty blobs of near-identical JSON.
 *  - The journal row stays small, so the History screen lists a thousand entries
 *    without ever touching a LONGTEXT column.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Journal;

use ACFJP\Exceptions\ApplyException;
use ACFJP\Exceptions\ErrorCodes;

defined( 'ABSPATH' ) || exit;

final class SnapshotStore {

	private string $table;

	public function __construct() {
		global $wpdb;

		$this->table = $wpdb->prefix . 'acfjp_snapshots';
	}

	/**
	 * Store a snapshot and return its hash. Storing the same state twice is free
	 * and simply bumps the reference count.
	 *
	 * @param array<string,mixed> $export Native ACF field-group export.
	 * @throws ApplyException When the snapshot cannot be written.
	 */
	public function put( array $export ): string {
		global $wpdb;

		$canonical = $this->canonicalJson( $export );
		$hash      = hash( 'sha256', $canonical );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT hash FROM {$this->table} WHERE hash = %s", $hash ) );

		if ( null !== $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET refcount = refcount + 1 WHERE hash = %s", $hash ) );

			return $hash;
		}

		$payload = $this->encode( $canonical );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$this->table,
			array(
				'hash'       => $hash,
				'created_at' => current_time( 'mysql', true ),
				'bytes'      => strlen( $canonical ),
				'encoding'   => $payload['encoding'],
				'payload'    => $payload['data'],
				'refcount'   => 1,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			throw new ApplyException(
				ErrorCodes::SNAPSHOT_FAILED,
				__( 'The snapshot could not be saved, so no changes were applied.', 'wp-acf-json-pro' ),
				array( 'hash' => $hash, 'db_error' => $wpdb->last_error )
			);
		}

		return $hash;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( string $hash ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT payload, encoding FROM {$this->table} WHERE hash = %s", $hash ),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		$json = $this->decode( (string) $row['payload'], (string) $row['encoding'] );

		if ( null === $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	public function exists( string $hash ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT hash FROM {$this->table} WHERE hash = %s", $hash ) );
	}

	/**
	 * Drop one reference. The row survives until nothing points at it and the
	 * retention sweep collects it.
	 */
	public function release( string $hash ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET refcount = GREATEST(refcount - 1, 0) WHERE hash = %s",
				$hash
			)
		);
	}

	/**
	 * Delete unreferenced snapshots older than the cutoff.
	 *
	 * @return int Rows removed.
	 */
	public function collectGarbage( int $olderThanDays = 1 ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $olderThanDays * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE refcount = 0 AND created_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * @return array{count:int,bytes:int}
	 */
	public function stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT COUNT(*) AS c, COALESCE(SUM(bytes),0) AS b FROM {$this->table}", ARRAY_A );

		return array(
			'count' => (int) ( $row['c'] ?? 0 ),
			'bytes' => (int) ( $row['b'] ?? 0 ),
		);
	}

	// ---- Encoding ------------------------------------------------------------

	/**
	 * Deterministic JSON: the hash must depend on content, not key order, or
	 * deduplication never fires.
	 *
	 * @param array<string,mixed> $export
	 */
	private function canonicalJson( array $export ): string {
		return (string) wp_json_encode( $this->sortRecursive( $export ) );
	}

	/**
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private function sortRecursive( array $value ): array {
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = $this->sortRecursive( $v );
			}
		}

		return $value;
	}

	/**
	 * @return array{encoding:string,data:string}
	 */
	private function encode( string $json ): array {
		if ( function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $json, 6 );

			if ( false !== $compressed ) {
				return array( 'encoding' => 'gz', 'data' => base64_encode( $compressed ) );
			}
		}

		return array( 'encoding' => 'plain', 'data' => $json );
	}

	private function decode( string $payload, string $encoding ): ?string {
		if ( 'plain' === $encoding ) {
			return $payload;
		}

		$raw = base64_decode( $payload, true );

		if ( false === $raw ) {
			return null;
		}

		$json = function_exists( 'gzdecode' ) ? gzdecode( $raw ) : false;

		return false === $json ? null : $json;
	}
}
