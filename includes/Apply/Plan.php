<?php
/**
 * A previewed, not-yet-applied batch.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

use ACFJP\Diff\ChangeSet;
use ACFJP\Json\Report;

defined( 'ABSPATH' ) || exit;

final class Plan implements \JsonSerializable {

	/**
	 * @param array<string,mixed> $meta
	 */
	public function __construct(
		public readonly string $id,
		public readonly ChangeSet $changeSet,
		public readonly Report $report,
		public readonly string $stateHash,
		public readonly array $meta = array(),
	) {}

	public function isApplicable(): bool {
		return $this->report->isValid() && ! $this->changeSet->isEmpty();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'plan_id'    => $this->id,
			'state_hash' => $this->stateHash,
			'applicable' => $this->isApplicable(),
			'validation' => $this->report->jsonSerialize(),
			'changeset'  => $this->changeSet->jsonSerialize(),
			'meta'       => $this->meta,
		);
	}
}
