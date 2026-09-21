<?php
/**
 * Pairs incoming field definitions with the fields that already exist.
 *
 * This is where key preservation is decided, and it is the single most consequential
 * function in a declarative operation. Match wrongly and the engine deletes a field
 * and creates a lookalike - same label, new key, no content.
 *
 * Priority: key, then name, then label. Never position: reordering a group must not
 * make every field look new.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diff;

use ACFJP\Model\Field;
use ACFJP\Model\FieldKind;

defined( 'ABSPATH' ) || exit;

final class Matcher {

	/**
	 * @param list<Field> $current
	 * @param list<Field> $incoming
	 * @return array{
	 *   pairs: list<array{current:Field,incoming:Field}>,
	 *   added: list<Field>,
	 *   removed: list<Field>
	 * }
	 */
	public function match( array $current, array $incoming ): array {
		$byKey   = array();
		$byName  = array();
		$byLabel = array();

		foreach ( $current as $field ) {
			if ( null !== $field->key && '' !== $field->key ) {
				$byKey[ $field->key ] = $field;
			}

			if ( '' !== $field->name ) {
				$byName[ $field->name ][] = $field;
			}

			if ( '' !== $field->label ) {
				$byLabel[ strtolower( $field->label ) ][] = $field;
			}
		}

		$pairs = array();
		$added = array();
		$taken = array();

		foreach ( $incoming as $field ) {
			$match = $this->findMatch( $field, $byKey, $byName, $byLabel, $taken );

			if ( null === $match ) {
				$added[] = $field;
				continue;
			}

			$taken[ (string) $match->key ] = true;

			$pairs[] = array( 'current' => $match, 'incoming' => $field );
		}

		$removed = array();

		foreach ( $current as $field ) {
			if ( ! isset( $taken[ (string) $field->key ] ) ) {
				$removed[] = $field;
			}
		}

		return array( 'pairs' => $pairs, 'added' => $added, 'removed' => $removed );
	}

	/**
	 * @param array<string,Field>       $byKey
	 * @param array<string,list<Field>> $byName
	 * @param array<string,list<Field>> $byLabel
	 * @param array<string,true>        $taken
	 */
	private function findMatch( Field $incoming, array $byKey, array $byName, array $byLabel, array $taken ): ?Field {
		// 1. Key. Absolute, and the only identity that survives a rename.
		if ( null !== $incoming->key && isset( $byKey[ $incoming->key ] ) && ! isset( $taken[ $incoming->key ] ) ) {
			return $byKey[ $incoming->key ];
		}

		// 2. Name, when unique among siblings. This is how ACF addresses content, so
		// two fields sharing a name are already broken; we decline to guess.
		if ( '' !== $incoming->name && isset( $byName[ $incoming->name ] ) ) {
			$available = $this->available( $byName[ $incoming->name ], $taken );

			if ( 1 === count( $available ) ) {
				return $available[0];
			}
		}

		// 3. Label, when unique. Only for structural fields (tabs, messages) and
		// fields with no name, where there is nothing better to go on.
		if ( '' !== $incoming->label && ( '' === $incoming->name || FieldKind::isStructural( $incoming->type ) ) ) {
			$key = strtolower( $incoming->label );

			if ( isset( $byLabel[ $key ] ) ) {
				$available = $this->available( $byLabel[ $key ], $taken );

				if ( 1 === count( $available ) ) {
					return $available[0];
				}
			}
		}

		return null;
	}

	/**
	 * @param list<Field>        $fields
	 * @param array<string,true> $taken
	 * @return list<Field>
	 */
	private function available( array $fields, array $taken ): array {
		return array_values(
			array_filter( $fields, static fn( Field $f ): bool => ! isset( $taken[ (string) $f->key ] ) )
		);
	}
}
