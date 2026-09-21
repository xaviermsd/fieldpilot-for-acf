<?php
/**
 * Where an operation should act.
 *
 * A target is a *request*, not a resolution: it holds whatever the developer or AI
 * wrote. Turning it into exactly one node of a tree is the Resolver's job, and it
 * either succeeds unambiguously or raises.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class Target implements \JsonSerializable {

	/**
	 * @param string       $group  Field group key, or title. A key is preferred and
	 *                             is detected by the `group_` prefix.
	 * @param list<string> $path   Human path from the group root, by label or name,
	 *                             e.g. ['Agent', 'Contact'].
	 * @param string|null  $field  A specific field by key, name or label.
	 * @param string|null  $layout A flexible-content layout by key or name.
	 */
	public function __construct(
		public readonly string $group,
		public readonly array $path = array(),
		public readonly ?string $field = null,
		public readonly ?string $layout = null,
	) {}

	/**
	 * @param array<string,mixed>|string $raw Normalised target node, or a bare group ref.
	 */
	public static function fromArray( array|string $raw ): self {
		if ( is_string( $raw ) ) {
			return new self( $raw );
		}

		$path = $raw['path'] ?? array();

		if ( is_string( $path ) ) {
			// Tolerate "Agent > Contact" and "Agent.Contact" - AI output does this.
			$path = preg_split( '/\s*(?:>|›|\.|\/)\s*/u', $path, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		}

		return new self(
			group: (string) ( $raw['field_group'] ?? $raw['group'] ?? '' ),
			path: array_values( array_map( 'strval', (array) $path ) ),
			field: isset( $raw['field'] ) ? (string) $raw['field'] : null,
			layout: isset( $raw['layout'] ) ? (string) $raw['layout'] : null,
		);
	}

	public function groupLooksLikeKey(): bool {
		return str_starts_with( $this->group, 'group_' );
	}

	public function isGroupRoot(): bool {
		return array() === $this->path && null === $this->field && null === $this->layout;
	}

	public function describe(): string {
		$parts = array_merge( array( $this->group ), $this->path );

		if ( null !== $this->layout ) {
			$parts[] = '[' . $this->layout . ']';
		}

		if ( null !== $this->field ) {
			$parts[] = $this->field;
		}

		return implode( ' › ', $parts );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array_filter(
			array(
				'field_group' => $this->group,
				'path'        => $this->path,
				'field'       => $this->field,
				'layout'      => $this->layout,
			),
			static fn( $v ): bool => null !== $v && array() !== $v
		);
	}
}
