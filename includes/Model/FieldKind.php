<?php
/**
 * Our semantic overlay on ACF field types.
 *
 * ACF's own per-type JSON Schemas (6.8+) describe *settings*. They do not describe
 * the three structural facts this engine needs, so we encode those here. This table
 * is deliberately small and stable: it captures our semantics, not ACF's settings,
 * so it does not rot when ACF adds a field type or renames a setting.
 *
 * See docs/ARCHITECTURE-REVIEW.md B.1.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class FieldKind {

	/** Types whose children live in `sub_fields`. */
	private const SUB_FIELD_CONTAINERS = array( 'group', 'repeater' );

	/** Types whose children live in `layouts[].sub_fields`. */
	private const LAYOUT_CONTAINERS = array( 'flexible_content' );

	/**
	 * Presentational types: they have no `name`, store no value, and must never be
	 * matched by name or reported as data-bearing when deleted.
	 */
	private const STRUCTURAL = array( 'tab', 'accordion', 'message', 'separator' );

	/** Types that resolve foreign fields at load time rather than owning children. */
	private const REFERENCE = array( 'clone' );

	/** ACF PRO only. Verified against acf_get_pro_field_types() in ACF 6.8.10. */
	private const PRO_ONLY = array( 'clone', 'flexible_content', 'gallery', 'repeater' );

	public static function hasSubFields( string $type ): bool {
		return in_array( $type, self::SUB_FIELD_CONTAINERS, true );
	}

	public static function hasLayouts( string $type ): bool {
		return in_array( $type, self::LAYOUT_CONTAINERS, true );
	}

	public static function isContainer( string $type ): bool {
		return self::hasSubFields( $type ) || self::hasLayouts( $type );
	}

	/**
	 * Presentational only: no name, no stored value.
	 */
	public static function isStructural( string $type ): bool {
		return in_array( $type, self::STRUCTURAL, true );
	}

	/**
	 * Resolves other fields at load time. Cannot be patched through - a change
	 * "inside" a clone belongs to the source field, elsewhere.
	 */
	public static function isReference( string $type ): bool {
		return in_array( $type, self::REFERENCE, true );
	}

	public static function requiresPro( string $type ): bool {
		return in_array( $type, self::PRO_ONLY, true );
	}

	/**
	 * Whether this type stores a value against a post/term/user.
	 */
	public static function storesValue( string $type ): bool {
		return ! self::isStructural( $type ) && ! self::isReference( $type );
	}

	private function __construct() {}
}
