<?php
/**
 * A resolved container: the exact place fields will be added to or read from.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Resolve;

use ACFJP\Model\Field;

defined( 'ABSPATH' ) || exit;

final class Locus implements \JsonSerializable {

	/**
	 * @param Field|null  $parent    The containing field, or null for the group root.
	 * @param string|null $layoutKey The containing flexible-content layout, if any.
	 * @param string      $display   Human path, for messages and the preview header.
	 */
	public function __construct(
		public readonly ?Field $parent,
		public readonly ?string $layoutKey = null,
		public readonly string $display = '',
	) {}

	public function isGroupRoot(): bool {
		return null === $this->parent;
	}

	public function parentKey(): ?string {
		return $this->parent?->key;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'parent_key' => $this->parentKey(),
			'layout_key' => $this->layoutKey,
			'display'    => $this->display,
		);
	}
}
