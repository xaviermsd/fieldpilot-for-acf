<?php
/**
 * One self-test result.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Check implements \JsonSerializable {

	public const PASS = 'pass';
	public const FAIL = 'fail';
	public const SKIP = 'skip';

	public function __construct(
		public readonly string $section,
		public readonly string $name,
		public readonly string $status,
		public readonly string $detail = '',
		public readonly bool $critical = false,
	) {}

	public static function pass( string $section, string $name, string $detail = '' ): self {
		return new self( $section, $name, self::PASS, $detail );
	}

	public static function fail( string $section, string $name, string $detail = '', bool $critical = false ): self {
		return new self( $section, $name, self::FAIL, $detail, $critical );
	}

	public static function skip( string $section, string $name, string $why ): self {
		return new self( $section, $name, self::SKIP, $why );
	}

	/**
	 * Assert equality, producing a pass or a descriptive fail.
	 */
	public static function is( string $section, string $name, mixed $actual, mixed $expected, bool $critical = false ): self {
		if ( $actual === $expected ) {
			return self::pass( $section, $name );
		}

		return self::fail(
			$section,
			$name,
			sprintf(
				/* translators: 1: expected value, 2: actual value */
				__( 'Expected %1$s, got %2$s.', 'fieldpilot-for-acf' ),
				self::show( $expected ),
				self::show( $actual )
			),
			$critical
		);
	}

	public static function true( string $section, string $name, bool $condition, string $detail = '', bool $critical = false ): self {
		return $condition ? self::pass( $section, $name ) : self::fail( $section, $name, $detail, $critical );
	}

	public function passed(): bool {
		return self::PASS === $this->status;
	}

	public function failed(): bool {
		return self::FAIL === $this->status;
	}

	public static function show( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return gettype( $value ) . '(' . ( is_countable( $value ) ? count( $value ) : '?' ) . ')';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'section'  => $this->section,
			'name'     => $this->name,
			'status'   => $this->status,
			'detail'   => $this->detail,
			'critical' => $this->critical,
		);
	}
}
