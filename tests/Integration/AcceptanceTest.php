<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Integration;

use ACFJP\Core\Plugin;
use ACFJP\Model\Operation;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end against a real WordPress + ACF, via wp-env.
 *
 *   npx wp-env start
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/fieldpilot-for-acf \
 *       vendor/bin/phpunit --testsuite integration
 *
 * These tests write to a real database. They are the only place the Writer,
 * Verifier, Restore and the two content-dependent invariants are exercised.
 *
 * @group integration
 */
final class AcceptanceTest extends TestCase {

	private const GROUP_KEY = 'group_acfjp_acceptance';

	protected function setUp(): void {
		if ( ! function_exists( 'acf_import_field_group' ) ) {
			self::markTestSkipped( 'Requires a WordPress environment with ACF active.' );
		}

		wp_set_current_user( 1 );

		acf_import_field_group( $this->fixture() );
	}

	protected function tearDown(): void {
		if ( function_exists( 'acf_get_raw_field_group' ) ) {
			$group = acf_get_raw_field_group( self::GROUP_KEY );

			if ( is_array( $group ) && ! empty( $group['ID'] ) ) {
				acf_delete_field_group( (int) $group['ID'] );
			}
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fixture(): array {
		return array(
			'key'      => self::GROUP_KEY,
			'title'    => 'Property',
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
			'fields'   => array(
				array(
					'key'        => 'field_acfjp_agent',
					'name'       => 'agent',
					'label'      => 'Agent',
					'type'       => 'group',
					'sub_fields' => array(
						array( 'key' => 'field_acfjp_name', 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
						array( 'key' => 'field_acfjp_phone', 'name' => 'phone', 'label' => 'Phone', 'type' => 'text' ),
						array(
							'key'          => 'field_acfjp_email',
							'name'         => 'email',
							'label'        => 'Email',
							'type'         => 'email',
							'required'     => 0,
							'instructions' => 'Work address only',
						),
					),
				),
			),
		);
	}

	/**
	 * THE regression test for the entire product.
	 *
	 * Export before, apply, export after, and assert the two documents differ in
	 * EXACTLY the two places the payload named. Not semantically - structurally.
	 * If this ever goes red, the core promise is broken.
	 */
	public function testCanonicalScenarioChangesOnlyWhatWasAsked(): void {
		$engine = Plugin::instance()->engine();

		$before = acf_prepare_field_group_for_export( acf_get_field_group( self::GROUP_KEY ) );

		$result = $engine->run(
			array(
				'version'   => '1.0',
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'required' => true ) ),
				'add'       => array( array( 'name' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'text' ) ),
			),
			null,
			array(),
			false,
			'test'
		);

		self::assertTrue( $result->applied );
		self::assertSame( 2, $result->changeSet->count() );

		$after = acf_prepare_field_group_for_export( acf_get_field_group( self::GROUP_KEY ) );

		$beforeAgent = $before['fields'][0]['sub_fields'];
		$afterAgent  = $after['fields'][0]['sub_fields'];

		// One field was appended, nothing removed, nothing reordered.
		self::assertCount( count( $beforeAgent ) + 1, $afterAgent );
		self::assertSame( 'whatsapp', $afterAgent[3]['name'] );

		// Email: required flipped, everything else byte-identical.
		$beforeEmail = $beforeAgent[2];
		$afterEmail  = $afterAgent[2];

		self::assertSame( 'field_acfjp_email', $afterEmail['key'], 'Keys must never be regenerated.' );
		self::assertSame( 1, (int) $afterEmail['required'] );
		self::assertSame( 'Work address only', $afterEmail['instructions'] );

		unset( $beforeEmail['required'], $afterEmail['required'] );
		self::assertSame( $beforeEmail, $afterEmail, 'Only "required" may differ on the email field.' );

		// Name and Phone are untouched, byte for byte.
		self::assertSame( $beforeAgent[0], $afterAgent[0] );
		self::assertSame( $beforeAgent[1], $afterAgent[1] );
	}

	/**
	 * INVARIANT 4 - ROLLBACK.
	 */
	public function testRollbackRestoresAByteIdenticalExport(): void {
		$engine = Plugin::instance()->engine();

		$before = acf_prepare_field_group_for_export( acf_get_field_group( self::GROUP_KEY ) );

		$result = $engine->run(
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Property', 'path' => array( 'Agent' ) ),
				'changes'   => array( 'email' => array( 'required' => true, 'label' => 'E-mail' ) ),
				'add'       => array( array( 'name' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'text' ) ),
			),
			null,
			array(),
			true,
			'test'
		);

		$engine->rollback( $result->journalId, 'test' );

		$restored = acf_prepare_field_group_for_export( acf_get_field_group( self::GROUP_KEY ) );

		self::assertSame(
			$this->normalise( $before ),
			$this->normalise( $restored ),
			'Rollback must restore the exact prior configuration.'
		);
	}

	/**
	 * A PHP-registered group must be refused, not silently forked into orphaned
	 * acf-field posts. See docs/ARCHITECTURE-REVIEW.md B.2.
	 */
	public function testPhpRegisteredGroupIsRefused(): void {
		acf_add_local_field_group(
			array(
				'key'    => 'group_acfjp_local_php',
				'title'  => 'Locally Registered',
				'fields' => array( array( 'key' => 'field_acfjp_local', 'name' => 'thing', 'label' => 'Thing', 'type' => 'text' ) ),
			)
		);

		$this->expectExceptionMessageMatches( '/registered in PHP/' );

		Plugin::instance()->engine()->run(
			array(
				'operation' => 'update',
				'target'    => array( 'field_group' => 'Locally Registered' ),
				'changes'   => array( 'thing' => array( 'required' => true ) ),
			),
			null,
			array(),
			true,
			'test'
		);
	}

	/**
	 * ACF stamps `modified` on every save, so it must be excluded before comparing.
	 *
	 * @param array<string,mixed> $export
	 * @return array<string,mixed>
	 */
	private function normalise( array $export ): array {
		unset( $export['modified'], $export['ID'] );

		array_walk_recursive(
			$export,
			static function ( &$value, $key ): void {
				if ( 'modified' === $key ) {
					$value = null;
				}
			}
		);

		return $export;
	}
}
