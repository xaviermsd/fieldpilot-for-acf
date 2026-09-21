<?php
/**
 * Field-type knowledge, sourced from ACF itself.
 *
 * ACF 6.8.0 added acf_get_field_json_schema(), which loads one strict draft-07
 * schema per field type from ACF's own schemas/fields/v1/ directory - 36 of them,
 * covering every setting each type accepts. Consuming those instead of maintaining
 * our own table means our validation tracks ACF automatically and cannot drift.
 *
 * Below ACF 6.8 the function does not exist; we degrade to structural validation
 * and say so in the preview rather than guessing.
 *
 * See docs/ARCHITECTURE-REVIEW.md B.1.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

use ACFJP\Model\FieldKind;

defined( 'ABSPATH' ) || exit;

final class FieldTypeSchemas {

	/** @var array<string,array<string,mixed>> */
	private array $cache = array();

	/** @var list<string>|null */
	private ?array $installed = null;

	public function __construct( private readonly SchemaValidator $validator = new SchemaValidator() ) {}

	/**
	 * Whether this ACF install can supply per-type schemas at all.
	 */
	public function available(): bool {
		return function_exists( 'acf_get_field_json_schema' );
	}

	/**
	 * The schema for one field type, or an empty array when none is available.
	 *
	 * @return array<string,mixed>
	 */
	public function for( string $type ): array {
		if ( isset( $this->cache[ $type ] ) ) {
			return $this->cache[ $type ];
		}

		$schema = $this->available() ? acf_get_field_json_schema( $type ) : array();

		/**
		 * Supply or override the schema for a field type. Add-ons providing a
		 * third-party field type should hook this so their fields validate as
		 * strictly as ACF's own.
		 *
		 * @param array<string,mixed> $schema
		 * @param string              $type
		 */
		$schema = (array) apply_filters( 'acfjp_field_type_schema', $schema, $type );

		$this->cache[ $type ] = $schema;

		return $schema;
	}

	/**
	 * Validate one field definition against its type's schema.
	 *
	 * Our own structural keys are stripped before validating, because ACF's schemas
	 * declare additionalProperties:false and know nothing about them.
	 *
	 * @param array<string,mixed> $field
	 * @return list<array{pointer:string,message:string,keyword:string}>
	 */
	public function validateField( array $field, string $pointer = '' ): array {
		$type   = (string) ( $field['type'] ?? '' );
		$schema = $this->for( $type );

		if ( array() === $schema ) {
			return array();
		}

		// Container payloads are validated structurally by the Validator, not here.
		unset( $field['sub_fields'], $field['layouts'], $field['parent'], $field['parent_layout'], $field['menu_order'], $field['ID'] );

		// Field::toAcfArray() always emits `key` and `name`, using '' for "not set",
		// because that is the shape ACF's own exporter produces. ACF's schemas then
		// reject '' against their ^field_[a-z0-9]+$ and ^[a-z_][a-z0-9_]*$ patterns.
		//
		// A new field legitimately has no key - the engine mints one at write time -
		// and a structural field (tab, message, accordion) legitimately has no name.
		// Neither is a problem the user can act on, so validating them produces a
		// warning that is pure noise. Absent means absent.
		foreach ( array( 'key', 'name' ) as $identity ) {
			if ( isset( $field[ $identity ] ) && '' === $field[ $identity ] ) {
				unset( $field[ $identity ] );
			}
		}

		return $this->validator->validate( $field, $schema, $pointer );
	}

	/**
	 * Field types this ACF install can actually instantiate.
	 *
	 * @return list<string>
	 */
	public function installedTypes(): array {
		if ( null !== $this->installed ) {
			return $this->installed;
		}

		$types = array();

		foreach ( acf_get_field_types() as $name => $type ) {
			// ACF returns objects keyed by name; be tolerant of either shape.
			$types[] = is_object( $type ) && isset( $type->name ) ? (string) $type->name : (string) $name;
		}

		sort( $types );

		$this->installed = $types;

		return $types;
	}

	public function isInstalled( string $type ): bool {
		return in_array( $type, $this->installedTypes(), true );
	}

	/**
	 * A type ACF knows of but cannot instantiate here. In practice: the four PRO
	 * types on a free install. Lets us return FIELD_TYPE_REQUIRES_PRO instead of a
	 * baffling "unknown field type".
	 */
	public function requiresPro( string $type ): bool {
		return ! $this->isInstalled( $type ) && FieldKind::requiresPro( $type );
	}

	/**
	 * Closest installed type names, for error suggestions.
	 *
	 * @return list<string>
	 */
	public function suggestTypes( string $needle ): array {
		$scored = array();

		foreach ( $this->installedTypes() as $type ) {
			$scored[] = array( 'type' => $type, 'score' => levenshtein( strtolower( $needle ), $type ) );
		}

		usort( $scored, static fn( array $a, array $b ): int => $a['score'] <=> $b['score'] );

		return array_column( array_slice( $scored, 0, 5 ), 'type' );
	}

	/**
	 * Setting names ACF recognises for a type. Used to warn about settings that
	 * will be stored but ignored - a common AI mistake.
	 *
	 * @return list<string>
	 */
	public function knownSettings( string $type ): array {
		$schema = $this->for( $type );

		return is_array( $schema['properties'] ?? null ) ? array_keys( $schema['properties'] ) : array();
	}
}
