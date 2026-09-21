<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Diff\Matcher;
use ACFJP\Model\Field;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACFJP\Diff\Matcher
 */
final class MatcherTest extends TestCase {

	private Matcher $matcher;

	protected function setUp(): void {
		$this->matcher = new Matcher();
	}

	private function field( ?string $key, string $name, string $label = '', string $type = 'text' ): Field {
		return new Field( key: $key, name: $name, label: '' !== $label ? $label : ucfirst( $name ), type: $type );
	}

	public function testMatchesByKeyEvenWhenTheNameChanged(): void {
		$current  = array( $this->field( 'field_a', 'phone', 'Phone' ) );
		$incoming = array( $this->field( 'field_a', 'business_phone', 'Business Phone' ) );

		$result = $this->matcher->match( $current, $incoming );

		self::assertCount( 1, $result['pairs'] );
		self::assertSame( array(), $result['added'] );
		self::assertSame( array(), $result['removed'] );
	}

	public function testMatchesByNameWhenNoKeyIsSupplied(): void {
		$current  = array( $this->field( 'field_a', 'phone' ) );
		$incoming = array( $this->field( null, 'phone', 'Business Phone' ) );

		$result = $this->matcher->match( $current, $incoming );

		self::assertCount( 1, $result['pairs'] );
		self::assertSame( 'field_a', $result['pairs'][0]['current']->key );
	}

	/**
	 * Reordering must not look like a wholesale replacement, or every declarative
	 * import would delete and recreate the group, losing every key.
	 */
	public function testOrderIsIrrelevant(): void {
		$current  = array( $this->field( 'field_a', 'a' ), $this->field( 'field_b', 'b' ) );
		$incoming = array( $this->field( null, 'b' ), $this->field( null, 'a' ) );

		$result = $this->matcher->match( $current, $incoming );

		self::assertCount( 2, $result['pairs'] );
		self::assertSame( array(), $result['added'] );
		self::assertSame( array(), $result['removed'] );
	}

	public function testUnmatchedIncomingBecomesAdded(): void {
		$result = $this->matcher->match(
			array( $this->field( 'field_a', 'a' ) ),
			array( $this->field( null, 'a' ), $this->field( null, 'c' ) )
		);

		self::assertCount( 1, $result['added'] );
		self::assertSame( 'c', $result['added'][0]->name );
	}

	public function testUnmatchedCurrentBecomesRemoved(): void {
		$result = $this->matcher->match(
			array( $this->field( 'field_a', 'a' ), $this->field( 'field_b', 'b' ) ),
			array( $this->field( null, 'a' ) )
		);

		self::assertCount( 1, $result['removed'] );
		self::assertSame( 'b', $result['removed'][0]->name );
	}

	/**
	 * Structural fields carry no name, so the label is all there is to go on.
	 */
	public function testStructuralFieldsMatchByLabel(): void {
		$current  = array( $this->field( 'field_tab', '', 'Contact Details', 'tab' ) );
		$incoming = array( $this->field( null, '', 'Contact Details', 'tab' ) );

		$result = $this->matcher->match( $current, $incoming );

		self::assertCount( 1, $result['pairs'] );
	}

	/**
	 * Two siblings sharing a name are already broken. Guessing which one was meant
	 * is how a tool writes to the wrong field, so it declines.
	 */
	public function testAmbiguousNameDoesNotMatch(): void {
		$current = array( $this->field( 'field_a', 'dup' ), $this->field( 'field_b', 'dup' ) );

		$result = $this->matcher->match( $current, array( $this->field( null, 'dup' ) ) );

		self::assertSame( array(), $result['pairs'] );
		self::assertCount( 1, $result['added'] );
	}

	public function testOneCurrentFieldIsNeverMatchedTwice(): void {
		$current  = array( $this->field( 'field_a', 'a' ) );
		$incoming = array( $this->field( null, 'a' ), $this->field( null, 'a' ) );

		$result = $this->matcher->match( $current, $incoming );

		self::assertCount( 1, $result['pairs'] );
		self::assertCount( 1, $result['added'] );
	}
}
