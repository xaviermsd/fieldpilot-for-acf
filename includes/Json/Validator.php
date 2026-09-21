<?php
/**
 * Semantic validation of a normalised payload.
 *
 * Runs before anything touches ACF. Structural correctness is delegated to ACF's
 * own per-type schemas (FieldTypeSchemas); this class checks the things a schema
 * cannot see - operation coherence, sibling name collisions, key reuse, container
 * misuse and PRO availability.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

use ACFJP\Acf\KeyFactory;
use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Model\Field;
use ACFJP\Model\FieldKind;
use ACFJP\Model\Layout;
use ACFJP\Model\Operation;
use ACFJP\Model\Payload;

defined( 'ABSPATH' ) || exit;

final class Validator {

	private const SUPPORTED_VERSIONS = array( '1.0' );

	public function __construct(
		private readonly FieldTypeSchemas $schemas,
	) {}

	public function validate( Payload $payload ): Report {
		$report = new Report();

		$this->checkVersion( $payload, $report );
		$this->checkOperationCoherence( $payload, $report );

		$seenKeys = array();

		foreach ( $payload->add as $index => $field ) {
			$this->checkField( $field, '/add/' . $index, $report, $seenKeys );
		}

		if ( null !== $payload->group ) {
			foreach ( $payload->group->fields as $index => $field ) {
				$this->checkField( $field, '/field_group/fields/' . $index, $report, $seenKeys );
			}

			$this->checkSiblingNames( $payload->group->fields, '/field_group/fields', $report );
		}

		foreach ( $payload->addLayouts as $index => $layout ) {
			$this->checkLayout( $layout, '/add_layouts/' . $index, $report, $seenKeys );
		}

		$this->checkSiblingNames( $payload->add, '/add', $report );
		$this->checkChanges( $payload, $report );
		$this->checkMoves( $payload, $report );

		return $report;
	}

	private function checkVersion( Payload $payload, Report $report ): void {
		if ( ! in_array( $payload->version, self::SUPPORTED_VERSIONS, true ) ) {
			// Forward-compatible minors are a warning, not an error: a 1.1 payload
			// read by a 1.0 engine is defined to be readable.
			$major = (int) explode( '.', $payload->version )[0];

			if ( 1 === $major ) {
				$report->add(
					Issue::warning(
						ErrorCodes::UNSUPPORTED_VERSION,
						sprintf(
							/* translators: 1: payload version, 2: supported version */
							__( 'Payload declares version %1$s; this plugin implements %2$s. Unknown keys will be ignored.', 'fieldpilot-for-acf' ),
							$payload->version,
							self::SUPPORTED_VERSIONS[0]
						),
						'/version'
					)
				);

				return;
			}

			$report->add(
				Issue::error(
					ErrorCodes::UNSUPPORTED_VERSION,
					sprintf(
						/* translators: 1: payload version, 2: supported versions */
						__( 'Unsupported schema version %1$s. This plugin supports: %2$s.', 'fieldpilot-for-acf' ),
						$payload->version,
						implode( ', ', self::SUPPORTED_VERSIONS )
					),
					'/version'
				)
			);
		}
	}

	private function checkOperationCoherence( Payload $payload, Report $report ): void {
		$operation = $payload->operation;

		if ( $operation->requiresExistingTarget() && null === $payload->target ) {
			$report->add(
				Issue::error(
					ErrorCodes::MISSING_TARGET,
					sprintf(
						/* translators: %s: operation name */
						__( 'Operation "%s" needs a target field group.', 'fieldpilot-for-acf' ),
						$operation->value
					),
					'/target',
					array( __( 'Add {"target": {"field_group": "Your Group"}}.', 'fieldpilot-for-acf' ) )
				)
			);
		}

		if ( Operation::Create === $operation && null === $payload->group ) {
			$report->add(
				Issue::error(
					ErrorCodes::MISSING_FIELDS,
					__( 'A "create" operation needs a field group definition with fields.', 'fieldpilot-for-acf' ),
					'/field_group'
				)
			);
		}

		if ( Operation::Update === $operation && array() === $payload->changes && array() === $payload->add && array() === $payload->groupChanges ) {
			$report->add(
				Issue::error(
					ErrorCodes::MISSING_FIELDS,
					__( 'An "update" operation needs "changes", "add" or "group_changes".', 'fieldpilot-for-acf' ),
					'/changes'
				)
			);
		}

		if ( Operation::Delete === $operation && array() === $payload->delete ) {
			$report->add(
				Issue::error(
					ErrorCodes::MISSING_FIELDS,
					__( 'A "delete" operation needs a list of fields to remove.', 'fieldpilot-for-acf' ),
					'/delete'
				)
			);
		}

		if ( Operation::Move === $operation && array() === $payload->moves ) {
			$report->add(
				Issue::error(
					ErrorCodes::MISSING_FIELDS,
					__( 'A "move" operation needs at least one move.', 'fieldpilot-for-acf' ),
					'/moves'
				)
			);
		}

		if ( Operation::Add === $operation && array() === $payload->add && array() === $payload->addLayouts ) {
			$report->add(
				Issue::error(
					ErrorCodes::MISSING_FIELDS,
					__( 'An "add" operation needs fields or layouts to add.', 'fieldpilot-for-acf' ),
					'/add'
				)
			);
		}

		if ( $payload->isEmpty() && Operation::Create !== $operation ) {
			$report->add(
				Issue::error(
					ErrorCodes::EMPTY_PAYLOAD,
					__( 'This payload requests no changes.', 'fieldpilot-for-acf' ),
					'/'
				)
			);
		}
	}

	/**
	 * @param array<string,true> $seenKeys
	 */
	private function checkField( Field $field, string $pointer, Report $report, array &$seenKeys ): void {
		$type = $field->type;

		// 1. Does this install have the type at all?
		if ( ! $this->schemas->isInstalled( $type ) ) {
			if ( $this->schemas->requiresPro( $type ) ) {
				$report->add(
					Issue::error(
						ErrorCodes::FIELD_TYPE_REQUIRES_PRO,
						sprintf(
							/* translators: 1: field label, 2: field type */
							__( '"%1$s" uses the %2$s field type, which requires ACF PRO.', 'fieldpilot-for-acf' ),
							$field->label,
							$type
						),
						$pointer . '/type',
						array( __( 'Upgrade to ACF PRO, or replace this field with a type available in ACF free.', 'fieldpilot-for-acf' ) )
					)
				);
			} else {
				// A third-party type we cannot see is a warning, not an error:
				// refusing it would break every site using a custom field plugin.
				$report->add(
					Issue::warning(
						ErrorCodes::UNKNOWN_FIELD_TYPE,
						sprintf(
							/* translators: 1: field label, 2: field type */
							__( '"%1$s" uses field type "%2$s", which is not registered on this site. It will be stored but will not render until the plugin providing it is active.', 'fieldpilot-for-acf' ),
							$field->label,
							$type
						),
						$pointer . '/type',
						$this->schemas->suggestTypes( $type )
					)
				);
			}
		}

		// 2. Validate against ACF's own schema for the type.
		foreach ( $this->schemas->validateField( $field->toAcfArray(), $pointer ) as $violation ) {
			$report->add(
				Issue::warning(
					ErrorCodes::INVALID_SETTING,
					$violation['message'],
					$violation['pointer']
				)
			);
		}

		// 3. Identity.
		if ( null !== $field->key && '' !== $field->key ) {
			if ( ! KeyFactory::isWellFormed( $field->key ) ) {
				$report->add(
					Issue::error(
						ErrorCodes::INVALID_FIELD_KEY,
						sprintf(
							/* translators: %s: the supplied key */
							__( 'Field key "%s" is malformed. ACF keys look like field_5f9a1b2c3d4e5.', 'fieldpilot-for-acf' ),
							$field->key
						),
						$pointer . '/key'
					)
				);
			}

			if ( isset( $seenKeys[ $field->key ] ) ) {
				$report->add(
					Issue::error(
						ErrorCodes::DUPLICATE_KEY,
						sprintf(
							/* translators: %s: the duplicated key */
							__( 'Field key "%s" appears more than once in this payload.', 'fieldpilot-for-acf' ),
							$field->key
						),
						$pointer . '/key'
					)
				);
			}

			$seenKeys[ $field->key ] = true;
		}

		if ( '' === $field->name && ! FieldKind::isStructural( $type ) ) {
			$report->add(
				Issue::error(
					ErrorCodes::INVALID_FIELD_NAME,
					sprintf(
						/* translators: %s: field label */
						__( '"%s" has no name, and its type stores a value so it needs one.', 'fieldpilot-for-acf' ),
						$field->label !== '' ? $field->label : $type
					),
					$pointer . '/name'
				)
			);
		}

		// 4. Containers.
		if ( array() !== $field->children && ! FieldKind::hasSubFields( $type ) ) {
			$report->add(
				Issue::error(
					ErrorCodes::NOT_A_CONTAINER,
					sprintf(
						/* translators: 1: field label, 2: field type */
						__( '"%1$s" is a %2$s field and cannot contain sub-fields.', 'fieldpilot-for-acf' ),
						$field->label,
						$type
					),
					$pointer . '/sub_fields',
					array( __( 'Use a group or repeater field to nest fields.', 'fieldpilot-for-acf' ) )
				)
			);
		}

		if ( array() !== $field->layouts && ! FieldKind::hasLayouts( $type ) ) {
			$report->add(
				Issue::error(
					ErrorCodes::NOT_A_CONTAINER,
					sprintf(
						/* translators: 1: field label, 2: field type */
						__( '"%1$s" is a %2$s field and cannot contain layouts.', 'fieldpilot-for-acf' ),
						$field->label,
						$type
					),
					$pointer . '/layouts'
				)
			);
		}

		// 5. Recurse.
		foreach ( $field->children as $index => $child ) {
			$this->checkField( $child, $pointer . '/sub_fields/' . $index, $report, $seenKeys );
		}

		$this->checkSiblingNames( $field->children, $pointer . '/sub_fields', $report );

		foreach ( $field->layouts as $index => $layout ) {
			$this->checkLayout( $layout, $pointer . '/layouts/' . $index, $report, $seenKeys );
		}
	}

	/**
	 * @param array<string,true> $seenKeys
	 */
	private function checkLayout( Layout $layout, string $pointer, Report $report, array &$seenKeys ): void {
		if ( '' === $layout->name ) {
			$report->add(
				Issue::error(
					ErrorCodes::INVALID_FIELD_NAME,
					__( 'Every flexible-content layout needs a name.', 'fieldpilot-for-acf' ),
					$pointer . '/name'
				)
			);
		}

		foreach ( $layout->subFields as $index => $field ) {
			$this->checkField( $field, $pointer . '/sub_fields/' . $index, $report, $seenKeys );
		}

		$this->checkSiblingNames( $layout->subFields, $pointer . '/sub_fields', $report );
	}

	/**
	 * Two siblings with the same name write to the same meta key. ACF permits it
	 * and the result is silent data loss, so we refuse.
	 *
	 * @param list<Field> $fields
	 */
	private function checkSiblingNames( array $fields, string $pointer, Report $report ): void {
		$seen = array();

		foreach ( $fields as $index => $field ) {
			if ( '' === $field->name || FieldKind::isStructural( $field->type ) ) {
				continue;
			}

			if ( isset( $seen[ $field->name ] ) ) {
				$report->add(
					Issue::error(
						ErrorCodes::DUPLICATE_NAME,
						sprintf(
							/* translators: %s: the duplicated field name */
							__( 'Two sibling fields are both named "%s". They would write to the same meta key.', 'fieldpilot-for-acf' ),
							$field->name
						),
						$pointer . '/' . $index . '/name'
					)
				);
			}

			$seen[ $field->name ] = true;
		}
	}

	private function checkChanges( Payload $payload, Report $report ): void {
		foreach ( $payload->changes as $reference => $settings ) {
			if ( array() === $settings ) {
				$report->add(
					Issue::warning(
						ErrorCodes::EMPTY_PAYLOAD,
						sprintf(
							/* translators: %s: field reference */
							__( 'No settings were supplied for "%s", so nothing will change.', 'fieldpilot-for-acf' ),
							$reference
						),
						'/changes/' . $reference
					)
				);
			}

			// A type change on an existing field is a conflict, decided later by the
			// diff engine against real state - flagged here so it is never a surprise.
			if ( isset( $settings['type'] ) ) {
				$report->add(
					Issue::warning(
						ErrorCodes::INVALID_SETTING,
						sprintf(
							/* translators: %s: field reference */
							__( 'Changing the type of "%s" may make existing content unreadable. You will be asked to confirm.', 'fieldpilot-for-acf' ),
							$reference
						),
						'/changes/' . $reference . '/type'
					)
				);
			}

			if ( isset( $settings['name'] ) ) {
				$report->add(
					Issue::warning(
						ErrorCodes::INVALID_SETTING,
						sprintf(
							/* translators: %s: field reference */
							__( 'Renaming "%s" changes the meta key its content is stored under. You will be asked to confirm.', 'fieldpilot-for-acf' ),
							$reference
						),
						'/changes/' . $reference . '/name'
					)
				);
			}
		}
	}

	private function checkMoves( Payload $payload, Report $report ): void {
		foreach ( $payload->moves as $index => $move ) {
			if ( '' === $move->field ) {
				$report->add(
					Issue::error(
						ErrorCodes::MISSING_TARGET,
						__( 'A move needs a field to move.', 'fieldpilot-for-acf' ),
						'/moves/' . $index . '/field'
					)
				);
			}

			if ( $move->needsAnchor() && null === $move->anchor ) {
				$report->add(
					Issue::error(
						ErrorCodes::MISSING_TARGET,
						sprintf(
							/* translators: %s: position keyword */
							__( 'Position "%s" needs an "anchor" field to position against.', 'fieldpilot-for-acf' ),
							$move->position
						),
						'/moves/' . $index . '/anchor'
					)
				);
			}
		}
	}
}
