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

use ACFJP\Acf\DataProbe;
use ACFJP\Acf\GroupLocator;
use ACFJP\Acf\TreeReader;
use ACFJP\Diff\Change;
use ACFJP\Diff\ChangeSet;
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

	/** One batch at a time: a second apply waits for the first to finish or time out. */
	public const LOCK_OPTION = 'acfjp_apply_lock';

	/** Seconds after which a lock left by a crashed request is ignored. */
	private const LOCK_TTL = 120;

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
		private readonly ?DataProbe $probe = null,
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
				changeSet: new ChangeSet( '', '', $payload->operation->value ),
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
			$e = new ApplyException(
				ErrorCodes::EMPTY_PAYLOAD,
				__( 'There is nothing to apply.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		$this->acquireLock();

		try {
			$result = $this->applyLocked( $plan, $changeSet, $confirmed, $source );
		} finally {
			$this->releaseLock();
		}

		$this->plans->forget( $planId );

		do_action( 'acfjp_applied', $result );

		return $result;
	}

	/**
	 * Guard, snapshot, write, verify, journal. Anything that throws after the first
	 * write - verification, the after-snapshot, the journal insert - undoes the
	 * batch, so no partial application is ever left behind.
	 *
	 * @throws ApplyException
	 */
	private function applyLocked( Plan $plan, ChangeSet $changeSet, bool $confirmed, string $source ): ApplyResult {
		$isNewGroup = (bool) ( $plan->meta['is_new_group'] ?? false );

		$current = $isNewGroup
			? $this->emptyTree( $changeSet->groupKey, $changeSet->groupTitle )
			: $this->reader->readRaw( $changeSet->groupKey, true );

		// Guard runs before the snapshot: a batch that cannot succeed never begins.
		$this->guard->assertCanApply(
			$changeSet,
			$current,
			$confirmed,
			$isNewGroup ? null : $plan->stateHash,
			$isNewGroup
		);

		$this->assertContentUnchanged( $changeSet );

		$batchId    = wp_generate_uuid4();
		$beforeHash = $isNewGroup ? '' : $this->snapshots->put( $this->reader->snapshotArray( $changeSet->groupKey ) );
		$afterHash  = '';

		try {
			$write    = $this->writer->write( $changeSet, $current );
			$failures = $this->verifier->verify( $changeSet, $write, $current );

			if ( array() !== $failures ) {
				$e = new ApplyException(
					ErrorCodes::VERIFY_FAILED,
					implode( ' ', $failures ),
					array(
						'batch_id' => $batchId,
						'failures' => $failures,
					),
					$failures
				);
				throw $e;
			}

			$this->reader->forget( $write->groupKey );
			$this->groups->forget();

			$recorded  = $changeSet->withGroupKey( $write->groupKey );
			$afterHash = $this->snapshots->put( $this->reader->snapshotArray( $write->groupKey ) );
			$journalId = $this->journal->record( $batchId, $recorded, $beforeHash, $afterHash, $source );
		} catch ( \Throwable $e ) {
			if ( '' !== $afterHash ) {
				$this->snapshots->release( $afterHash );
			}

			$restored = $this->undoFailedBatch( $beforeHash, $changeSet, $batchId, $source, $e->getMessage() );

			$failure = $this->failure( $e, $restored, $isNewGroup, $batchId );
			throw $failure;
		}

		/**
		 * Fires after a batch has been applied and verified.
		 *
		 * @param ApplyResult $result
		 */
		return new ApplyResult(
			applied: true,
			batchId: $batchId,
			journalId: $journalId,
			changeSet: $recorded,
			write: $write,
			beforeHash: $beforeHash,
			afterHash: $afterHash,
			warnings: array_map(
				static fn( $issue ): string => $issue->message,
				$plan->report->warnings()
			),
		);
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

			$e = new ApplyException(
				null === $first ? ErrorCodes::INVALID_FIELD : $first->code,
				null === $first ? __( 'The payload is not valid.', 'fieldpilot-for-acf' ) : $first->message,
				array( 'errors' => array_map( static fn( $i ): array => $i->jsonSerialize(), $plan->report->errors() ) )
			);
			throw $e;
		}

		return $this->apply( $plan->id, $resolutions, $confirmed, $source );
	}

	/**
	 * Create an empty field group through the same pipeline as any other change,
	 * so it is guarded (capability, read-only mode), verified, journaled and can
	 * be rolled back from History.
	 *
	 * @throws ApplyException
	 * @throws ResolutionException
	 */
	public function createGroup( string $title, string $source = 'admin' ): ApplyResult {
		$title = trim( $title );

		if ( '' === $title ) {
			$e = new ApplyException(
				ErrorCodes::MISSING_TARGET,
				__( 'Enter a title for the new field group.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		if ( $this->groups->exists( $title ) ) {
			$e = new ApplyException(
				ErrorCodes::DUPLICATE_NAME,
				sprintf(
					/* translators: %s: field group title */
					__( 'A field group called "%s" already exists. Pick it from the list instead.', 'fieldpilot-for-acf' ),
					$title
				),
				array( 'title' => $title )
			);
			throw $e;
		}

		return $this->run(
			array(
				'operation'   => Operation::Create->value,
				'field_group' => array(
					'title'  => $title,
					'fields' => array(),
				),
			),
			null,
			array(),
			false,
			$source
		);
	}

	// ---- Rollback ------------------------------------------------------------

	/**
	 * Put a field group back to the state before a journaled change.
	 *
	 * Refused while a later change to the same group is still applied: restoring an
	 * older snapshot would silently throw that later change away.
	 *
	 * @throws ApplyException
	 */
	public function rollback( int $journalId, string $source = 'admin' ): Entry {
		$this->guard->assertWritable();
		$this->guard->assertCapability();

		$entry = $this->journal->get( $journalId );

		if ( null === $entry ) {
			$e = new ApplyException(
				ErrorCodes::SNAPSHOT_NOT_FOUND,
				__( 'That history entry no longer exists.', 'fieldpilot-for-acf' ),
				array( 'journal_id' => $journalId )
			);
			throw $e;
		}

		if ( Entry::STATUS_ROLLED_BACK === $entry->status ) {
			$e = new ApplyException(
				ErrorCodes::ALREADY_ROLLED_BACK,
				__( 'This change has already been rolled back.', 'fieldpilot-for-acf' ),
				array( 'journal_id' => $journalId )
			);
			throw $e;
		}

		if ( ! $entry->isRollbackable() ) {
			$e = new ApplyException(
				Entry::STATUS_REVERTED === $entry->status || Entry::STATUS_FAILED === $entry->status ? ErrorCodes::STATE_CHANGED : ErrorCodes::SNAPSHOT_NOT_FOUND,
				match ( $entry->status ) {
					Entry::STATUS_REVERTED => __( 'This entry records a rollback, so there is nothing to undo. To bring those changes back, apply the JSON again.', 'fieldpilot-for-acf' ),
					Entry::STATUS_FAILED   => __( 'This change failed and was undone automatically, so there is nothing to roll back.', 'fieldpilot-for-acf' ),
					default                => __( 'No snapshot was stored for this change, so it cannot be rolled back automatically.', 'fieldpilot-for-acf' ),
				},
				array( 'journal_id' => $journalId )
			);
			throw $e;
		}

		$later = $this->journal->laterChanges( $entry->groupKey, $entry->id );

		if ( array() !== $later ) {
			$e = new ApplyException(
				ErrorCodes::STATE_CHANGED,
				sprintf(
					/* translators: %s: list of history entry numbers, such as "#7, #6" */
					_n(
						'Change %s was applied to this field group after this one. Roll it back first, so it is not lost.',
						'Changes %s were applied to this field group after this one. Roll them back first, newest first, so they are not lost.',
						count( $later ),
						'fieldpilot-for-acf'
					),
					'#' . implode( ', #', $later )
				),
				array(
					'journal_id' => $journalId,
					'later'      => $later,
				)
			);
			throw $e;
		}

		$this->guard->assertMutable( $entry->groupKey );

		$this->acquireLock();

		try {
			$afterHash = $entry->isCreation()
				? $this->rollbackCreation( $entry, $source )
				: $this->rollbackToSnapshot( $entry, $source );
		} finally {
			$this->releaseLock();
		}

		$this->journal->markRolledBack( $journalId );

		$this->journal->record(
			wp_generate_uuid4(),
			new ChangeSet(
				groupKey: $entry->groupKey,
				groupTitle: $entry->groupTitle,
				operation: 'rollback',
				changes: array(),
			),
			$afterHash['before'],
			$afterHash['after'],
			$source,
			Entry::STATUS_REVERTED,
			sprintf(
				/* translators: %d: journal entry id */
				__( 'Rolled back change #%d.', 'fieldpilot-for-acf' ),
				$journalId
			)
		);

		$this->reader->forget( $entry->groupKey );
		$this->groups->forget();

		return $entry;
	}

	/**
	 * A rollback is itself a change, so the state it replaces is snapshotted and
	 * journaled too, and stays on record until retention prunes it.
	 *
	 * @return array{before:string,after:?string}
	 * @throws ApplyException
	 */
	private function rollbackToSnapshot( Entry $entry, string $source ): array {
		$currentHash = $this->snapshots->put( $this->reader->snapshotArray( $entry->groupKey ) );

		try {
			$this->restore->fromHash( $entry->beforeHash );
		} catch ( \Throwable $e ) {
			$this->recordQuietly(
				wp_generate_uuid4(),
				new ChangeSet( $entry->groupKey, $entry->groupTitle, 'rollback' ),
				$currentHash,
				$source,
				Entry::STATUS_FAILED,
				$e->getMessage()
			);

			throw $e;
		}

		$this->reader->forget( $entry->groupKey );

		return array(
			'before' => $currentHash,
			'after'  => $this->snapshots->put( $this->reader->snapshotArray( $entry->groupKey ) ),
		);
	}

	/**
	 * Rolling back a "create" moves the group to the trash, where ACF can restore
	 * it, rather than deleting it outright.
	 *
	 * @return array{before:string,after:?string}
	 * @throws ApplyException
	 */
	private function rollbackCreation( Entry $entry, string $source ): array {
		$currentHash = $this->snapshots->put( $this->reader->snapshotArray( $entry->groupKey ) );

		$trashed = function_exists( 'acf_trash_field_group' )
			? acf_trash_field_group( $entry->groupKey )
			: acf_delete_field_group( $entry->groupKey );

		if ( ! $trashed ) {
			$this->snapshots->release( $currentHash );

			$e = new ApplyException(
				ErrorCodes::RESTORE_FAILED,
				sprintf(
					/* translators: %s: field group title */
					__( 'The field group "%s" could not be moved to the trash, so nothing was rolled back.', 'fieldpilot-for-acf' ),
					$entry->groupTitle
				),
				array(
					'group_key' => $entry->groupKey,
					'source'    => $source,
				)
			);
			throw $e;
		}

		return array(
			'before' => $currentHash,
			'after'  => null,
		);
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
		$reference = '';
		if ( null !== $payload->target ) {
			$reference = $payload->target->group;
		} elseif ( null !== $payload->group ) {
			$reference = $payload->group->title;
		}

		if ( Operation::Create === $payload->operation ) {
			// Creating onto an existing group is almost always a mistake; say so
			// rather than producing a confusing duplicate.
			if ( '' !== $reference && $this->groups->exists( $reference ) ) {
				$e = new ResolutionException(
					ErrorCodes::DUPLICATE_NAME,
					sprintf(
						/* translators: %s: field group title */
						__( 'A field group called "%s" already exists. Use "add", "update" or "merge" to change it.', 'fieldpilot-for-acf' ),
						$reference
					),
					array( 'reference' => $reference ),
					array( 'add', 'update', 'merge', 'sync', 'replace' )
				);
				throw $e;
			}

			$group = $payload->group;

			return array(
				$this->emptyTree(
					null !== $group && null !== $group->key ? $group->key : '',
					null !== $group ? $group->title : $reference
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

	/**
	 * Undo a batch that failed part-way. Returns whether the site is back to its
	 * state before the batch.
	 */
	private function undoFailedBatch( string $beforeHash, ChangeSet $changeSet, string $batchId, string $source, string $message ): bool {
		$restored   = true;
		$createdKey = $this->writer->createdGroupKey();

		try {
			if ( '' !== $beforeHash ) {
				$this->restore->fromHash( $beforeHash );
			} elseif ( null !== $createdKey && is_array( acf_get_raw_field_group( $createdKey ) ) ) {
				// A group this batch created has no earlier state to restore: remove it.
				$restored = (bool) acf_delete_field_group( $createdKey );
			}
		} catch ( \Throwable $restoreError ) {
			$restored = false;
			$message .= ' ' . $restoreError->getMessage();
		}

		$this->recordQuietly(
			$batchId,
			$changeSet->withGroupKey( $createdKey ?? $changeSet->groupKey ),
			$beforeHash,
			$source,
			$restored ? Entry::STATUS_FAILED : Entry::STATUS_INCOMPLETE,
			$message
		);

		$this->reader->forget( $changeSet->groupKey );
		$this->groups->forget();

		return $restored;
	}

	/**
	 * Translate whatever went wrong into the exception the caller sees, saying
	 * truthfully whether the automatic rollback worked.
	 */
	private function failure( \Throwable $e, bool $restored, bool $isNewGroup, string $batchId ): ApplyException {
		$context = array( 'batch_id' => $batchId );
		$details = array();

		if ( $e instanceof ApplyException ) {
			$context = $e->context() + $context;
			$details = $e->suggestions();
		}

		if ( ! $restored ) {
			$message = $isNewGroup
				? __( 'Applying the changes failed, and the half-created field group could not be removed. Delete it under ACF > Field Groups.', 'fieldpilot-for-acf' )
				: __( 'Applying the changes failed, and the automatic rollback did not finish, so the field group may be partly changed. Open History and use "Restore snapshot" on the failed entry to put it back as it was.', 'fieldpilot-for-acf' );

			return new ApplyException( ErrorCodes::RESTORE_FAILED, $message, $context + array( 'cause' => $e->getMessage() ), $details, null, $e );
		}

		if ( $e instanceof ApplyException && ErrorCodes::VERIFY_FAILED === $e->errorCode() ) {
			return new ApplyException(
				ErrorCodes::VERIFY_FAILED,
				__( 'The changes did not save correctly, so everything was rolled back. Nothing on your site was altered.', 'fieldpilot-for-acf' ),
				$context,
				$details,
				null,
				$e
			);
		}

		return new ApplyException(
			$e instanceof ApplyException ? $e->errorCode() : ErrorCodes::WRITE_FAILED,
			$e->getMessage() . ' ' . __( 'Everything was rolled back, so nothing on your site was altered.', 'fieldpilot-for-acf' ),
			$context,
			$details,
			null,
			$e
		);
	}

	/**
	 * Content can be saved between preview and apply. A change that was safe when
	 * previewed - deleting, renaming, retyping or moving an empty field - is not
	 * safe once content exists, so the user has to preview again and decide.
	 *
	 * @throws ApplyException
	 */
	private function assertContentUnchanged( ChangeSet $changeSet ): void {
		if ( null === $this->probe || ! $this->probe->isEnabled() ) {
			return;
		}

		foreach ( $changeSet->changes as $change ) {
			if ( false !== $change->hasContent || null !== $change->conflict || null === $change->targetKey ) {
				continue;
			}

			$relevant = match ( $change->type ) {
				Change::DELETE => true,
				Change::UPDATE => isset( $change->settingDiffs['type'] ) || isset( $change->settingDiffs['name'] ),
				Change::MOVE   => ( $change->context['from_parent'] ?? null ) !== ( $change->context['to_parent'] ?? null ),
				default        => false,
			};

			if ( ! $relevant ) {
				continue;
			}

			$keys = array( (string) $change->targetKey );

			if ( Change::DELETE === $change->type && null !== $change->field ) {
				foreach ( $change->field->descendants() as $descendant ) {
					if ( null !== $descendant->key ) {
						$keys[] = $descendant->key;
					}
				}
			}

			foreach ( $keys as $key ) {
				if ( $this->probe->hasContent( $key ) ) {
					$e = new ApplyException(
						ErrorCodes::STATE_CHANGED,
						sprintf(
							/* translators: %s: field label */
							__( 'Content was saved to "%s" after this preview was made. Preview the change again so you can decide what happens to that content.', 'fieldpilot-for-acf' ),
							$change->label
						),
						array(
							'change_id' => $change->id,
							'field_key' => $key,
						)
					);
					throw $e;
				}
			}
		}
	}

	/**
	 * @throws ApplyException When another batch holds the lock.
	 */
	private function acquireLock(): void {
		$now = time();

		if ( add_option( self::LOCK_OPTION, (string) $now, '', false ) ) {
			return;
		}

		$held = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $held > 0 && ( $now - $held ) < self::LOCK_TTL ) {
			$e = new ApplyException(
				ErrorCodes::STATE_CHANGED,
				__( 'Another FieldPilot change is being applied right now. Wait a few seconds, then preview and apply again.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		// A lock left behind by a request that died; take it over.
		update_option( self::LOCK_OPTION, (string) $now, false );
	}

	private function releaseLock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Journal a failure without letting a broken journal hide the original error.
	 */
	private function recordQuietly( string $batchId, ChangeSet $changeSet, string $beforeHash, string $source, string $status, string $message ): void {
		try {
			$this->journal->record( $batchId, $changeSet, $beforeHash, null, $source, $status, $message );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
