<?php
/**
 * Generates ACF-compatible keys, and guarantees they are unique site-wide.
 *
 * Keys are load-bearing: ACF stores a field's key as the value of the hidden
 * `_<name>` meta row, and acf_get_reference() resolves content back to a field
 * through it. A duplicate key silently misroutes content. A regenerated key on an
 * existing field orphans all of its content.
 *
 * Rule: this class is used ONLY for fields that do not yet exist.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Acf;

defined( 'ABSPATH' ) || exit;

final class KeyFactory {

	/** Matches ACF's own schema constraint: ^field_[a-z0-9]+$ */
	private const FIELD_PREFIX  = 'field_';
	private const GROUP_PREFIX  = 'group_';
	private const LAYOUT_PREFIX = 'layout_';

	/** @var array<string,true> Keys minted this request, not yet in the database. */
	private array $reserved = array();

	/** @var array<string,true>|null Lazily built index of every key ACF knows. */
	private ?array $existing = null;

	public function field(): string {
		return $this->mint( self::FIELD_PREFIX );
	}

	public function group(): string {
		return $this->mint( self::GROUP_PREFIX );
	}

	public function layout(): string {
		return $this->mint( self::LAYOUT_PREFIX );
	}

	/**
	 * Whether a key is already taken, counting keys minted earlier in this request.
	 */
	public function isTaken( string $key ): bool {
		return isset( $this->reserved[ $key ] ) || isset( $this->knownKeys()[ $key ] );
	}

	/**
	 * Claim a caller-supplied key so nothing else in this batch mints it.
	 */
	public function reserve( string $key ): void {
		$this->reserved[ $key ] = true;
	}

	/**
	 * Validate the shape of a key the caller supplied.
	 */
	public static function isWellFormed( string $key ): bool {
		return 1 === preg_match( '/^(?:field|group|layout)_[a-z0-9]+$/', $key );
	}

	/**
	 * Normalise an incoming field name to ACF's constraint: ^[a-z_][a-z0-9_]*$.
	 * Returns null when nothing usable survives, so the caller can raise rather
	 * than write a mangled name.
	 */
	public static function normalizeName( string $name ): ?string {
		$name = strtolower( trim( $name ) );
		$name = remove_accents( $name );
		$name = (string) preg_replace( '/[^a-z0-9_]+/', '_', $name );
		$name = trim( (string) preg_replace( '/_+/', '_', $name ), '_' );

		if ( '' === $name ) {
			return null;
		}

		// Must not start with a digit.
		if ( 1 === preg_match( '/^[0-9]/', $name ) ) {
			$name = '_' . $name;
		}

		return $name;
	}

	private function mint( string $prefix ): string {
		do {
			// uniqid() is what ACF itself uses; 13 lowercase hex chars satisfies
			// ACF's ^field_[a-z0-9]+$ pattern. random_bytes closes the microsecond
			// collision window when many keys are minted in one request.
			$candidate = $prefix . substr( bin2hex( random_bytes( 8 ) ), 0, 13 );
		} while ( $this->isTaken( $candidate ) );

		$this->reserved[ $candidate ] = true;

		return $candidate;
	}

	/**
	 * Every field, group and layout key ACF currently knows about.
	 *
	 * @return array<string,true>
	 */
	private function knownKeys(): array {
		if ( null !== $this->existing ) {
			return $this->existing;
		}

		global $wpdb;

		$this->existing = array();

		// Field and field-group keys are stored as post_name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			"SELECT post_name FROM {$wpdb->posts} WHERE post_type IN ('acf-field','acf-field-group')"
		);

		foreach ( (array) $names as $name ) {
			$this->existing[ (string) $name ] = true;
		}

		// Layout keys live inside serialised field settings, and local (JSON/PHP)
		// groups are not in the posts table at all. Walk what ACF has loaded.
		$previousFilters = acf_disable_filters();

		try {
			foreach ( acf_get_field_groups() as $group ) {
				if ( ! empty( $group['key'] ) ) {
					$this->existing[ (string) $group['key'] ] = true;
				}

				foreach ( acf_get_fields( $group ) as $field ) {
					$this->collectKeys( $field );
				}
			}
		} finally {
			acf_enable_filters( $previousFilters );
		}

		return $this->existing ?? array();
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private function collectKeys( array $field ): void {
		if ( ! empty( $field['key'] ) ) {
			$this->existing[ (string) $field['key'] ] = true;
		}

		foreach ( $field['sub_fields'] ?? array() as $sub ) {
			if ( is_array( $sub ) ) {
				$this->collectKeys( $sub );
			}
		}

		foreach ( $field['layouts'] ?? array() as $layout ) {
			if ( ! is_array( $layout ) ) {
				continue;
			}

			if ( ! empty( $layout['key'] ) ) {
				$this->existing[ (string) $layout['key'] ] = true;
			}

			foreach ( $layout['sub_fields'] ?? array() as $sub ) {
				if ( is_array( $sub ) ) {
					$this->collectKeys( $sub );
				}
			}
		}
	}
}
