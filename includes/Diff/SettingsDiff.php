<?php
/**
 * Compares two field configurations setting by setting.
 *
 * The partial-update guarantee lives here: in patch mode, only settings the payload
 * actually mentions are examined. A setting absent from the incoming definition is
 * not "unset" - it is simply not part of the request.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diff;

use ACFJP\Model\Field;

defined( 'ABSPATH' ) || exit;

final class SettingsDiff {

	/**
	 * Settings never reported as changes. ACF or WordPress owns them.
	 */
	private const IGNORED = array(
		'modified',
		'ID',
		'parent',
		'parent_repeater',
		'prefix',
		'value',
		'_name',
		'_valid',
		'_prepare',
		'menu_order',
		'sub_fields',
		'layouts',
		'key',
	);

	/**
	 * Diff the settings a payload declares against a field's current state.
	 *
	 * @param array<string,mixed> $incoming Declared settings, already normalised.
	 * @return array<string,array{from:mixed,to:mixed}>
	 */
	public function forSettings( Field $current, array $incoming ): array {
		$diffs = array();

		foreach ( $incoming as $setting => $to ) {
			$setting = (string) $setting;

			if ( in_array( $setting, self::IGNORED, true ) ) {
				continue;
			}

			$from = match ( $setting ) {
				'label' => $current->label,
				'name'  => $current->name,
				'type'  => $current->type,
				default => $current->settings[ $setting ] ?? null,
			};

			if ( ! $this->equivalent( $from, $to ) ) {
				$diffs[ $setting ] = array( 'from' => $from, 'to' => $to );
			}
		}

		return $diffs;
	}

	/**
	 * Diff two whole field definitions. Used by declarative operations, where the
	 * incoming definition is the complete desired state for that field.
	 *
	 * @return array<string,array{from:mixed,to:mixed}>
	 */
	public function forFields( Field $current, Field $incoming ): array {
		$declared = $incoming->settings;

		$declared['label'] = $incoming->label;
		$declared['name']  = $incoming->name;
		$declared['type']  = $incoming->type;

		return $this->forSettings( $current, $declared );
	}

	/**
	 * Are two setting values the same as far as ACF is concerned?
	 *
	 * ACF stores booleans as 0/1 and empty values inconsistently across types, and
	 * the Normalizer coerces incoming values to ACF's conventions. This handles the
	 * residue so the preview never shows `required: 0 → false` as a change.
	 */
	public function equivalent( mixed $a, mixed $b ): bool {
		if ( $a === $b ) {
			return true;
		}

		// Boolean-ish: true/1/"1" are the same value; false/0/"0" likewise.
		if ( $this->isBoolish( $a ) && $this->isBoolish( $b ) ) {
			return $this->toBool( $a ) === $this->toBool( $b );
		}

		// Numeric strings versus numbers.
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a === (float) $b;
		}

		// null, '' and [] all mean "not set" for most ACF settings.
		if ( $this->isBlank( $a ) && $this->isBlank( $b ) ) {
			return true;
		}

		if ( is_array( $a ) && is_array( $b ) ) {
			return $this->canonical( $a ) === $this->canonical( $b );
		}

		return false;
	}

	private function isBoolish( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return true;
		}

		if ( is_int( $value ) ) {
			return 0 === $value || 1 === $value;
		}

		if ( is_string( $value ) ) {
			return in_array( $value, array( '0', '1' ), true );
		}

		return false;
	}

	private function toBool( mixed $value ): bool {
		return is_bool( $value ) ? $value : ( '0' !== (string) $value && '' !== (string) $value );
	}

	private function isBlank( mixed $value ): bool {
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private function canonical( array $value ): array {
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = $this->canonical( $v );
			} elseif ( is_bool( $v ) ) {
				$value[ $k ] = $v ? 1 : 0;
			} elseif ( is_numeric( $v ) && ! is_string( $v ) ) {
				$value[ $k ] = 0 + $v;
			}
		}

		return $value;
	}
}
