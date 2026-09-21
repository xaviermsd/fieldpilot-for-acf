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
	 * @return list<string> Human-readable failures; empty means verified.
	 */
	public function verify( ChangeSet $changeSet, WriteResult $result ): array {
		$this->reader->forget( $changeSet->groupKey );

		$after = $this->reader->readRaw( $changeSet->groupKey, true );

		$failures = array();

		foreach ( $changeSet->changes as $change ) {
			if ( in_array( $change->id, $result->skipped, true ) ) {
				continue;
			}

			$failure = $this->verifyChange( $change, $after );

			if ( null !== $failure ) {
				$failures[] = $failure;
			}
		}

		$failures = array_merge( $failures, $this->verifyLayoutBindings( $after ) );

		return $failures;
	}

	private function verifyChange( Change $change, RawTree $after ): ?string {
		return match ( $change->type ) {
			Change::UPDATE       => $this->verifyUpdate( $change, $after ),
			Change::DELETE       => $this->verifyDelete( $change, $after ),
			Change::ADD          => $this->verifyAdd( $change, $after ),
			Change::MOVE         => $this->verifyMove( $change, $after ),
			Change::DELETE_LAYOUT => $this->verifyLayoutGone( $change, $after ),
			default              => null,
		};
	}

	private function verifyUpdate( Change $change, RawTree $after ): ?string {
		$field = $after->byKey( (string) $change->targetKey );

		if ( null === $field ) {
			return sprintf(
				/* translators: %s: field label */
				__( '"%s" was updated but can no longer be found.', 'wp-acf-json-pro' ),
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
					__( '"%1$s": %2$s should be %3$s but is %4$s.', 'wp-acf-json-pro' ),
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
			__( '"%s" should have been deleted but is still present.', 'wp-acf-json-pro' ),
			$change->label
		);
	}

	private function verifyAdd( Change $change, RawTree $after ): ?string {
		$field = $change->field;

		if ( null === $field ) {
			return null;
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
						__( '"%1$s" was added as %3$s but should be %2$s.', 'wp-acf-json-pro' ),
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
			__( '"%s" was added but is not present in the saved field group.', 'wp-acf-json-pro' ),
			$change->label
		);
	}

	private function verifyMove( Change $change, RawTree $after ): ?string {
		$key = (string) $change->targetKey;

		if ( ! $after->has( $key ) ) {
			return sprintf(
				/* translators: %s: field label */
				__( '"%s" was moved but can no longer be found.', 'wp-acf-json-pro' ),
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
				__( '"%s" did not end up in the expected container.', 'wp-acf-json-pro' ),
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
			__( 'Layout "%s" should have been removed but is still present.', 'wp-acf-json-pro' ),
			$change->label
		);
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
				__( '"%s" is inside a flexible content field but is not bound to a layout; ACF would attach it to the first one.', 'wp-acf-json-pro' ),
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
