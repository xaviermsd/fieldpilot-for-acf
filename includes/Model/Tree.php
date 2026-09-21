<?php
/**
 * An indexed, immutable view over one field group.
 *
 * Building the index once turns every later lookup - by key, by name within a
 * parent, by human path - into an array access. The diff engine walks trees, not
 * ACF, which is what makes it testable without WordPress.
 *
 * This class is abstract on purpose. See RawTree / ResolvedTree and
 * docs/ARCHITECTURE-REVIEW.md B.8: mixing the two is a data-safety bug, so the
 * type system is used to make it impossible rather than a comment asking nicely.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

abstract class Tree {

	/** @var array<string,Field> key => field */
	private array $byKey = array();

	/** @var array<string,string|null> key => parent field key (null at group root) */
	private array $parentOf = array();

	/** @var array<string,string|null> key => owning layout key, for FC descendants */
	private array $layoutOf = array();

	/** @var array<string,list<string>> key => label path from the group root */
	private array $labelPath = array();

	/** @var array<string,list<string>> key => name path from the group root */
	private array $namePath = array();

	/** @var array<string,Layout> layout key => layout */
	private array $layouts = array();

	/** @var array<string,string> layout key => owning field key */
	private array $layoutOwner = array();

	public function __construct( public readonly FieldGroup $group ) {
		$this->index( $this->group->fields, null, null, array(), array() );
	}

	/**
	 * @param list<Field>  $fields
	 * @param list<string> $labelTrail
	 * @param list<string> $nameTrail
	 */
	private function index( array $fields, ?string $parentKey, ?string $layoutKey, array $labelTrail, array $nameTrail ): void {
		foreach ( $fields as $field ) {
			if ( null === $field->key || '' === $field->key ) {
				continue;
			}

			$labels = array_merge( $labelTrail, array( $field->label ) );
			$names  = array_merge( $nameTrail, array( $field->name ) );

			$this->byKey[ $field->key ]     = $field;
			$this->parentOf[ $field->key ]  = $parentKey;
			$this->layoutOf[ $field->key ]  = $layoutKey;
			$this->labelPath[ $field->key ] = $labels;
			$this->namePath[ $field->key ]  = $names;

			if ( array() !== $field->children ) {
				$this->index( $field->children, $field->key, null, $labels, $names );
			}

			foreach ( $field->layouts as $layout ) {
				$lk = $layout->key ?? $layout->name;

				$this->layouts[ $lk ]     = $layout;
				$this->layoutOwner[ $lk ] = $field->key;

				$this->index(
					$layout->subFields,
					$field->key,
					$lk,
					array_merge( $labels, array( $layout->label ) ),
					array_merge( $names, array( $layout->name ) )
				);
			}
		}
	}

	// ---- Lookups -------------------------------------------------------------

	public function byKey( string $key ): ?Field {
		return $this->byKey[ $key ] ?? null;
	}

	public function has( string $key ): bool {
		return isset( $this->byKey[ $key ] );
	}

	/** @return list<string> */
	public function allKeys(): array {
		return array_keys( $this->byKey );
	}

	/** @return array<string,Field> */
	public function all(): array {
		return $this->byKey;
	}

	public function parentKeyOf( string $key ): ?string {
		return $this->parentOf[ $key ] ?? null;
	}

	public function layoutKeyOf( string $key ): ?string {
		return $this->layoutOf[ $key ] ?? null;
	}

	/** @return list<string> */
	public function labelPath( string $key ): array {
		return $this->labelPath[ $key ] ?? array();
	}

	/** @return list<string> */
	public function namePath( string $key ): array {
		return $this->namePath[ $key ] ?? array();
	}

	/**
	 * Human-readable location, e.g. "Property › Agent › Email".
	 */
	public function displayPath( string $key ): string {
		return implode( ' › ', array_merge( array( $this->group->title ), $this->labelPath( $key ) ) );
	}

	public function layout( string $layoutKey ): ?Layout {
		return $this->layouts[ $layoutKey ] ?? null;
	}

	public function layoutOwnerKey( string $layoutKey ): ?string {
		return $this->layoutOwner[ $layoutKey ] ?? null;
	}

	/** @return array<string,Layout> */
	public function allLayouts(): array {
		return $this->layouts;
	}

	/**
	 * Direct children of a field, or the group's top-level fields when null.
	 *
	 * @return list<Field>
	 */
	public function childrenOf( ?string $parentKey ): array {
		if ( null === $parentKey ) {
			return $this->group->fields;
		}

		$field = $this->byKey( $parentKey );

		return null === $field ? array() : $field->children;
	}

	/**
	 * Whether $ancestorKey is an ancestor of $key. Used by the delete-ownership
	 * assertion (ARCHITECTURE-REVIEW B.8 rule 3).
	 */
	public function isDescendantOf( string $key, string $ancestorKey ): bool {
		$cursor = $this->parentKeyOf( $key );

		while ( null !== $cursor ) {
			if ( $cursor === $ancestorKey ) {
				return true;
			}
			$cursor = $this->parentKeyOf( $cursor );
		}

		return false;
	}

	/**
	 * True when the path from the group root to $key passes through a clone field.
	 * Such a field is not owned here and must not be patched (B.8 rule 2).
	 */
	public function traversesClone( string $key ): bool {
		$cursor = $this->parentKeyOf( $key );

		while ( null !== $cursor ) {
			$field = $this->byKey( $cursor );
			if ( null !== $field && $field->isReference() ) {
				return true;
			}
			$cursor = $this->parentKeyOf( $cursor );
		}

		return false;
	}

	/**
	 * Every field, depth-first, parents before children.
	 *
	 * @return list<Field>
	 */
	public function flatten(): array {
		return array_values( $this->byKey );
	}

	public function count(): int {
		return count( $this->byKey );
	}

	/**
	 * Hash of the entire group configuration. The optimistic-concurrency token:
	 * `plan` records it, `apply` recomputes it, a mismatch is 409 STATE_CHANGED.
	 */
	public function stateHash(): string {
		$material = array( 'group' => $this->group->fingerprint(), 'fields' => array() );

		foreach ( $this->byKey as $key => $field ) {
			$material['fields'][ $key ] = array(
				'fingerprint' => $field->fingerprint(),
				'parent'      => $this->parentOf[ $key ],
				'layout'      => $this->layoutOf[ $key ],
				'order'       => $field->menuOrder,
			);
		}

		ksort( $material['fields'] );

		return hash( 'sha256', (string) wp_json_encode( $material ) );
	}
}
