<?php
/**
 * One journal row: a batch that was applied (or attempted).
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Journal;

defined( 'ABSPATH' ) || exit;

final class Entry implements \JsonSerializable {

	public const STATUS_APPLIED     = 'applied';
	public const STATUS_FAILED      = 'failed';
	public const STATUS_ROLLED_BACK = 'rolled_back';
	public const STATUS_REVERTED    = 'reverted';

	/**
	 * @param array<string,mixed> $changeset Decoded ChangeSet as stored.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $batchId,
		public readonly string $createdAt,
		public readonly int $userId,
		public readonly string $source,
		public readonly string $operation,
		public readonly string $groupKey,
		public readonly string $groupTitle,
		public readonly string $beforeHash,
		public readonly ?string $afterHash,
		public readonly array $changeset,
		public readonly int $changeCount,
		public readonly string $status,
		public readonly ?string $message = null,
		public readonly ?string $rolledBackAt = null,
		public readonly ?int $rolledBackBy = null,
	) {}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function fromRow( array $row ): self {
		$changeset = json_decode( (string) ( $row['changeset'] ?? '[]' ), true );

		return new self(
			id: (int) $row['id'],
			batchId: (string) $row['batch_id'],
			createdAt: (string) $row['created_at'],
			userId: (int) $row['user_id'],
			source: (string) $row['source'],
			operation: (string) $row['operation'],
			groupKey: (string) $row['group_key'],
			groupTitle: (string) $row['group_title'],
			beforeHash: (string) $row['before_hash'],
			afterHash: isset( $row['after_hash'] ) ? (string) $row['after_hash'] : null,
			changeset: is_array( $changeset ) ? $changeset : array(),
			changeCount: (int) $row['change_count'],
			status: (string) $row['status'],
			message: isset( $row['message'] ) ? (string) $row['message'] : null,
			rolledBackAt: isset( $row['rolled_back_at'] ) ? (string) $row['rolled_back_at'] : null,
			rolledBackBy: isset( $row['rolled_back_by'] ) ? (int) $row['rolled_back_by'] : null,
		);
	}

	public function isRollbackable(): bool {
		return self::STATUS_APPLIED === $this->status && '' !== $this->beforeHash;
	}

	public function userName(): string {
		$user = get_userdata( $this->userId );

		return $user ? $user->display_name : __( 'Unknown', 'fieldpilot-for-acf' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'id'            => $this->id,
			'batch_id'      => $this->batchId,
			'created_at'    => $this->createdAt,
			'user_id'       => $this->userId,
			'user_name'     => $this->userName(),
			'source'        => $this->source,
			'operation'     => $this->operation,
			'group_key'     => $this->groupKey,
			'group_title'   => $this->groupTitle,
			'change_count'  => $this->changeCount,
			'status'        => $this->status,
			'message'       => $this->message,
			'rollbackable'  => $this->isRollbackable(),
			'rolled_back_at' => $this->rolledBackAt,
			'changeset'     => $this->changeset,
		);
	}
}
