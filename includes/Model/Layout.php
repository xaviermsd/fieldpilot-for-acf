<?php
/**
 * One Flexible Content layout.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class Layout implements \JsonSerializable {

	/**
	 * @param list<Field>         $subFields Fields belonging to this layout.
	 * @param array<string,mixed> $settings  Remaining layout settings (min, max, display...).
	 */
	public function __construct(
		public readonly ?string $key,
		public readonly string $name,
		public readonly string $label,
		public readonly array $subFields = array(),
		public readonly array $settings = array(),
	) {}

	/**
	 * @param array<string,mixed> $raw ACF layout array.
	 */
	public static function fromAcfArray( array $raw, ?string $ownerKey = null ): self {
		$subFields = array();

		foreach ( $raw['sub_fields'] ?? array() as $sub ) {
			if ( is_array( $sub ) ) {
				$subFields[] = Field::fromAcfArray( $sub, $ownerKey, isset( $raw['key'] ) ? (string) $raw['key'] : null );
			}
		}

		$settings = $raw;
		unset( $settings['key'], $settings['name'], $settings['label'], $settings['sub_fields'] );

		return new self(
			key: isset( $raw['key'] ) ? (string) $raw['key'] : null,
			name: (string) ( $raw['name'] ?? '' ),
			label: (string) ( $raw['label'] ?? '' ),
			subFields: $subFields,
			settings: $settings,
		);
	}

	/**
	 * @param list<Field> $subFields
	 */
	public function withSubFields( array $subFields ): self {
		return new self( $this->key, $this->name, $this->label, $subFields, $this->settings );
	}

	public function withKey( string $key ): self {
		return new self( $key, $this->name, $this->label, $this->subFields, $this->settings );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toAcfArray(): array {
		$out = $this->settings;

		if ( null !== $this->key ) {
			$out['key'] = $this->key;
		}

		$out['name']  = $this->name;
		$out['label'] = $this->label;

		$out['sub_fields'] = array_map(
			static fn( Field $f ): array => $f->toAcfArray(),
			$this->subFields
		);

		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return $this->toAcfArray();
	}
}
