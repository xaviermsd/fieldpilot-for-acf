<?php
/**
 * Decides whether a field group can be safely patched.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * ACF resolves fields from local sources BEFORE the database:
 *
 *     // acf-field-functions.php, acf_get_field()
 *     if ( acf_is_local_field( $id ) ) { $field = acf_get_local_field( $id ); }
 *     else                             { $field = acf_get_raw_field( $id ); }
 *
 * A local field carries no 'ID'. And acf_update_field() branches on exactly that:
 *
 *     // acf-field-functions.php, acf_update_field()
 *     if ( $field['ID'] ) { wp_update_post( $save ); }
 *     else                { $field['ID'] = wp_insert_post( $save ); }   // ← inserts a NEW post
 *
 * So writing to a PHP-registered or unsynced-JSON field does not fail. It silently
 * creates an orphaned acf-field post that no field group owns, while the PHP/JSON
 * registration keeps winning at runtime. The developer sees success, gets nothing,
 * and accumulates junk rows that make later syncs unpredictable.
 *
 * Every mutating path in this plugin MUST pass through this classifier first.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Acf;

defined( 'ABSPATH' ) || exit;

final class MutabilityClassifier {

	/** @var array<string,MutabilityReport> Per-request memoisation. */
	private array $cache = array();

	/**
	 * Classify a single field group by key.
	 *
	 * @param string $groupKey e.g. 'group_64f0a1b2c3d4e'.
	 */
	public function classify( string $groupKey ): MutabilityReport {
		if ( isset( $this->cache[ $groupKey ] ) ) {
			return $this->cache[ $groupKey ];
		}

		$dbGroup    = $this->databaseGroup( $groupKey );
		$localGroup = $this->localGroup( $groupKey );

		$report = match ( true ) {
			// Lives in the database. Patchable, regardless of whether a JSON file
			// also shadows it - the DB copy is what acf_update_field() will write.
			null !== $dbGroup  => $this->databaseReport( $groupKey, $dbGroup, $localGroup ),

			// Local only. Which kind decides whether there is a way forward.
			null !== $localGroup => $this->localReport( $groupKey, $localGroup ),

			default => new MutabilityReport(
				groupKey: $groupKey,
				groupTitle: $groupKey,
				mutability: Mutability::Missing,
			),
		};

		$this->cache[ $groupKey ] = $report;

		return $report;
	}

	/**
	 * Classify every field group ACF knows about. Powers the Dashboard, so a
	 * developer learns which parts of their site this tool can touch before they
	 * paste anything.
	 *
	 * @return list<MutabilityReport>
	 */
	public function classifyAll(): array {
		$reports = array();

		foreach ( acf_get_field_groups() as $group ) {
			if ( ! empty( $group['key'] ) ) {
				$reports[] = $this->classify( (string) $group['key'] );
			}
		}

		return $reports;
	}

	/**
	 * Build the report for a group that exists in the database.
	 *
	 * @param array<string,mixed>      $dbGroup    Raw DB field group.
	 * @param array<string,mixed>|null $localGroup Local copy, when one shadows it.
	 */
	private function databaseReport( string $groupKey, array $dbGroup, ?array $localGroup ): MutabilityReport {
		$jsonPath    = null;
		$syncPending = false;

		if ( null !== $localGroup && 'json' === ( $localGroup['local'] ?? null ) ) {
			$jsonPath = $this->jsonPathFor( $groupKey );

			// ACF's own "sync available" test: the JSON file's modified stamp is
			// newer than the database copy's.
			$localModified = (int) ( $localGroup['modified'] ?? 0 );
			$dbModified    = (int) ( $dbGroup['modified'] ?? 0 );
			$syncPending   = $localModified > $dbModified;
		}

		return new MutabilityReport(
			groupKey: $groupKey,
			groupTitle: (string) ( $dbGroup['title'] ?? $groupKey ),
			mutability: Mutability::Database,
			postId: isset( $dbGroup['ID'] ) ? (int) $dbGroup['ID'] : null,
			jsonPath: $jsonPath,
			syncPending: $syncPending,
		);
	}

	/**
	 * Build the report for a group that exists only locally.
	 *
	 * @param array<string,mixed> $localGroup Local field group array.
	 */
	private function localReport( string $groupKey, array $localGroup ): MutabilityReport {
		$isJson = 'json' === ( $localGroup['local'] ?? null );

		return new MutabilityReport(
			groupKey: $groupKey,
			groupTitle: (string) ( $localGroup['title'] ?? $groupKey ),
			mutability: $isJson ? Mutability::LocalJson : Mutability::LocalPhp,
			postId: null,
			jsonPath: $isJson ? $this->jsonPathFor( $groupKey ) : null,
			syncPending: $isJson,
			// LocalJson has a one-click way forward: import the JSON into the DB,
			// then patch. LocalPhp has none, so we offer a PHP snippet instead.
			remedy: $isJson ? 'sync' : 'export_php',
		);
	}

	/**
	 * Raw database field group, or null. Deliberately does not fall back to local.
	 *
	 * @return array<string,mixed>|null
	 */
	private function databaseGroup( string $groupKey ): ?array {
		$group = acf_get_raw_field_group( $groupKey );

		return is_array( $group ) && ! empty( $group['ID'] ) ? $group : null;
	}

	/**
	 * Local (JSON or PHP) field group, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function localGroup( string $groupKey ): ?array {
		if ( ! function_exists( 'acf_is_local_field_group' ) || ! acf_is_local_field_group( $groupKey ) ) {
			return null;
		}

		$group = acf_get_local_field_group( $groupKey );

		return is_array( $group ) ? $group : null;
	}

	/**
	 * Absolute path of the acf-json file backing this group, when one is on disk.
	 */
	private function jsonPathFor( string $groupKey ): ?string {
		if ( ! function_exists( 'acf_get_local_json_files' ) ) {
			return null;
		}

		$files = acf_get_local_json_files( 'acf-field-group' );

		return isset( $files[ $groupKey ] ) ? (string) $files[ $groupKey ] : null;
	}
}
