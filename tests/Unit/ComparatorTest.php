<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Acf\DataProbe;
use ACFJP\Diff\Change;
use ACFJP\Diff\Comparator;
use ACFJP\Diff\Conflict;
use ACFJP\Diff\Matcher;
use ACFJP\Diff\Risk;
use ACFJP\Diff\SettingsDiff;
use ACFJP\Json\Normalizer;
use ACFJP\Model\FieldGroup;
use ACFJP\Model\Payload;
use ACFJP\Model\RawTree;
use ACFJP\Resolve\Locus;
use ACFJP\Resolve\TargetResolver;
use PHPUnit\Framework\TestCase;

/**
 * The diff engine. This is the product, so these are the tests that matter most.
 *
 * The DataProbe is constructed disabled, which keeps the suite free of a database
 * and makes every field report "no content" - content-aware risk escalation is
 * covered by the integration suite instead.
 *
 * @covers \ACFJP\Diff\Comparator
 */
final class ComparatorTest extends TestCase {

	private Comparator $comparator;
	private TargetResolver $resolver;
	private Normalizer $normalizer;

	protected function setUp(): void {
		$this->resolver   = new TargetResolver();
		$this->normalizer = new Normalizer();
		$this->comparator = new Comparator(
			$this->resolver,
			new Matcher(),
			new SettingsDiff(),
			new DataProbe( false ),
		);
	}

