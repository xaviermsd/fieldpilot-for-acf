<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Property;

use ACFJP\Acf\DataProbe;
use ACFJP\Diff\Change;
use ACFJP\Diff\Comparator;
use ACFJP\Diff\Matcher;
use ACFJP\Diff\SettingsDiff;
use ACFJP\Model\Field;
use ACFJP\Model\FieldGroup;
use ACFJP\Model\Operation;
use ACFJP\Model\Payload;
use ACFJP\Model\RawTree;
use ACFJP\Model\Target;
use ACFJP\Resolve\Locus;
use ACFJP\Resolve\TargetResolver;
use PHPUnit\Framework\TestCase;

/**
 * The invariants that protect the product, asserted over GENERATED trees rather
 * than hand-picked examples.
 *
 * Hand-written cases prove the engine handles what we thought of. These prove it
 * handles what we did not. Two of the four invariants (rollback, idempotence of
 * application) need a real database and live in tests/Integration; the two that
 * are pure live here.
 *
 * @covers \ACFJP\Diff\Comparator
 * @covers \ACFJP\Diff\Matcher
 */
final class InvariantTest extends TestCase {

	private const ITERATIONS = 60;

	private Comparator $comparator;
	private TargetResolver $resolver;

	protected function setUp(): void {
		$this->resolver   = new TargetResolver();
		$this->comparator = new Comparator(
			$this->resolver,
			new Matcher(),
			new SettingsDiff(),
			new DataProbe( false ),
		);
	}

	/**
	 * INVARIANT 1 - PRESERVATION.
	 *
	 * For any tree and any single-setting update, the ChangeSet names exactly one
	 * field and exactly one setting. This is the product's core promise expressed
	 * as a property: a patch cannot fan out.
	 */
	public function testAnUpdateNeverTouchesMoreThanItNames(): void {
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$tree = $this->generateTree( $i );

			$leaf = $this->firstLeaf( $tree );

			if ( null === $leaf ) {
				continue;
			}

			$payload = new Payload(
				version: '1.0',
				operation: Operation::Update,
				target: new Target( $tree->group->title ),
				changes: array( (string) $leaf->key => array( 'instructions' => 'seed ' . $i ) ),
			);

			$changeSet = $this->comparator->compare( $tree, $payload, new Locus( null, null, $tree->group->title ) );

			self::assertCount( 1, $changeSet, 'Iteration ' . $i );

			$change = $changeSet->changes[0];

			self::assertSame( Change::UPDATE, $change->type );
			self::assertSame( $leaf->key, $change->targetKey );
			self::assertSame( array( 'instructions' ), array_keys( $change->settingDiffs ) );
		}
	}

	/**
	 * INVARIANT 2 - IDEMPOTENCE OF THE DIFF.
	 *
	 * Diffing a tree against a declaration of its own current state must produce
	 * nothing. If this fails, every "sync" would churn the whole group, and every
	 * preview would show phantom changes.
	 */
	public function testSyncingATreeAgainstItselfProducesNoChanges(): void {
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$tree = $this->generateTree( $i );

			$payload = new Payload(
				version: '1.0',
				operation: Operation::Sync,
				target: new Target( $tree->group->title ),
				group: $tree->group,
			);

			$changeSet = $this->comparator->compare( $tree, $payload, new Locus( null, null, $tree->group->title ) );

			self::assertTrue(
				$changeSet->isEmpty(),
				sprintf(
					'Iteration %d produced %d spurious change(s): %s',
					$i,
					$changeSet->count(),
					implode( ', ', array_map( static fn ( Change $c ): string => $c->type . ':' . $c->label, $changeSet->changes ) )
				)
			);
		}
	}

	/**
	 * INVARIANT 3 - KEY PRESERVATION UNDER REORDERING.
	 *
	 * Shuffling the declared order must never be read as delete-and-recreate. That
	 * would mint new keys for every field and orphan all of their content.
	 */
	public function testReorderingNeverLooksLikeReplacement(): void {
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$tree = $this->generateTree( $i );

			$shuffled = $tree->group->fields;

			if ( count( $shuffled ) < 2 ) {
				continue;
			}

			$shuffled = array_reverse( $shuffled );

			$payload = new Payload(
				version: '1.0',
				operation: Operation::Sync,
				target: new Target( $tree->group->title ),
				group: $tree->group->withFields( $shuffled ),
			);

			$changeSet = $this->comparator->compare( $tree, $payload, new Locus( null, null, $tree->group->title ) );

			self::assertSame( array(), $changeSet->ofType( Change::DELETE ), 'Iteration ' . $i );
			self::assertSame( array(), $changeSet->ofType( Change::ADD ), 'Iteration ' . $i );
		}
	}

	// ---- Generator -----------------------------------------------------------

	/**
	 * Deterministic pseudo-random trees. Seeded so a failure is reproducible from
	 * the iteration number alone.
	 */
	private function generateTree( int $seed ): RawTree {
		mt_srand( $seed );

		$fields = array();
		$count  = mt_rand( 1, 5 );

		for ( $i = 0; $i < $count; $i++ ) {
			$fields[] = $this->generateField( $seed . '_' . $i, 1 );
		}

		return new RawTree(
			FieldGroup::fromAcfArray(
				array(
					'key'    => 'group_' . dechex( $seed + 4096 ),
					'title'  => 'Generated ' . $seed,
					'fields' => $fields,
				)
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function generateField( string $path, int $depth ): array {
		$scalars = array( 'text', 'textarea', 'number', 'email', 'url', 'true_false', 'image' );

		$makeContainer = $depth < 4 && 0 === mt_rand( 0, 2 );

		$type = $makeContainer
			? ( 0 === mt_rand( 0, 1 ) ? 'group' : 'repeater' )
			: $scalars[ mt_rand( 0, count( $scalars ) - 1 ) ];

		$name = 'f_' . str_replace( '_', '', $path );

		$field = array(
			'key'          => 'field_' . substr( md5( $path ), 0, 13 ),
			'name'         => $name,
			'label'        => ucfirst( $name ),
			'type'         => $type,
			'instructions' => 0 === mt_rand( 0, 1 ) ? '' : 'Help for ' . $name,
			'required'     => mt_rand( 0, 1 ),
			'wrapper'      => array( 'width' => '', 'class' => '', 'id' => '' ),
		);

		if ( 'group' === $type || 'repeater' === $type ) {
			$children = array();
			$count    = mt_rand( 1, 3 );

			for ( $i = 0; $i < $count; $i++ ) {
				$children[] = $this->generateField( $path . '_' . $i, $depth + 1 );
			}

			$field['sub_fields'] = $children;
		}

		return $field;
	}

	private function firstLeaf( RawTree $tree ): ?Field {
		foreach ( $tree->all() as $field ) {
			if ( ! $field->isContainer() ) {
				return $field;
			}
		}

		return null;
	}
}
