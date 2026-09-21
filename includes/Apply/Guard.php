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
		return (bool) apply_filters( 'acfjp/read_only', false );
	}

	/**
	 * @throws GuardException
	 */
	public function assertWritable(): void {
		if ( ! self::isReadOnly() ) {
			return;
		}

		Journal::audit( 'read_only_blocked', array() );

		throw new GuardException(
			ErrorCodes::READ_ONLY_MODE,
			__( 'WP ACF JSON Pro is in read-only mode. Previews and validation work; nothing can be written.', 'wp-acf-json-pro' ),
			array( 'read_only' => true ),
			array(
				defined( 'ACFJP_READ_ONLY' ) && ACFJP_READ_ONLY
					? __( 'Remove the ACFJP_READ_ONLY constant from wp-config.php to allow changes.', 'wp-acf-json-pro' )
					: __( 'Turn off read-only mode under ACF JSON Pro → Settings to allow changes.', 'wp-acf-json-pro' ),
			)
		);
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
		$capability = (string) apply_filters( 'acfjp/capability', self::CAPABILITY );

		if ( current_user_can( $capability ) ) {
			return;
		}

		Journal::audit( 'capability_denied', array( 'capability' => $capability ) );

		throw new GuardException(
			ErrorCodes::INSUFFICIENT_CAPABILITY,
			__( 'You do not have permission to change ACF field configuration.', 'wp-acf-json-pro' ),
			array( 'capability' => $capability )
		);
	}

	/**
	 * @throws GuardException
	 */
	public function assertMutable( string $groupKey ): void {
		$report = $this->mutability->classify( $groupKey );

		if ( $report->isPatchable() ) {
			return;
		}

		throw match ( $report->mutability ) {
			Mutability::LocalPhp => new GuardException(
				ErrorCodes::GROUP_REGISTERED_IN_PHP,
				sprintf(
					/* translators: %s: field group title */
					__( '"%s" is registered in PHP with acf_add_local_field_group(), so it cannot be changed from the database. Edit the code that registers it.', 'wp-acf-json-pro' ),
					$report->groupTitle
				),
				array( 'group_key' => $groupKey, 'mutability' => $report->mutability->value, 'remedy' => $report->remedy ),
				array( __( 'Use Export to generate the PHP for the change, then paste it into the file that registers this group.', 'wp-acf-json-pro' ) )
			),
			Mutability::LocalJson => new GuardException(
				ErrorCodes::GROUP_NOT_SYNCED,
				sprintf(
					/* translators: %s: field group title */
					__( '"%s" is loaded from an acf-json file and has not been synced into the database yet, so there is nothing here to patch.', 'wp-acf-json-pro' ),
					$report->groupTitle
				),
				array( 'group_key' => $groupKey, 'mutability' => $report->mutability->value, 'remedy' => 'sync', 'json_path' => $report->jsonPath ),
				array( __( 'Sync this field group into the database first, then apply the change. The JSON file is rewritten afterwards, so your repository stays authoritative.', 'wp-acf-json-pro' ) )
			),
			default => new GuardException(
				ErrorCodes::GROUP_NOT_PATCHABLE,
				sprintf(
					/* translators: %s: field group key */
					__( 'Field group "%s" cannot be modified.', 'wp-acf-json-pro' ),
					$groupKey
				),
				array( 'group_key' => $groupKey, 'mutability' => $report->mutability->value )
			),
		};
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

		throw new GuardException(
			ErrorCodes::STATE_CHANGED,
			__( 'This field group changed after the preview was generated. Review the changes again before applying.', 'wp-acf-json-pro' ),
			array( 'expected' => $expectedHash, 'actual' => $actual ),
			array( __( 'Re-run the preview to see a diff against the current state.', 'wp-acf-json-pro' ) )
		);
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

		throw new GuardException(
			ErrorCodes::FIELD_TYPE_REQUIRES_PRO,
			sprintf(
				/* translators: %s: comma-separated list of field types */
				__( 'This change needs ACF PRO field types that are not available here: %s.', 'wp-acf-json-pro' ),
				implode( ', ', array_keys( $missing ) )
			),
			array( 'types' => array_keys( $missing ) )
		);
	}

	/**
	 * @throws GuardException
	 */
	private function assertConflictsResolved( ChangeSet $changeSet ): void {
		$unresolved = $changeSet->unresolvedConflicts();

		if ( array() === $unresolved ) {
			return;
		}

		throw new GuardException(
			ErrorCodes::UNRESOLVED_CONFLICTS,
			sprintf(
				/* translators: %d: number of unresolved conflicts */
				_n(
					'%d conflict needs a decision before this can be applied.',
					'%d conflicts need a decision before this can be applied.',
					count( $unresolved ),
					'wp-acf-json-pro'
				),
				count( $unresolved )
			),
			array( 'conflicts' => array_map( static fn( $c ): array => $c->jsonSerialize(), $unresolved ) )
		);
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

		throw new GuardException(
			ErrorCodes::CONFIRMATION_REQUIRED,
			sprintf(
				/* translators: %d: number of destructive changes */
				_n(
					'%d change can remove or orphan content. Confirm explicitly to continue.',
					'%d changes can remove or orphan content. Confirm explicitly to continue.',
					count( $destructive ),
					'wp-acf-json-pro'
				),
				count( $destructive )
			),
			array(
				'changes' => array_map( static fn( Change $c ): array => $c->jsonSerialize(), $destructive ),
			)
		);
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
				throw new GuardException(
					ErrorCodes::GROUP_NOT_PATCHABLE,
					sprintf(
						/* translators: 1: field label, 2: field group title */
						__( 'Refusing to delete "%1$s": it is not owned by %2$s.', 'wp-acf-json-pro' ),
						$change->label,
						$current->group->title
					),
					array( 'field_key' => $change->targetKey, 'group_key' => $current->group->key )
				);
			}
		}
	}
}
