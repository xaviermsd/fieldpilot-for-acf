<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Diff\SettingsDiff;
use ACFJP\Model\Field;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACFJP\Diff\SettingsDiff
 */
final class SettingsDiffTest extends TestCase {

	private SettingsDiff $diff;

	protected function setUp(): void {
		$this->diff = new SettingsDiff();
	}

	private function field( array $settings = array(), string $type = 'text' ): Field {
		return new Field(
			key: 'field_abc123',
			name: 'email',
			label: 'Email',
			type: $type,
			settings: $settings,
		);
	}

	/**
	 * The product's core promise, at unit level: a setting the payload does not
	 * mention is not part of the diff at all.
	 */
	public function testOnlyNamedSettingsAreCompared(): void {
		$field = $this->field( array( 'required' => 0, 'instructions' => 'Work address only', 'placeholder' => 'you@example.com' ) );

		$diffs = $this->diff->forSettings( $field, array( 'required' => 1 ) );

		self::assertSame( array( 'required' ), array_keys( $diffs ) );
		self::assertSame( 0, $diffs['required']['from'] );
		self::assertSame( 1, $diffs['required']['to'] );
	}

	public function testUnchangedSettingsProduceNoDiff(): void {
		$field = $this->field( array( 'required' => 1 ) );

		self::assertSame( array(), $this->diff->forSettings( $field, array( 'required' => 1 ) ) );
	}

	/**
	 * ACF stores 0/1; a payload may say false/true. Reporting that as a change
	 * would fill every preview with noise.
	 */
	public function testBooleanRepresentationsAreEquivalent(): void {
		$field = $this->field( array( 'required' => 0 ) );

		self::assertSame( array(), $this->diff->forSettings( $field, array( 'required' => false ) ) );
		self::assertSame( array(), $this->diff->forSettings( $field, array( 'required' => '0' ) ) );
	}

	public function testBlankRepresentationsAreEquivalent(): void {
		$field = $this->field( array( 'placeholder' => '' ) );

		self::assertSame( array(), $this->diff->forSettings( $field, array( 'placeholder' => null ) ) );
	}

	public function testIdentityPropertiesAreDiffable(): void {
		$field = $this->field();

		$diffs = $this->diff->forSettings( $field, array( 'label' => 'Email Address' ) );

		self::assertSame( 'Email', $diffs['label']['from'] );
		self::assertSame( 'Email Address', $diffs['label']['to'] );
	}

	public function testVolatileKeysAreNeverDiffed(): void {
		$field = $this->field( array( 'modified' => 111 ) );

		self::assertSame(
			array(),
			$this->diff->forSettings( $field, array( 'modified' => 999, 'ID' => 42, 'parent' => 7 ) )
		);
	}

	public function testArrayOrderDoesNotMatterForAssociativeSettings(): void {
		$field = $this->field( array( 'choices' => array( 'a' => 'A', 'b' => 'B' ) ) );

		self::assertSame(
			array(),
			$this->diff->forSettings( $field, array( 'choices' => array( 'b' => 'B', 'a' => 'A' ) ) )
		);
	}

	public function testForFieldsComparesTheWholeDeclaration(): void {
		$current  = $this->field( array( 'required' => 0 ) );
		$incoming = new Field( key: 'field_abc123', name: 'email', label: 'Email', type: 'email', settings: array( 'required' => 1 ) );

		$diffs = $this->diff->forFields( $current, $incoming );

		self::assertArrayHasKey( 'type', $diffs );
		self::assertArrayHasKey( 'required', $diffs );
		self::assertArrayNotHasKey( 'label', $diffs );
	}
}
