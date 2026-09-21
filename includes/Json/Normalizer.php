<?php
/**
 * Turns any accepted dialect into exactly one Payload.
 *
 * Everything downstream - validation, resolution, diffing, application - sees only
 * the normalised model. That is what lets the engine accept sloppy AI output and
 * pristine ACF exports through the same code path without special cases leaking
 * into the diff engine.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

use ACFJP\Acf\KeyFactory;
use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\ValidationException;
use ACFJP\Model\Field;
use ACFJP\Model\FieldGroup;
use ACFJP\Model\FieldKind;
use ACFJP\Model\Layout;
use ACFJP\Model\MoveSpec;
use ACFJP\Model\Operation;
use ACFJP\Model\Payload;
use ACFJP\Model\Target;

defined( 'ABSPATH' ) || exit;

final class Normalizer {

	private const MAX_DEPTH = 32;

	public function __construct(
		private readonly Dialect $dialect = new Dialect(),
	) {}

	/**
	 * @param array<string,mixed> $raw
	 * @param Operation|null      $defaultOperation Used when the payload declares none.
	 * @throws ValidationException
	 */
	public function normalize( array $raw, ?Operation $defaultOperation = null ): Payload {
		$dialect = $this->dialect->detect( $raw );

		return match ( $dialect ) {
			Dialect::NATIVE => $this->fromNative( $raw, $defaultOperation ),
			default         => $this->fromPatch( $raw, $defaultOperation, $dialect ),
		};
	}

	// ---- Native ACF exports --------------------------------------------------

	/**
	 * @param array<string,mixed> $raw
	 * @throws ValidationException
	 */
	private function fromNative( array $raw, ?Operation $defaultOperation ): Payload {
		$groups = $this->dialect->nativeGroups( $raw );

		if ( array() === $groups ) {
			throw new ValidationException(
				ErrorCodes::MISSING_FIELDS,
				__( 'This looks like an ACF export but contains no field groups.', 'wp-acf-json-pro' )
			);
		}

		if ( count( $groups ) > 1 ) {
			throw new ValidationException(
				ErrorCodes::INVALID_FIELD,
				sprintf(
					/* translators: %d: number of field groups found */
					__( 'This export contains %d field groups. Import them one at a time so each change can be previewed separately.', 'wp-acf-json-pro' ),
					count( $groups )
				),
				array( 'group_count' => count( $groups ) ),
				array( __( 'Split the export into one file per field group.', 'wp-acf-json-pro' ) )
			);
		}

		$groupArray           = $groups[0];
		$groupArray['fields'] = $this->normalizeFieldList( $groupArray['fields'] ?? array(), 1, '/fields' );

		$group = FieldGroup::fromAcfArray( $groupArray );

		return new Payload(
			version: '1.0',
			// A native export declares a whole state, so the honest default is
			// Create. The UI offers merge/sync/replace explicitly; we never pick a
			// destructive mode on the user's behalf.
			operation: $defaultOperation ?? Operation::Create,
			target: new Target( $group->key ?? $group->title ),
			group: $group,
			dialect: Dialect::NATIVE,
		);
	}

	// ---- Our schema, and loose approximations of it --------------------------

	/**
	 * @param array<string,mixed> $raw
	 * @throws ValidationException
	 */
	private function fromPatch( array $raw, ?Operation $defaultOperation, string $dialect ): Payload {
		$raw = Aliases::topLevel( $raw );

		$operation = $this->readOperation( $raw, $defaultOperation );
		$target    = $this->readTarget( $raw );

		$add          = $this->toFields( $this->normalizeFieldList( $this->listOf( $raw, 'add' ), 1, '/add' ) );
		$changes      = $this->readChanges( $raw );
		$delete       = $this->readDelete( $raw );
		$moves        = $this->readMoves( $raw );
		$addLayouts   = $this->readLayouts( $raw );
		$groupChanges = is_array( $raw['group_changes'] ?? null ) ? (array) $raw['group_changes'] : array();

		// `create` and `replace` carry a whole group definition rather than a patch.
		$group = null;

		if ( isset( $raw['field_group'] ) && is_array( $raw['field_group'] ) ) {
			$groupArray           = $raw['field_group'];
			$groupArray['fields'] = $this->normalizeFieldList( $groupArray['fields'] ?? array(), 1, '/field_group/fields' );
			$group                = FieldGroup::fromAcfArray( $groupArray );
		}

		// A `create` written as {"operation":"create","title":..,"fields":[..]}.
		if ( null === $group && Operation::Create === $operation && isset( $raw['fields'] ) ) {
			$group = FieldGroup::fromAcfArray(
				array(
					'title'  => (string) ( $raw['title'] ?? ( $target?->group ?? '' ) ),
					'key'    => isset( $raw['key'] ) ? (string) $raw['key'] : null,
					'fields' => $this->normalizeFieldList( $raw['fields'], 1, '/fields' ),
				)
			);
		}

		return new Payload(
			version: isset( $raw['version'] ) ? (string) $raw['version'] : '1.0',
			operation: $operation,
			target: $target,
			group: $group,
			add: $add,
			changes: $changes,
			delete: $delete,
			moves: $moves,
			addLayouts: $addLayouts,
			groupChanges: $groupChanges,
			options: is_array( $raw['options'] ?? null ) ? (array) $raw['options'] : array(),
			dialect: $dialect,
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @throws ValidationException
	 */
	private function readOperation( array $raw, ?Operation $default ): Operation {
		if ( ! isset( $raw['operation'] ) ) {
			if ( null !== $default ) {
				return $default;
			}

			throw new ValidationException(
				ErrorCodes::MISSING_OPERATION,
				__( 'The payload does not say what to do. Add an "operation".', 'wp-acf-json-pro' ),
				array(),
				Operation::names(),
				'/operation'
			);
		}

		try {
			return Operation::fromString( (string) $raw['operation'] );
		} catch ( \ValueError ) {
			throw new ValidationException(
				ErrorCodes::UNKNOWN_OPERATION,
				sprintf(
					/* translators: %s: the operation supplied */
					__( 'Unknown operation "%s".', 'wp-acf-json-pro' ),
					(string) $raw['operation']
				),
				array( 'operation' => (string) $raw['operation'] ),
				Operation::names(),
				'/operation'
			);
		}
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function readTarget( array $raw ): ?Target {
		if ( isset( $raw['target'] ) ) {
			if ( is_string( $raw['target'] ) ) {
				return new Target( $raw['target'] );
			}

			if ( is_array( $raw['target'] ) ) {
				return Target::fromArray( Aliases::target( $raw['target'] ) );
			}
		}

		// Loose dialect: the group is often named at the top level.
		foreach ( array( 'field_group', 'group', 'fieldGroup' ) as $candidate ) {
			if ( isset( $raw[ $candidate ] ) && is_string( $raw[ $candidate ] ) ) {
				return new Target( $raw[ $candidate ] );
			}
		}

		return null;
	}

	/**
	 * Changes arrive either as `{"email": {"required": true}}` or as a list of
	 * `{"field": "email", "required": true}` objects. Both are common.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,array<string,mixed>>
	 */
	private function readChanges( array $raw ): array {
		$changes = $raw['changes'] ?? array();

		if ( ! is_array( $changes ) ) {
			return array();
		}

		$out = array();

		foreach ( $changes as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$value = Aliases::field( $value );

			if ( is_int( $key ) ) {
				$reference = (string) ( $value['field'] ?? $value['name'] ?? $value['key'] ?? '' );
				unset( $value['field'] );

				if ( '' === $reference ) {
					continue;
				}
			} else {
				$reference = (string) $key;
			}

			$out[ $reference ] = Coerce::settings( $value );
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return list<string>
	 */
	private function readDelete( array $raw ): array {
		$delete = $raw['delete'] ?? array();

		if ( is_string( $delete ) ) {
			$delete = array( $delete );
		}

		if ( ! is_array( $delete ) ) {
			return array();
		}

		$out = array();

		foreach ( $delete as $item ) {
			if ( is_string( $item ) ) {
				$out[] = $item;
				continue;
			}

			if ( is_array( $item ) ) {
				$reference = (string) ( $item['field'] ?? $item['name'] ?? $item['key'] ?? '' );

				if ( '' !== $reference ) {
					$out[] = $reference;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return list<MoveSpec>
	 */
	private function readMoves( array $raw ): array {
		$moves = $raw['moves'] ?? array();

		if ( ! is_array( $moves ) ) {
			return array();
		}

		// A single move object rather than a list.
		if ( isset( $moves['field'] ) ) {
			$moves = array( $moves );
		}

		$out = array();

		foreach ( $moves as $move ) {
			if ( is_array( $move ) && isset( $move['field'] ) ) {
				$out[] = MoveSpec::fromArray( $move );
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return list<Layout>
	 */
	private function readLayouts( array $raw ): array {
		$layouts = $raw['add_layouts'] ?? $raw['layouts'] ?? array();

		if ( ! is_array( $layouts ) ) {
			return array();
		}

		$out = array();

		foreach ( $layouts as $key => $layout ) {
			if ( ! is_array( $layout ) ) {
				continue;
			}

			$layout = $this->normalizeLayout( $layout, is_string( $key ) ? $key : null, 1, '/add_layouts' );
			$out[]  = Layout::fromAcfArray( $layout );
		}

		return $out;
	}

	// ---- Field normalisation -------------------------------------------------

	/**
	 * @param mixed $fields
	 * @return list<array<string,mixed>>
	 * @throws ValidationException
	 */
	private function normalizeFieldList( mixed $fields, int $depth, string $pointer ): array {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$out   = array();
		$index = 0;

		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			// Support the map form {"company_name": {"label": ...}} as well as a list.
			if ( is_string( $key ) && ! isset( $field['name'] ) ) {
				$field['name'] = $key;
			}

			$out[] = $this->normalizeField( $field, $depth, $pointer . '/' . $index );
			++$index;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	private function normalizeField( array $raw, int $depth, string $pointer ): array {
		if ( $depth > self::MAX_DEPTH ) {
			throw new ValidationException(
				ErrorCodes::MAX_DEPTH_EXCEEDED,
				sprintf(
					/* translators: %d: maximum nesting depth */
					__( 'Fields are nested more than %d levels deep.', 'wp-acf-json-pro' ),
					self::MAX_DEPTH
				),
				array( 'max_depth' => self::MAX_DEPTH ),
				array(),
				$pointer
			);
		}

		$raw  = Aliases::field( $raw );
		$type = (string) ( $raw['type'] ?? 'text' );

		$raw['type'] = $type;

		// Label and name fill in for each other. ACF itself derives name from label.
		$label = isset( $raw['label'] ) ? (string) $raw['label'] : '';
		$name  = isset( $raw['name'] ) ? (string) $raw['name'] : '';

		if ( '' === $name && '' !== $label && ! FieldKind::isStructural( $type ) ) {
			$name = (string) KeyFactory::normalizeName( $label );
		}

		if ( '' === $label && '' !== $name ) {
			$label = ucwords( str_replace( '_', ' ', $name ) );
		}

		if ( '' !== $name ) {
			$normalized = KeyFactory::normalizeName( $name );

			if ( null === $normalized ) {
				throw new ValidationException(
					ErrorCodes::INVALID_FIELD_NAME,
					sprintf(
						/* translators: %s: the supplied field name */
						__( 'The field name "%s" contains no usable characters.', 'wp-acf-json-pro' ),
						$name
					),
					array( 'name' => $name ),
					array( __( 'Field names must match ^[a-z_][a-z0-9_]*$ - lowercase letters, digits and underscores.', 'wp-acf-json-pro' ) ),
					$pointer . '/name'
				);
			}

			$name = $normalized;
		}

		$raw['label'] = $label;
		$raw['name']  = $name;

		// Nested structures.
		if ( FieldKind::hasSubFields( $type ) ) {
			$raw['sub_fields'] = $this->normalizeFieldList( $raw['sub_fields'] ?? array(), $depth + 1, $pointer . '/sub_fields' );
		} elseif ( FieldKind::hasLayouts( $type ) ) {
			$layouts = array();

			foreach ( (array) ( $raw['layouts'] ?? array() ) as $layoutKey => $layout ) {
				if ( is_array( $layout ) ) {
					$layouts[] = $this->normalizeLayout( $layout, is_string( $layoutKey ) ? $layoutKey : null, $depth + 1, $pointer . '/layouts' );
				}
			}

			$raw['layouts'] = $layouts;
		} else {
			// A non-container carrying sub_fields is a modelling mistake. Drop it
			// rather than silently storing children ACF will never render.
			unset( $raw['sub_fields'], $raw['layouts'] );
		}

		// Coerce every remaining setting.
		$structural = array( 'key', 'name', 'label', 'type', 'sub_fields', 'layouts' );
		$settings   = array_diff_key( $raw, array_flip( $structural ) );
		$settings   = Coerce::settings( $settings );

		return array_merge( $settings, array_intersect_key( $raw, array_flip( $structural ) ) );
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	private function normalizeLayout( array $raw, ?string $keyHint, int $depth, string $pointer ): array {
		$label = (string) ( $raw['label'] ?? $raw['title'] ?? '' );
		$name  = (string) ( $raw['name'] ?? '' );

		if ( '' === $name && '' !== $label ) {
			$name = (string) KeyFactory::normalizeName( $label );
		}

		if ( '' === $label && '' !== $name ) {
			$label = ucwords( str_replace( '_', ' ', $name ) );
		}

		if ( '' === $name && null !== $keyHint && ! str_starts_with( $keyHint, 'layout_' ) ) {
			$name  = (string) KeyFactory::normalizeName( $keyHint );
			$label = '' !== $label ? $label : ucwords( str_replace( '_', ' ', $name ) );
		}

		$raw['name']       = $name;
		$raw['label']      = $label;
		$raw['display']    = (string) ( $raw['display'] ?? 'block' );
		$raw['sub_fields'] = $this->normalizeFieldList( $raw['sub_fields'] ?? array(), $depth + 1, $pointer . '/sub_fields' );

		if ( null !== $keyHint && str_starts_with( $keyHint, 'layout_' ) && ! isset( $raw['key'] ) ) {
			$raw['key'] = $keyHint;
		}

		return $raw;
	}

	/**
	 * Materialise normalised field arrays into model objects.
	 *
	 * Payload::$add is typed as a list of Field, not of arrays: everything past
	 * the Normalizer works on the model, never on raw arrays.
	 *
	 * @param list<array<string,mixed>> $arrays
	 * @return list<Field>
	 */
	private function toFields( array $arrays ): array {
		return array_values(
			array_map(
				static fn ( array $field ): Field => Field::fromAcfArray( $field ),
				$arrays
			)
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<mixed>
	 */
	private function listOf( array $raw, string $key ): array {
		$value = $raw[ $key ] ?? array();

		if ( ! is_array( $value ) ) {
			return array();
		}

		// A single field object rather than a list.
		if ( isset( $value['type'] ) || isset( $value['name'] ) || isset( $value['label'] ) ) {
			return array( $value );
		}

		return $value;
	}
}
