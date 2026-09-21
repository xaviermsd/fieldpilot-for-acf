<?php
/**
 * Computes what a payload would change.
 *
 * PURE. This class reads a tree and a payload and returns a ChangeSet. It performs
 * no writes and, apart from asking DataProbe whether a field holds content, touches
 * no global state. That is what makes preview trustworthy and the whole engine
 * testable from fixtures.
 *
 * It accepts only a RawTree. See docs/ARCHITECTURE-REVIEW.md B.8 - a filtered tree
 * contains fields ACF synthesised and, with seamless Clone, fields belonging to
 * other field groups.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diff;

use ACFJP\Acf\DataProbe;
use ACFJP\Exceptions\ResolutionException;
use ACFJP\Model\Field;
use ACFJP\Model\FieldKind;
use ACFJP\Model\Layout;
use ACFJP\Model\MoveSpec;
use ACFJP\Model\Operation;
use ACFJP\Model\Payload;
use ACFJP\Model\RawTree;
use ACFJP\Resolve\Locus;
use ACFJP\Resolve\TargetResolver;

defined( 'ABSPATH' ) || exit;

final class Comparator {

	/** @var list<Change> */
	private array $changes = array();

	public function __construct(
		private readonly TargetResolver $resolver,
		private readonly Matcher $matcher,
		private readonly SettingsDiff $settingsDiff,
		private readonly DataProbe $probe,
	) {}

	/**
	 * @throws ResolutionException When a referenced field cannot be resolved.
	 */
	public function compare( RawTree $current, Payload $payload, Locus $locus ): ChangeSet {
		$this->changes = array();

		match ( $payload->operation ) {
			Operation::Create  => $this->planCreate( $payload ),
			Operation::Add     => $this->planAdd( $current, $payload, $locus ),
			Operation::Update  => $this->planUpdate( $current, $payload, $locus ),
			Operation::Delete  => $this->planDelete( $current, $payload, $locus ),
			Operation::Move    => $this->planMoves( $current, $payload, $locus ),
			Operation::Merge,
			Operation::Sync,
			Operation::Replace => $this->planDeclarative( $current, $payload, $locus ),
		};

		$this->planGroupChanges( $current, $payload );

		return new ChangeSet(
			groupKey: (string) $current->group->key,
			groupTitle: $current->group->title,
			operation: $payload->operation->value,
			changes: $this->changes,
			beforeHash: $current->stateHash(),
		);
	}

	// ---- Operations ----------------------------------------------------------

	private function planCreate( Payload $payload ): void {
		$group = $payload->group;

		if ( null === $group ) {
			return;
		}

		$this->changes[] = new Change(
			id: Change::makeId( Change::CREATE_GROUP, array( 'title' => $group->title ) ),
			type: Change::CREATE_GROUP,
			risk: Risk::Safe,
			label: $group->title,
			path: $group->title,
			targetKey: $group->key,
			context: array( 'field_count' => count( $group->fields ) ),
		);

		foreach ( $group->fields as $order => $field ) {
			$this->emitAdd( $field, null, null, $group->title, $order );
		}
	}

	private function planAdd( RawTree $current, Payload $payload, Locus $locus ): void {
		$siblings = $this->siblingsAt( $current, $locus );
		$order    = count( $siblings );

		foreach ( $payload->add as $field ) {
			$change = $this->emitAdd( $field, $locus->parentKey(), $locus->layoutKey, $locus->display, $order );
			++$order;

			// A new field whose name is already taken among its siblings would write
			// to the same meta key as the existing one.
			$collision = $this->findSiblingByName( $siblings, $field->name );

			if ( null !== $collision && null !== $change ) {
				$this->replaceLast(
					$change->withConflict(
						Conflict::nameCollision(
							Change::makeId( 'conflict_name', array( 'name' => $field->name, 'parent' => $locus->parentKey() ) ),
							$field->name,
							$locus->display,
							array( 'existing_key' => $collision->key )
						)
					)
				);
			}
		}

		foreach ( $payload->addLayouts as $layout ) {
			$this->emitAddLayout( $layout, $locus );
		}
	}

	private function planUpdate( RawTree $current, Payload $payload, Locus $locus ): void {
		foreach ( $payload->changes as $reference => $settings ) {
			$field = $this->resolver->resolveField( $current, (string) $reference, $locus->parentKey(), $locus->layoutKey );

			$diffs = $this->settingsDiff->forSettings( $field, $settings );

			if ( array() === $diffs ) {
				continue;
			}

			$this->emitUpdate( $current, $field, $diffs );
		}

		// `update` may also carry additions; that is the canonical acceptance case.
		if ( array() !== $payload->add || array() !== $payload->addLayouts ) {
			$this->planAdd( $current, $payload, $locus );
		}
	}

	private function planDelete( RawTree $current, Payload $payload, Locus $locus ): void {
		foreach ( $payload->delete as $reference ) {
			$field = $this->resolver->resolveField( $current, $reference, $locus->parentKey(), $locus->layoutKey );

			$this->emitDelete( $current, $field );
		}
	}

	private function planMoves( RawTree $current, Payload $payload, Locus $locus ): void {
		foreach ( $payload->moves as $move ) {
			$this->emitMove( $current, $move, $locus );
		}
	}

	/**
	 * merge / sync / replace share one tree walk; they differ only in what happens
	 * to fields the payload does not mention.
	 */
	private function planDeclarative( RawTree $current, Payload $payload, Locus $locus ): void {
		$incoming = $payload->group?->fields ?? $payload->add;

		$this->walkDeclarative(
			$current,
			$this->siblingsAt( $current, $locus ),
			$incoming,
			$locus->parentKey(),
			$locus->layoutKey,
			$locus->display,
			Operation::Merge !== $payload->operation
		);
	}

	/**
	 * @param list<Field> $currentFields
	 * @param list<Field> $incomingFields
	 */
	private function walkDeclarative(
		RawTree $current,
		array $currentFields,
		array $incomingFields,
		?string $parentKey,
		?string $layoutKey,
		string $path,
		bool $deleteMissing
	): void {
		$result = $this->matcher->match( $currentFields, $incomingFields );

		foreach ( $result['pairs'] as $pair ) {
			$existing = $pair['current'];
			$wanted   = $pair['incoming'];

			$diffs = $this->settingsDiff->forFields( $existing, $wanted );

			if ( array() !== $diffs ) {
				$this->emitUpdate( $current, $existing, $diffs );
			}

			// Recurse into containers that exist on both sides.
			if ( FieldKind::hasSubFields( $existing->type ) && FieldKind::hasSubFields( $wanted->type ) ) {
				$this->walkDeclarative(
					$current,
					$existing->children,
					$wanted->children,
					$existing->key,
					null,
					$path . ' › ' . $existing->label,
					$deleteMissing
				);
			}

			if ( FieldKind::hasLayouts( $existing->type ) && FieldKind::hasLayouts( $wanted->type ) ) {
				$this->walkLayouts( $current, $existing, $wanted, $path, $deleteMissing );
			}
		}

		$order = count( $currentFields );

		foreach ( $result['added'] as $field ) {
			$this->emitAdd( $field, $parentKey, $layoutKey, $path, $order );
			++$order;
		}

		if ( $deleteMissing ) {
			foreach ( $result['removed'] as $field ) {
				$this->emitDelete( $current, $field );
			}
		}
	}

	private function walkLayouts( RawTree $current, Field $existing, Field $wanted, string $path, bool $deleteMissing ): void {
		$existingByName = array();

		foreach ( $existing->layouts as $layout ) {
			$existingByName[ $layout->name ] = $layout;
		}

		$seen = array();

		foreach ( $wanted->layouts as $layout ) {
			$match = $existingByName[ $layout->name ] ?? null;

			if ( null === $match ) {
				$this->emitAddLayout( $layout, new Locus( $existing, null, $path . ' › ' . $existing->label ) );
				continue;
			}

			$seen[ $layout->name ] = true;

			$this->walkDeclarative(
				$current,
				$match->subFields,
				$layout->subFields,
				$existing->key,
				$match->key ?? $match->name,
				$path . ' › ' . $existing->label . ' › ' . $match->label,
				$deleteMissing
			);
		}

		if ( ! $deleteMissing ) {
			return;
		}

		foreach ( $existing->layouts as $layout ) {
			if ( isset( $seen[ $layout->name ] ) ) {
				continue;
			}

			$hasContent = $this->layoutHasContent( $layout );

			$this->changes[] = new Change(
				id: Change::makeId( Change::DELETE_LAYOUT, array( 'layout' => $layout->key ?? $layout->name ) ),
				type: Change::DELETE_LAYOUT,
				risk: $hasContent ? Risk::Destructive : Risk::Caution,
				label: '' !== $layout->label ? $layout->label : $layout->name,
				path: $path . ' › ' . $existing->label,
				targetKey: $layout->key,
				layout: $layout,
				parentKey: $existing->key,
				hasContent: $this->probe->isEnabled() ? $hasContent : null,
				context: array( 'sub_field_count' => count( $layout->subFields ) ),
			);
		}
	}

	private function planGroupChanges( RawTree $current, Payload $payload ): void {
		$declared = $payload->groupChanges;

		if ( null !== $payload->group && Operation::Create !== $payload->operation ) {
			$declared = array_merge( $payload->group->settings, array( 'title' => $payload->group->title ), $declared );
		}

		if ( array() === $declared ) {
			return;
		}

		$diffs = array();

		foreach ( $declared as $setting => $to ) {
			$setting = (string) $setting;

			if ( in_array( $setting, \ACFJP\Model\FieldGroup::VOLATILE, true ) || 'fields' === $setting ) {
				continue;
			}

			$from = 'title' === $setting ? $current->group->title : ( $current->group->settings[ $setting ] ?? null );

			if ( ! $this->settingsDiff->equivalent( $from, $to ) ) {
				$diffs[ $setting ] = array( 'from' => $from, 'to' => $to );
			}
		}

		if ( array() === $diffs ) {
			return;
		}

		$this->changes[] = new Change(
			id: Change::makeId( Change::GROUP_UPDATE, array( 'group' => $current->group->key ) ),
			type: Change::GROUP_UPDATE,
			risk: isset( $diffs['location'] ) ? Risk::Caution : Risk::Safe,
			label: $current->group->title,
			path: $current->group->title,
			targetKey: $current->group->key,
			settingDiffs: $diffs,
		);
	}

	// ---- Emitters ------------------------------------------------------------

	private function emitAdd( Field $field, ?string $parentKey, ?string $layoutKey, string $path, int $order ): Change {
		$descendants = count( $field->descendants() );

		$change = new Change(
			id: Change::makeId( Change::ADD, array( 'name' => $field->name, 'parent' => $parentKey, 'layout' => $layoutKey, 'order' => $order ) ),
			type: Change::ADD,
			risk: Risk::Safe,
			label: '' !== $field->label ? $field->label : $field->name,
			path: $path,
			targetKey: $field->key,
			fieldType: $field->type,
			field: $field,
			parentKey: $parentKey,
			layoutKey: $layoutKey,
			context: array_filter(
				array(
					'order'       => $order,
					'descendants' => $descendants,
				)
			),
		);

		$this->changes[] = $change;

		return $change;
	}

	private function emitAddLayout( Layout $layout, Locus $locus ): void {
		$this->changes[] = new Change(
			id: Change::makeId( Change::ADD_LAYOUT, array( 'name' => $layout->name, 'parent' => $locus->parentKey() ) ),
			type: Change::ADD_LAYOUT,
			risk: Risk::Safe,
			label: '' !== $layout->label ? $layout->label : $layout->name,
			path: $locus->display,
			targetKey: $layout->key,
			layout: $layout,
			parentKey: $locus->parentKey(),
			context: array( 'sub_field_count' => count( $layout->subFields ) ),
		);
	}

	/**
	 * @param array<string,array{from:mixed,to:mixed}> $diffs
	 */
	private function emitUpdate( RawTree $current, Field $field, array $diffs ): void {
		$key        = (string) $field->key;
		$hasContent = $this->probe->hasContent( $key );

		$conflict = null;
		$risk     = Risk::Safe;

		// A type change is never silent.
		if ( isset( $diffs['type'] ) ) {
			$from = (string) $diffs['type']['from'];
			$to   = (string) $diffs['type']['to'];

			$structural = FieldKind::isContainer( $from ) || FieldKind::isContainer( $to );

			$conflict = $structural
				? Conflict::containerChange(
					Change::makeId( 'conflict_container', array( 'key' => $key ) ),
					$field->label,
					$from,
					$to,
					array( 'field_key' => $key )
				)
				: Conflict::typeChange(
					Change::makeId( 'conflict_type', array( 'key' => $key ) ),
					$field->label,
					$from,
					$to,
					$hasContent,
					array( 'field_key' => $key )
				);

			$risk = $hasContent || $structural ? Risk::Destructive : Risk::Caution;
		}

		// A name change relocates the meta key its content lives under.
		if ( isset( $diffs['name'] ) && $hasContent ) {
			$conflict ??= Conflict::renameWithData(
				Change::makeId( 'conflict_rename', array( 'key' => $key ) ),
				$field->label,
				(string) $diffs['name']['from'],
				(string) $diffs['name']['to'],
				array( 'field_key' => $key )
			);

			$risk = Risk::Destructive;
		} elseif ( isset( $diffs['name'] ) ) {
			$risk = Risk::highest( array( $risk, Risk::Caution ) );
		}

		$this->changes[] = new Change(
			id: Change::makeId( Change::UPDATE, array( 'key' => $key ) ),
			type: Change::UPDATE,
			risk: $risk,
			label: '' !== $field->label ? $field->label : $field->name,
			path: $current->displayPath( $key ),
			targetKey: $key,
			fieldType: $field->type,
			settingDiffs: $diffs,
			field: $field,
			parentKey: $current->parentKeyOf( $key ),
			layoutKey: $current->layoutKeyOf( $key ),
			conflict: $conflict,
			hasContent: $this->probe->isEnabled() ? $hasContent : null,
		);
	}

	private function emitDelete( RawTree $current, Field $field ): void {
		$key         = (string) $field->key;
		$descendants = $field->descendants();
		$hasContent  = $this->subtreeHasContent( $field );

		$conflict = null;

		if ( $hasContent ) {
			$conflict = Conflict::deleteWithData(
				Change::makeId( 'conflict_delete', array( 'key' => $key ) ),
				$field->label,
				count( $descendants ),
				array( 'field_key' => $key )
			);
		}

		$this->changes[] = new Change(
			id: Change::makeId( Change::DELETE, array( 'key' => $key ) ),
			type: Change::DELETE,
			risk: $hasContent ? Risk::Destructive : Risk::Caution,
			label: '' !== $field->label ? $field->label : $field->name,
			path: $current->displayPath( $key ),
			targetKey: $key,
			fieldType: $field->type,
			field: $field,
			parentKey: $current->parentKeyOf( $key ),
			layoutKey: $current->layoutKeyOf( $key ),
			conflict: $conflict,
			hasContent: $this->probe->isEnabled() ? $hasContent : null,
			context: array( 'descendants' => count( $descendants ) ),
		);
	}

	private function emitMove( RawTree $current, MoveSpec $move, Locus $locus ): void {
		$field = $this->resolver->resolveField( $current, $move->field, $locus->parentKey(), $locus->layoutKey );
		$key   = (string) $field->key;

		$fromParent = $current->parentKeyOf( $key );
		$toLocus    = null === $move->to ? $locus : $this->resolver->resolveLocus( $current, $move->to );
		$toParent   = $toLocus->parentKey();

		$changesParent = $fromParent !== $toParent;
		$hasContent    = $this->probe->hasContent( $key );

		// Moving a field between containers changes the meta key its values are
		// stored under (a repeater child's key is compound). Same hazard as a rename.
		$risk = $changesParent && $hasContent ? Risk::Destructive : Risk::Caution;

		$conflict = null;

		if ( $changesParent && $hasContent ) {
			$conflict = Conflict::renameWithData(
				Change::makeId( 'conflict_move', array( 'key' => $key ) ),
				$field->label,
				$current->displayPath( $key ),
				$toLocus->display,
				array( 'field_key' => $key, 'move' => true )
			);
		}

		$summary = $changesParent
			? sprintf(
				/* translators: 1: source path, 2: destination path */
				__( 'moved from %1$s to %2$s', 'fieldpilot-for-acf' ),
				$current->displayPath( $key ),
				$toLocus->display
			)
			: sprintf(
				/* translators: %s: position keyword */
				__( 'repositioned (%s)', 'fieldpilot-for-acf' ),
				$move->position
			);

		$this->changes[] = new Change(
			id: Change::makeId( Change::MOVE, array( 'key' => $key ) ),
			type: Change::MOVE,
			risk: $risk,
			label: '' !== $field->label ? $field->label : $field->name,
			path: $current->displayPath( $key ),
			targetKey: $key,
			fieldType: $field->type,
			field: $field,
			parentKey: $toParent,
			layoutKey: $toLocus->layoutKey,
			conflict: $conflict,
			hasContent: $this->probe->isEnabled() ? $hasContent : null,
			context: array(
				'from_parent' => $fromParent,
				'to_parent'   => $toParent,
				'position'    => $move->position,
				'anchor'      => $move->anchor,
				'summary'     => $summary,
			),
		);
	}

	// ---- Helpers -------------------------------------------------------------

	/**
	 * @return list<Field>
	 */
	private function siblingsAt( RawTree $current, Locus $locus ): array {
		if ( $locus->isGroupRoot() ) {
			return $current->group->fields;
		}

		if ( null !== $locus->layoutKey ) {
			$layout = $current->layout( $locus->layoutKey );

			return null === $layout ? array() : $layout->subFields;
		}

		return $locus->parent?->children ?? array();
	}

	/**
	 * @param list<Field> $siblings
	 */
	private function findSiblingByName( array $siblings, string $name ): ?Field {
		if ( '' === $name ) {
			return null;
		}

		foreach ( $siblings as $sibling ) {
			if ( $sibling->name === $name ) {
				return $sibling;
			}
		}

		return null;
	}

	private function subtreeHasContent( Field $field ): bool {
		if ( null !== $field->key && $this->probe->hasContent( $field->key ) ) {
			return true;
		}

		foreach ( $field->descendants() as $descendant ) {
			if ( null !== $descendant->key && $this->probe->hasContent( $descendant->key ) ) {
				return true;
			}
		}

		return false;
	}

	private function layoutHasContent( Layout $layout ): bool {
		foreach ( $layout->subFields as $field ) {
			if ( $this->subtreeHasContent( $field ) ) {
				return true;
			}
		}

		return false;
	}

	private function replaceLast( Change $change ): void {
		array_pop( $this->changes );

		$this->changes[] = $change;
	}
}
