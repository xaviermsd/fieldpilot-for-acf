<?php
/**
 * Materialises an ACF field group as one of our trees.
 *
 * TWO READS, DELIBERATELY DIFFERENT. See docs/ARCHITECTURE-REVIEW.md B.3 and B.8.
 *
 *   readRaw()      - stored data, ACF filters disabled. The only safe basis for
 *                    diffing and writing.
 *   readResolved() - what ACF renders to an editor. Display only.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Acf;

use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\ResolutionException;
use ACFJP\Model\Field;
use ACFJP\Model\FieldGroup;
use ACFJP\Model\FieldKind;
use ACFJP\Model\RawTree;
use ACFJP\Model\ResolvedTree;

defined( 'ABSPATH' ) || exit;

final class TreeReader {

	/**
	 * Hard recursion bound. Real ACF trees are 3-5 deep; this exists so that a
	 * malformed parent chain (or a hostile payload upstream) cannot exhaust the
	 * stack. Filterable for the one site in a million that needs more.
	 */
	private const MAX_DEPTH = 32;

	/** @var array<string,RawTree> */
	private array $rawCache = array();

	/**
	 * Stored configuration, with every ACF read filter disabled.
	 *
	 * @throws ResolutionException When the group does not exist in the database.
	 */
	public function readRaw( string $groupKey, bool $fresh = false ): RawTree {
		if ( ! $fresh && isset( $this->rawCache[ $groupKey ] ) ) {
			return $this->rawCache[ $groupKey ];
		}

		$previousFilters = acf_disable_filters();

		try {
			$rawGroup = acf_get_raw_field_group( $groupKey );

			if ( ! is_array( $rawGroup ) || empty( $rawGroup['ID'] ) ) {
				$e = new ResolutionException(
					ErrorCodes::GROUP_NOT_FOUND,
					sprintf(
						/* translators: %s: field group key */
						__( 'Field group "%s" was not found in the database.', 'fieldpilot-for-acf' ),
						$groupKey
					),
					array( 'group_key' => $groupKey ),
					$this->suggestGroupKeys( $groupKey )
				);
				throw $e;
			}

			$rawGroup['fields'] = $this->readChildren( (int) $rawGroup['ID'], 1 );

			$tree = new RawTree( FieldGroup::fromAcfArray( $rawGroup ) );
		} finally {
			acf_enable_filters( $previousFilters );
		}

		$this->rawCache[ $groupKey ] = $tree;

		return $tree;
	}

	/**
	 * Filtered configuration - what ACF actually shows an editor. Display only.
	 *
	 * @throws ResolutionException When the group is unknown to ACF entirely.
	 */
	public function readResolved( string $groupKey ): ResolvedTree {
		$group = acf_get_field_group( $groupKey );

		if ( ! is_array( $group ) ) {
			$e = new ResolutionException(
				ErrorCodes::GROUP_NOT_FOUND,
				sprintf(
					/* translators: %s: field group key */
					__( 'Field group "%s" was not found.', 'fieldpilot-for-acf' ),
					$groupKey
				),
				array( 'group_key' => $groupKey ),
				$this->suggestGroupKeys( $groupKey )
			);
			throw $e;
		}

		$group['fields'] = acf_get_fields( $group );

		return new ResolvedTree( FieldGroup::fromAcfArray( $group ) );
	}

	/**
	 * Native ACF export of a group, used for snapshots and for the Export screen.
	 * This intentionally uses ACF's own exporter so our snapshots are byte-compatible
	 * with ACF's importer - which is what makes rollback reliable.
	 *
	 * @return array<string,mixed>
	 * @throws ResolutionException When the group does not exist.
	 */
	public function exportArray( string $groupKey ): array {
		$group = acf_get_field_group( $groupKey );

		if ( ! is_array( $group ) ) {
			$e = new ResolutionException(
				ErrorCodes::GROUP_NOT_FOUND,
				sprintf(
					/* translators: %s: field group key */
					__( 'Field group "%s" was not found.', 'fieldpilot-for-acf' ),
					$groupKey
				),
				array( 'group_key' => $groupKey )
			);
			throw $e;
		}

		$fields = acf_get_fields( $group );
		$group['fields'] = $fields;

		return acf_prepare_field_group_for_export( $group );
	}

	/**
	 * Drop memoised trees. Call after any write.
	 */
	public function forget( ?string $groupKey = null ): void {
		if ( null === $groupKey ) {
			$this->rawCache = array();
			return;
		}

		unset( $this->rawCache[ $groupKey ] );
	}

	// ---- Internals -----------------------------------------------------------

	/**
	 * Recursively assemble stored children into export-shaped nested arrays.
	 *
	 * ACF stores every field as its own acf-field post joined by post_parent, with
	 * no nesting in the payload. We rebuild the nesting here, in memory only. The
	 * result is never written back to a parent field - see B.3.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function readChildren( int $parentId, int $depth ): array {
		if ( $depth > $this->maxDepth() ) {
			return array();
		}

		$out = array();

		foreach ( acf_get_raw_fields( $parentId ) as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['ID'] ) ) {
				continue;
			}

			// acf_get_raw_fields() queries post_status IN ('publish','trash') so that
			// untrashing works. Trashed fields are not part of the live configuration
			// and must not appear in a diff.
			if ( 'trash' === get_post_status( (int) $raw['ID'] ) ) {
				continue;
			}

			$type = (string) ( $raw['type'] ?? 'text' );

			if ( FieldKind::hasSubFields( $type ) ) {
				$raw['sub_fields'] = $this->readChildren( (int) $raw['ID'], $depth + 1 );
			} elseif ( FieldKind::hasLayouts( $type ) ) {
				$raw['layouts'] = $this->assembleLayouts(
					is_array( $raw['layouts'] ?? null ) ? $raw['layouts'] : array(),
					$this->readChildren( (int) $raw['ID'], $depth + 1 )
				);
			}

			$out[] = $raw;
		}

		return $out;
	}

	/**
	 * Distribute a flexible-content field's stored children into its layouts.
	 *
	 * Verified against ACF PRO 6.8.10: sub-fields carry `parent_layout` holding the
	 * layout's KEY (not its name), and `menu_order` is the index within that layout.
	 *
	 * ACF's own load_field() silently reassigns a sub-field with an empty
	 * `parent_layout` to the FIRST layout. We reproduce that so our raw view matches
	 * what ACF will actually render, but we record it so the Verifier can flag it.
	 *
	 * @param array<string,mixed>       $storedLayouts Layout definitions from post_content.
	 * @param list<array<string,mixed>> $children      Flat sub-field arrays.
	 * @return array<string,mixed>
	 */
	private function assembleLayouts( array $storedLayouts, array $children ): array {
		$layouts     = array();
		$firstKey    = null;
		$byLayoutKey = array();

		foreach ( $storedLayouts as $index => $layout ) {
			if ( ! is_array( $layout ) ) {
				continue;
			}

			$key = (string) ( $layout['key'] ?? ( is_string( $index ) ? $index : '' ) );

			if ( '' === $key ) {
				continue;
			}

			$layout['key']        = $key;
			$layout['sub_fields'] = array();

			$layouts[ $key ]     = $layout;
			$byLayoutKey[ $key ] = true;
			$firstKey          ??= $key;
		}

		foreach ( $children as $child ) {
			$layoutKey = (string) ( $child['parent_layout'] ?? '' );

			if ( '' === $layoutKey || ! isset( $byLayoutKey[ $layoutKey ] ) ) {
				// Matches ACF's own fallback. Marked so the Verifier can warn.
				$layoutKey                = (string) $firstKey;
				$child['_acfjp_orphaned'] = true;
			}

			if ( isset( $layouts[ $layoutKey ] ) ) {
				$layouts[ $layoutKey ]['sub_fields'][] = $child;
			}
		}

		return $layouts;
	}

	private function maxDepth(): int {
		/**
		 * Maximum nesting depth the reader will descend.
		 *
		 * @param int $depth Default 32.
		 */
		return (int) apply_filters( 'acfjp/max_depth', self::MAX_DEPTH );
	}

	/**
	 * Offer the closest existing group keys when one is not found. Structured
	 * errors with suggestions are what let an AI correct itself on the next pass.
	 *
	 * @return list<string>
	 */
	private function suggestGroupKeys( string $needle ): array {
		$candidates = array();

		foreach ( acf_get_field_groups() as $group ) {
			if ( ! empty( $group['key'] ) ) {
				$candidates[ (string) $group['key'] ] = (string) ( $group['title'] ?? $group['key'] );
			}
		}

		$scored = array();
		$n      = substr( strtolower( $needle ), 0, 255 );

		foreach ( $candidates as $key => $title ) {
			$k = substr( strtolower( $key ), 0, 255 );
			$t = substr( strtolower( $title ), 0, 255 );

			$distance = min(
				levenshtein( $n, $k ),
				levenshtein( $n, $t )
			);

			$scored[] = array( 'label' => sprintf( '%s (%s)', $title, $key ), 'score' => $distance );
		}

		usort( $scored, static fn( array $a, array $b ): int => $a['score'] <=> $b['score'] );

		return array_column( array_slice( $scored, 0, 5 ), 'label' );
	}
}
