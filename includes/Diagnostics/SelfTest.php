<?php
/**
 * Proves the write path works on THIS install, against THIS ACF version.
 *
 * WHY IT LIVES IN THE PLUGIN. The pure half of this engine is covered by fast unit
 * tests that need no database. The effect half - Guard, Writer, Verifier, Restore -
 * can only be proven against real ACF, and the ACF version, the PHP version, the
 * installed field types and whatever third-party plugins filter `acf/update_field`
 * all vary per site. A test that passed on a developer's laptop says little about
 * a particular production install. This one runs where it matters.
 *
 * SAFETY. Every field group it creates carries the `_acfjp_selftest` marker in its
 * key, and cleanup deletes only groups carrying that marker. It never reads, writes
 * or deletes a field group it did not create, and it removes its own journal rows
 * afterwards. It is safe to run on a site with real content.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diagnostics;

use ACFJP\Acf\GroupLocator;
use ACFJP\Acf\TreeReader;
use ACFJP\Apply\Engine;
use ACFJP\Apply\Guard;
use ACFJP\Exceptions\AcfjpException;
use ACFJP\Exceptions\ErrorCodes;

defined( 'ABSPATH' ) || exit;

final class SelfTest {

	/** Everything this class creates carries this marker, and only these are removed. */
	private const MARKER = '_acfjp_selftest';

	private const GROUP_KEY = 'group' . self::MARKER . '_property';
	private const LOCAL_KEY = 'group' . self::MARKER . '_php';
	private const SOURCE    = 'selftest';

	private Result $result;

	public function __construct(
		private readonly Engine $engine,
		private readonly ?GroupLocator $groups = null,
		private readonly ?TreeReader $reader = null,
	) {
		$this->result = new Result();
	}

	public function run(): Result {
		$this->result = new Result();

		try {
			if ( ! $this->environment() ) {
				return $this->result;
			}

			$this->canonicalScenario();
			$this->rollback();
			$this->conflicts();
			$this->concurrency();
			$this->localPhpRefusal();
			$this->nestedStructures();
			$this->errorQuality();
		} catch ( \Throwable $e ) {
			$this->result->add(
				Check::fail(
					__( 'Unexpected', 'fieldpilot-for-acf' ),
					__( 'The self-test stopped early', 'fieldpilot-for-acf' ),
					get_class( $e ) . ': ' . $e->getMessage(),
					true
				)
			);
		} finally {
			$this->cleanup();
		}

		return $this->result;
	}

	// ---- Sections ------------------------------------------------------------

	private function environment(): bool {
		$section = __( 'Environment', 'fieldpilot-for-acf' );

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			$this->result->add( Check::fail( $section, __( 'ACF is active', 'fieldpilot-for-acf' ), '', true ) );

			return false;
		}

		$this->result->add(
			Check::pass(
				$section,
				__( 'ACF detected', 'fieldpilot-for-acf' ),
				defined( 'ACF_VERSION' ) ? 'v' . ACF_VERSION : ''
			)
		);

		$this->result->add(
			Check::pass(
				$section,
				__( 'ACF PRO', 'fieldpilot-for-acf' ),
				$this->hasPro()
					? __( 'available', 'fieldpilot-for-acf' )
					: __( 'not installed - repeater and flexible content checks will be skipped', 'fieldpilot-for-acf' )
			)
		);

		$this->result->add(
			Check::pass(
				$section,
				__( 'Field type schemas', 'fieldpilot-for-acf' ),
				function_exists( 'acf_get_field_json_schema' )
					? __( 'available (ACF 6.8+), strict validation', 'fieldpilot-for-acf' )
					: __( 'unavailable, validation is looser', 'fieldpilot-for-acf' )
			)
		);

		if ( Guard::isReadOnly() ) {
			$this->result->add(
				Check::skip(
					$section,
					__( 'Write mode', 'fieldpilot-for-acf' ),
					__( 'read-only mode is on, so nothing can be written and the write checks cannot run', 'fieldpilot-for-acf' )
				)
			);

			return false;
		}

		$this->result->add( Check::pass( $section, __( 'Write mode', 'fieldpilot-for-acf' ), __( 'enabled', 'fieldpilot-for-acf' ) ) );

		global $wpdb;

		$table  = $wpdb->prefix . 'acfjp_journal';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		$this->result->add(
			Check::true(
				$section,
				__( 'History tables exist', 'fieldpilot-for-acf' ),
				$exists,
				__( 'Deactivate and reactivate the plugin to create them.', 'fieldpilot-for-acf' ),
				true
			)
		);

		return $exists;
	}

	/**
	 * The product's core promise, asserted structurally: set one setting, add one
	 * field, and prove nothing else in the group moved by a single byte.
	 */
	private function canonicalScenario(): void {
		$section = __( '1. Partial update', 'fieldpilot-for-acf' );

		$this->resetFixture();

		$before      = $this->export();
		$beforeAgent = $before['fields'][0]['sub_fields'] ?? array();

		try {
			$plan = $this->engine->plan( $this->canonicalPayload() );

			$this->result->add( Check::true( $section, __( 'Payload validates', 'fieldpilot-for-acf' ), $plan->report->isValid() ) );
			$this->result->add( Check::is( $section, __( 'Preview shows exactly 2 changes', 'fieldpilot-for-acf' ), $plan->changeSet->count(), 2 ) );
			$this->result->add( Check::is( $section, __( 'Neither change is risky', 'fieldpilot-for-acf' ), $plan->changeSet->highestRisk()->value, 'safe' ) );

			$result = $this->engine->apply( $plan->id, array(), false, self::SOURCE );

			$this->result->add( Check::true( $section, __( 'Apply succeeded', 'fieldpilot-for-acf' ), $result->applied, '', true ) );

			$after      = $this->export();
			$afterAgent = $after['fields'][0]['sub_fields'] ?? array();

			$this->result->add( Check::is( $section, __( 'Exactly one field was added', 'fieldpilot-for-acf' ), count( $afterAgent ), count( $beforeAgent ) + 1, true ) );
			$this->result->add( Check::is( $section, __( 'The new field is WhatsApp', 'fieldpilot-for-acf' ), $afterAgent[3]['name'] ?? null, 'whatsapp' ) );

			$beforeEmail = $beforeAgent[2];
			$afterEmail  = $afterAgent[2];

			$this->result->add(
				Check::is(
					$section,
					__( 'Existing field key was NOT regenerated', 'fieldpilot-for-acf' ),
					$afterEmail['key'] ?? null,
					'field' . self::MARKER . '_email',
					true
				)
			);

			$this->result->add( Check::is( $section, __( 'required flipped to 1', 'fieldpilot-for-acf' ), (int) ( $afterEmail['required'] ?? 0 ), 1 ) );
			$this->result->add( Check::is( $section, __( 'instructions survived untouched', 'fieldpilot-for-acf' ), $afterEmail['instructions'] ?? null, 'Work address only', true ) );
			$this->result->add( Check::is( $section, __( 'placeholder survived untouched', 'fieldpilot-for-acf' ), $afterEmail['placeholder'] ?? null, 'you@example.com', true ) );

			unset( $beforeEmail['required'], $afterEmail['required'] );

			$this->result->add(
				Check::true(
					$section,
					__( 'ONLY "required" differs on the edited field', 'fieldpilot-for-acf' ),
					$this->normalise( $beforeEmail ) === $this->normalise( $afterEmail ),
					__( 'Something other than the requested setting changed. This is the core promise of the plugin.', 'fieldpilot-for-acf' ),
					true
				)
			);

			$this->result->add(
				Check::true(
					$section,
					__( 'Sibling fields are byte-identical', 'fieldpilot-for-acf' ),
					$this->normalise( $beforeAgent[0] ) === $this->normalise( $afterAgent[0] )
						&& $this->normalise( $beforeAgent[1] ) === $this->normalise( $afterAgent[1] ),
					__( 'A field the payload never mentioned was modified.', 'fieldpilot-for-acf' ),
					true
				)
			);

			$this->journalId = $result->journalId;
			$this->beforeSnapshot = $before;
		} catch ( \Throwable $e ) {
			$this->result->add( Check::fail( $section, __( 'Partial update', 'fieldpilot-for-acf' ), $this->describe( $e ), true ) );
		}
	}

	private int $journalId = 0;

	/** @var array<string,mixed> */
	private array $beforeSnapshot = array();

	private function rollback(): void {
		$section = __( '2. Rollback', 'fieldpilot-for-acf' );

		if ( 0 === $this->journalId ) {
			$this->result->add( Check::skip( $section, __( 'Rollback', 'fieldpilot-for-acf' ), __( 'nothing was applied to roll back', 'fieldpilot-for-acf' ) ) );

			return;
		}

		try {
			$this->engine->rollback( $this->journalId, self::SOURCE );

			$restored = $this->export();

			$this->result->add(
				Check::true(
					$section,
					__( 'Restores a byte-identical configuration', 'fieldpilot-for-acf' ),
					$this->normalise( $this->beforeSnapshot ) === $this->normalise( $restored ),
					__( 'The snapshot did not reproduce the original state. Rollback is the safety net; treat a failure here as blocking.', 'fieldpilot-for-acf' ),
					true
				)
			);

			$this->result->add( Check::is( $section, __( 'The added field was removed', 'fieldpilot-for-acf' ), count( $restored['fields'][0]['sub_fields'] ?? array() ), 3 ) );
		} catch ( \Throwable $e ) {
			$this->result->add( Check::fail( $section, __( 'Rollback', 'fieldpilot-for-acf' ), $this->describe( $e ), true ) );
		}
	}

	private function conflicts(): void {
		$section = __( '3. Conflicts', 'fieldpilot-for-acf' );

		$this->resetFixture();

		try {
			$plan = $this->engine->plan(
				array(
					'operation' => 'update',
					'target'    => array( 'field_group' => self::GROUP_KEY, 'path' => array( 'Agent' ) ),
					'changes'   => array( 'phone' => array( 'type' => 'email', 'label' => 'Telephone' ) ),
				)
			);

			$this->result->add( Check::is( $section, __( 'A type change raises a conflict', 'fieldpilot-for-acf' ), count( $plan->changeSet->unresolvedConflicts() ), 1 ) );

			try {
				$this->engine->apply( $plan->id, array(), true, self::SOURCE );

				$this->result->add( Check::fail( $section, __( 'Unresolved conflicts block the apply', 'fieldpilot-for-acf' ), __( 'It was applied anyway.', 'fieldpilot-for-acf' ), true ) );
			} catch ( AcfjpException $e ) {
				$this->result->add( Check::is( $section, __( 'Unresolved conflicts block the apply', 'fieldpilot-for-acf' ), $e->errorCode(), ErrorCodes::UNRESOLVED_CONFLICTS ) );
			}

			$plan       = $this->engine->plan(
				array(
					'operation' => 'update',
					'target'    => array( 'field_group' => self::GROUP_KEY, 'path' => array( 'Agent' ) ),
					'changes'   => array( 'phone' => array( 'type' => 'email', 'label' => 'Telephone' ) ),
				)
			);
			$conflictId = $plan->changeSet->conflicts()[0]->id;

			$this->engine->apply( $plan->id, array( $conflictId => 'keep_existing' ), true, self::SOURCE );

			$phone = ( $this->export()['fields'][0]['sub_fields'] ?? array() )[1] ?? array();

			$this->result->add( Check::is( $section, __( '"Keep existing" preserved the type', 'fieldpilot-for-acf' ), $phone['type'] ?? null, 'text', true ) );
			$this->result->add( Check::is( $section, __( 'The non-conflicting change still applied', 'fieldpilot-for-acf' ), $phone['label'] ?? null, 'Telephone' ) );
		} catch ( \Throwable $e ) {
			$this->result->add( Check::fail( $section, __( 'Conflict handling', 'fieldpilot-for-acf' ), $this->describe( $e ), true ) );
		}
	}

	private function concurrency(): void {
		$section = __( '4. Stale previews', 'fieldpilot-for-acf' );

		$this->resetFixture();

		try {
			$plan = $this->engine->plan( $this->canonicalPayload() );

			// Simulate a second administrator editing the group in between.
			$raw = acf_get_raw_field( 'field' . self::MARKER . '_name' );
			if ( is_array( $raw ) ) {
				$raw['label'] = 'Full Name';
				acf_update_field( $raw );
			}

			$this->flushAcf();

			try {
				$this->engine->apply( $plan->id, array(), true, self::SOURCE );

				$this->result->add( Check::fail( $section, __( 'A stale preview is refused', 'fieldpilot-for-acf' ), __( 'It was applied anyway.', 'fieldpilot-for-acf' ), true ) );
			} catch ( AcfjpException $e ) {
				$this->result->add( Check::is( $section, __( 'A stale preview is refused', 'fieldpilot-for-acf' ), $e->errorCode(), ErrorCodes::STATE_CHANGED ) );
			}
		} catch ( \Throwable $e ) {
			$this->result->add( Check::fail( $section, __( 'Concurrency', 'fieldpilot-for-acf' ), $this->describe( $e ), true ) );
		}
	}

	/**
	 * The corruption path from docs/ARCHITECTURE-REVIEW.md §B.2. Counting acf-field
	 * posts either side is the assertion that matters - a refusal that still wrote
	 * orphaned rows would look like a pass without it.
	 */
	private function localPhpRefusal(): void {
		$section = __( '5. PHP-registered groups', 'fieldpilot-for-acf' );

		global $wpdb;

		acf_add_local_field_group(
			array(
				'key'      => self::LOCAL_KEY,
				'title'    => 'ACFJP Self Test Local PHP',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				'fields'   => array(
					array( 'key' => 'field' . self::MARKER . '_local', 'name' => 'thing', 'label' => 'Thing', 'type' => 'text' ),
				),
			)
		);

		$this->flushAcf();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'acf-field'" );

		try {
			$this->engine->run(
				array(
					'operation' => 'update',
					'target'    => array( 'field_group' => self::LOCAL_KEY ),
					'changes'   => array( 'thing' => array( 'required' => true ) ),
				),
				null,
				array(),
				true,
				self::SOURCE
			);

			$this->result->add( Check::fail( $section, __( 'A PHP-registered group is refused', 'fieldpilot-for-acf' ), __( 'It was accepted.', 'fieldpilot-for-acf' ), true ) );
		} catch ( AcfjpException $e ) {
			$this->result->add( Check::is( $section, __( 'A PHP-registered group is refused', 'fieldpilot-for-acf' ), $e->errorCode(), ErrorCodes::GROUP_REGISTERED_IN_PHP, true ) );
		} catch ( \Throwable $e ) {
			$this->result->add( Check::fail( $section, __( 'A PHP-registered group is refused', 'fieldpilot-for-acf' ), $this->describe( $e ), true ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'acf-field'" );

		$this->result->add(
			Check::is(
				$section,
				__( 'No orphaned field rows were created', 'fieldpilot-for-acf' ),
				$after,
				$before,
				true
			)
		);
	}

	private function nestedStructures(): void {
		$section = __( '6. Nested structures', 'fieldpilot-for-acf' );

		if ( ! $this->hasPro() ) {
			$this->result->add( Check::skip( $section, __( 'Repeater and flexible content', 'fieldpilot-for-acf' ), __( 'ACF PRO is not installed', 'fieldpilot-for-acf' ) ) );

			return;
		}

		$this->resetFixture();

		try {
			$this->engine->run(
				array(
					'operation' => 'add',
					'target'    => array( 'field_group' => self::GROUP_KEY ),
					'add'       => array(
						array(
							'name'       => 'team',
							'label'      => 'Team',
							'type'       => 'repeater',
							'sub_fields' => array(
								array( 'name' => 'member_name', 'label' => 'Member Name', 'type' => 'text' ),
								array(
									'name'       => 'social',
									'label'      => 'Social',
									'type'       => 'group',
									'sub_fields' => array( array( 'name' => 'linkedin', 'label' => 'LinkedIn', 'type' => 'url' ) ),
								),
							),
						),
						array(
							'name'    => 'builder',
							'label'   => 'Page Builder',
							'type'    => 'flexible_content',
							'layouts' => array(
								array(
									'name'       => 'hero',
									'label'      => 'Hero',
									'sub_fields' => array(
										array( 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ),
										array( 'name' => 'hero_image', 'label' => 'Image', 'type' => 'image' ),
									),
								),
								array(
									'name'       => 'cta',
									'label'      => 'Call To Action',
									'sub_fields' => array( array( 'name' => 'cta_label', 'label' => 'Button Label', 'type' => 'text' ) ),
								),
							),
						),
					),
				),
				null,
				array(),
				true,
				self::SOURCE
			);

			$after = $this->export();

			$team = null;
			$flex = null;

			foreach ( $after['fields'] ?? array() as $field ) {
				if ( 'team' === ( $field['name'] ?? '' ) ) {
					$team = $field;
				}
				if ( 'builder' === ( $field['name'] ?? '' ) ) {
					$flex = $field;
				}
			}

			$this->result->add( Check::true( $section, __( 'Repeater created', 'fieldpilot-for-acf' ), null !== $team ) );
			$this->result->add( Check::is( $section, __( 'Repeater kept both sub-fields', 'fieldpilot-for-acf' ), count( $team['sub_fields'] ?? array() ), 2 ) );
			$this->result->add( Check::is( $section, __( 'Three-level nesting survived', 'fieldpilot-for-acf' ), count( $team['sub_fields'][1]['sub_fields'] ?? array() ), 1, true ) );

			$this->result->add( Check::true( $section, __( 'Flexible content created', 'fieldpilot-for-acf' ), null !== $flex ) );

			$layouts = array_values( $flex['layouts'] ?? array() );

			$this->result->add( Check::is( $section, __( 'Both layouts created', 'fieldpilot-for-acf' ), count( $layouts ), 2 ) );

			$heroCount = count( $layouts[0]['sub_fields'] ?? array() );
			$ctaCount  = count( $layouts[1]['sub_fields'] ?? array() );

			$this->result->add(
				Check::true(
					$section,
					__( 'Each layout kept its own fields', 'fieldpilot-for-acf' ),
					2 === $heroCount && 1 === $ctaCount,
					sprintf(
						/* translators: 1: fields in the first layout, 2: fields in the second */
						__( 'Got %1$d and %2$d, expected 2 and 1. ACF silently moves sub-fields with no layout binding into the first layout, so this usually means parent_layout was not written.', 'fieldpilot-for-acf' ),
						$heroCount,
						$ctaCount
					),
					true
				)
			);
		} catch ( \Throwable $e ) {
			$this->result->add( Check::fail( $section, __( 'Nested structures', 'fieldpilot-for-acf' ), $this->describe( $e ), true ) );
		}
	}

	private function errorQuality(): void {
		$section = __( '7. Error messages', 'fieldpilot-for-acf' );

		$this->resetFixture();

		try {
			$this->engine->plan(
				array(
					'operation' => 'update',
					'target'    => array( 'field_group' => self::GROUP_KEY, 'path' => array( 'Agent' ) ),
					'changes'   => array( 'emial' => array( 'required' => true ) ),
				)
			);

			$this->result->add( Check::fail( $section, __( 'A misspelled field name is caught', 'fieldpilot-for-acf' ), __( 'It was accepted.', 'fieldpilot-for-acf' ) ) );
		} catch ( AcfjpException $e ) {
			$this->result->add( Check::is( $section, __( 'A misspelled field name is caught', 'fieldpilot-for-acf' ), $e->errorCode(), ErrorCodes::FIELD_NOT_FOUND ) );
			$this->result->add(
				Check::true(
					$section,
					__( 'The real field name is suggested', 'fieldpilot-for-acf' ),
					in_array( 'email', $e->suggestions(), true ),
					sprintf(
						/* translators: %s: comma-separated suggestions */
						__( 'Suggestions were: %s', 'fieldpilot-for-acf' ),
						implode( ', ', $e->suggestions() ) ?: '-'
					)
				)
			);
		} catch ( \Throwable $e ) {
			$this->result->add( Check::fail( $section, __( 'Error quality', 'fieldpilot-for-acf' ), $this->describe( $e ) ) );
		}
	}

	// ---- Fixture helpers -----------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private function canonicalPayload(): array {
		return array(
			'version'   => '1.0',
			'operation' => 'update',
			'target'    => array( 'field_group' => self::GROUP_KEY, 'path' => array( 'Agent' ) ),
			'changes'   => array( 'email' => array( 'required' => true ) ),
			'add'       => array( array( 'name' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'text' ) ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fixture(): array {
		return array(
			'key'      => self::GROUP_KEY,
			'title'    => 'ACFJP Self Test Property',
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
			'active'   => false,
			'fields'   => array(
				array(
					'key'        => 'field' . self::MARKER . '_agent',
					'name'       => 'agent',
					'label'      => 'Agent',
					'type'       => 'group',
					'sub_fields' => array(
						array( 'key' => 'field' . self::MARKER . '_name', 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
						array( 'key' => 'field' . self::MARKER . '_phone', 'name' => 'phone', 'label' => 'Phone', 'type' => 'text' ),
						array(
							'key'          => 'field' . self::MARKER . '_email',
							'name'         => 'email',
							'label'        => 'Email',
							'type'         => 'email',
							'required'     => 0,
							'instructions' => 'Work address only',
							'placeholder'  => 'you@example.com',
						),
					),
				),
			),
		);
	}

	private function resetFixture(): void {
		$this->deleteGroup( self::GROUP_KEY );

		acf_import_field_group( $this->fixture() );

		$this->flushAcf();

		$this->journalId = 0;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function export( ?string $groupKey = null ): array {
		$this->flushAcf();

		$key = $groupKey ?? self::GROUP_KEY;

		if ( null !== $this->reader ) {
			try {
				return $this->reader->exportArray( $key );
			} catch ( \Throwable ) {
				// Fallback to manual export below
			}
		}

		$group = acf_get_field_group( $key );

		if ( ! is_array( $group ) ) {
			return array();
		}

		$group['fields'] = acf_get_fields( $group );

		return acf_prepare_field_group_for_export( $group );
	}

	private function deleteGroup( string $key ): void {
		// Belt and braces: only ever touch our own marked groups.
		if ( ! str_contains( $key, self::MARKER ) ) {
			return;
		}

		$group = acf_get_raw_field_group( $key );

		if ( is_array( $group ) && ! empty( $group['ID'] ) ) {
			acf_delete_field_group( (int) $group['ID'] );
		}

		$this->flushAcf();
	}

	private function cleanup(): void {
		global $wpdb;

		$this->deleteGroup( self::GROUP_KEY );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}acfjp_journal WHERE source = %s",
				self::SOURCE
			)
		);

		$this->flushAcf();
	}

	private function flushAcf(): void {
		if ( function_exists( 'acf_get_store' ) ) {
			acf_get_store( 'fields' )?->reset();
			acf_get_store( 'field-groups' )?->reset();
		}

		$this->groups?->forget();
		$this->reader?->forget();
	}

	private function hasPro(): bool {
		return class_exists( 'acf_pro' ) || defined( 'ACF_PRO' );
	}

	/**
	 * ACF stamps `modified` on every save, so it is noise in a structural comparison.
	 *
	 * @param array<string,mixed> $export
	 * @return array<string,mixed>
	 */
	private function normalise( array $export ): array {
		unset( $export['modified'], $export['ID'] );

		foreach ( $export as $key => $value ) {
			if ( is_array( $value ) ) {
				$export[ $key ] = $this->normalise( $value );
			}
		}

		return $export;
	}

	private function describe( \Throwable $e ): string {
		return $e instanceof AcfjpException
			? $e->errorCode() . ': ' . $e->getMessage()
			: get_class( $e ) . ': ' . $e->getMessage();
	}
}
