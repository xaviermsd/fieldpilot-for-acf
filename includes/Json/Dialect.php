<?php
/**
 * Works out which flavour of JSON arrived.
 *
 * Three are supported, and the product depends on tolerating all three:
 *
 *   NATIVE  ACF's own export. Whole field groups, group_/field_ keys, no operation.
 *   ACFJP   Our patch schema. Has `operation`, usually `version` and `target`.
 *   LOOSE   What an AI produces when it has not been given our schema: the right
 *           ideas under the wrong key names. We accept it and normalise it rather
 *           than making the developer hand-fix an LLM's synonym choices.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

defined( 'ABSPATH' ) || exit;

final class Dialect {

	public const NATIVE = 'native_acf';
	public const ACFJP  = 'acfjp';
	public const LOOSE  = 'loose';

	/**
	 * @param array<string,mixed> $raw
	 */
	public function detect( array $raw ): string {
		// An explicit operation is decisive.
		if ( isset( $raw['operation'] ) || isset( $raw['changes'] ) ) {
			return self::ACFJP;
		}

		// A bare ACF export: either one group object or a list of them.
		if ( $this->looksNative( $raw ) ) {
			return self::NATIVE;
		}

		if ( array_is_list( $raw ) && array() !== $raw ) {
			$first = $raw[0];

			if ( is_array( $first ) && $this->looksNative( $first ) ) {
				return self::NATIVE;
			}
		}

		if ( isset( $raw['version'] ) ) {
			return self::ACFJP;
		}

		return self::LOOSE;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function looksNative( array $raw ): bool {
		$hasGroupKey = isset( $raw['key'] ) && is_string( $raw['key'] ) && str_starts_with( $raw['key'], 'group_' );
		$hasFields   = isset( $raw['fields'] ) && is_array( $raw['fields'] );
		$hasLocation = isset( $raw['location'] );

		return $hasGroupKey && ( $hasFields || $hasLocation );
	}

	/**
	 * Normalise a native payload to a single group array, since ACF exports either
	 * one object or a list.
	 *
	 * @param array<string,mixed> $raw
	 * @return list<array<string,mixed>>
	 */
	public function nativeGroups( array $raw ): array {
		if ( $this->looksNative( $raw ) ) {
			return array( $raw );
		}

		$groups = array();

		foreach ( $raw as $candidate ) {
			if ( is_array( $candidate ) && $this->looksNative( $candidate ) ) {
				$groups[] = $candidate;
			}
		}

		return $groups;
	}
}
