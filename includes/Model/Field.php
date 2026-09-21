<?php
/**
 * An immutable ACF field.
 *
 * Identity (key/name/label/type) is separated from `settings` deliberately: the
 * partial-update guarantee is implemented as "merge only the named settings", and
 * that is far harder to get wrong when identity cannot accidentally be part of the
 * merge.
 *
 * `children` and `layouts` are OUR representation of nesting. They are NOT written
 * back into the parent's stored array - see docs/ARCHITECTURE-REVIEW.md B.3 for why
 * that would corrupt a field group.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Model;

defined( 'ABSPATH' ) || exit;

final class Field implements \JsonSerializable {

	/**
	 * Keys that are identity or ACF bookkeeping, never user-facing settings.
	 * Anything here is stripped out of `settings` on construction and re-applied
	 * from the typed properties on output.
	 */
	public const RESERVED = array(
		'ID',
		'key',
		'name',
		'label',
		'type',
		'parent',
		'parent_layout',
		'parent_repeater',
		'menu_order',
		'sub_fields',
		'layouts',
		'prefix',
		'value',
		'_name',
		'_valid',
		'_prepare',
		'class',
		'id',
	);

	/**
	 * Settings excluded from diffing because ACF or WordPress owns them and they
	 * change without user intent.
	 */
	public const VOLATILE = array( 'modified', '_name', '_valid', '_prepare', 'ID', 'parent', 'parent_repeater', 'prefix' );

	/**
	 * @param list<Field>         $children Sub-fields, for group/repeater.
	 * @param list<Layout>        $layouts  Layouts, for flexible_content.
	 * @param array<string,mixed> $settings Every non-reserved ACF setting.
	 */
	public function __construct(
		public readonly ?string $key,
		public readonly string $name,
		public readonly string $label,
		public readonly string $type,
		public readonly array $settings = array(),
		public readonly array $children = array(),
		public readonly array $layouts = array(),
		public readonly ?int $acfId = null,
		public readonly ?string $parentKey = null,
		public readonly ?string $parentLayout = null,
		public readonly ?int $menuOrder = null,
	) {}

	/**
	 * Build from an ACF field array, in either stored or exported shape.
	 *
	 * @param array<string,mixed> $raw
	 */
	public static function fromAcfArray( array $raw, ?string $parentKey = null, ?string $parentLayout = null ): self {
		$type = (string) ( $raw['type'] ?? 'text' );
		$key  = isset( $raw['key'] ) && '' !== $raw['key'] ? (string) $raw['key'] : null;

		$children = array();
		if ( FieldKind::hasSubFields( $type ) ) {
			foreach ( $raw['sub_fields'] ?? array() as $sub ) {
				if ( is_array( $sub ) ) {
					$children[] = self::fromAcfArray( $sub, $key );
				}
			}
		}

		$layouts = array();
		if ( FieldKind::hasLayouts( $type ) ) {
			// ACF stores layouts as an associative array keyed by layout key.
			foreach ( $raw['layouts'] ?? array() as $layout ) {
				if ( is_array( $layout ) ) {
					$layouts[] = Layout::fromAcfArray( $layout, $key );
				}
			}
		}

		$settings = $raw;
		foreach ( self::RESERVED as $reserved ) {
			unset( $settings[ $reserved ] );
		}

		return new self(
			key: $key,
			name: (string) ( $raw['name'] ?? '' ),
			label: (string) ( $raw['label'] ?? '' ),
			type: $type,
			settings: $settings,
			children: $children,
			layouts: $layouts,
			acfId: isset( $raw['ID'] ) && $raw['ID'] ? (int) $raw['ID'] : null,
			parentKey: $parentKey ?? ( isset( $raw['parent'] ) && ! is_numeric( $raw['parent'] ) ? (string) $raw['parent'] : null ),
			parentLayout: $parentLayout ?? ( isset( $raw['parent_layout'] ) ? (string) $raw['parent_layout'] : null ),
			menuOrder: isset( $raw['menu_order'] ) ? (int) $raw['menu_order'] : null,
		);
	}

	// ---- Predicates ----------------------------------------------------------

	public function isContainer(): bool {
		return FieldKind::isContainer( $this->type );
	}

	public function isStructural(): bool {
		return FieldKind::isStructural( $this->type );
	}

	public function isReference(): bool {
		return FieldKind::isReference( $this->type );
	}

	public function storesValue(): bool {
		return FieldKind::storesValue( $this->type );
	}

	/**
	 * Every descendant, depth-first, including layout sub-fields.
	 *
	 * @return list<Field>
	 */
	public function descendants(): array {
		$out = array();

		foreach ( $this->children as $child ) {
			$out[] = $child;
			$out   = array_merge( $out, $child->descendants() );
		}

		foreach ( $this->layouts as $layout ) {
			foreach ( $layout->subFields as $sub ) {
				$out[] = $sub;
				$out   = array_merge( $out, $sub->descendants() );
			}
		}

		return $out;
	}

	// ---- Immutable transforms ------------------------------------------------

	public function withKey( string $key ): self {
		return $this->copy( array( 'key' => $key ) );
	}

	public function withParent( ?string $parentKey, ?string $parentLayout = null ): self {
		return $this->copy( array( 'parentKey' => $parentKey, 'parentLayout' => $parentLayout ) );
	}

	public function withMenuOrder( int $order ): self {
		return $this->copy( array( 'menuOrder' => $order ) );
	}

	/**
	 * @param list<Field> $children
	 */
	public function withChildren( array $children ): self {
		return $this->copy( array( 'children' => $children ) );
	}

	/**
	 * @param list<Layout> $layouts
	 */
	public function withLayouts( array $layouts ): self {
		return $this->copy( array( 'layouts' => $layouts ) );
	}

	/**
	 * Merge the named settings over the existing ones. THE partial-update primitive.
	 * Settings absent from $changes are preserved exactly.
	 *
	 * @param array<string,mixed> $changes
	 */
	public function withSettings( array $changes ): self {
		$identity = array();

		foreach ( array( 'label', 'name', 'type' ) as $prop ) {
			if ( array_key_exists( $prop, $changes ) ) {
				$identity[ $prop ] = (string) $changes[ $prop ];
				unset( $changes[ $prop ] );
			}
		}

		foreach ( self::RESERVED as $reserved ) {
			unset( $changes[ $reserved ] );
		}

		return $this->copy( $identity + array( 'settings' => array_merge( $this->settings, $changes ) ) );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function copy( array $overrides ): self {
		return new self(
			key: $overrides['key'] ?? $this->key,
			name: $overrides['name'] ?? $this->name,
			label: $overrides['label'] ?? $this->label,
			type: $overrides['type'] ?? $this->type,
			settings: $overrides['settings'] ?? $this->settings,
			children: $overrides['children'] ?? $this->children,
			layouts: $overrides['layouts'] ?? $this->layouts,
			acfId: $overrides['acfId'] ?? $this->acfId,
			parentKey: array_key_exists( 'parentKey', $overrides ) ? $overrides['parentKey'] : $this->parentKey,
			parentLayout: array_key_exists( 'parentLayout', $overrides ) ? $overrides['parentLayout'] : $this->parentLayout,
			menuOrder: $overrides['menuOrder'] ?? $this->menuOrder,
		);
	}

	// ---- Output --------------------------------------------------------------

	/**
	 * Export shape: nested, with children inline. Suitable for ACF's importer and
	 * for our own exports. NOT the shape written to a single acf-field post.
	 *
	 * @return array<string,mixed>
	 */
	public function toAcfArray(): array {
		$out = $this->settings;

		$out['key']   = $this->key ?? '';
		$out['label'] = $this->label;
		$out['name']  = $this->name;
		$out['type']  = $this->type;

		if ( null !== $this->menuOrder ) {
			$out['menu_order'] = $this->menuOrder;
		}

		if ( null !== $this->parentLayout ) {
			$out['parent_layout'] = $this->parentLayout;
		}

		if ( FieldKind::hasSubFields( $this->type ) ) {
			$out['sub_fields'] = array_map( static fn( Field $f ): array => $f->toAcfArray(), $this->children );
		}

		if ( FieldKind::hasLayouts( $this->type ) ) {
			$layouts = array();
			foreach ( $this->layouts as $layout ) {
				$layouts[ $layout->key ?? $layout->name ] = $layout->toAcfArray();
			}
			$out['layouts'] = $layouts;
		}

		return $out;
	}

	/**
	 * Stable content hash of this field's own configuration, excluding identity,
	 * children and volatile keys. Used to detect "nothing actually changed".
	 */
	public function fingerprint(): string {
		$material = $this->settings;

		foreach ( self::VOLATILE as $volatile ) {
			unset( $material[ $volatile ] );
		}

		ksort( $material );

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'name'     => $this->name,
					'label'    => $this->label,
					'type'     => $this->type,
					'settings' => $material,
				)
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return $this->toAcfArray();
	}
}
