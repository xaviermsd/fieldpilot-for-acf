<?php
/**
 * The outcome of applying a plan.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

use ACFJP\Diff\ChangeSet;

defined( 'ABSPATH' ) || exit;

final class ApplyResult implements \JsonSerializable {

	/**
	 * @param list<string> $warnings
	 */
	public function __construct(
		public readonly bool $applied,
		public readonly string $batchId,
		public readonly int $journalId,
		public readonly ChangeSet $changeSet,
		public readonly WriteResult $write,
		public readonly string $beforeHash,
		public readonly ?string $afterHash,
		public readonly array $warnings = array(),
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'applied'     => $this->applied,
			'batch_id'    => $this->batchId,
			'journal_id'  => $this->journalId,
			'group_key'   => $this->changeSet->groupKey,
			'group_title' => $this->changeSet->groupTitle,
			'operation'   => $this->changeSet->operation,
			'count'       => $this->changeSet->count(),
			'counts'      => $this->changeSet->counts(),
			'write'       => $this->write->jsonSerialize(),
			'before_hash' => $this->beforeHash,
			'after_hash'  => $this->afterHash,
			'warnings'    => $this->warnings,
			'rollback'    => array(
				'available'  => '' !== $this->beforeHash,
				'journal_id' => $this->journalId,
			),
		);
	}
}
