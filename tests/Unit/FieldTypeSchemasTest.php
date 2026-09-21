<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Json\FieldTypeSchemas;
use ACFJP\Json\SchemaValidator;
use ACFJP\Model\Field;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACFJP\Json\FieldTypeSchemas
 */
final class FieldTypeSchemasTest extends TestCase {

	/**
	 * A stand-in for ACF's own text.json, reduced to the parts that matter here.
	 *
	 * @return array<string,mixed>
	 */
	private function textSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'label', 'type' ),
			'properties'           => array(
				'label'        => array( 'type' => 'string', 'minLength' => 1 ),
				'type'         => array( 'type' => 'string', 'enum' => array( 'text' ) ),
				'name'         => array( 'type' => 'string', 'pattern' => '^[a-z_][a-z0-9_]*$' ),
				'key'          => array( 'type' => 'string', 'pattern' => '^field_[a-z0-9]+$' ),
				'instructions' => array( 'type' => 'string' ),
				'required'     => array( 'type' => 'boolean' ),
			),
		);
	}

	private function schemas(): FieldTypeSchemas {
		$schemas = new FieldTypeSchemas( new SchemaValidator() );

		// The filter is the supported way for a third party to supply a schema, so
		// exercising it here also covers that path.
		$GLOBALS['acfjp_test_schema'] = $this->textSchema();

		return $schemas;
	}

	/**
	 * A new field has no key - the engine mints one at write time. Reporting the
	 * empty placeholder against ACF's key pattern produced a warning on every
	 * single add, which is noise the user cannot act on.
	 *
	 * Regression: reported from a live site, 2026-09-21.
	 */
	public function testAnAbsentKeyIsNotReportedAsMalformed(): void {
		$field = new Field( key: null, name: 'testing_2', label: 'Testing 2', type: 'text' );

		$array = $field->toAcfArray();

		self::assertSame( '', $array['key'], 'toAcfArray still emits the placeholder.' );

		$violations = $this->schemas()->validateField( $array, '/add/0' );

		$pointers = array_column( $violations, 'pointer' );

		self::assertNotContains( '/add/0/key', $pointers, 'An absent key must not be validated against the key pattern.' );
	}

	public function testAnAbsentNameIsNotReportedForStructuralFields(): void {
		$field = new Field( key: null, name: '', label: 'Contact Details', type: 'tab' );

		$violations = $this->schemas()->validateField( $field->toAcfArray(), '/add/0' );

		self::assertNotContains( '/add/0/name', array_column( $violations, 'pointer' ) );
	}

	/**
	 * The stripping must not become a licence to ignore a key that IS supplied and
	 * IS malformed - that one is a real mistake worth reporting.
	 */
	public function testASuppliedButMalformedKeyIsStillReported(): void {
		$field = new Field( key: 'NOT-A-KEY', name: 'thing', label: 'Thing', type: 'text' );

		$violations = $this->schemas()->validateField( $field->toAcfArray(), '/add/0' );

		self::assertContains( '/add/0/key', array_column( $violations, 'pointer' ) );
	}

	public function testAValidFieldProducesNoViolations(): void {
		$field = new Field( key: 'field_abc123', name: 'thing', label: 'Thing', type: 'text' );

		self::assertSame( array(), $this->schemas()->validateField( $field->toAcfArray(), '/add/0' ) );
	}

	public function testNoSchemaMeansNoViolations(): void {
		unset( $GLOBALS['acfjp_test_schema'] );

		$schemas = new FieldTypeSchemas( new SchemaValidator() );
		$field   = new Field( key: null, name: 'thing', label: 'Thing', type: 'some_third_party_type' );

		self::assertSame( array(), $schemas->validateField( $field->toAcfArray(), '/add/0' ) );
	}
}
