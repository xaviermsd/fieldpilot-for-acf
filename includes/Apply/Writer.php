<?php
/**
 * The only class in this plugin that changes ACF configuration.
 *
 * TWO NON-NEGOTIABLE MECHANICS, both verified against ACF 6.8.10 source:
 *
 * 1. PARTIAL UPDATES ARE READ-MERGE-WRITE ON RAW DATA.
 *    ACF serialises every field setting into a single post_content blob, so there
 *    is no "update one setting" API - acf_update_field()'s $specific parameter
 *    filters wp_posts COLUMNS, not settings. The only correct sequence is
 *    acf_get_raw_field() → merge the named keys → acf_update_field(). Raw, not
 *    acf_get_field(), because the load filters synthesise sub_fields and
 *    parent_repeater which must never be persisted (ARCHITECTURE-REVIEW B.3).
 *
 * 2. NESTED WRITES USE ACF'S FLATTEN-AND-MAP, NOT OUR OWN RECURSION.
 *    A child's `parent` must be a post ID that does not exist until the parent is
 *    inserted. acf_prepare_fields_for_import() flattens a subtree into a sequence
 *    where every parent precedes its children and `parent` holds a KEY; walking it
 *    with a running key⇒ID map resolves the ordering by construction. Delegating
 *    also means PRO's Repeater and Flexible Content set their own `parent_layout`
 *    and `menu_order`, so we never hand-build them (ARCHITECTURE-REVIEW B.4, B.7).
 *
 * 3. EVERYTHING HANDED TO ACF IS SLASHED FIRST.
 *    acf_update_field(), acf_update_field_group() and acf_import_field_group() all
 *    run wp_unslash() because ACF's own callers pass $_POST [verified, ACF 6.8.10].
 *    Data read from the database is already unslashed, so passing it straight in
 *    strips every backslash from instructions, defaults and choices. save() and
 *    saveGroup() add the one layer of slashes ACF is about to remove.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Apply;

use ACFJP\Acf\KeyFactory;
use ACFJP\Diff\Change;
use ACFJP\Diff\ChangeSet;
use ACFJP\Diff\Conflict;
use ACFJP\Exceptions\ApplyException;
use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Model\Field;
use ACFJP\Model\FieldKind;
use ACFJP\Model\Layout;
use ACFJP\Model\MoveSpec;
use ACFJP\Model\RawTree;

defined( 'ABSPATH' ) || exit;

final class Writer {

	/** @var array<string,int> field/group key => post ID */
	private array $idMap = array();

	/** @var array<string,int> */
	private array $created = array();

	/** @var list<string> */
	private array $updated = array();

	/** @var list<string> */
	private array $deleted = array();

	/** @var list<string> */
	private array $skipped = array();

	/** @var array<string,array<string,string>> change id => key => field type, root first */
	private array $added = array();

	/** @var array<string,list<string>> sibling scope => names claimed earlier in this batch */
	private array $claimedNames = array();

	/** The group being written. Changes from '' to the minted key when a batch creates it. */
	private string $groupKey = '';

	private ?string $createdGroupKey = null;

	private ?RawTree $current = null;

	/** Marks a layout in WriteResult::$addedKeys, which otherwise maps field keys to types. */
	public const LAYOUT_MARKER = '__layout__';

	public function __construct( private readonly KeyFactory $keys ) {}

	/**
	 * Apply a ChangeSet. Assumes the Guard has already passed and a snapshot exists.
	 *
	 * @throws ApplyException
	 */
	public function write( ChangeSet $changeSet, RawTree $current ): WriteResult {
		$this->reset();

		$this->current  = $current;
		$this->groupKey = $changeSet->groupKey;
		$groupId        = $current->group->acfId ?? 0;

		$this->seedIdMap( $current, $this->groupKey, $groupId );

		// Ordered deliberately. Creates and updates settle before moves need their
		// IDs; deletes go last so nothing is removed that a later step depends on.
		$this->applyOfType( $changeSet, Change::CREATE_GROUP, fn( Change $c ) => $this->writeCreateGroup( $c ) );
		$this->applyOfType( $changeSet, Change::GROUP_UPDATE, fn( Change $c ) => $this->writeGroupUpdate( $c ) );
		$this->applyOfType( $changeSet, Change::ADD_LAYOUT, fn( Change $c ) => $this->writeLayout( $c, true ) );
		$this->applyOfType( $changeSet, Change::ADD, fn( Change $c ) => $this->writeAdd( $c ) );
		$this->applyOfType( $changeSet, Change::UPDATE, fn( Change $c ) => $this->writeUpdate( $c ) );
		$this->applyOfType( $changeSet, Change::MOVE, fn( Change $c ) => $this->writeMove( $c ) );
		$this->applyOfType( $changeSet, Change::DELETE_LAYOUT, fn( Change $c ) => $this->writeLayout( $c, false ) );
		$this->applyOfType( $changeSet, Change::DELETE, fn( Change $c ) => $this->writeDelete( $c ) );

		$finalGroupId = $this->idMap[ $this->groupKey ] ?? $groupId;

		$this->resyncLocalJson( $this->groupKey );

		return new WriteResult(
			groupKey: $this->groupKey,
			groupId: $finalGroupId,
			createdKeys: $this->created,
			updatedKeys: $this->updated,
			deletedKeys: $this->deleted,
			skipped: $this->skipped,
			addedKeys: $this->added,
		);
	}

	/**
	 * The key of a group the last write() created, so a failed batch can remove it.
	 */
	public function createdGroupKey(): ?string {
		return $this->createdGroupKey;
	}

	// ---- Change handlers -----------------------------------------------------

	/**
	 * @throws ApplyException
	 */
	private function writeCreateGroup( Change $change ): void {
		$group = $change->context['group'] ?? null;

		// Creating a group has nothing to destroy, so ACF's own importer is the
		// right primitive here. It is confined to this method, Replace/Sync and
		// Restore - see ARCHITECTURE-REVIEW B.5.
		$array = is_array( $group ) ? $group : array( 'title' => $change->label );

		unset( $array['ID'] );

		// Fields arrive as ADD changes, so each one is keyed, verified and
		// journaled exactly like an addition to an existing group.
		$array['fields'] = array();

		$key = isset( $array['key'] ) ? (string) $array['key'] : '';

		if ( '' === $key || ! KeyFactory::isWellFormed( $key ) || is_array( acf_get_raw_field_group( $key ) ) ) {
			$key = $this->keys->group();
		}

		$array['key'] = $key;

		$imported = acf_import_field_group( wp_slash( $array ) );

		if ( ! is_array( $imported ) || empty( $imported['ID'] ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field group title */
					__( 'The field group "%s" could not be created.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array( 'change_id' => $change->id )
			);
			throw $e;
		}

		$this->groupKey        = (string) $imported['key'];
		$this->createdGroupKey = $this->groupKey;

		$this->idMap[ $this->groupKey ]   = (int) $imported['ID'];
		$this->created[ $this->groupKey ] = (int) $imported['ID'];
	}

	/**
	 * @throws ApplyException
	 */
	private function writeGroupUpdate( Change $change ): void {
		$groupKey = $this->groupKey;
		$raw      = acf_get_raw_field_group( $groupKey );

		if ( ! is_array( $raw ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field group key */
					__( 'Field group "%s" disappeared while applying changes.', 'fieldpilot-for-acf' ),
					$groupKey
				)
			);
			throw $e;
		}

		foreach ( $change->settingDiffs as $setting => $diff ) {
			$raw[ (string) $setting ] = $diff['to'];
		}

		$updated = $this->saveGroup( $raw );

		if ( ! is_array( $updated ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				__( 'The field group settings could not be saved.', 'fieldpilot-for-acf' ),
				array( 'change_id' => $change->id )
			);
			throw $e;
		}

		$this->updated[] = $groupKey;
	}

	/**
	 * @throws ApplyException
	 */
	private function writeAdd( Change $change ): void {
		$field = $change->field;

		if ( null === $field ) {
			return;
		}

		$resolution = $change->conflict?->resolution;

		if ( Conflict::SKIP === $resolution ) {
			$this->skipped[] = $change->id;
			return;
		}

		$parentKey = $change->parentKey ?? $this->groupKey;

		if ( Conflict::NAME_COLLISION === $change->conflict?->kind ) {
			// "Update the existing field instead" turns an add into an update.
			if ( Conflict::APPLY_INCOMING === $resolution ) {
				$this->mergeIntoExisting( $change, (string) ( $change->conflict->context['existing_key'] ?? '' ), $field );
				return;
			}

			if ( Conflict::CREATE_NEW === $resolution ) {
				$field = $field->withSettings( array( 'name' => $this->uniqueName( $field->name, $parentKey, $change->layoutKey ) ) );
			}
		}

		$field = $this->assignKeys( $field );

		$this->claimName( $field->name, $parentKey, $change->layoutKey );
		$this->added[ $change->id ] = $this->keyTypes( $field );

		$order = isset( $change->context['order'] ) ? (int) $change->context['order'] : null;

		$flatFields = $this->flattenField( $field, $parentKey, $change->layoutKey, $order );

		$this->writeFlattened( $flatFields, $change );
	}

	/**
	 * @throws ApplyException
	 */
	private function writeUpdate( Change $change ): void {
		$key = (string) $change->targetKey;

		if ( Conflict::CREATE_NEW === $change->conflict?->resolution && Conflict::TYPE_CHANGE === $change->conflict->kind ) {
			$this->addAsNewField( $change );
			return;
		}

		$diffs = $this->effectiveDiffs( $change );

		if ( null === $diffs ) {
			$this->skipped[] = $change->id;
			return;
		}

		if ( array() === $diffs ) {
			return;
		}

		$raw = $this->raw( $key );

		if ( ! is_array( $raw ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field key */
					__( 'Field "%s" could not be loaded for update.', 'fieldpilot-for-acf' ),
					$key
				),
				array(
					'field_key' => $key,
					'change_id' => $change->id,
				)
			);
			throw $e;
		}

		// THE partial-update primitive. Only the named settings are touched;
		// everything else in $raw survives byte-for-byte.
		foreach ( $diffs as $setting => $diff ) {
			$raw[ (string) $setting ] = $diff['to'];
		}

		$result = $this->save( $raw );

		if ( ! is_array( $result ) || empty( $result['ID'] ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( '"%s" could not be updated.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array(
					'field_key' => $key,
					'change_id' => $change->id,
				)
			);
			throw $e;
		}

		$this->updated[] = $key;
	}

	/**
	 * @throws ApplyException
	 */
	private function writeDelete( Change $change ): void {
		if ( Conflict::SKIP === $change->conflict?->resolution ) {
			$this->skipped[] = $change->id;
			return;
		}

		$key = (string) $change->targetKey;
		$id  = $this->idMap[ $key ] ?? 0;

		if ( 0 === $id ) {
			$raw = $this->raw( $key );
			$id  = is_array( $raw ) ? (int) ( $raw['ID'] ?? 0 ) : 0;
		}

		if ( 0 === $id ) {
			// Already gone. Not an error: the end state is what was asked for.
			return;
		}

		if ( ! acf_delete_field( $id ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( '"%s" could not be deleted.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array(
					'field_key' => $key,
					'change_id' => $change->id,
				)
			);
			throw $e;
		}

		$this->deleted[] = $key;
	}

	/**
	 * @throws ApplyException
	 */
	private function writeMove( Change $change ): void {
		if ( Conflict::SKIP === $change->conflict?->resolution ) {
			$this->skipped[] = $change->id;
			return;
		}

		$key = (string) $change->targetKey;
		$raw = $this->raw( $key );

		if ( ! is_array( $raw ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field key */
					__( 'Field "%s" could not be loaded for the move.', 'fieldpilot-for-acf' ),
					$key
				),
				array( 'field_key' => $key )
			);
			throw $e;
		}

		$newParentKey = $change->parentKey ?? $this->groupKey;
		$parentId     = $this->idMap[ $newParentKey ] ?? 0;

		if ( 0 === $parentId ) {
			$parent   = $this->raw( $newParentKey );
			$parentId = is_array( $parent ) ? (int) ( $parent['ID'] ?? 0 ) : 0;
		}

		if ( 0 === $parentId ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( 'The destination for "%s" could not be found.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array(
					'field_key'  => $key,
					'parent_key' => $newParentKey,
				)
			);
			throw $e;
		}

		$raw['parent'] = $parentId;

		if ( null !== $change->layoutKey ) {
			$raw['parent_layout'] = $change->layoutKey;
		} else {
			unset( $raw['parent_layout'] );
		}

		$position = (string) ( $change->context['position'] ?? MoveSpec::POSITION_LAST );
		$anchor   = $change->context['anchor'] ?? null;

		// Read the destination from the database, not the pre-write tree, so fields
		// added earlier in this batch keep their place in the numbering.
		$siblings = $this->destinationSiblings( $parentId, $change->layoutKey, $key );
		$ordered  = $this->orderAfterMove( $siblings, $key, $position, is_string( $anchor ) ? $anchor : null );

		$raw['menu_order'] = (int) array_search( $key, $ordered, true );

		if ( ! is_array( $this->save( $raw ) ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( '"%s" could not be moved.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array( 'field_key' => $key )
			);
			throw $e;
		}

		$this->updated[] = $key;

		// Renumber the rest of the destination so ordering is contiguous.
		foreach ( $ordered as $index => $siblingKey ) {
			if ( $siblingKey === $key || ! isset( $siblings[ $siblingKey ] ) ) {
				continue;
			}

			$sibling = $siblings[ $siblingKey ];

			if ( (int) ( $sibling['menu_order'] ?? -1 ) !== $index ) {
				$sibling['menu_order'] = $index;
				$this->save( $sibling );
			}
		}
	}

	/**
	 * Add or remove a flexible-content layout. Layouts live inside the owning
	 * field's settings, so this is a read-merge-write of the parent.
	 *
	 * @throws ApplyException
	 */
	private function writeLayout( Change $change, bool $adding ): void {
		$parentKey = (string) $change->parentKey;
		$raw       = $this->raw( $parentKey );

		if ( ! is_array( $raw ) || 'flexible_content' !== ( $raw['type'] ?? '' ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field key */
					__( 'Flexible content field "%s" could not be loaded.', 'fieldpilot-for-acf' ),
					$parentKey
				),
				array( 'field_key' => $parentKey )
			);
			throw $e;
		}

		$layouts = is_array( $raw['layouts'] ?? null ) ? $raw['layouts'] : array();

		if ( $adding ) {
			$layout = $change->layout;

			if ( null === $layout ) {
				return;
			}

			$layoutKey = (string) $layout->key;

			if ( '' === $layoutKey || isset( $layouts[ $layoutKey ] ) ) {
				$layoutKey = $this->keys->layout();
			}

			// Key the whole subtree first: grandchildren with no key would otherwise
			// be skipped by writeFlattened() and silently lost.
			$layout = $layout
				->withKey( $layoutKey )
				->withSubFields( array_map( fn( Field $f ): Field => $this->assignKeys( $f ), $layout->subFields ) );

			$stored = $layout->toAcfArray();
			unset( $stored['sub_fields'] );

			$layouts[ $layoutKey ] = $stored;
			$raw['layouts']        = $layouts;

			if ( ! is_array( $this->save( $raw ) ) ) {
				$e = new ApplyException(
					ErrorCodes::WRITE_FAILED,
					__( 'The layout could not be added.', 'fieldpilot-for-acf' ),
					array( 'field_key' => $parentKey )
				);
				throw $e;
			}

			$added = array( $layoutKey => self::LAYOUT_MARKER );

			// Layout sub-fields are ordinary acf-field posts parented to the FC
			// field and bound to the layout by parent_layout.
			foreach ( $layout->subFields as $index => $sub ) {
				$added += $this->keyTypes( $sub );

				$this->writeFlattened( $this->flattenField( $sub, $parentKey, $layoutKey, $index ), $change );
			}

			$this->added[ $change->id ] = $added;

			return;
		}

		$layoutKey = (string) $change->targetKey;

		unset( $layouts[ $layoutKey ] );

		$raw['layouts'] = $layouts;

		if ( ! is_array( $this->save( $raw ) ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				__( 'The layout could not be removed.', 'fieldpilot-for-acf' ),
				array( 'field_key' => $parentKey )
			);
			throw $e;
		}

		// Remove the layout's fields too, or they are orphaned and ACF silently
		// reassigns them to the first remaining layout on the next read.
		foreach ( acf_get_raw_fields( (int) $raw['ID'] ) as $sub ) {
			if ( is_array( $sub ) && ( $sub['parent_layout'] ?? '' ) === $layoutKey ) {
				acf_delete_field( (int) $sub['ID'] );
				$this->deleted[] = (string) $sub['key'];
			}
		}
	}

	// ---- Mechanics -----------------------------------------------------------

	/**
	 * Flatten a nested field definition with ACF's own importer helpers and write
	 * the sequence, resolving each `parent` key to a post ID as we go.
	 *
	 * @param list<array<string,mixed>> $fields
	 * @throws ApplyException
	 */
	private function writeFlattened( array $fields, Change $change ): void {
		$flat = acf_prepare_fields_for_import( $fields );

		foreach ( $flat as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}

			$parent = $field['parent'] ?? 0;

			if ( is_string( $parent ) && isset( $this->idMap[ $parent ] ) ) {
				$field['parent'] = $this->idMap[ $parent ];
			}

			// An existing key means update in place; ACF needs the post ID for that.
			if ( isset( $this->idMap[ (string) $field['key'] ] ) ) {
				$field['ID'] = $this->idMap[ (string) $field['key'] ];
			}

			$saved = $this->save( $field );

			if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
				$e = new ApplyException(
					ErrorCodes::WRITE_FAILED,
					sprintf(
						/* translators: %s: field label */
						__( '"%s" could not be written.', 'fieldpilot-for-acf' ),
						(string) ( $field['label'] ?? $field['key'] )
					),
					array(
						'field_key' => (string) $field['key'],
						'change_id' => $change->id,
					)
				);
				throw $e;
			}

			$key = (string) $saved['key'];

			$this->idMap[ $key ] = (int) $saved['ID'];

			if ( ! isset( $this->created[ $key ] ) && ! in_array( $key, $this->updated, true ) ) {
				$this->created[ $key ] = (int) $saved['ID'];
			}
		}
	}

	/**
	 * Merge an incoming definition into a field that already exists, preserving its
	 * key and therefore its content. The Comparator offers this only when both
	 * sides share a type and the incoming field has no children, so it never
	 * retypes a field or rebuilds a container behind the user's back.
	 *
	 * @throws ApplyException
	 */
	private function mergeIntoExisting( Change $change, string $existingKey, Field $incoming ): void {
		$raw = $this->raw( $existingKey );

		if ( ! is_array( $raw ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( 'The existing field "%s" could not be loaded, so nothing was merged into it.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array(
					'field_key' => $existingKey,
					'change_id' => $change->id,
				)
			);
			throw $e;
		}

		if ( (string) ( $raw['type'] ?? '' ) !== $incoming->type ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: 1: field label, 2: existing type, 3: incoming type */
					__( '"%1$s" is a %2$s field and the payload describes a %3$s field, so it was not changed.', 'fieldpilot-for-acf' ),
					$change->label,
					(string) ( $raw['type'] ?? '' ),
					$incoming->type
				),
				array(
					'field_key' => $existingKey,
					'change_id' => $change->id,
				)
			);
			throw $e;
		}

		foreach ( $incoming->settings as $setting => $value ) {
			if ( 'wrapper' === $setting && is_array( $value ) ) {
				$value = array_merge( is_array( $raw['wrapper'] ?? null ) ? $raw['wrapper'] : array(), $value );
			}

			$raw[ (string) $setting ] = $value;
		}

		if ( '' !== $incoming->label && ! in_array( 'label', $incoming->implicit, true ) ) {
			$raw['label'] = $incoming->label;
		}

		$saved = $this->save( $raw );

		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( '"%s" could not be updated.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array(
					'field_key' => $existingKey,
					'change_id' => $change->id,
				)
			);
			throw $e;
		}

		$this->updated[]            = $existingKey;
		$this->added[ $change->id ] = array( $existingKey => $incoming->type );
	}

	/**
	 * "Add as a new field" on a type conflict: the original keeps its type, key and
	 * content, and a sibling with the requested settings, a fresh key and a unique
	 * name is created right after it.
	 *
	 * @throws ApplyException
	 */
	private function addAsNewField( Change $change ): void {
		$original = $this->raw( (string) $change->targetKey );

		if ( ! is_array( $original ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( '"%s" could not be loaded, so no new field was added next to it.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array(
					'field_key' => (string) $change->targetKey,
					'change_id' => $change->id,
				)
			);
			throw $e;
		}

		$copy = $original;

		foreach ( $change->settingDiffs as $setting => $diff ) {
			$copy[ (string) $setting ] = $diff['to'];
		}

		$parentKey = $change->parentKey ?? $this->groupKey;

		unset( $copy['ID'] );

		$copy['key']        = $this->keys->field();
		$copy['name']       = $this->uniqueName( (string) ( $copy['name'] ?? '' ), $parentKey, $change->layoutKey );
		$copy['menu_order'] = (int) ( $original['menu_order'] ?? 0 ) + 1;

		$saved = $this->save( $copy );

		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			$e = new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( 'The new field for "%s" could not be written.', 'fieldpilot-for-acf' ),
					$change->label
				),
				array(
					'field_key' => (string) $change->targetKey,
					'change_id' => $change->id,
				)
			);
			throw $e;
		}

		$newKey = (string) $saved['key'];

		$this->claimName( (string) $copy['name'], $parentKey, $change->layoutKey );

		$this->idMap[ $newKey ]     = (int) $saved['ID'];
		$this->created[ $newKey ]   = (int) $saved['ID'];
		$this->added[ $change->id ] = array( $newKey => (string) ( $copy['type'] ?? '' ) );
	}

	/**
	 * Apply a change's conflict resolution to its setting diffs.
	 *
	 * @return array<string,array{from:mixed,to:mixed}>|null Null means skip entirely.
	 */
	private function effectiveDiffs( Change $change ): ?array {
		$diffs      = $change->settingDiffs;
		$resolution = $change->conflict?->resolution;

		return match ( $resolution ) {
			Conflict::SKIP          => null,
			Conflict::KEEP_EXISTING => array_diff_key( $diffs, array( 'type' => true ) ),
			Conflict::LABEL_ONLY    => array_diff_key( $diffs, array( 'name' => true ) ),
			default                 => $diffs,
		};
	}

	/**
	 * Assign keys to a subtree, minting only where one is missing. An existing key
	 * is never regenerated - it is the reference stored against every value.
	 */
	private function assignKeys( Field $field ): Field {
		$key = $field->key;

		if ( null === $key || '' === $key || $this->keys->isTaken( $key ) ) {
			$key = $this->keys->field();
		} else {
			$this->keys->reserve( $key );
		}

		$field = $field->withKey( $key );

		if ( array() !== $field->children ) {
			$field = $field->withChildren(
				array_map( fn( Field $child ): Field => $this->assignKeys( $child ), $field->children )
			);
		}

		if ( array() !== $field->layouts ) {
			$field = $field->withLayouts(
				array_map(
					function ( Layout $layout ): Layout {
						$layoutKey = $layout->key ?? $this->keys->layout();

						return $layout
							->withKey( $layoutKey )
							->withSubFields( array_map( fn( Field $f ): Field => $this->assignKeys( $f ), $layout->subFields ) );
					},
					$field->layouts
				)
			);
		}

		return $field;
	}

	/**
	 * Current members of a move destination, keyed by field key, in stored order.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function destinationSiblings( int $parentId, ?string $layoutKey, string $movingKey ): array {
		$siblings = array();

		foreach ( (array) acf_get_raw_fields( $parentId ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['key'] ) || $row['key'] === $movingKey ) {
				continue;
			}

			if ( 'trash' === get_post_status( (int) ( $row['ID'] ?? 0 ) ) ) {
				continue;
			}

			if ( null !== $layoutKey && ( $row['parent_layout'] ?? '' ) !== $layoutKey ) {
				continue;
			}

			$siblings[ (string) $row['key'] ] = $row;
		}

		uasort(
			$siblings,
			static fn( array $a, array $b ): int => (int) ( $a['menu_order'] ?? 0 ) <=> (int) ( $b['menu_order'] ?? 0 )
		);

		return $siblings;
	}

	/**
	 * Destination ordering after a move.
	 *
	 * @param array<string,array<string,mixed>> $siblings
	 * @return list<string>
	 */
	private function orderAfterMove( array $siblings, string $movingKey, string $position, ?string $anchor ): array {
		$keys = array_map( 'strval', array_keys( $siblings ) );

		$index = match ( $position ) {
			MoveSpec::POSITION_FIRST  => 0,
			MoveSpec::POSITION_BEFORE => $this->anchorIndex( $siblings, $anchor, 0 ),
			MoveSpec::POSITION_AFTER  => $this->anchorIndex( $siblings, $anchor, 1 ),
			default                   => count( $keys ),
		};

		array_splice( $keys, $index, 0, array( $movingKey ) );

		return array_values( $keys );
	}

	/**
	 * @param array<string,array<string,mixed>> $siblings
	 */
	private function anchorIndex( array $siblings, ?string $anchor, int $offset ): int {
		if ( null === $anchor ) {
			return count( $siblings );
		}

		$index = 0;

		foreach ( $siblings as $key => $row ) {
			if ( (string) $key === $anchor
				|| ( $row['name'] ?? null ) === $anchor
				|| 0 === strcasecmp( (string) ( $row['label'] ?? '' ), $anchor ) ) {
				return $index + $offset;
			}

			++$index;
		}

		return count( $siblings );
	}

	/**
	 * Recursively flatten a Field model into an ordered list of ACF field arrays,
	 * ensuring parents precede children and all layout / order bindings are set.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function flattenField( Field $field, string $parentKey, ?string $parentLayout = null, ?int $order = null ): array {
		$array = $field->settings;

		// The payload may name only part of the wrapper; ACF expects all three keys.
		if ( isset( $array['wrapper'] ) && is_array( $array['wrapper'] ) ) {
			$array['wrapper'] += array(
				'width' => '',
				'class' => '',
				'id'    => '',
			);
		}

		$array['key']    = (string) $field->key;
		$array['name']   = $field->name;
		$array['label']  = $field->label;
		$array['type']   = $field->type;
		$array['parent'] = $parentKey;

		if ( null !== $parentLayout ) {
			$array['parent_layout'] = $parentLayout;
		}

		if ( null !== $order ) {
			$array['menu_order'] = $order;
		} elseif ( null !== $field->menuOrder ) {
			$array['menu_order'] = $field->menuOrder;
		}

		if ( FieldKind::hasLayouts( $field->type ) ) {
			$layouts = array();
			foreach ( $field->layouts as $layout ) {
				$layoutKey             = $layout->key ?? ( 'layout_' . $layout->name );
				$layoutArr             = $layout->settings;
				$layoutArr['key']      = $layoutKey;
				$layoutArr['name']     = $layout->name;
				$layoutArr['label']    = $layout->label;
				$layouts[ $layoutKey ] = $layoutArr;
			}
			$array['layouts'] = $layouts;
		}

		$out = array( $array );

		if ( FieldKind::hasSubFields( $field->type ) ) {
			foreach ( $field->children as $index => $child ) {
				$out = array_merge( $out, $this->flattenField( $child, (string) $field->key, null, $index ) );
			}
		} elseif ( FieldKind::hasLayouts( $field->type ) ) {
			foreach ( $field->layouts as $layout ) {
				$layoutKey = $layout->key ?? ( 'layout_' . $layout->name );
				foreach ( $layout->subFields as $index => $sub ) {
					$out = array_merge( $out, $this->flattenField( $sub, (string) $field->key, $layoutKey, $index ) );
				}
			}
		}

		return $out;
	}

	/**
	 * A name no sibling uses yet, derived from $name. Names are unique per
	 * container in ACF, not site-wide, so only the destination is checked.
	 */
	private function uniqueName( string $name, string $parentKey, ?string $layoutKey ): string {
		$taken  = $this->siblingNames( $parentKey, $layoutKey );
		$suffix = 2;

		do {
			$candidate = $name . '_' . $suffix;
			++$suffix;
		} while ( in_array( $candidate, $taken, true ) );

		return $candidate;
	}

	/**
	 * @return list<string>
	 */
	private function siblingNames( string $parentKey, ?string $layoutKey ): array {
		$names   = $this->claimedNames[ $this->scope( $parentKey, $layoutKey ) ] ?? array();
		$current = $this->current;

		if ( null === $current ) {
			return $names;
		}

		if ( $parentKey === $current->group->key ) {
			$siblings = $current->group->fields;
		} elseif ( null !== $layoutKey ) {
			$layout   = $current->layout( $layoutKey );
			$siblings = null === $layout ? array() : $layout->subFields;
		} else {
			$parent   = $current->byKey( $parentKey );
			$siblings = null === $parent ? array() : $parent->children;
		}

		foreach ( $siblings as $sibling ) {
			$names[] = $sibling->name;
		}

		return $names;
	}

	private function claimName( string $name, string $parentKey, ?string $layoutKey ): void {
		if ( '' !== $name ) {
			$this->claimedNames[ $this->scope( $parentKey, $layoutKey ) ][] = $name;
		}
	}

	private function scope( string $parentKey, ?string $layoutKey ): string {
		return $parentKey . '|' . ( $layoutKey ?? '' );
	}

	/**
	 * Every key in a keyed subtree with its type, root first, for verification.
	 *
	 * @return array<string,string>
	 */
	private function keyTypes( Field $field ): array {
		$out = array( (string) $field->key => $field->type );

		foreach ( $field->descendants() as $descendant ) {
			$out[ (string) $descendant->key ] = $descendant->type;
		}

		return $out;
	}

	/**
	 * acf_get_raw_field() does not fail on an empty key: ACF runs an unfiltered
	 * query and returns whichever field post sorts first. A lookup that comes
	 * back with another field's key is treated as not found too.
	 *
	 * @return array<string,mixed>|false
	 */
	private function raw( string $key ): array|false {
		if ( '' === $key ) {
			return false;
		}

		$raw = acf_get_raw_field( $key );

		return is_array( $raw ) && ( $raw['key'] ?? '' ) === $key ? $raw : false;
	}

	/**
	 * acf_update_field() unslashes its input; see mechanic 3 above.
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>|false
	 */
	private function save( array $field ): array|false {
		$saved = acf_update_field( wp_slash( $field ) );

		// ACF hands the field back even when wp_insert_post() failed, so only a
		// real post ID shows it was stored.
		return is_array( $saved ) && ! empty( $saved['ID'] ) ? $saved : false;
	}

	/**
	 * @param array<string,mixed> $group
	 * @return array<string,mixed>|false
	 */
	private function saveGroup( array $group ): array|false {
		$saved = acf_update_field_group( wp_slash( $group ) );

		return is_array( $saved ) && ! empty( $saved['ID'] ) ? $saved : false;
	}

	/**
	 * Field-level writes do NOT trigger ACF's local-JSON writer: it hooks
	 * `acf/update_field_group` only [verified, includes/local-json.php]. Firing one
	 * group save at the end of a batch keeps acf-json/ in step with the database,
	 * so a developer's repository stays authoritative.
	 */
	private function resyncLocalJson( string $groupKey ): void {
		$group = acf_get_raw_field_group( $groupKey );

		if ( is_array( $group ) ) {
			$this->saveGroup( $group );
		}

		// Loaded field arrays are now stale everywhere in this request.
		if ( function_exists( 'acf_get_store' ) ) {
			acf_get_store( 'fields' )?->reset();
			acf_get_store( 'field-groups' )?->reset();
		}
	}

	/**
	 * @param callable(Change):void $handler
	 * @throws ApplyException
	 */
	private function applyOfType( ChangeSet $changeSet, string $type, callable $handler ): void {
		foreach ( $changeSet->ofType( $type ) as $change ) {
			$handler( $change );
		}
	}

	private function seedIdMap( RawTree $current, string $groupKey, int $groupId ): void {
		if ( $groupId > 0 ) {
			$this->idMap[ $groupKey ] = $groupId;
		}

		foreach ( $current->all() as $key => $field ) {
			if ( null !== $field->acfId ) {
				$this->idMap[ $key ] = $field->acfId;
			}

			$this->keys->reserve( $key );
		}
	}

	private function reset(): void {
		$this->idMap   = array();
		$this->created = array();
		$this->updated = array();
		$this->deleted = array();
		$this->skipped = array();
		$this->added   = array();

		$this->claimedNames    = array();
		$this->groupKey        = '';
		$this->createdGroupKey = null;
		$this->current         = null;
	}
}
