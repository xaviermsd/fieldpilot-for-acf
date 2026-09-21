<?php
/**
 * Turns a human target into exactly one place in a tree.
 *
 * IDENTITY PRIORITY, applied in order and never mixed:
 *
 *   1. Exact ACF field key         - unambiguous by construction
 *   2. Field name within the scope - what ACF itself uses to address a field
 *   3. Exact structural path       - labels or names, walked segment by segment
 *   4. Label, only when unique     - convenient, but the easiest to get wrong
 *   5. Otherwise: raise.
 *
 * The rule that matters is the last one. Every ambiguity is an error with the
 * candidates listed. Silently picking the first match is how a tool writes to the
 * wrong field, and the whole product is a bet that developers will trust something
 * that refuses rather than something that guesses.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Resolve;

use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\ResolutionException;
use ACFJP\Model\Field;
use ACFJP\Model\Target;
use ACFJP\Model\Tree;

defined( 'ABSPATH' ) || exit;

final class TargetResolver {

	/**
	 * Resolve the container a target points at.
	 *
	 * @throws ResolutionException
	 */
	public function resolveLocus( Tree $tree, Target $target ): Locus {
		if ( $target->isGroupRoot() ) {
			return new Locus( null, null, $tree->group->title );
		}

		$parent    = null;
		$layoutKey = null;

		foreach ( $target->path as $segment ) {
			$parent = $this->stepInto( $tree, $parent, $layoutKey, $segment, $target );

			// Stepping into a flexible-content field resets the layout scope; the
			// next segment may name a layout.
			$layoutKey = null;

			if ( ! $parent->isContainer() ) {
				throw new ResolutionException(
					ErrorCodes::NOT_A_CONTAINER,
					sprintf(
						/* translators: 1: field label, 2: field type */
						__( '"%1$s" is a %2$s field, so nothing can be nested inside it.', 'fieldpilot-for-acf' ),
						$parent->label,
						$parent->type
					),
					array( 'field_key' => $parent->key, 'type' => $parent->type )
				);
			}
		}

		if ( null !== $target->layout ) {
			if ( null === $parent || array() === $parent->layouts ) {
				throw new ResolutionException(
					ErrorCodes::LAYOUT_NOT_FOUND,
					sprintf(
						/* translators: %s: layout reference */
						__( 'Layout "%s" was named, but the target is not a flexible content field.', 'fieldpilot-for-acf' ),
						$target->layout
					),
					array( 'layout' => $target->layout )
				);
			}

			$layoutKey = $this->resolveLayoutKey( $parent, $target->layout );
		}

		$display = null === $parent
			? $tree->group->title
			: $tree->displayPath( (string) $parent->key );

		return new Locus( $parent, $layoutKey, $display );
	}

	/**
	 * Resolve a single field reference, optionally scoped to one container.
	 *
	 * @param string|null $withinParentKey Restrict to direct children of this field;
	 *                                     null searches the whole tree.
	 * @throws ResolutionException
	 */
	public function resolveField( Tree $tree, string $reference, ?string $withinParentKey = null, ?string $withinLayoutKey = null ): Field {
		$reference = trim( $reference );

		if ( '' === $reference ) {
			throw new ResolutionException(
				ErrorCodes::FIELD_NOT_FOUND,
				__( 'No field was named.', 'fieldpilot-for-acf' )
			);
		}

		$candidates = $this->candidatesIn( $tree, $withinParentKey, $withinLayoutKey );

		// 1. Exact key - accept it even outside the scope, since a key is absolute.
		if ( str_starts_with( $reference, 'field_' ) ) {
			$field = $tree->byKey( $reference );

			if ( null !== $field ) {
				$this->assertNotCloned( $tree, $field );

				return $field;
			}

			throw new ResolutionException(
				ErrorCodes::FIELD_NOT_FOUND,
				sprintf(
					/* translators: %s: field key */
					__( 'No field has the key "%s".', 'fieldpilot-for-acf' ),
					$reference
				),
				array( 'reference' => $reference ),
				$this->describe( $tree, $candidates )
			);
		}

		// 2. Name.
		$byName = array_filter( $candidates, static fn( Field $f ): bool => $f->name === $reference );

		if ( 1 === count( $byName ) ) {
			$field = array_values( $byName )[0];
			$this->assertNotCloned( $tree, $field );

			return $field;
		}

		if ( count( $byName ) > 1 ) {
			throw $this->ambiguous( $tree, $reference, array_values( $byName ) );
		}

		// 3. Dotted or arrowed path, e.g. "contact.email".
		if ( 1 === preg_match( '/[>›.\/]/u', $reference ) ) {
			$segments = preg_split( '/\s*[>›.\/]\s*/u', $reference, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

			$cursor     = $withinParentKey;
			$cursorLayout = $withinLayoutKey;
			$field      = null;

			foreach ( $segments as $segment ) {
				$field        = $this->resolveField( $tree, $segment, $cursor, $cursorLayout );
				$cursor       = $field->key;
				$cursorLayout = null;
			}

			if ( null !== $field ) {
				return $field;
			}
		}

		// 4. Label, only when unique.
		$byLabel = array_filter(
			$candidates,
			static fn( Field $f ): bool => 0 === strcasecmp( $f->label, $reference )
		);

		if ( 1 === count( $byLabel ) ) {
			$field = array_values( $byLabel )[0];
			$this->assertNotCloned( $tree, $field );

			return $field;
		}

		if ( count( $byLabel ) > 1 ) {
			throw $this->ambiguous( $tree, $reference, array_values( $byLabel ) );
		}

		throw new ResolutionException(
			ErrorCodes::FIELD_NOT_FOUND,
			sprintf(
				/* translators: 1: field reference, 2: field group title */
				__( 'Field "%1$s" was not found in %2$s.', 'fieldpilot-for-acf' ),
				$reference,
				$tree->group->title
			),
			array( 'reference' => $reference, 'group_key' => $tree->group->key ),
			$this->describe( $tree, $candidates )
		);
	}

	/**
	 * Resolve a field only if it exists; null rather than an exception.
	 */
	public function findField( Tree $tree, string $reference, ?string $withinParentKey = null, ?string $withinLayoutKey = null ): ?Field {
		try {
			return $this->resolveField( $tree, $reference, $withinParentKey, $withinLayoutKey );
		} catch ( ResolutionException ) {
			return null;
		}
	}

	// ---- Internals -----------------------------------------------------------

	/**
	 * @throws ResolutionException
	 */
	private function stepInto( Tree $tree, ?Field $parent, ?string $layoutKey, string $segment, Target $target ): Field {
		// A segment may name a layout rather than a field.
		if ( null !== $parent && array() !== $parent->layouts ) {
			foreach ( $parent->layouts as $layout ) {
				if ( 0 === strcasecmp( $layout->name, $segment ) || 0 === strcasecmp( $layout->label, $segment ) || $layout->key === $segment ) {
					// Layouts are not fields; descend by re-scoping instead.
					$children = $layout->subFields;

					if ( array() === $children ) {
						throw new ResolutionException(
							ErrorCodes::PATH_NOT_FOUND,
							sprintf(
								/* translators: %s: layout name */
								__( 'Layout "%s" has no fields to descend into.', 'fieldpilot-for-acf' ),
								$segment
							),
							array( 'layout' => $segment )
						);
					}

					// Represent the layout as its owning field with the layout scope
					// carried in the Locus; callers use resolveLocus for this.
					return $parent;
				}
			}
		}

		try {
			return $this->resolveField( $tree, $segment, $parent?->key, $layoutKey );
		} catch ( ResolutionException $e ) {
			throw new ResolutionException(
				ErrorCodes::PATH_NOT_FOUND,
				sprintf(
					/* translators: 1: path segment, 2: full target description */
					__( 'Could not find "%1$s" while resolving %2$s.', 'fieldpilot-for-acf' ),
					$segment,
					$target->describe()
				),
				$e->context(),
				$e->suggestions(),
				null,
				$e
			);
		}
	}

	/**
	 * @throws ResolutionException
	 */
	private function resolveLayoutKey( Field $parent, string $reference ): string {
		$matches = array();

		foreach ( $parent->layouts as $layout ) {
			if ( $layout->key === $reference || 0 === strcasecmp( $layout->name, $reference ) || 0 === strcasecmp( $layout->label, $reference ) ) {
				$matches[] = $layout;
			}
		}

		if ( 1 === count( $matches ) ) {
			return (string) ( $matches[0]->key ?? $matches[0]->name );
		}

		if ( count( $matches ) > 1 ) {
			throw new ResolutionException(
				ErrorCodes::AMBIGUOUS_TARGET,
				sprintf(
					/* translators: %s: layout reference */
					__( 'More than one layout matches "%s".', 'fieldpilot-for-acf' ),
					$reference
				),
				array( 'layout' => $reference )
			);
		}

		throw new ResolutionException(
			ErrorCodes::LAYOUT_NOT_FOUND,
			sprintf(
				/* translators: 1: layout reference, 2: field label */
				__( 'Layout "%1$s" was not found in "%2$s".', 'fieldpilot-for-acf' ),
				$reference,
				$parent->label
			),
			array( 'layout' => $reference ),
			array_map(
				static fn( $l ): string => $l->label !== '' ? $l->label : $l->name,
				$parent->layouts
			)
		);
	}

	/**
	 * The fields a reference may match, given the scope.
	 *
	 * @return list<Field>
	 */
	private function candidatesIn( Tree $tree, ?string $parentKey, ?string $layoutKey ): array {
		if ( null === $parentKey ) {
			return $tree->group->fields;
		}

		$parent = $tree->byKey( $parentKey );

		if ( null === $parent ) {
			return array();
		}

		if ( null !== $layoutKey ) {
			$layout = $tree->layout( $layoutKey );

			return null === $layout ? array() : $layout->subFields;
		}

		if ( array() !== $parent->layouts ) {
			// No layout named: a reference may match in any layout, and matching in
			// two is genuinely ambiguous, which is what we want to surface.
			$all = array();

			foreach ( $parent->layouts as $layout ) {
				$all = array_merge( $all, $layout->subFields );
			}

			return $all;
		}

		return $parent->children;
	}

	/**
	 * A field reached through a clone belongs to another field group. Patching it
	 * here would write to configuration the user did not name.
	 * See docs/ARCHITECTURE-REVIEW.md B.8.
	 *
	 * @throws ResolutionException
	 */
	private function assertNotCloned( Tree $tree, Field $field ): void {
		if ( null === $field->key || ! $tree->traversesClone( $field->key ) ) {
			return;
		}

		throw new ResolutionException(
			ErrorCodes::TRAVERSES_CLONE,
			sprintf(
				/* translators: %s: field label */
				__( '"%s" is displayed here through a Clone field but is defined elsewhere. Edit it in the field group that owns it.', 'fieldpilot-for-acf' ),
				$field->label
			),
			array( 'field_key' => $field->key ),
			array( __( 'Target the field group that defines this field directly.', 'fieldpilot-for-acf' ) )
		);
	}

	/**
	 * @param list<Field> $matches
	 */
	private function ambiguous( Tree $tree, string $reference, array $matches ): ResolutionException {
		return new ResolutionException(
			ErrorCodes::AMBIGUOUS_TARGET,
			sprintf(
				/* translators: 1: number of matches, 2: the reference */
				__( '%1$d fields match "%2$s". Target one by key or by full path.', 'fieldpilot-for-acf' ),
				count( $matches ),
				$reference
			),
			array( 'reference' => $reference, 'matches' => array_map( static fn( Field $f ): ?string => $f->key, $matches ) ),
			array_map(
				static fn( Field $f ): string => sprintf( '%s (%s)', $tree->displayPath( (string) $f->key ), (string) $f->key ),
				$matches
			)
		);
	}

	/**
	 * @param list<Field> $fields
	 * @return list<string>
	 */
	private function describe( Tree $tree, array $fields ): array {
		return array_values(
			array_map(
				static fn( Field $f ): string => '' !== $f->name ? $f->name : $f->label,
				array_slice( $fields, 0, 12 )
			)
		);
	}
}
