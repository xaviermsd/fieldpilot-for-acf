<?php
/**
 * The complete, ordered set of changes a payload would make.
 *
 * This object is the boundary between the pure zone and the effect zone: it is the
 * only thing that crosses. It is hashable, so a preview and a later apply can prove
 * they are talking about the same plan; and it is serialisable, so the journal can
 * store what actually happened rather than a prose summary.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diff;

defined( 'ABSPATH' ) || exit;

final class ChangeSet implements \JsonSerializable, \Countable {

	/**
	 * @param list<Change> $changes
	 */
	public function __construct(
		public readonly string $groupKey,
		public readonly string $groupTitle,
		public readonly string $operation,
		public readonly array $changes = array(),
		public readonly string $beforeHash = '',
	) {}

	public function count(): int {
		return count( $this->changes );
	}

	public function isEmpty(): bool {
		return array() === $this->changes;
	}

	/**
	 * @param list<Change> $changes
	 */
	public function withChanges( array $changes ): self {
		return new self( $this->groupKey, $this->groupTitle, $this->operation, $changes, $this->beforeHash );
	}

	/**
	 * @return list<Change>
	 */
	public function ofType( string $type ): array {
		return array_values( array_filter( $this->changes, static fn( Change $c ): bool => $c->type === $type ) );
	}

	/**
	 * @return array<string,int>
	 */
	public function counts(): array {
		$counts = array();

		foreach ( $this->changes as $change ) {
			$counts[ $change->type ] = ( $counts[ $change->type ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * @return list<Conflict>
	 */
	public function conflicts(): array {
		$conflicts = array();

		foreach ( $this->changes as $change ) {
			if ( null !== $change->conflict ) {
				$conflicts[] = $change->conflict;
			}
		}

		return $conflicts;
	}

	/**
	 * @return list<Conflict>
	 */
	public function unresolvedConflicts(): array {
		return array_values(
			array_filter( $this->conflicts(), static fn( Conflict $c ): bool => ! $c->isResolved() )
		);
	}

	public function highestRisk(): Risk {
		return Risk::highest( array_map( static fn( Change $c ): Risk => $c->risk, $this->changes ) );
	}

	public function isDestructive(): bool {
		return Risk::Destructive === $this->highestRisk();
	}

	/**
	 * Apply the caller's conflict decisions.
	 *
	 * @param array<string,string> $resolutions conflict id => option id
	 */
	public function withResolutions( array $resolutions ): self {
		$changes = array();

		foreach ( $this->changes as $change ) {
			$conflict = $change->conflict;

			if ( null !== $conflict && isset( $resolutions[ $conflict->id ] ) ) {
				$change = $change->withConflict( $conflict->resolveWith( $resolutions[ $conflict->id ] ) );
			}

			$changes[] = $change;
		}

		return $this->withChanges( $changes );
	}

	/**
	 * Identity of the plan. Two ChangeSets with the same hash request exactly the
	 * same thing; the apply endpoint compares this against the stored plan so a
	 * stale preview can never be applied.
	 */
	public function hash(): string {
		$material = array(
			'group'     => $this->groupKey,
			'operation' => $this->operation,
			'before'    => $this->beforeHash,
			'changes'   => array_map(
				static fn( Change $c ): array => array(
					'id'       => $c->id,
					'type'     => $c->type,
					'target'   => $c->targetKey,
					'settings' => $c->settingDiffs,
				),
				$this->changes
			),
		);

		return hash( 'sha256', (string) wp_json_encode( $material ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'group_key'   => $this->groupKey,
			'group_title' => $this->groupTitle,
			'operation'   => $this->operation,
			'before_hash' => $this->beforeHash,
			'hash'        => $this->hash(),
			'count'       => $this->count(),
			'counts'      => $this->counts(),
			'risk'        => $this->highestRisk()->value,
			'changes'     => array_map( static fn( Change $c ): array => $c->jsonSerialize(), $this->changes ),
			'conflicts'   => array_map( static fn( Conflict $c ): array => $c->jsonSerialize(), $this->conflicts() ),
		);
	}
}
