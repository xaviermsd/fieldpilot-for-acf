<?php
/**
 * The single entry point. Admin, REST and WP-CLI all come through here.
 *
 * One pipeline, no side doors. If a feature cannot be expressed as plan() then
 * apply(), it does not belong in this plugin - that constraint is what keeps the
 * preview honest and the journal complete.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

use ACFJP\Acf\GroupLocator;
use ACFJP\Acf\TreeReader;
use ACFJP\Diff\Comparator;
use ACFJP\Exceptions\ApplyException;
use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\ResolutionException;
use ACFJP\Journal\Entry;
use ACFJP\Journal\Journal;
use ACFJP\Journal\SnapshotStore;
use ACFJP\Json\Normalizer;
use ACFJP\Json\Parser;
use ACFJP\Json\Report;
use ACFJP\Json\Validator;
use ACFJP\Model\FieldGroup;
use ACFJP\Model\Operation;
use ACFJP\Model\Payload;
use ACFJP\Model\RawTree;
use ACFJP\Resolve\Locus;
use ACFJP\Resolve\TargetResolver;

defined( 'ABSPATH' ) || exit;

final class Engine {

	public function __construct(
		private readonly Parser $parser,
		private readonly Normalizer $normalizer,
		private readonly Validator $validator,
		private readonly GroupLocator $groups,
		private readonly TreeReader $reader,
		private readonly TargetResolver $resolver,
		private readonly Comparator $comparator,
		private readonly Guard $guard,
		private readonly Writer $writer,
		private readonly Verifier $verifier,
		private readonly Restore $restore,
		private readonly SnapshotStore $snapshots,
		private readonly Journal $journal,
		private readonly PlanStore $plans,
	) {}

	// ---- Validate only -------------------------------------------------------

	/**
	 * Parse, normalise and validate without reading ACF state.
	 *
	 * @param string|array<string,mixed> $input
	 */
	public function validate( string|array $input, ?Operation $defaultOperation = null ): Report {
		$payload = $this->toPayload( $input, $defaultOperation );

		return $this->validator->validate( $payload );
	}

	// ---- Plan ----------------------------------------------------------------

	/**
	 * Compute what would change. Reads ACF; writes nothing.
	 *
	 * @param string|array<string,mixed> $input
	 * @param array<string,mixed>        $options
	 * @throws ResolutionException
	 */
	public function plan( string|array $input, ?Operation $defaultOperation = null, array $options = array() ): Plan {
		$payload = $this->toPayload( $input, $defaultOperation )->withOptions( $options );
		$report  = $this->validator->validate( $payload );

		if ( ! $report->isValid() ) {
			// Return the invalid plan rather than throwing: the caller wants the
			// full list of problems, and an AI regenerating its JSON needs them all.
			return new Plan(
				id: PlanStore::newId(),
				changeSet: new \ACFJP\Diff\ChangeSet( '', '', $payload->operation->value ),
				report: $report,
				stateHash: '',
				meta: array( 'dialect' => $payload->dialect ),
			);
		}

		[ $tree, $isNew ] = $this->treeFor( $payload );

		$locus = $this->locusFor( $tree, $payload );

		$changeSet = $this->comparator->compare( $tree, $payload, $locus );

		if ( array() !== $payload->resolutions() ) {
			$changeSet = $changeSet->withResolutions( $payload->resolutions() );
		}

		$plan = new Plan(
			id: PlanStore::newId(),
			changeSet: $changeSet,
			report: $report,
			stateHash: $tree->stateHash(),
			meta: array(
				'dialect'      => $payload->dialect,
				'operation'    => $payload->operation->value,
				'is_new_group' => $isNew,
				'target'       => $payload->target?->jsonSerialize(),
				'locus'        => $locus->jsonSerialize(),
			),
		);

		$this->plans->put( $plan );

		return $plan;
	}

	// ---- Apply ---------------------------------------------------------------

	/**
	 * Apply a previously computed plan.
	 *
	 * @param array<string,string> $resolutions conflict id => option id
	 * @throws ApplyException
	 */
	public function apply( string $planId, array $resolutions = array(), bool $confirmed = false, string $source = 'admin' ): ApplyResult {
		$plan      = $this->plans->get( $planId );
		$changeSet = $plan->changeSet;

		if ( array() !== $resolutions ) {
			$changeSet = $changeSet->withResolutions( $resolutions );
		}

		if ( $changeSet->isEmpty() ) {
			throw new ApplyException(
				ErrorCodes::EMPTY_PAYLOAD,
				__( 'There is nothing to apply.', 'fieldpilot-for-acf' )
			);
		}

		$isNewGroup = (bool) ( $plan->meta['is_new_group'] ?? false );

		$current = $isNewGroup
			? $this->emptyTree( $changeSet->groupKey, $changeSet->groupTitle )
			: $this->reader->readRaw( $changeSet->groupKey, true );

		// Guard runs before the snapshot: a batch that cannot succeed never begins.
		$this->guard->assertCanApply(
			$changeSet,
			$current,
			$confirmed,
			$isNewGroup ? null : $plan->stateHash
		);

		$batchId    = wp_generate_uuid4();
		$beforeHash = '';

		if ( ! $isNewGroup ) {
			$beforeHash = $this->snapshots->put( $this->reader->exportArray( $changeSet->groupKey ) );
		}

		try {
			$write = $this->writer->write( $changeSet, $current );
		} catch ( \Throwable $e ) {
			$this->rollbackAfterFailure( $beforeHash, $changeSet, $batchId, $source, $e->getMessage() );

			throw $e instanceof ApplyException
				? $e
				: new ApplyException(
					ErrorCodes::WRITE_FAILED,
					$e->getMessage(),
					array( 'batch_id' => $batchId ),
					array(),
					null,
					$e
				);
		}

		$failures = $this->verifier->verify( $changeSet, $write );

		if ( array() !== $failures ) {
			$this->rollbackAfterFailure( $beforeHash, $changeSet, $batchId, $source, implode( ' ', $failures ) );

			throw new ApplyException(
				ErrorCodes::VERIFY_FAILED,
				__( 'The changes did not save correctly, so everything was rolled back. Nothing on your site was altered.', 'fieldpilot-for-acf' ),
				array( 'batch_id' => $batchId, 'failures' => $failures ),
				$failures
			);
		}

		$this->reader->forget( $changeSet->groupKey );
		$this->groups->forget();

		$afterHash = $this->snapshots->put( $this->reader->exportArray( $changeSet->groupKey ) );

		$journalId = $this->journal->record(
			$batchId,
			$changeSet,
			$beforeHash,
			$afterHash,
			$source
		);

		$this->plans->forget( $planId );

		/**
		 * Fires after a batch has been applied and verified.
		 *
		 * @param ApplyResult $result
		 */
		$result = new ApplyResult(
			applied: true,
			batchId: $batchId,
			journalId: $journalId,
			changeSet: $changeSet,
			write: $write,
			beforeHash: $beforeHash,
			afterHash: $afterHash,
			warnings: array_map(
				static fn( $issue ): string => $issue->message,
				$plan->report->warnings()
			),
		);

		do_action( 'acfjp/applied', $result );

		return $result;
	}

	/**
	 * Parse, plan and apply in one call. Used by WP-CLI and by REST callers that
	 * have already decided. Still takes a snapshot and still verifies.
	 *
	 * @param string|array<string,mixed> $input
	 * @param array<string,string>       $resolutions
	 * @throws ApplyException
	 */
	public function run( string|array $input, ?Operation $defaultOperation = null, array $resolutions = array(), bool $confirmed = false, string $source = 'cli' ): ApplyResult {
		$plan = $this->plan( $input, $defaultOperation, array( 'resolutions' => $resolutions ) );

		if ( ! $plan->report->isValid() ) {
			$first = $plan->report->errors()[0] ?? null;

			throw new ApplyException(
				null === $first ? ErrorCodes::INVALID_FIELD : $first->code,
				null === $first ? __( 'The payload is not valid.', 'fieldpilot-for-acf' ) : $first->message,
				array( 'errors' => array_map( static fn( $i ): array => $i->jsonSerialize(), $plan->report->errors() ) )
			);
		}

		return $this->apply( $plan->id, $resolutions, $confirmed, $source );
	}

	// ---- Rollback ------------------------------------------------------------

	/**
	 * @throws ApplyException
	 */
	public function rollback( int $journalId, string $source = 'admin' ): Entry {
		$this->guard->assertWritable();
		$this->guard->assertCapability();

		$entry = $this->journal->get( $journalId );

		if ( null === $entry ) {
			throw new ApplyException(
				ErrorCodes::SNAPSHOT_NOT_FOUND,
				__( 'That history entry no longer exists.', 'fieldpilot-for-acf' ),
				array( 'journal_id' => $journalId )
			);
		}

		if ( Entry::STATUS_ROLLED_BACK === $entry->status ) {
			throw new ApplyException(
				ErrorCodes::ALREADY_ROLLED_BACK,
				__( 'This change has already been rolled back.', 'fieldpilot-for-acf' ),
				array( 'journal_id' => $journalId )
			);
		}

		if ( ! $entry->isRollbackable() ) {
			throw new ApplyException(
				ErrorCodes::SNAPSHOT_NOT_FOUND,
				__( 'No snapshot was stored for this change, so it cannot be rolled back automatically.', 'fieldpilot-for-acf' ),
				array( 'journal_id' => $journalId )
			);
		}

		$this->guard->assertMutable( $entry->groupKey );

		// A rollback is itself a change, so it gets its own snapshot. Rolling back
		// a rollback therefore works.
		$currentHash = $this->snapshots->put( $this->reader->exportArray( $entry->groupKey ) );

		$this->restore->fromHash( $entry->beforeHash );

		$this->journal->markRolledBack( $journalId );

		$reverted = new \ACFJP\Diff\ChangeSet(
			groupKey: $entry->groupKey,
			groupTitle: $entry->groupTitle,
			operation: 'rollback',
			changes: array(),
			beforeHash: $currentHash,
		);

		$this->journal->record(
			wp_generate_uuid4(),
			$reverted,
			$currentHash,
			$entry->beforeHash,
			$source,
			Entry::STATUS_REVERTED,
			sprintf(
				/* translators: %d: journal entry id */
				__( 'Rolled back change #%d.', 'fieldpilot-for-acf' ),
				$journalId
			)
		);

		$this->reader->forget( $entry->groupKey );

		return $entry;
	}

	// ---- Internals -----------------------------------------------------------

	/**
	 * @param string|array<string,mixed> $input
	 */
	private function toPayload( string|array $input, ?Operation $defaultOperation ): Payload {
		$raw = is_string( $input ) ? $this->parser->parse( $input ) : $input;

		return $this->normalizer->normalize( $raw, $defaultOperation );
	}

	/**
	 * @return array{0:RawTree,1:bool} The tree and whether the group is new.
	 * @throws ResolutionException
	 */
	private function treeFor( Payload $payload ): array {
		$reference = $payload->target?->group ?? $payload->group?->title ?? '';

		if ( Operation::Create === $payload->operation ) {
			// Creating onto an existing group is almost always a mistake; say so
			// rather than producing a confusing duplicate.
			if ( '' !== $reference && $this->groups->exists( $reference ) ) {
				throw new ResolutionException(
					ErrorCodes::DUPLICATE_NAME,
					sprintf(
						/* translators: %s: field group title */
						__( 'A field group called "%s" already exists. Use "add", "update" or "merge" to change it.', 'fieldpilot-for-acf' ),
						$reference
					),
					array( 'reference' => $reference ),
					array( 'add', 'update', 'merge', 'sync', 'replace' )
				);
			}

			$group = $payload->group;

			return array(
				$this->emptyTree(
					$group?->key ?? '',
					$group?->title ?? $reference
				),
				true,
			);
		}

		$groupKey = $this->groups->locate( $reference );

		$this->guard->assertMutable( $groupKey );

		return array( $this->reader->readRaw( $groupKey, true ), false );
	}

	/**
	 * @throws ResolutionException
	 */
	private function locusFor( RawTree $tree, Payload $payload ): Locus {
		if ( null === $payload->target || $payload->target->isGroupRoot() ) {
			return new Locus( null, null, $tree->group->title );
		}

		return $this->resolver->resolveLocus( $tree, $payload->target );
	}

	private function emptyTree( string $key, string $title ): RawTree {
		return new RawTree( new FieldGroup( '' !== $key ? $key : null, $title ) );
	}

	private function rollbackAfterFailure( string $beforeHash, \ACFJP\Diff\ChangeSet $changeSet, string $batchId, string $source, string $message ): void {
		if ( '' !== $beforeHash ) {
			try {
				$this->restore->fromHash( $beforeHash );
			} catch ( \Throwable $restoreError ) {
				$message .= ' ' . $restoreError->getMessage();
			}
		}

		$this->journal->record(
			$batchId,
			$changeSet,
			$beforeHash,
			null,
			$source,
			Entry::STATUS_FAILED,
			$message
		);

		$this->reader->forget( $changeSet->groupKey );
	}
}
