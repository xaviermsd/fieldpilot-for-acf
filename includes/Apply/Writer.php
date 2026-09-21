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

	public function __construct( private readonly KeyFactory $keys ) {}

	/**
	 * Apply a ChangeSet. Assumes the Guard has already passed and a snapshot exists.
	 *
	 * @throws ApplyException
	 */
	public function write( ChangeSet $changeSet, RawTree $current ): WriteResult {
		$this->reset();

		$groupKey = $changeSet->groupKey;
		$groupId  = $current->group->acfId ?? 0;

		$this->seedIdMap( $current, $groupKey, $groupId );

		// Ordered deliberately. Creates and updates settle before moves need their
		// IDs; deletes go last so nothing is removed that a later step depends on.
		$this->applyOfType( $changeSet, Change::CREATE_GROUP, fn( Change $c ) => $this->writeCreateGroup( $c ) );
		$this->applyOfType( $changeSet, Change::GROUP_UPDATE, fn( Change $c ) => $this->writeGroupUpdate( $c, $groupKey ) );
		$this->applyOfType( $changeSet, Change::ADD_LAYOUT, fn( Change $c ) => $this->writeLayout( $c, true ) );
		$this->applyOfType( $changeSet, Change::ADD, fn( Change $c ) => $this->writeAdd( $c, $groupKey ) );
		$this->applyOfType( $changeSet, Change::UPDATE, fn( Change $c ) => $this->writeUpdate( $c ) );
		$this->applyOfType( $changeSet, Change::MOVE, fn( Change $c ) => $this->writeMove( $c, $current, $groupKey ) );
		$this->applyOfType( $changeSet, Change::DELETE_LAYOUT, fn( Change $c ) => $this->writeLayout( $c, false ) );
		$this->applyOfType( $changeSet, Change::DELETE, fn( Change $c ) => $this->writeDelete( $c ) );

		$finalGroupId = $this->idMap[ $groupKey ] ?? $groupId;

		$this->resyncLocalJson( $groupKey );

		return new WriteResult(
			groupKey: $groupKey,
			groupId: $finalGroupId,
			createdKeys: $this->created,
			updatedKeys: $this->updated,
			deletedKeys: $this->deleted,
			skipped: $this->skipped,
		);
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

		if ( empty( $array['key'] ) ) {
			$array['key'] = $this->keys->group();
		}

		$array['fields'] ??= array();

		$imported = acf_import_field_group( $array );

		if ( ! is_array( $imported ) || empty( $imported['ID'] ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field group title */
					__( 'The field group "%s" could not be created.', 'wp-acf-json-pro' ),
					$change->label
				),
				array( 'change_id' => $change->id )
			);
		}

		$this->idMap[ (string) $imported['key'] ] = (int) $imported['ID'];
		$this->created[ (string) $imported['key'] ] = (int) $imported['ID'];
	}

	/**
	 * @throws ApplyException
	 */
	private function writeGroupUpdate( Change $change, string $groupKey ): void {
		$raw = acf_get_raw_field_group( $groupKey );

		if ( ! is_array( $raw ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field group key */
					__( 'Field group "%s" disappeared while applying changes.', 'wp-acf-json-pro' ),
					$groupKey
				)
			);
		}

		foreach ( $change->settingDiffs as $setting => $diff ) {
			$raw[ (string) $setting ] = $diff['to'];
		}

		$updated = acf_update_field_group( $raw );

		if ( ! is_array( $updated ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				__( 'The field group settings could not be saved.', 'wp-acf-json-pro' ),
				array( 'change_id' => $change->id )
			);
		}

		$this->updated[] = $groupKey;
	}

	/**
	 * @throws ApplyException
	 */
	private function writeAdd( Change $change, string $groupKey ): void {
		$field = $change->field;

		if ( null === $field ) {
			return;
		}

		$resolution = $change->conflict?->resolution;

		if ( Conflict::SKIP === $resolution ) {
			$this->skipped[] = $change->id;
			return;
		}

		// "Update the existing field instead" turns an add into an update.
		if ( Conflict::APPLY_INCOMING === $resolution && Conflict::NAME_COLLISION === $change->conflict?->kind ) {
			$existingKey = (string) ( $change->conflict->context['existing_key'] ?? '' );

			if ( '' !== $existingKey ) {
				$this->mergeIntoExisting( $existingKey, $field );
				return;
			}
		}

		if ( Conflict::CREATE_NEW === $resolution ) {
			$field = $field->withSettings( array( 'name' => $this->uniqueName( $field->name ) ) );
		}

		$field = $this->assignKeys( $field );

		$parentKey = $change->parentKey ?? $groupKey;
		$order     = isset( $change->context['order'] ) ? (int) $change->context['order'] : null;

		$flatFields = $this->flattenField( $field, $parentKey, $change->layoutKey, $order );

		$this->writeFlattened( $flatFields, $change );
	}

	/**
	 * @throws ApplyException
	 */
	private function writeUpdate( Change $change ): void {
		$key = (string) $change->targetKey;

		$diffs = $this->effectiveDiffs( $change );

		if ( null === $diffs ) {
			$this->skipped[] = $change->id;
			return;
		}

		if ( array() === $diffs ) {
			return;
		}

		$raw = acf_get_raw_field( $key );

		if ( ! is_array( $raw ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field key */
					__( 'Field "%s" could not be loaded for update.', 'wp-acf-json-pro' ),
					$key
				),
				array( 'field_key' => $key, 'change_id' => $change->id )
			);
		}

		// THE partial-update primitive. Only the named settings are touched;
		// everything else in $raw survives byte-for-byte.
		foreach ( $diffs as $setting => $diff ) {
			$raw[ (string) $setting ] = $diff['to'];
		}

		$result = acf_update_field( $raw );

		if ( ! is_array( $result ) || empty( $result['ID'] ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( '"%s" could not be updated.', 'wp-acf-json-pro' ),
					$change->label
				),
				array( 'field_key' => $key, 'change_id' => $change->id )
			);
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
			$raw = acf_get_raw_field( $key );
			$id  = is_array( $raw ) ? (int) ( $raw['ID'] ?? 0 ) : 0;
		}

		if ( 0 === $id ) {
			// Already gone. Not an error: the end state is what was asked for.
			return;
		}

		if ( ! acf_delete_field( $id ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( '"%s" could not be deleted.', 'wp-acf-json-pro' ),
					$change->label
				),
				array( 'field_key' => $key, 'change_id' => $change->id )
			);
		}

		$this->deleted[] = $key;
	}

	/**
	 * @throws ApplyException
	 */
	private function writeMove( Change $change, RawTree $current, string $groupKey ): void {
		if ( Conflict::SKIP === $change->conflict?->resolution ) {
			$this->skipped[] = $change->id;
			return;
		}

		$key = (string) $change->targetKey;
		$raw = acf_get_raw_field( $key );

		if ( ! is_array( $raw ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field key */
					__( 'Field "%s" could not be loaded for the move.', 'wp-acf-json-pro' ),
					$key
				),
				array( 'field_key' => $key )
			);
		}

		$newParentKey = $change->parentKey ?? $groupKey;

		$raw['parent'] = $this->idMap[ $newParentKey ] ?? $newParentKey;

		if ( null !== $change->layoutKey ) {
			$raw['parent_layout'] = $change->layoutKey;
		} else {
			unset( $raw['parent_layout'] );
		}

		$position = (string) ( $change->context['position'] ?? MoveSpec::POSITION_LAST );
		$anchor   = $change->context['anchor'] ?? null;

		$ordered = $this->orderedSiblingKeys( $current, $newParentKey, $change->layoutKey, $key, $position, is_string( $anchor ) ? $anchor : null );

		$raw['menu_order'] = (int) array_search( $key, $ordered, true );

		if ( ! is_array( acf_update_field( $raw ) ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field label */
					__( '"%s" could not be moved.', 'wp-acf-json-pro' ),
					$change->label
				),
				array( 'field_key' => $key )
			);
		}

		$this->updated[] = $key;

		// Renumber the rest of the destination so ordering is contiguous.
		foreach ( $ordered as $index => $siblingKey ) {
			if ( $siblingKey === $key ) {
				continue;
			}

			$sibling = acf_get_raw_field( $siblingKey );

			if ( is_array( $sibling ) && (int) ( $sibling['menu_order'] ?? -1 ) !== $index ) {
				$sibling['menu_order'] = $index;
				acf_update_field( $sibling );
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
		$raw       = acf_get_raw_field( $parentKey );

		if ( ! is_array( $raw ) ) {
			throw new ApplyException(
				ErrorCodes::WRITE_FAILED,
				sprintf(
					/* translators: %s: field key */
					__( 'Flexible content field "%s" could not be loaded.', 'wp-acf-json-pro' ),
					$parentKey
				),
				array( 'field_key' => $parentKey )
			);
		}

		$layouts = is_array( $raw['layouts'] ?? null ) ? $raw['layouts'] : array();

		if ( $adding ) {
			$layout = $change->layout;

			if ( null === $layout ) {
				return;
			}

			$layoutKey = $layout->key ?? $this->keys->layout();
			$layout    = $layout->withKey( $layoutKey );

			$stored = $layout->toAcfArray();
			$subs   = $stored['sub_fields'] ?? array();
			unset( $stored['sub_fields'] );

			$layouts[ $layoutKey ] = $stored;
			$raw['layouts']        = $layouts;

			if ( ! is_array( acf_update_field( $raw ) ) ) {
				throw new ApplyException(
					ErrorCodes::WRITE_FAILED,
					__( 'The layout could not be added.', 'wp-acf-json-pro' ),
					array( 'field_key' => $parentKey )
				);
			}

			// Layout sub-fields are ordinary acf-field posts parented to the FC
			// field and bound to the layout by parent_layout.
			foreach ( (array) $subs as $index => $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}

				$sub['parent']        = $parentKey;
				$sub['parent_layout'] = $layoutKey;
				$sub['menu_order']    = $index;

				if ( empty( $sub['key'] ) ) {
					$sub['key'] = $this->keys->field();
				}

				$this->writeFlattened( array( $sub ), $change );
			}

			return;
		}

		$layoutKey = (string) $change->targetKey;

		unset( $layouts[ $layoutKey ] );

		$raw['layouts'] = $layouts;

		acf_update_field( $raw );

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

			$saved = acf_update_field( $field );

			if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
				throw new ApplyException(
					ErrorCodes::WRITE_FAILED,
					sprintf(
						/* translators: %s: field label */
						__( '"%s" could not be written.', 'wp-acf-json-pro' ),
						(string) ( $field['label'] ?? $field['key'] )
					),
					array( 'field_key' => (string) $field['key'], 'change_id' => $change->id )
				);
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
	 * key and therefore its content.
	 */
	private function mergeIntoExisting( string $existingKey, Field $incoming ): void {
		$raw = acf_get_raw_field( $existingKey );

		if ( ! is_array( $raw ) ) {
			return;
		}

		foreach ( $incoming->settings as $setting => $value ) {
			$raw[ (string) $setting ] = $value;
		}

		$raw['label'] = $incoming->label;
		$raw['type']  = $incoming->type;

		acf_update_field( $raw );

		$this->updated[] = $existingKey;
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
	 * Destination ordering after a move.
	 *
	 * @return list<string>
	 */
	private function orderedSiblingKeys(
		RawTree $current,
		string $parentKey,
		?string $layoutKey,
		string $movingKey,
		string $position,
		?string $anchor
	): array {
		$siblings = array();

		if ( $parentKey === $current->group->key ) {
			$siblings = $current->group->fields;
		} elseif ( null !== $layoutKey ) {
			$layout   = $current->layout( $layoutKey );
			$siblings = null === $layout ? array() : $layout->subFields;
		} else {
			$parent   = $current->byKey( $parentKey );
			$siblings = null === $parent ? array() : $parent->children;
		}

		$keys = array_values(
			array_filter(
				array_map( static fn( Field $f ): string => (string) $f->key, $siblings ),
				static fn( string $k ): bool => $k !== $movingKey && '' !== $k
			)
		);

		$index = match ( $position ) {
			MoveSpec::POSITION_FIRST  => 0,
			MoveSpec::POSITION_BEFORE => $this->anchorIndex( $current, $keys, $anchor, 0 ),
			MoveSpec::POSITION_AFTER  => $this->anchorIndex( $current, $keys, $anchor, 1 ),
			default                   => count( $keys ),
		};

		array_splice( $keys, $index, 0, array( $movingKey ) );

		return $keys;
	}

	/**
	 * @param list<string> $keys
	 */
	private function anchorIndex( RawTree $current, array $keys, ?string $anchor, int $offset ): int {
		if ( null === $anchor ) {
			return count( $keys );
		}

		foreach ( $keys as $index => $key ) {
			$field = $current->byKey( $key );

			if ( $key === $anchor || ( null !== $field && ( $field->name === $anchor || 0 === strcasecmp( $field->label, $anchor ) ) ) ) {
				return $index + $offset;
			}
		}

		return count( $keys );
	}

	/**
	 * Recursively flatten a Field model into an ordered list of ACF field arrays,
	 * ensuring parents precede children and all layout / order bindings are set.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function flattenField( Field $field, string $parentKey, ?string $parentLayout = null, ?int $order = null ): array {
		$array = $field->settings;

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
				$layoutKey = $layout->key ?? ( 'layout_' . $layout->name );
				$layoutArr = $layout->settings;
				$layoutArr['key']   = $layoutKey;
				$layoutArr['name']  = $layout->name;
				$layoutArr['label'] = $layout->label;
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

	private function uniqueName( string $name ): string {
		$candidate = $name . '_2';
		$suffix    = 2;

		while ( is_array( acf_get_raw_field( $candidate ) ) && $suffix < 50 ) {
			++$suffix;
			$candidate = $name . '_' . $suffix;
		}

		return $candidate;
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
			acf_update_field_group( $group );
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
	}
}
