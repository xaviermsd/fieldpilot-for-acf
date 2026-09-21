<?php
/**
 * The result of classifying one field group.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Acf;

defined( 'ABSPATH' ) || exit;

final class MutabilityReport implements \JsonSerializable {

	/**
	 * @param string      $groupKey     The acf field group key.
	 * @param string      $groupTitle   Human title, best effort.
	 * @param Mutability  $mutability   Where the group lives.
	 * @param int|null    $postId       DB post ID, when it has one.
	 * @param string|null $jsonPath     Absolute path to the acf-json file, when one backs it.
	 * @param bool        $syncPending  True when a JSON file is newer than the DB copy.
	 * @param string|null $remedy       Machine remedy hint: 'sync' | 'export_php' | null.
	 */
	public function __construct(
		public readonly string $groupKey,
		public readonly string $groupTitle,
		public readonly Mutability $mutability,
		public readonly ?int $postId = null,
		public readonly ?string $jsonPath = null,
		public readonly bool $syncPending = false,
		public readonly ?string $remedy = null,
	) {}

	public function isPatchable(): bool {
		return $this->mutability->isPatchable();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'group_key'    => $this->groupKey,
			'group_title'  => $this->groupTitle,
			'mutability'   => $this->mutability->value,
			'patchable'    => $this->isPatchable(),
			'post_id'      => $this->postId,
			'json_path'    => $this->jsonPath,
			'sync_pending' => $this->syncPending,
			'remedy'       => $this->remedy,
		);
	}
}
