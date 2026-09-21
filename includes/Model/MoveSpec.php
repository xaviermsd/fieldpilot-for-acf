<?php
/**
 * A request to relocate an existing field.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class MoveSpec implements \JsonSerializable {

	public const POSITION_FIRST  = 'first';
	public const POSITION_LAST   = 'last';
	public const POSITION_BEFORE = 'before';
	public const POSITION_AFTER  = 'after';

	/**
	 * @param string      $field    The field to move, by key/name/label.
	 * @param Target|null $to       New parent. Null keeps the current parent and only reorders.
	 * @param string      $position first|last|before|after.
	 * @param string|null $anchor   Sibling reference, required for before|after.
	 */
	public function __construct(
		public readonly string $field,
		public readonly ?Target $to = null,
		public readonly string $position = self::POSITION_LAST,
		public readonly ?string $anchor = null,
	) {}

	/**
	 * @param array<string,mixed> $raw
	 */
	public static function fromArray( array $raw ): self {
		$to = null;

		if ( isset( $raw['to'] ) && ( is_array( $raw['to'] ) || is_string( $raw['to'] ) ) ) {
			$to = Target::fromArray( $raw['to'] );
		}

		return new self(
			field: (string) ( $raw['field'] ?? '' ),
			to: $to,
			position: (string) ( $raw['position'] ?? self::POSITION_LAST ),
			anchor: isset( $raw['anchor'] ) ? (string) $raw['anchor'] : null,
		);
	}

	public function needsAnchor(): bool {
		return in_array( $this->position, array( self::POSITION_BEFORE, self::POSITION_AFTER ), true );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array_filter(
			array(
				'field'    => $this->field,
				'to'       => $this->to?->jsonSerialize(),
				'position' => $this->position,
				'anchor'   => $this->anchor,
			),
			static fn( $v ): bool => null !== $v
		);
	}
}
