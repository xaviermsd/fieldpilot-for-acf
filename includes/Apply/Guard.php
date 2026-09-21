<?php
/**
 * The last checkpoint before anything is written.
 *
 * A batch that cannot fully succeed must never begin. Everything this class checks
 * is cheap and certain; anything uncertain belongs in the Verifier, after the fact,
 * where a snapshot exists to fall back on.
 *
 * The mutability check is the one that matters most, and it is the finding that
 * prompted this class to exist at all - see docs/ARCHITECTURE-REVIEW.md B.2.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

use ACFJP\Acf\Mutability;
use ACFJP\Acf\MutabilityClassifier;
use ACFJP\Diff\Change;
use ACFJP\Diff\ChangeSet;
use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\GuardException;
use ACFJP\Journal\Journal;
use ACFJP\Json\FieldTypeSchemas;
use ACFJP\Model\RawTree;

defined( 'ABSPATH' ) || exit;

final class Guard {

	public const CAPABILITY = 'manage_options';

	public function __construct(
		private readonly MutabilityClassifier $mutability,
		private readonly FieldTypeSchemas $schemas,
	) {}

	/**
	 * @param string|null $expectedHash The tree hash the plan was computed against.
	 * @throws GuardException
	 */
	public function assertCanApply( ChangeSet $changeSet, RawTree $current, bool $confirmed, ?string $expectedHash = null ): void {
		$this->assertWritable();
		$this->assertCapability();
		$this->assertMutable( $changeSet->groupKey );
		$this->assertStateUnchanged( $current, $expectedHash );
		$this->assertTypesAvailable( $changeSet );
		$this->assertConflictsResolved( $changeSet );
		$this->assertConfirmed( $changeSet, $confirmed );
		$this->assertOwnership( $changeSet, $current );
	}

	/**
	 * Read-only mode: every mutating path is refused, everything else works.
	 *
	 * The point of this is trying the plugin on a site that matters. Reading ACF,
	 * validating a payload and computing a diff touch nothing - they are the pure
	 * pipeline plus reads. Applying is the part with risk. This makes "I will only
	 * preview" an enforced property rather than something a person has to remember
	 * at the moment they are curious.
	 *
	 * Turn it on in wp-config.php, where a UI slip cannot reach it:
	 *
	 *     define( 'ACFJP_READ_ONLY', true );
	 */
	public static function isReadOnly(): bool {
		if ( defined( 'ACFJP_READ_ONLY' ) && ACFJP_READ_ONLY ) {
			return true;
		}

		$settings = (array) get_option( 'acfjp_settings', array() );

		if ( ! empty( $settings['read_only'] ) ) {
			return true;
		}

		/**
		 * Force read-only mode.
		 *
		 * @param bool $readOnly
		 */
		return (bool) apply_filters( 'acfjp_read_only', false );
	}

	/**
	 * @throws GuardException
	 */
	public function assertWritable(): void {
		if ( ! self::isReadOnly() ) {
			return;
		}

		Journal::audit( 'read_only_blocked', array() );

		$e = new GuardException(
			ErrorCodes::READ_ONLY_MODE,
			__( 'FieldPilot is in read-only mode. Previews and validation work; nothing can be written.', 'fieldpilot-for-acf' ),
			array( 'read_only' => true ),
			array(
				defined( 'ACFJP_READ_ONLY' ) && ACFJP_READ_ONLY
					? __( 'Remove the ACFJP_READ_ONLY constant from wp-config.php to allow changes.', 'fieldpilot-for-acf' )
					: __( 'Turn off read-only mode under FieldPilot → Settings to allow changes.', 'fieldpilot-for-acf' ),
			)
		);
		throw $e;
	}

	/**
	 * @throws GuardException
	 */
	public function assertCapability(): void {
		/**
		 * The capability required to change ACF configuration through this plugin.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$capability = (string) apply_filters( 'acfjp_capability', self::CAPABILITY );

		if ( current_user_can( $capability ) ) {
			return;
		}

		Journal::audit( 'capability_denied', array( 'capability' => $capability ) );

		$e = new GuardException(
			ErrorCodes::INSUFFICIENT_CAPABILITY,
			__( 'You do not have permission to change ACF field configuration.', 'fieldpilot-for-acf' ),
			array( 'capability' => $capability )
		);
		throw $e;
	}

	/**
	 * @throws GuardException
	 */
	public function assertMutable( string $groupKey ): void {
		$report = $this->mutability->classify( $groupKey );

		if ( $report->isPatchable() ) {
			return;
		}

		$exception = match ( $report->mutability ) {
			Mutability::LocalPhp => new GuardException(
				ErrorCodes::GROUP_REGISTERED_IN_PHP,
				sprintf(
					/* translators: %s: field group title */
					__( '"%s" is registered in PHP with acf_add_local_field_group(), so it cannot be changed from the database. Edit the code that registers it.', 'fieldpilot-for-acf' ),
					$report->groupTitle
				),
				array( 'group_key' => $groupKey, 'mutability' => $report->mutability->value, 'remedy' => $report->remedy ),
				array( __( 'Use Export to generate the PHP for the change, then paste it into the file that registers this group.', 'fieldpilot-for-acf' ) )
			),
			Mutability::LocalJson => new GuardException(
				ErrorCodes::GROUP_NOT_SYNCED,
				sprintf(
					/* translators: %s: field group title */
					__( '"%s" is loaded from an acf-json file and has not been synced into the database yet, so there is nothing here to patch.', 'fieldpilot-for-acf' ),
					$report->groupTitle
				),
				array( 'group_key' => $groupKey, 'mutability' => $report->mutability->value, 'remedy' => 'sync', 'json_path' => $report->jsonPath ),
				array( __( 'Sync this field group into the database first, then apply the change. The JSON file is rewritten afterwards, so your repository stays authoritative.', 'fieldpilot-for-acf' ) )
			),
			default => new GuardException(
				ErrorCodes::GROUP_NOT_PATCHABLE,
				sprintf(
					/* translators: %s: field group key */
					__( 'Field group "%s" cannot be modified.', 'fieldpilot-for-acf' ),
					$groupKey
				),
				array( 'group_key' => $groupKey, 'mutability' => $report->mutability->value )
			),
		};
		throw $exception;
	}

	/**
	 * Optimistic concurrency. A plan is computed against one state; if ACF moved
	 * underneath - another admin, a deploy, a CLI run - applying the stale plan
	 * would produce a result nobody previewed.
	 *
	 * @throws GuardException
	 */
	public function assertStateUnchanged( RawTree $current, ?string $expectedHash ): void {
		if ( null === $expectedHash || '' === $expectedHash ) {
			return;
		}

		$actual = $current->stateHash();

		if ( hash_equals( $expectedHash, $actual ) ) {
			return;
		}

		$e = new GuardException(
			ErrorCodes::STATE_CHANGED,
			__( 'This field group changed after the preview was generated. Review the changes again before applying.', 'fieldpilot-for-acf' ),
			array( 'expected' => $expectedHash, 'actual' => $actual ),
			array( __( 'Re-run the preview to see a diff against the current state.', 'fieldpilot-for-acf' ) )
		);
		throw $e;
	}

	/**
	 * @throws GuardException
	 */
	private function assertTypesAvailable( ChangeSet $changeSet ): void {
		$missing = array();

		foreach ( $changeSet->changes as $change ) {
			if ( null === $change->field ) {
				continue;
			}

			foreach ( array_merge( array( $change->field ), $change->field->descendants() ) as $field ) {
				if ( $this->schemas->requiresPro( $field->type ) ) {
					$missing[ $field->type ] = true;
				}
			}
		}

		if ( array() === $missing ) {
			return;
		}

		$e = new GuardException(
			ErrorCodes::FIELD_TYPE_REQUIRES_PRO,
			sprintf(
				/* translators: %s: comma-separated list of field types */
				__( 'This change needs ACF PRO field types that are not available here: %s.', 'fieldpilot-for-acf' ),
				implode( ', ', array_keys( $missing ) )
			),
			array( 'types' => array_keys( $missing ) )
		);
		throw $e;
	}

	/**
	 * @throws GuardException
	 */
	private function assertConflictsResolved( ChangeSet $changeSet ): void {
		$unresolved = $changeSet->unresolvedConflicts();

		if ( array() === $unresolved ) {
			return;
		}

		$e = new GuardException(
			ErrorCodes::UNRESOLVED_CONFLICTS,
			sprintf(
				/* translators: %d: number of unresolved conflicts */
				_n(
					'%d conflict needs a decision before this can be applied.',
					'%d conflicts need a decision before this can be applied.',
					count( $unresolved ),
					'fieldpilot-for-acf'
				),
				count( $unresolved )
			),
			array( 'conflicts' => array_map( static fn( $c ): array => $c->jsonSerialize(), $unresolved ) )
		);
		throw $e;
	}

	/**
	 * @throws GuardException
	 */
	private function assertConfirmed( ChangeSet $changeSet, bool $confirmed ): void {
		if ( ! $changeSet->isDestructive() || $confirmed ) {
			return;
		}

		$destructive = array_values(
			array_filter(
				$changeSet->changes,
				static fn( Change $c ): bool => $c->risk->requiresConfirmation()
			)
		);

		$e = new GuardException(
			ErrorCodes::CONFIRMATION_REQUIRED,
			sprintf(
				/* translators: %d: number of destructive changes */
				_n(
					'%d change can remove or orphan content. Confirm explicitly to continue.',
					'%d changes can remove or orphan content. Confirm explicitly to continue.',
					count( $destructive ),
					'fieldpilot-for-acf'
				),
				count( $destructive )
			),
			array(
				'changes' => array_map( static fn( Change $c ): array => $c->jsonSerialize(), $destructive ),
			)
		);
		throw $e;
	}

	/**
	 * Defence in depth for the Clone hazard (ARCHITECTURE-REVIEW B.8 rule 3): a
	 * delete must target a field this group actually owns. The comparator should
	 * never produce anything else; if it ever does, it does not execute.
	 *
	 * @throws GuardException
	 */
	private function assertOwnership( ChangeSet $changeSet, RawTree $current ): void {
		foreach ( $changeSet->changes as $change ) {
			if ( Change::DELETE !== $change->type || null === $change->targetKey ) {
				continue;
			}

			if ( ! $current->has( $change->targetKey ) ) {
				$e = new GuardException(
					ErrorCodes::GROUP_NOT_PATCHABLE,
					sprintf(
						/* translators: 1: field label, 2: field group title */
						__( 'Refusing to delete "%1$s": it is not owned by %2$s.', 'fieldpilot-for-acf' ),
						$change->label,
						$current->group->title
					),
					array( 'field_key' => $change->targetKey, 'group_key' => $current->group->key )
				);
				throw $e;
			}
		}
	}
}
