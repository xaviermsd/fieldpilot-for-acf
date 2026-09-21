<?php
/**
 * What a write actually did.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

defined( 'ABSPATH' ) || exit;

final class WriteResult implements \JsonSerializable {

	/**
	 * @param array<string,int>   $createdKeys  New field key => post ID.
	 * @param list<string>        $updatedKeys
	 * @param list<string>        $deletedKeys
	 * @param list<string>        $skipped      Change ids skipped by conflict resolution.
	 * @param array<string,mixed> $context
	 */
	public function __construct(
		public readonly string $groupKey,
		public readonly int $groupId,
		public readonly array $createdKeys = array(),
		public readonly array $updatedKeys = array(),
		public readonly array $deletedKeys = array(),
		public readonly array $skipped = array(),
		public readonly array $context = array(),
	) {}

	public function total(): int {
		return count( $this->createdKeys ) + count( $this->updatedKeys ) + count( $this->deletedKeys );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'group_key'    => $this->groupKey,
			'group_id'     => $this->groupId,
			'created'      => array_keys( $this->createdKeys ),
			'updated'      => $this->updatedKeys,
			'deleted'      => $this->deletedKeys,
			'skipped'      => $this->skipped,
			'total'        => $this->total(),
			'context'      => $this->context,
		);
	}
}
