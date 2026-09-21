<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Json\Normalizer;
use ACFJP\Model\Operation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACFJP\Json\Normalizer
 * @covers \ACFJP\Json\Aliases
 * @covers \ACFJP\Json\Dialect
 */
final class NormalizerTest extends TestCase {

	private Normalizer $normalizer;

	protected function setUp(): void {
		$this->normalizer = new Normalizer();
	}

	public function testCanonicalPayload(): void {
		$payload = $this->normalizer->normalize(
			array(
				'version'   => '1.0',
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'required' => true ) ),
				'add'       => array( array( 'name' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'text' ) ),
			)
		);

		self::assertSame( Operation::Update, $payload->operation );
		self::assertSame( 'Property', $payload->target?->group );
		self::assertSame( array( 'Agent' ), $payload->target?->path );
		self::assertSame( 1, $payload->changes['email']['required'] );
		self::assertCount( 1, $payload->add );
		self::assertSame( 'whatsapp', $payload->add[0]->name );
	}

	/**
	 * An AI given no schema writes "group", "updates", "description", "default".
	 * Accepting those is the difference between pasting and hand-fixing.
	 */
	public function testLooseAiDialectIsNormalised(): void {
		$payload = $this->normalizer->normalize(
			array(
				'action'  => 'update',
				'group'   => 'Company Details',
				'updates' => array(
					array( 'field' => 'phone', 'title' => 'Business Phone', 'description' => 'Reception only' ),
				),
				'new_fields' => array(
					array( 'field_name' => 'Website URL', 'field_type' => 'url', 'default' => 'https://' ),
				),
			)
		);

		self::assertSame( Operation::Update, $payload->operation );
		self::assertSame( 'Company Details', $payload->target?->group );
		self::assertSame( 'Business Phone', $payload->changes['phone']['label'] );
		self::assertSame( 'Reception only', $payload->changes['phone']['instructions'] );

		self::assertSame( 'website_url', $payload->add[0]->name );
		self::assertSame( 'url', $payload->add[0]->type );
		self::assertSame( 'https://', $payload->add[0]->settings['default_value'] );
	}

	public function testArrowedPathStringIsSplit(): void {
		$payload = $this->normalizer->normalize(
			array(
				'operation' => 'add',
				'target'    => array( 'field_group' => 'Property', 'path' => 'Agent > Contact' ),
				'add'       => array( array( 'name' => 'x', 'type' => 'text' ) ),
			)
		);

		self::assertSame( array( 'Agent', 'Contact' ), $payload->target?->path );
	}

	public function testNameIsDerivedFromLabelAndSlugged(): void {
		$payload = $this->normalizer->normalize(
			array(
				'operation' => 'add',
				'target'    => 'Property',
				'add'       => array( array( 'label' => 'Company Café Name!', 'type' => 'text' ) ),
			)
		);

		self::assertSame( 'company_cafe_name', $payload->add[0]->name );
	}

	public function testLabelIsDerivedFromName(): void {
		$payload = $this->normalizer->normalize(
			array(
				'operation' => 'add',
				'target'    => 'Property',
				'add'       => array( array( 'name' => 'company_name', 'type' => 'text' ) ),
			)
		);

		self::assertSame( 'Company Name', $payload->add[0]->label );
	}

	public function testNestedContainersRecurse(): void {
		$payload = $this->normalizer->normalize(
			array(
				'operation' => 'add',
				'target'    => 'Property',
				'add'       => array(
					array(
						'name'     => 'team',
						'type'     => 'group',
						'children' => array(
							array( 'name' => 'lead', 'type' => 'text' ),
							array(
								'name'      => 'members',
								'type'      => 'repeater',
								'subfields' => array( array( 'name' => 'member_name', 'type' => 'text' ) ),
							),
						),
					),
				),
			)
		);

		$team = $payload->add[0];

		self::assertCount( 2, $team->children );
		self::assertSame( 'members', $team->children[1]->name );
		self::assertCount( 1, $team->children[1]->children );
		self::assertSame( 'member_name', $team->children[1]->children[0]->name );
	}

	public function testSubFieldsOnANonContainerAreDropped(): void {
		$payload = $this->normalizer->normalize(
			array(
				'operation' => 'add',
				'target'    => 'Property',
				'add'       => array(
					array( 'name' => 'title', 'type' => 'text', 'sub_fields' => array( array( 'name' => 'nope', 'type' => 'text' ) ) ),
				),
			)
		);

		self::assertSame( array(), $payload->add[0]->children );
	}

	public function testFlexibleContentLayoutsAreNormalised(): void {
		$payload = $this->normalizer->normalize(
			array(
				'operation' => 'add',
				'target'    => 'Page',
				'add'       => array(
					array(
						'name'    => 'builder',
						'type'    => 'flexible_content',
						'layouts' => array(
							array(
								'label'      => 'Hero Banner',
								'sub_fields' => array( array( 'name' => 'heading', 'type' => 'text' ) ),
							),
						),
					),
				),
			)
		);

		$layout = $payload->add[0]->layouts[0];

		self::assertSame( 'hero_banner', $layout->name );
		self::assertSame( 'Hero Banner', $layout->label );
		self::assertCount( 1, $layout->subFields );
	}

	public function testNativeAcfExportIsDetectedAndDefaultsToCreate(): void {
		$payload = $this->normalizer->normalize(
			array(
				'key'      => 'group_5f9a1b2c3d4e5',
				'title'    => 'Company Details',
				'fields'   => array(
					array( 'key' => 'field_aaa111', 'name' => 'company_name', 'label' => 'Company Name', 'type' => 'text' ),
				),
				'location' => array(),
			)
		);

		self::assertSame( 'native_acf', $payload->dialect );
		self::assertSame( Operation::Create, $payload->operation );
		self::assertSame( 'Company Details', $payload->group?->title );
		self::assertSame( 'field_aaa111', $payload->group?->fields[0]->key );
	}

	public function testNativeExportHonoursAnExplicitDefaultOperation(): void {
		$payload = $this->normalizer->normalize(
			array( 'key' => 'group_x1', 'title' => 'T', 'fields' => array(), 'location' => array() ),
			Operation::Sync
		);

		self::assertSame( Operation::Sync, $payload->operation );
	}

	public function testUnknownOperationIsRejectedNotGuessed(): void {
		$this->expectExceptionMessageMatches( '/Unknown operation/' );

		$this->normalizer->normalize( array( 'operation' => 'upsert', 'target' => 'X' ) );
	}

	public function testMissingOperationWithNoDefaultIsRejected(): void {
		$this->expectExceptionMessageMatches( '/does not say what to do/' );

		$this->normalizer->normalize( array( 'target' => 'X', 'changes' => array() ) );
	}

	public function testDeleteAcceptsAStringOrObjects(): void {
		$payload = $this->normalizer->normalize(
			array(
				'operation' => 'delete',
				'target'    => 'Property',
				'remove'    => array( 'twitter', array( 'field' => 'facebook' ) ),
			)
		);

		self::assertSame( array( 'twitter', 'facebook' ), $payload->delete );
	}
}