	/**
	 * The canonical acceptance scenario from the product brief.
	 */
	private function propertyTree(): RawTree {
		return new RawTree(
			FieldGroup::fromAcfArray(
				array(
					'key'    => 'group_prop',
					'title'  => 'Property',
					'fields' => array(
						array(
							'key'        => 'field_agent',
							'name'       => 'agent',
							'label'      => 'Agent',
							'type'       => 'group',
							'sub_fields' => array(
								array( 'key' => 'field_name', 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
								array( 'key' => 'field_phone', 'name' => 'phone', 'label' => 'Phone', 'type' => 'text' ),
								array(
									'key'          => 'field_email',
									'name'         => 'email',
									'label'        => 'Email',
									'type'         => 'email',
									'required'     => 0,
									'instructions' => 'Work address only',
								),
							),
						),
					),
				)
			)
		);
	}

	private function plan( RawTree $tree, array $raw ): \ACFJP\Diff\ChangeSet {
		$payload = $this->normalizer->normalize( $raw );
		$locus   = null === $payload->target || $payload->target->isGroupRoot()
			? new Locus( null, null, $tree->group->title )
			: $this->resolver->resolveLocus( $tree, $payload->target );

		return $this->comparator->compare( $tree, $payload, $locus );
	}

	// ---- The acceptance scenario --------------------------------------------

	public function testCanonicalScenarioProducesExactlyTwoChanges(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'version'   => '1.0',
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'required' => true ) ),
				'add'       => array( array( 'name' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'text' ) ),
			)
		);

		self::assertCount( 2, $changeSet );

		$update = $changeSet->ofType( Change::UPDATE )[0];
		$add    = $changeSet->ofType( Change::ADD )[0];

		// Exactly one setting changed, and it is the one that was asked for.
		self::assertSame( array( 'required' ), array_keys( $update->settingDiffs ) );
		self::assertSame( 0, $update->settingDiffs['required']['from'] );
		self::assertSame( 1, $update->settingDiffs['required']['to'] );
		self::assertSame( 'field_email', $update->targetKey );
		self::assertSame( 'Property › Agent › Email', $update->path );

		self::assertSame( 'WhatsApp', $add->label );
		self::assertSame( 'text', $add->fieldType );
		self::assertSame( 'field_agent', $add->parentKey );

		// Nothing here endangers content.
		self::assertSame( Risk::Safe, $changeSet->highestRisk() );
		self::assertFalse( $changeSet->isDestructive() );
		self::assertSame( array(), $changeSet->unresolvedConflicts() );
	}

	public function testUnrelatedFieldsAreNotTouched(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'required' => true ) ),
			)
		);

		$touched = array_filter(
			array_map( static fn ( Change $c ): ?string => $c->targetKey, $changeSet->changes )
		);

		self::assertSame( array( 'field_email' ), array_values( $touched ) );
	}

	public function testInstructionsSurviveARequiredChange(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'required' => true ) ),
			)
		);

		self::assertArrayNotHasKey( 'instructions', $changeSet->ofType( Change::UPDATE )[0]->settingDiffs );
	}

	public function testNoChangesWhenTheRequestMatchesReality(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'required' => false ) ),
			)
		);

		self::assertTrue( $changeSet->isEmpty() );
	}

	// ---- Conflicts ----------------------------------------------------------

	public function testChangingAFieldTypeRaisesAConflict(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'phone' => array( 'type' => 'email' ) ),
			)
		);

		$change = $changeSet->ofType( Change::UPDATE )[0];

		self::assertTrue( $change->hasConflict() );
		self::assertSame( Conflict::TYPE_CHANGE, $change->conflict?->kind );
		self::assertTrue( $change->isBlocked(), 'An unresolved conflict must block the batch.' );
		self::assertCount( 1, $changeSet->unresolvedConflicts() );
	}

	public function testChangingAContainerTypeRaisesTheStrongerConflict(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'update',
				'target'    => 'Property',
				'changes'   => array( 'agent' => array( 'type' => 'repeater' ) ),
			)
		);

		$change = $changeSet->ofType( Change::UPDATE )[0];

		self::assertSame( Conflict::CONTAINER_CHANGE, $change->conflict?->kind );
		self::assertSame( Risk::Destructive, $change->risk );
	}

	public function testResolvingAConflictUnblocksTheBatch(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'phone' => array( 'type' => 'email' ) ),
			)
		);

		$conflictId = $changeSet->conflicts()[0]->id;

		$resolved = $changeSet->withResolutions( array( $conflictId => Conflict::KEEP_EXISTING ) );

		self::assertSame( array(), $resolved->unresolvedConflicts() );
	}

	public function testAddingAFieldWhoseNameIsTakenRaisesACollision(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'add',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'add'       => array( array( 'name' => 'phone', 'label' => 'Phone Again', 'type' => 'text' ) ),
			)
		);

		self::assertSame( Conflict::NAME_COLLISION, $changeSet->ofType( Change::ADD )[0]->conflict?->kind );
	}

	// ---- Deletes ------------------------------------------------------------

	public function testDeleteIsAtLeastCautionEvenWithoutContent(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'delete',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'delete'    => array( 'phone' ),
			)
		);

		$change = $changeSet->ofType( Change::DELETE )[0];

		self::assertSame( 'field_phone', $change->targetKey );
		self::assertSame( Risk::Caution, $change->risk );
	}

	public function testDeletingAContainerCountsItsDescendants(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'delete',
				'target'    => 'Property',
				'delete'    => array( 'agent' ),
			)
		);

		self::assertSame( 3, $changeSet->ofType( Change::DELETE )[0]->context['descendants'] );
	}

	// ---- Declarative operations ---------------------------------------------

	public function testMergeAddsWithoutDeleting(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation'   => 'merge',
				'target'      => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'add'         => array(
					array( 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
					array( 'name' => 'mobile', 'label' => 'Mobile', 'type' => 'text' ),
				),
			)
		);

		self::assertCount( 1, $changeSet->ofType( Change::ADD ) );
		self::assertSame( 'Mobile', $changeSet->ofType( Change::ADD )[0]->label );
		self::assertSame( array(), $changeSet->ofType( Change::DELETE ) );
	}

	public function testSyncDeletesWhatIsNotDeclared(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'sync',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'add'       => array( array( 'name' => 'name', 'label' => 'Name', 'type' => 'text' ) ),
			)
		);

		$deleted = array_map( static fn ( Change $c ): ?string => $c->targetKey, $changeSet->ofType( Change::DELETE ) );

		self::assertContains( 'field_phone', $deleted );
		self::assertContains( 'field_email', $deleted );
		self::assertNotContains( 'field_name', $deleted );
	}

	public function testSyncPreservesKeysOfMatchedFields(): void {
		$changeSet = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'sync',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'add'       => array(
					array( 'name' => 'name', 'label' => 'Full Name', 'type' => 'text' ),
					array( 'name' => 'phone', 'label' => 'Phone', 'type' => 'text' ),
					array( 'name' => 'email', 'label' => 'Email', 'type' => 'email' ),
				),
			)
		);

		// The label change is an update to the EXISTING field, not a delete + add.
		$updates = $changeSet->ofType( Change::UPDATE );

		self::assertCount( 1, $updates );
		self::assertSame( 'field_name', $updates[0]->targetKey );
		self::assertSame( array(), $changeSet->ofType( Change::DELETE ) );
		self::assertSame( array(), $changeSet->ofType( Change::ADD ) );
	}

	// ---- Plan identity ------------------------------------------------------

	public function testChangeSetHashIsStable(): void {
		$raw = array(
			'operation' => 'update',
			'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
			'changes'   => array( 'email' => array( 'required' => true ) ),
		);

		self::assertSame(
			$this->plan( $this->propertyTree(), $raw )->hash(),
			$this->plan( $this->propertyTree(), $raw )->hash()
		);
	}

	public function testChangeSetHashDiffersForDifferentRequests(): void {
		$a = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'required' => true ) ),
			)
		);

		$b = $this->plan(
			$this->propertyTree(),
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'label' => 'E-mail' ) ),
			)
		);

		self::assertNotSame( $a->hash(), $b->hash() );
	}
}
