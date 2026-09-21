<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Json\Coerce;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACFJP\Json\Coerce
 */
final class CoerceTest extends TestCase {

	/**
	 * @dataProvider boolCases
	 */
	public function testToAcfBool( mixed $input, int $expected ): void {
		self::assertSame( $expected, Coerce::toAcfBool( $input ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:int}>
	 */
	public static function boolCases(): array {
		return array(
			'true'        => array( true, 1 ),
			'false'       => array( false, 0 ),
			'int one'     => array( 1, 1 ),
			'int zero'    => array( 0, 0 ),
			'string yes'  => array( 'yes', 1 ),
			'string no'   => array( 'no', 0 ),
			'string true' => array( 'true', 1 ),
			'string on'   => array( 'on', 1 ),
			'string one'  => array( '1', 1 ),
			'string zero' => array( '0', 0 ),
			'empty'       => array( '', 0 ),
			'null'        => array( null, 0 ),
		);
	}

	public function testChoicesFromPlainList(): void {
		self::assertSame(
			array( 'red' => 'red', 'blue' => 'blue' ),
			Coerce::choices( array( 'red', 'blue' ) )
		);
	}

	public function testChoicesKeepsAssociativeMap(): void {
		self::assertSame(
			array( 'r' => 'Red', 'b' => 'Blue' ),
			Coerce::choices( array( 'r' => 'Red', 'b' => 'Blue' ) )
		);
	}

	public function testChoicesFromAcfAdminString(): void {
		self::assertSame(
			array( 'r' => 'Red', 'b' => 'Blue' ),
			Coerce::choices( "r : Red\nb : Blue" )
		);
	}

	public function testChoicesFromValueLabelObjects(): void {
		self::assertSame(
			array( 'r' => 'Red' ),
			Coerce::choices( array( array( 'value' => 'r', 'label' => 'Red' ) ) )
		);
	}

	public function testConditionalLogicFalseBecomesZero(): void {
		self::assertSame( 0, Coerce::conditionalLogic( false ) );
		self::assertSame( 0, Coerce::conditionalLogic( array() ) );
		self::assertSame( 0, Coerce::conditionalLogic( null ) );
	}

	public function testConditionalLogicWrapsASingleRule(): void {
		$rule = array( 'field' => 'field_abc', 'operator' => '==', 'value' => '1' );

		self::assertSame( array( array( $rule ) ), Coerce::conditionalLogic( $rule ) );
	}

	public function testConditionalLogicWrapsAFlatRuleList(): void {
		$rules = array(
			array( 'field' => 'field_a', 'operator' => '==', 'value' => '1' ),
			array( 'field' => 'field_b', 'operator' => '!=', 'value' => '' ),
		);

		self::assertSame( array( $rules ), Coerce::conditionalLogic( $rules ) );
	}

	public function testSettingsCoercesKnownBooleansAndIntegers(): void {
		$out = Coerce::settings(
			array(
				'required'     => true,
				'multiple'     => 'yes',
				'maxlength'    => '120',
				'instructions' => 'Leave me alone',
			)
		);

		self::assertSame( 1, $out['required'] );
		self::assertSame( 1, $out['multiple'] );
		self::assertSame( 120, $out['maxlength'] );
		self::assertSame( 'Leave me alone', $out['instructions'] );
	}

	public function testWrapperAlwaysHasThreeKeys(): void {
		self::assertSame(
			array( 'width' => '50', 'class' => '', 'id' => '' ),
			Coerce::wrapper( array( 'width' => '50' ) )
		);
	}
}
