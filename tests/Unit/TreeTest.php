<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Model\FieldGroup;
use ACFJP\Model\RawTree;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACFJP\Model\Tree
 * @covers \ACFJP\Model\Field
 * @covers \ACFJP\Model\FieldGroup
 * @covers \ACFJP\Model\Layout
 */
final class TreeTest extends TestCase {

	private function tree(): RawTree {
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
								array( 'key' => 'field_email', 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => 0 ),
							),
						),
						array(
							'key'     => 'field_builder',
							'name'    => 'builder',
							'label'   => 'Page Builder',
							'type'    => 'flexible_content',
							'layouts' => array(
								'layout_hero' => array(
									'key'        => 'layout_hero',
									'name'       => 'hero',
									'label'      => 'Hero',
									'sub_fields' => array(
										array( 'key' => 'field_heading', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ),
									),
								),
							),
						),
					),
				)
			)
		);
	}

	public function testIndexesEveryFieldAtEveryDepth(): void {
		$tree = $this->tree();

		self::assertSame( 5, $tree->count() );
		self::assertNotNull( $tree->byKey( 'field_email' ) );
		self::assertNotNull( $tree->byKey( 'field_heading' ) );
	}

	public function testRecordsParentage(): void {
		$tree = $this->tree();

		self::assertNull( $tree->parentKeyOf( 'field_agent' ) );
		self::assertSame( 'field_agent', $tree->parentKeyOf( 'field_email' ) );
		self::assertSame( 'field_builder', $tree->parentKeyOf( 'field_heading' ) );
	}

	public function testRecordsLayoutMembership(): void {
		$tree = $this->tree();

		self::assertSame( 'layout_hero', $tree->layoutKeyOf( 'field_heading' ) );
		self::assertNull( $tree->layoutKeyOf( 'field_email' ) );
		self::assertSame( 'field_builder', $tree->layoutOwnerKey( 'layout_hero' ) );
	}

	public function testBuildsHumanPaths(): void {
		$tree = $this->tree();

		self::assertSame( array( 'Agent', 'Email' ), $tree->labelPath( 'field_email' ) );
		self::assertSame( 'Property › Agent › Email', $tree->displayPath( 'field_email' ) );
		self::assertSame( array( 'builder', 'hero', 'heading' ), $tree->namePath( 'field_heading' ) );
	}

	public function testDescendantChecks(): void {
		$tree = $this->tree();

		self::assertTrue( $tree->isDescendantOf( 'field_email', 'field_agent' ) );
		self::assertFalse( $tree->isDescendantOf( 'field_agent', 'field_email' ) );
	}

	/**
	 * The plan token: it must change when anything meaningful changes, and not
	 * otherwise, or optimistic concurrency either never fires or always does.
	 */
	public function testStateHashIsStableAcrossIdenticalTrees(): void {
		self::assertSame( $this->tree()->stateHash(), $this->tree()->stateHash() );
	}

	public function testStateHashChangesWhenASettingChanges(): void {
		$before = $this->tree()->stateHash();

		$group = FieldGroup::fromAcfArray(
			array(
				'key'    => 'group_prop',
				'title'  => 'Property',
				'fields' => array(
					array( 'key' => 'field_agent', 'name' => 'agent', 'label' => 'Agent', 'type' => 'group', 'sub_fields' => array() ),
				),
			)
		);

		self::assertNotSame( $before, ( new RawTree( $group ) )->stateHash() );
	}

	public function testPartialSettingMergePreservesEverythingElse(): void {
		$field = $this->tree()->byKey( 'field_email' );

		self::assertNotNull( $field );

		$merged = $field->withSettings( array( 'required' => 1 ) );

		self::assertSame( 1, $merged->settings['required'] );
		self::assertSame( 'email', $merged->name );
		self::assertSame( 'Email', $merged->label );
		self::assertSame( 'email', $merged->type );
		self::assertSame( $field->key, $merged->key );
	}

	public function testReservedKeysNeverLeakIntoSettings(): void {
		$field = $this->tree()->byKey( 'field_email' );

		self::assertNotNull( $field );

		foreach ( array( 'key', 'name', 'label', 'type', 'sub_fields', 'parent', 'ID' ) as $reserved ) {
			self::assertArrayNotHasKey( $reserved, $field->settings, $reserved . ' leaked into settings' );
		}
	}
}
