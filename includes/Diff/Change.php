<?php
/**
 * One atomic difference between current and requested state.
 *
 * A Change is data, not behaviour. It is computed with no database writes, it is
 * serialisable, and it is what the preview renders, what the plan token hashes and
 * what the journal stores. The Writer consumes Changes; it never re-derives them.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Diff;

use ACFJP\Model\Field;
use ACFJP\Model\Layout;

defined( 'ABSPATH' ) || exit;

final class Change implements \JsonSerializable {

	public const ADD          = 'add';
	public const UPDATE       = 'update';
	public const DELETE       = 'delete';
	public const MOVE         = 'move';
	public const REORDER      = 'reorder';
	public const GROUP_UPDATE = 'group_update';
	public const CREATE_GROUP = 'create_group';
	public const ADD_LAYOUT   = 'add_layout';
	public const DELETE_LAYOUT = 'delete_layout';

	/**
	 * @param array<string,array{from:mixed,to:mixed}> $settingDiffs Per-setting before/after.
	 * @param array<string,mixed>                      $context      Extra machine detail.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly Risk $risk,
		public readonly string $label,
		public readonly string $path,
		public readonly ?string $targetKey = null,
		public readonly ?string $fieldType = null,
		public readonly array $settingDiffs = array(),
		public readonly ?Field $field = null,
		public readonly ?Layout $layout = null,
		public readonly ?string $parentKey = null,
		public readonly ?string $layoutKey = null,
		public readonly ?Conflict $conflict = null,
		public readonly ?bool $hasContent = null,
		public readonly array $context = array(),
	) {}

	public function hasConflict(): bool {
		return null !== $this->conflict;
	}

	public function isBlocked(): bool {
		return null !== $this->conflict && ! $this->conflict->isResolved();
	}

	public function withConflict( ?Conflict $conflict ): self {
		return new self(
			$this->id,
			$this->type,
			$this->risk,
			$this->label,
			$this->path,
			$this->targetKey,
			$this->fieldType,
			$this->settingDiffs,
			$this->field,
			$this->layout,
			$this->parentKey,
			$this->layoutKey,
			$conflict,
			$this->hasContent,
			$this->context,
		);
	}

	/**
	 * @param array<string,array{from:mixed,to:mixed}> $diffs
	 */
	public function withSettingDiffs( array $diffs ): self {
		return new self(
			$this->id,
			$this->type,
			$this->risk,
			$this->label,
			$this->path,
			$this->targetKey,
			$this->fieldType,
			$diffs,
			$this->field,
			$this->layout,
			$this->parentKey,
			$this->layoutKey,
			$this->conflict,
			$this->hasContent,
			$this->context,
		);
	}

	/**
	 * A short human summary, used in the collapsed preview row and the CLI.
	 */
	public function summary(): string {
		return match ( $this->type ) {
			self::ADD => sprintf(
				/* translators: %s: field type */
				__( 'new %s field', 'fieldpilot-for-acf' ),
				(string) $this->fieldType
			),
			self::DELETE => __( 'removed', 'fieldpilot-for-acf' ),
			self::MOVE   => (string) ( $this->context['summary'] ?? __( 'moved', 'fieldpilot-for-acf' ) ),
			self::REORDER => __( 'reordered', 'fieldpilot-for-acf' ),
			self::UPDATE, self::GROUP_UPDATE => $this->describeSettingDiffs(),
			self::CREATE_GROUP => __( 'new field group', 'fieldpilot-for-acf' ),
			self::ADD_LAYOUT    => __( 'new layout', 'fieldpilot-for-acf' ),
			self::DELETE_LAYOUT => __( 'layout removed', 'fieldpilot-for-acf' ),
			default             => $this->type,
		};
	}

	private function describeSettingDiffs(): string {
		$parts = array();

		foreach ( $this->settingDiffs as $setting => $diff ) {
			$parts[] = sprintf(
				'%s: %s → %s',
				$setting,
				self::scalar( $diff['from'] ),
				self::scalar( $diff['to'] )
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * Render any setting value compactly for a one-line summary.
	 */
	public static function scalar( mixed $value ): string {
		return match ( true ) {
			null === $value    => '-',
			is_bool( $value )  => $value ? 'true' : 'false',
			'' === $value      => '""',
			is_scalar( $value ) => (string) $value,
			is_array( $value )  => sprintf(
				/* translators: %d: number of items */
				_n( '%d item', '%d items', count( $value ), 'fieldpilot-for-acf' ),
				count( $value )
			),
			default             => gettype( $value ),
		};
	}

	/**
	 * Deterministic id. Stable across preview and apply for the same logical change,
	 * which is what lets the UI map a user's conflict choice back onto a change.
	 *
	 * @param array<string,mixed> $parts
	 */
	public static function makeId( string $type, array $parts ): string {
		ksort( $parts );

		return substr( hash( 'sha256', $type . '|' . (string) wp_json_encode( $parts ) ), 0, 16 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array_filter(
			array(
				'id'            => $this->id,
				'type'          => $this->type,
				'risk'          => $this->risk->value,
				'label'         => $this->label,
				'path'          => $this->path,
				'target_key'    => $this->targetKey,
				'field_type'    => $this->fieldType,
				'setting_diffs' => $this->settingDiffs,
				'parent_key'    => $this->parentKey,
				'layout_key'    => $this->layoutKey,
				'conflict'      => $this->conflict?->jsonSerialize(),
				'has_content'   => $this->hasContent,
				'summary'       => $this->summary(),
				'context'       => $this->context,
			),
			static fn( $v ): bool => null !== $v && array() !== $v
		);
	}
}
