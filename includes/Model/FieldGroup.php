<?php
/**
 * An immutable ACF field group.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class FieldGroup implements \JsonSerializable {

	/** Identity and bookkeeping keys that are not user-facing settings. */
	public const RESERVED = array( 'ID', 'key', 'title', 'fields', 'local', '_valid' );

	/** Settings ACF churns without user intent. */
	public const VOLATILE = array( 'modified', 'ID', 'local', '_valid' );

	/**
	 * @param list<Field>         $fields   Top-level fields, in order.
	 * @param array<string,mixed> $settings location, menu_order, position, style, ...
	 */
	public function __construct(
		public readonly ?string $key,
		public readonly string $title,
		public readonly array $fields = array(),
		public readonly array $settings = array(),
		public readonly ?int $acfId = null,
	) {}

	/**
	 * @param array<string,mixed> $raw ACF field group array, export or stored shape.
	 */
	public static function fromAcfArray( array $raw ): self {
		$key = isset( $raw['key'] ) && '' !== $raw['key'] ? (string) $raw['key'] : null;

		$fields = array();
		foreach ( $raw['fields'] ?? array() as $field ) {
			if ( is_array( $field ) ) {
				$fields[] = Field::fromAcfArray( $field, $key );
			}
		}

		$settings = $raw;
		foreach ( self::RESERVED as $reserved ) {
			unset( $settings[ $reserved ] );
		}

		return new self(
			key: $key,
			title: (string) ( $raw['title'] ?? '' ),
			fields: $fields,
			settings: $settings,
			acfId: isset( $raw['ID'] ) && $raw['ID'] ? (int) $raw['ID'] : null,
		);
	}

	/**
	 * @param list<Field> $fields
	 */
	public function withFields( array $fields ): self {
		return new self( $this->key, $this->title, $fields, $this->settings, $this->acfId );
	}

	/**
	 * Merge only the named group settings; everything else is preserved.
	 *
	 * @param array<string,mixed> $changes
	 */
	public function withSettings( array $changes ): self {
		$title = $this->title;

		if ( array_key_exists( 'title', $changes ) ) {
			$title = (string) $changes['title'];
			unset( $changes['title'] );
		}

		foreach ( self::RESERVED as $reserved ) {
			unset( $changes[ $reserved ] );
		}

		return new self( $this->key, $title, $this->fields, array_merge( $this->settings, $changes ), $this->acfId );
	}

	public function withKey( string $key ): self {
		return new self( $key, $this->title, $this->fields, $this->settings, $this->acfId );
	}

	/**
	 * Native ACF export shape.
	 *
	 * @return array<string,mixed>
	 */
	public function toAcfArray(): array {
		$out = $this->settings;

		$out['key']    = $this->key ?? '';
		$out['title']  = $this->title;
		$out['fields'] = array_map( static fn( Field $f ): array => $f->toAcfArray(), $this->fields );

		return $out;
	}

	/**
	 * Group-level configuration hash, excluding fields and volatile keys.
	 */
	public function fingerprint(): string {
		$material = $this->settings;

		foreach ( self::VOLATILE as $volatile ) {
			unset( $material[ $volatile ] );
		}

		ksort( $material );

		return hash( 'sha256', (string) wp_json_encode( array( 'title' => $this->title, 'settings' => $material ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return $this->toAcfArray();
	}
}
