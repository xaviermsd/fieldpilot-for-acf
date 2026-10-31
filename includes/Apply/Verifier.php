<?php
/**
 * Proves that what was applied is what was planned.
 *
 * A successful acf_update_field() call is NOT evidence that the configuration is
 * correct: it returns the field array it was handed whether or not the post write
 * did what we wanted, and a third-party acf/update_field filter can rewrite the
 * payload on its way past. So we re-read the stored state and compare.
 *
 * If verification fails, the caller restores the snapshot. That is the whole point
 * of taking one.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

use ACFJP\Acf\TreeReader;
use ACFJP\Diff\Change;
use ACFJP\Diff\ChangeSet;
use ACFJP\Diff\Conflict;
use ACFJP\Diff\SettingsDiff;
use ACFJP\Model\Field;
use ACFJP\Model\FieldKind;
use ACFJP\Model\RawTree;

defined( 'ABSPATH' ) || exit;

final class Verifier {

	public function __construct(
		private readonly TreeReader $reader,
		private readonly SettingsDiff $settingsDiff,
	) {}

	/**
	 * @param RawTree|null $before The tree the batch was planned against. When given,
	 *                             every field and setting the batch did not name is
	 *                             checked to be exactly as it was.
	 * @return list<string> Human-readable failures; empty means verified.
	 */
	public function verify( ChangeSet $changeSet, WriteResult $result, ?RawTree $before = null ): array {
		$this->reader->forget( $result->groupKey );

		$after = $this->reader->readRaw( $result->groupKey, true );

		$failures = array();

		foreach ( $changeSet->changes as $change ) {
			if ( in_array( $change->id, $result->skipped, true ) ) {
				continue;
			}

			$failure = $this->verifyChange( $change, $after, $result );

			if ( null !== $failure ) {
				$failures[] = $failure;
			}
		}

		$failures = array_merge( $failures, $this->verifyLayoutBindings( $after ) );

		if ( null !== $before ) {
			$failures = array_merge( $failures, $this->verifyUntouched( $before, $after, $changeSet, $result ) );
		}

		return $failures;
	}

	private function verifyChange( Change $change, RawTree $after, WriteResult $result ): ?string {
		return match ( $change->type ) {
			Change::UPDATE        => $this->verifyUpdate( $change, $after, $result ),
			Change::DELETE        => $this->verifyDelete( $change, $after ),
			Change::ADD           => $this->verifyAdd( $change, $after, $result ),
			Change::ADD_LAYOUT    => $this->verifyAddedKeys( $change, $after, $result ),
			Change::MOVE          => $this->verifyMove( $change, $after ),
			Change::DELETE_LAYOUT => $this->verifyLayoutGone( $change, $after ),
			default               => null,
		};
	}

	private function verifyUpdate( Change $change, RawTree $after, WriteResult $result ): ?string {
		// "Add as a new field": the original is checked by verifyUntouched(); here
		// we only need the new sibling to exist with the requested type.
		if ( Conflict::CREATE_NEW === $change->conflict?->resolution && Conflict::TYPE_CHANGE === $change->conflict->kind ) {
			return $this->verifyAddedKeys( $change, $after, $result );
		}

		$field = $after->byKey( (string) $change->targetKey );

		if ( null === $field ) {
			return sprintf(
				/* translators: %s: field label */
				__( '"%s" was updated but can no longer be found.', 'fieldpilot-for-acf' ),
				$change->label
			);
		}

		$resolution = $change->conflict?->resolution;

		foreach ( $change->settingDiffs as $setting => $diff ) {
			$setting = (string) $setting;

			// Settings the resolution deliberately withheld.
			if ( ( 'type' === $setting && \ACFJP\Diff\Conflict::KEEP_EXISTING === $resolution )
				|| ( 'name' === $setting && \ACFJP\Diff\Conflict::LABEL_ONLY === $resolution ) ) {
				continue;
			}

			$actual = match ( $setting ) {
				'label' => $field->label,
				'name'  => $field->name,
				'type'  => $field->type,
				default => $field->settings[ $setting ] ?? null,
			};

			if ( ! $this->settingsDiff->equivalent( $actual, $diff['to'] ) ) {
				return sprintf(
					/* translators: 1: field label, 2: setting name, 3: expected value, 4: actual value */
					__( '"%1$s": %2$s should be %3$s but is %4$s.', 'fieldpilot-for-acf' ),
					$change->label,
					$setting,
					Change::scalar( $diff['to'] ),
					Change::scalar( $actual )
				);
			}
		}

		return null;
	}

	private function verifyDelete( Change $change, RawTree $after ): ?string {
		if ( ! $after->has( (string) $change->targetKey ) ) {
			return null;
		}

		return sprintf(
			/* translators: %s: field label */
			__( '"%s" should have been deleted but is still present.', 'fieldpilot-for-acf' ),
			$change->label
		);
	}

	private function verifyAdd( Change $change, RawTree $after, WriteResult $result ): ?string {
		$field = $change->field;

		if ( null === $field ) {
			return null;
		}

		if ( isset( $result->addedKeys[ $change->id ] ) ) {
			return $this->verifyAddedKeys( $change, $after, $result );
		}

		// Match on name within the destination, since the key may have been minted
		// during the write.
		$siblings = $this->siblingsOf( $after, $change->parentKey, $change->layoutKey );

		foreach ( $siblings as $sibling ) {
			if ( '' !== $field->name && $sibling->name === $field->name ) {
				return $sibling->type === $field->type
					? null
					: sprintf(
						/* translators: 1: field label, 2: expected type, 3: actual type */
						__( '"%1$s" was added as %3$s but should be %2$s.', 'fieldpilot-for-acf' ),
						$change->label,
						$field->type,
						$sibling->type
					);
			}

			if ( '' === $field->name && $sibling->label === $field->label ) {
				return null;
			}
		}

		return sprintf(
			/* translators: %s: field label */
			__( '"%s" was added but is not present in the saved field group.', 'fieldpilot-for-acf' ),
			$change->label
		);
	}

	private function verifyMove( Change $change, RawTree $after ): ?string {
		$key = (string) $change->targetKey;

		if ( ! $after->has( $key ) ) {
			return sprintf(
				/* translators: %s: field label */
				__( '"%s" was moved but can no longer be found.', 'fieldpilot-for-acf' ),
				$change->label
			);
		}

		$expectedParent = $change->parentKey;
		$actualParent   = $after->parentKeyOf( $key );

		// A move to the group root is recorded as a null parent in the tree.
		if ( $expectedParent === $after->group->key ) {
			$expectedParent = null;
		}

		if ( $expectedParent !== $actualParent ) {
			return sprintf(
				/* translators: %s: field label */
				__( '"%s" did not end up in the expected container.', 'fieldpilot-for-acf' ),
				$change->label
			);
		}

		return null;
	}

	private function verifyLayoutGone( Change $change, RawTree $after ): ?string {
		if ( null === $after->layout( (string) $change->targetKey ) ) {
			return null;
		}

		return sprintf(
			/* translators: %s: layout label */
			__( 'Layout "%s" should have been removed but is still present.', 'fieldpilot-for-acf' ),
			$change->label
		);
	}

	/**
	 * Everything an add created - the field, each nested field, each layout -
	 * must be in the saved tree with the type that was asked for, and the root's
	 * declared settings must have been stored.
	 */
	private function verifyAddedKeys( Change $change, RawTree $after, WriteResult $result ): ?string {
		$root = true;

		foreach ( $result->addedKeys[ $change->id ] ?? array() as $key => $type ) {
			$key = (string) $key;

			if ( Writer::LAYOUT_MARKER === $type ) {
				if ( null === $after->layout( $key ) ) {
					return sprintf(
						/* translators: %s: layout label */
						__( 'Layout "%s" was added but is not present in the saved field group.', 'fieldpilot-for-acf' ),
						$change->label
					);
				}

				continue;
			}

			$saved = $after->byKey( $key );

			if ( null === $saved ) {
				return sprintf(
					/* translators: %s: field label */
					__( '"%s" was added but is not present in the saved field group.', 'fieldpilot-for-acf' ),
					$root ? $change->label : $key
				);
			}

			if ( $saved->type !== $type ) {
				return sprintf(
					/* translators: 1: field label, 2: expected type, 3: actual type */
					__( '"%1$s" was added as %3$s but should be %2$s.', 'fieldpilot-for-acf' ),
					'' !== $saved->label ? $saved->label : $change->label,
					$type,
					$saved->type
				);
			}

			if ( $root && Change::ADD === $change->type && null !== $change->field ) {
				$failure = $this->verifyDeclaredSettings( $change, $change->field, $saved );

				if ( null !== $failure ) {
					return $failure;
				}
			}

			$root = false;
		}

		return null;
	}

	/**
	 * The settings an added field declared must be stored as declared. Settings
	 * ACF fills in by itself are not part of the request and are not checked.
	 */
	private function verifyDeclaredSettings( Change $change, Field $wanted, Field $saved ): ?string {
		foreach ( $wanted->settings as $setting => $value ) {
			$setting = (string) $setting;

			if ( in_array( $setting, Field::VOLATILE, true ) || str_starts_with( $setting, '_' ) ) {
				continue;
			}

			$actual = $saved->settings[ $setting ] ?? null;

			if ( 'wrapper' === $setting && is_array( $value ) && is_array( $actual ) ) {
				$actual = array_intersect_key( $actual, $value );
			}

			if ( ! $this->settingsDiff->equivalent( $actual, $value ) ) {
				return sprintf(
					/* translators: 1: field label, 2: setting name, 3: expected value, 4: actual value */
					__( '"%1$s": %2$s should be %3$s but is %4$s.', 'fieldpilot-for-acf' ),
					$change->label,
					$setting,
					Change::scalar( $value ),
					Change::scalar( $actual )
				);
			}
		}

		return null;
	}

	/**
	 * The product's core promise, checked after every write: a field the batch
	 * did not name is exactly as it was, and a field it did name differs only in
	 * the settings it was asked to change.
	 *
	 * Settings present after the write but absent before are ignored: ACF fills
	 * its defaults into any field it saves, and applies the same defaults on every
	 * load anyway, so they change nothing an editor sees.
	 *
	 * @return list<string>
	 */
	private function verifyUntouched( RawTree $before, RawTree $after, ChangeSet $changeSet, WriteResult $result ): array {
		/** @var array<string,list<string>|true> $allowed key => settings it may change, or true for "removed" */
		$allowed = array();

		foreach ( $changeSet->changes as $change ) {
			if ( in_array( $change->id, $result->skipped, true ) || null === $change->targetKey ) {
				continue;
			}

			$key = (string) $change->targetKey;

			if ( Change::UPDATE === $change->type ) {
				// "Add as a new field" leaves the original untouched, so it gets no allowance.
				if ( Conflict::CREATE_NEW === $change->conflict?->resolution && Conflict::TYPE_CHANGE === $change->conflict->kind ) {
					continue;
				}

				$current         = $allowed[ $key ] ?? array();
				$allowed[ $key ] = true === $current ? true : array_merge( $current, array_map( 'strval', array_keys( $change->settingDiffs ) ) );
			}

			if ( Change::ADD === $change->type && Conflict::APPLY_INCOMING === $change->conflict?->resolution && null !== $change->field ) {
				// "Update the existing field instead" may change what the payload declared.
				$existing = (string) ( $change->conflict->context['existing_key'] ?? '' );

				if ( '' !== $existing ) {
					$current              = $allowed[ $existing ] ?? array();
					$allowed[ $existing ] = true === $current ? true : array_merge( $current, array( 'label' ), array_map( 'strval', array_keys( $change->field->settings ) ) );
				}
			}

			if ( Change::DELETE === $change->type ) {
				$allowed[ $key ] = true;

				$field = $before->byKey( $key );

				foreach ( null === $field ? array() : $field->descendants() as $descendant ) {
					$allowed[ (string) $descendant->key ] = true;
				}
			}
		}

		// ACF PRO removes a deleted container's children itself, so everything
		// below a deleted field or layout is gone by design.
		foreach ( $changeSet->ofType( Change::DELETE_LAYOUT ) as $change ) {
			$layout = null === $change->targetKey ? null : $before->layout( (string) $change->targetKey );

			foreach ( null === $layout ? array() : $layout->subFields as $sub ) {
				$allowed[ (string) $sub->key ] = true;

				foreach ( $sub->descendants() as $descendant ) {
					$allowed[ (string) $descendant->key ] = true;
				}
			}
		}

		foreach ( $result->deletedKeys as $deleted ) {
			$allowed[ $deleted ] = true;

			$field = $before->byKey( $deleted );

			foreach ( null === $field ? array() : $field->descendants() as $descendant ) {
				$allowed[ (string) $descendant->key ] = true;
			}
		}

		$failures = array();

		foreach ( $before->all() as $key => $field ) {
			$permit = $allowed[ (string) $key ] ?? array();

			if ( true === $permit ) {
				continue;
			}

			$now = $after->byKey( (string) $key );

			if ( null === $now ) {
				$failures[] = sprintf(
					/* translators: %s: field label */
					__( '"%s" disappeared although the payload did not remove it.', 'fieldpilot-for-acf' ),
					'' !== $field->label ? $field->label : (string) $key
				);
				continue;
			}

			$unexpected = array_values( array_diff( $this->changedSettings( $field, $now ), $permit ) );

			if ( array() !== $unexpected ) {
				$failures[] = sprintf(
					/* translators: 1: field label, 2: comma-separated setting names */
					__( '"%1$s" changed (%2$s) although the payload did not ask for it.', 'fieldpilot-for-acf' ),
					'' !== $field->label ? $field->label : (string) $key,
					implode( ', ', $unexpected )
				);
			}
		}

		return $failures;
	}

	/**
	 * Settings that differ between two states of one field, by name.
	 *
	 * @return list<string>
	 */
	private function changedSettings( Field $before, Field $after ): array {
		$changed = array();

		foreach ( array( 'label', 'name', 'type' ) as $prop ) {
			if ( $before->{$prop} !== $after->{$prop} ) {
				$changed[] = $prop;
			}
		}

		foreach ( $before->settings as $setting => $value ) {
			$setting = (string) $setting;

			if ( in_array( $setting, Field::VOLATILE, true ) || str_starts_with( $setting, '_acfjp' ) ) {
				continue;
			}

			if ( ! array_key_exists( $setting, $after->settings ) ) {
				if ( ! empty( $value ) ) {
					$changed[] = $setting;
				}

				continue;
			}

			if ( ! $this->settingsDiff->equivalent( $value, $after->settings[ $setting ] ) ) {
				$changed[] = $setting;
			}
		}

		return $changed;
	}

	/**
	 * ACF's Flexible Content load_field() silently reassigns a sub-field with an
	 * empty parent_layout to the FIRST layout [verified, ACF PRO 6.8.10]. A field
	 * written without that attribute therefore does not error - it quietly moves.
	 * The reader marks such fields; this turns the mark into a verification failure.
	 *
	 * @return list<string>
	 */
	private function verifyLayoutBindings( RawTree $after ): array {
		$failures = array();

		foreach ( $after->all() as $field ) {
			if ( empty( $field->settings['_acfjp_orphaned'] ) ) {
				continue;
			}

			$failures[] = sprintf(
				/* translators: %s: field label */
				__( '"%s" is inside a flexible content field but is not bound to a layout; ACF would attach it to the first one.', 'fieldpilot-for-acf' ),
				$field->label
			);
		}

		return $failures;
	}

	/**
	 * @return list<Field>
	 */
	private function siblingsOf( RawTree $tree, ?string $parentKey, ?string $layoutKey ): array {
		if ( null === $parentKey || $parentKey === $tree->group->key ) {
			return $tree->group->fields;
		}

		if ( null !== $layoutKey ) {
			$layout = $tree->layout( $layoutKey );

			return null === $layout ? array() : $layout->subFields;
		}

		$parent = $tree->byKey( $parentKey );

		if ( null === $parent ) {
			return array();
		}

		if ( FieldKind::hasLayouts( $parent->type ) ) {
			$all = array();

			foreach ( $parent->layouts as $layout ) {
				$all = array_merge( $all, $layout->subFields );
			}

			return $all;
		}

		return $parent->children;
	}
}
