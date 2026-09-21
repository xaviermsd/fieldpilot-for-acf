<?php
/**
 * Value coercion.
 *
 * ACF stores booleans as 0/1 ints, choices as associative arrays, and disabled
 * conditional logic as int 0. AI output uses real booleans, plain lists and `false`.
 * Both are reasonable; only one of them is what ACF reads back. Coercing here means
 * the diff engine never reports `required: false → 0` as a change.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

defined( 'ABSPATH' ) || exit;

final class Coerce {

	/** Settings ACF persists as 0/1 integers. */
	private const BOOLEAN_SETTINGS = array(
		'required',
		'multiple',
		'allow_null',
		'allow_in_bindings',
		'ui',
		'ajax',
		'disabled',
		'readonly',
		'media_upload',
		'delay',
		'toolbar_sticky',
		'prefix_label',
		'prefix_name',
		'save_terms',
		'load_terms',
		'save_post_meta',
		'add_term',
		'collapsed_ui',
		'pagination',
		'acfe_permissions',
	);

	/** Settings ACF persists as integers. */
	private const INT_SETTINGS = array( 'min', 'max', 'maxlength', 'rows', 'rows_per_page', 'step', 'menu_order' );

	/**
	 * Normalise a whole settings array for one field.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function settings( array $settings ): array {
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;

			if ( in_array( $key, self::BOOLEAN_SETTINGS, true ) ) {
				$settings[ $key ] = self::toAcfBool( $value );
				continue;
			}

			if ( in_array( $key, self::INT_SETTINGS, true ) && '' !== $value && null !== $value ) {
				$settings[ $key ] = is_numeric( $value ) ? (int) $value : $value;
				continue;
			}

			if ( 'choices' === $key ) {
				$settings[ $key ] = self::choices( $value );
				continue;
			}

			if ( 'conditional_logic' === $key ) {
				$settings[ $key ] = self::conditionalLogic( $value );
				continue;
			}

			if ( 'wrapper' === $key ) {
				$settings[ $key ] = self::wrapper( $value );
			}
		}

		return $settings;
	}

	/**
	 * ACF's truthiness: int 1 or 0. Accepts booleans, "yes"/"no", "true"/"false",
	 * "on"/"off", and numeric strings.
	 */
	public static function toAcfBool( mixed $value ): int {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true ) ? 1 : 0;
		}

		return 0;
	}

	/**
	 * ACF stores choices as value => label.
	 *
	 * Accepts: an associative map (kept), a plain list (value doubles as label), or
	 * a newline-separated string in ACF's own "value : Label" admin format.
	 *
	 * @return array<string,string>
	 */
	public static function choices( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\R/', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $key => $item ) {
			// Objects of the shape {value, label} are common in AI output.
			if ( is_array( $item ) && ( isset( $item['value'] ) || isset( $item['label'] ) ) ) {
				$choiceValue         = (string) ( $item['value'] ?? $item['label'] );
				$out[ $choiceValue ] = (string) ( $item['label'] ?? $item['value'] );
				continue;
			}

			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = (string) $item;

			if ( is_string( $key ) ) {
				$out[ $key ] = $item;
				continue;
			}

			// "value : Label"
			if ( str_contains( $item, ' : ' ) ) {
				[ $choiceValue, $label ] = array_map( 'trim', explode( ' : ', $item, 2 ) );
				$out[ $choiceValue ]     = $label;
				continue;
			}

			$out[ $item ] = $item;
		}

		return $out;
	}

	/**
	 * ACF stores "no conditional logic" as int 0, and rules as a list of OR groups
	 * each containing a list of AND rules.
	 */
	public static function conditionalLogic( mixed $value ): mixed {
		if ( false === $value || null === $value || 0 === $value || '' === $value || array() === $value ) {
			return 0;
		}

		if ( ! is_array( $value ) ) {
			return 0;
		}

		// A single flat rule → wrap into [[rule]].
		if ( isset( $value['field'] ) || isset( $value['operator'] ) ) {
			return array( array( $value ) );
		}

		// A flat list of rules → wrap into one AND group.
		if ( array_is_list( $value ) && isset( $value[0] ) && is_array( $value[0] ) && isset( $value[0]['field'] ) ) {
			return array( $value );
		}

		return $value;
	}

	/**
	 * @return array{width:string,class:string,id:string}
	 */
	public static function wrapper( mixed $value ): array {
		$defaults = array( 'width' => '', 'class' => '', 'id' => '' );

		if ( ! is_array( $value ) ) {
			return $defaults;
		}

		return array(
			'width' => isset( $value['width'] ) ? (string) $value['width'] : '',
			'class' => isset( $value['class'] ) ? (string) $value['class'] : '',
			'id'    => isset( $value['id'] ) ? (string) $value['id'] : '',
		);
	}

	private function __construct() {}
}
