<?php
/**
 * A focused JSON Schema draft-07 validator.
 *
 * WHY NOT A LIBRARY. This plugin ships no Composer runtime dependencies (CLAUDE.md):
 * an unprefixed vendor/ directory in a WordPress plugin collides with whatever other
 * plugins bundled, and php-scoper is a build step this project does not want. The
 * schemas we validate against are ACF's own, and they use a narrow slice of draft-07
 * - type, enum, pattern, properties, required, additionalProperties, items, numeric
 * and length bounds. That slice is ~200 lines.
 *
 * Unsupported keywords are ignored rather than failing: a schema we do not fully
 * understand must never cause us to reject a payload ACF would accept.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

defined( 'ABSPATH' ) || exit;

final class SchemaValidator {

	/** @var list<array{pointer:string,message:string,keyword:string}> */
	private array $errors = array();

	/**
	 * @param mixed                $value  Decoded JSON value.
	 * @param array<string,mixed>  $schema Draft-07 schema fragment.
	 * @return list<array{pointer:string,message:string,keyword:string}> Empty when valid.
	 */
	public function validate( mixed $value, array $schema, string $pointer = '' ): array {
		$this->errors = array();
		$this->walk( $value, $schema, $pointer );

		return $this->errors;
	}

	/**
	 * @param array<string,mixed> $schema
	 */
	private function walk( mixed $value, array $schema, string $pointer ): void {
		if ( array() === $schema ) {
			return;
		}

		if ( isset( $schema['type'] ) && ! $this->matchesType( $value, (array) $schema['type'] ) ) {
			$this->fail(
				$pointer,
				'type',
				sprintf(
					/* translators: 1: expected JSON type(s), 2: actual type */
					__( 'Expected %1$s, got %2$s.', 'fieldpilot-for-acf' ),
					implode( ' or ', (array) $schema['type'] ),
					$this->describeType( $value )
				)
			);

			// Type is wrong; every other keyword would produce noise.
			return;
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$this->fail(
				$pointer,
				'enum',
				sprintf(
					/* translators: %s: comma-separated list of allowed values */
					__( 'Must be one of: %s.', 'fieldpilot-for-acf' ),
					implode( ', ', array_map( static fn( $v ): string => is_scalar( $v ) ? (string) $v : gettype( $v ), $schema['enum'] ) )
				)
			);
		}

		if ( is_string( $value ) ) {
			$this->checkString( $value, $schema, $pointer );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$this->checkNumber( $value, $schema, $pointer );
		}

		if ( is_array( $value ) ) {
			// A PHP array is either a JSON object or a JSON array; decide by shape.
			if ( $this->isList( $value ) ) {
				$this->checkArray( $value, $schema, $pointer );
			} else {
				$this->checkObject( $value, $schema, $pointer );
			}
		}
	}

	/**
	 * @param array<string,mixed> $schema
	 */
	private function checkString( string $value, array $schema, string $pointer ): void {
		if ( isset( $schema['minLength'] ) && mb_strlen( $value ) < (int) $schema['minLength'] ) {
			$this->fail(
				$pointer,
				'minLength',
				sprintf(
					/* translators: %d: minimum number of characters */
					__( 'Must be at least %d characters.', 'fieldpilot-for-acf' ),
					(int) $schema['minLength']
				)
			);
		}

		if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > (int) $schema['maxLength'] ) {
			$this->fail(
				$pointer,
				'maxLength',
				sprintf(
					/* translators: %d: maximum number of characters */
					__( 'Must be at most %d characters.', 'fieldpilot-for-acf' ),
					(int) $schema['maxLength']
				)
			);
		}

		if ( isset( $schema['pattern'] ) && is_string( $schema['pattern'] ) ) {
			$delimited = '/' . str_replace( '/', '\\/', $schema['pattern'] ) . '/u';

			// A schema with a pattern PCRE cannot compile must not reject the value.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			$matched = @preg_match( $delimited, $value );

			if ( 0 === $matched ) {
				$this->fail(
					$pointer,
					'pattern',
					sprintf(
						/* translators: %s: regular expression */
						__( 'Does not match the required format (%s).', 'fieldpilot-for-acf' ),
						$schema['pattern']
					)
				);
			}
		}
	}

	/**
	 * @param array<string,mixed> $schema
	 */
	private function checkNumber( int|float $value, array $schema, string $pointer ): void {
		if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$this->fail(
				$pointer,
				'minimum',
				sprintf(
					/* translators: %s: minimum value */
					__( 'Must be at least %s.', 'fieldpilot-for-acf' ),
					(string) $schema['minimum']
				)
			);
		}

		if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
			$this->fail(
				$pointer,
				'maximum',
				sprintf(
					/* translators: %s: maximum value */
					__( 'Must be at most %s.', 'fieldpilot-for-acf' ),
					(string) $schema['maximum']
				)
			);
		}
	}

	/**
	 * @param list<mixed>         $value
	 * @param array<string,mixed> $schema
	 */
	private function checkArray( array $value, array $schema, string $pointer ): void {
		if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) {
			$this->fail(
				$pointer,
				'minItems',
				sprintf(
					/* translators: %d: minimum number of items */
					__( 'Must contain at least %d items.', 'fieldpilot-for-acf' ),
					(int) $schema['minItems']
				)
			);
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $value as $index => $item ) {
				$this->walk( $item, $schema['items'], $pointer . '/' . $index );
			}
		}
	}

	/**
	 * @param array<string,mixed> $value
	 * @param array<string,mixed> $schema
	 */
	private function checkObject( array $value, array $schema, string $pointer ): void {
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();

		foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
			if ( ! array_key_exists( (string) $required, $value ) ) {
				$this->fail(
					$pointer . '/' . $required,
					'required',
					sprintf(
						/* translators: %s: property name */
						__( 'Required property "%s" is missing.', 'fieldpilot-for-acf' ),
						(string) $required
					)
				);
			}
		}

		foreach ( $value as $property => $propertyValue ) {
			$childPointer = $pointer . '/' . $property;

			if ( isset( $properties[ (string) $property ] ) && is_array( $properties[ (string) $property ] ) ) {
				$this->walk( $propertyValue, $properties[ (string) $property ], $childPointer );
				continue;
			}

			if ( array_key_exists( 'additionalProperties', $schema ) && false === $schema['additionalProperties'] ) {
				$this->fail(
					$childPointer,
					'additionalProperties',
					sprintf(
						/* translators: %s: property name */
						__( 'Unknown property "%s".', 'fieldpilot-for-acf' ),
						(string) $property
					)
				);
			}
		}
	}

	/**
	 * @param list<string> $types
	 */
	private function matchesType( mixed $value, array $types ): bool {
		foreach ( $types as $type ) {
			$ok = match ( $type ) {
				'string'  => is_string( $value ),
				'integer' => is_int( $value ),
				'number'  => is_int( $value ) || is_float( $value ),
				'boolean' => is_bool( $value ) || 0 === $value || 1 === $value || '0' === $value || '1' === $value,
				'null'    => null === $value,
				'array'   => is_array( $value ) && $this->isList( $value ),
				'object'  => is_array( $value ) && ! $this->isList( $value ),
				default   => true,
			};

			if ( $ok ) {
				return true;
			}
		}

		return false;
	}

	private function describeType( mixed $value ): string {
		return match ( true ) {
			is_string( $value )                      => 'string',
			is_bool( $value )                        => 'boolean',
			is_int( $value )                         => 'integer',
			is_float( $value )                       => 'number',
			null === $value                          => 'null',
			is_array( $value ) && $this->isList( $value ) => 'array',
			is_array( $value )                       => 'object',
			default                                  => gettype( $value ),
		};
	}

	/**
	 * An empty PHP array is ambiguous. Treat it as a JSON array, which is how
	 * json_decode(..., true) would have produced it from `[]`.
	 *
	 * @param array<mixed> $value
	 */
	private function isList( array $value ): bool {
		return array() === $value || array_is_list( $value );
	}

	private function fail( string $pointer, string $keyword, string $message ): void {
		$this->errors[] = array(
			'pointer' => '' === $pointer ? '/' : $pointer,
			'keyword' => $keyword,
			'message' => $message,
		);
	}
}
