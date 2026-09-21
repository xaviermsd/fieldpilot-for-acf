<?php
/**
 * The synonym table.
 *
 * Every entry here exists because a real model emitted it. Accepting these is not
 * sloppiness - it is the difference between a tool a developer can paste into and
 * one that sends them back to the chat window to argue about key names.
 *
 * Canonical names are always ACF's own.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

defined( 'ABSPATH' ) || exit;

final class Aliases {

	/** Top-level payload keys. */
	private const TOP_LEVEL = array(
		'op'             => 'operation',
		'action'         => 'operation',
		'mode'           => 'operation',
		'updates'        => 'changes',
		'modify'         => 'changes',
		'modifications'  => 'changes',
		'set'            => 'changes',
		'additions'      => 'add',
		'new_fields'     => 'add',
		'newFields'      => 'add',
		'add_fields'     => 'add',
		'insert'         => 'add',
		'remove'         => 'delete',
		'removals'       => 'delete',
		'delete_fields'  => 'delete',
		'move'           => 'moves',
		'relocate'       => 'moves',
		'group_settings' => 'group_changes',
		'groupChanges'   => 'group_changes',
		'schema_version' => 'version',
	);

	/** Target keys. */
	private const TARGET = array(
		'group'       => 'field_group',
		'fieldGroup'  => 'field_group',
		'field_group' => 'field_group',
		'groupName'   => 'field_group',
		'group_name'  => 'field_group',
		'group_key'   => 'field_group',
		'in'          => 'path',
		'parent'      => 'path',
		'parent_path' => 'path',
		'at'          => 'path',
	);

	/** Per-field setting keys. */
	private const FIELD = array(
		'title'          => 'label',
		'field_label'    => 'label',
		'field_name'     => 'name',
		'slug'           => 'name',
		'field_type'     => 'type',
		'field_key'      => 'key',
		'description'    => 'instructions',
		'help'           => 'instructions',
		'help_text'      => 'instructions',
		'hint'           => 'instructions',
		'instruction'    => 'instructions',
		'default'        => 'default_value',
		'defaultValue'   => 'default_value',
		'is_required'    => 'required',
		'mandatory'      => 'required',
		'options'        => 'choices',
		'values'         => 'choices',
		'fields'         => 'sub_fields',
		'children'       => 'sub_fields',
		'subfields'      => 'sub_fields',
		'subFields'      => 'sub_fields',
		'sub_field'      => 'sub_fields',
		'blocks'         => 'layouts',
		'conditional'    => 'conditional_logic',
		'conditions'     => 'conditional_logic',
		'max_length'     => 'maxlength',
		'maxLength'      => 'maxlength',
		'min_value'      => 'min',
		'max_value'      => 'max',
		'return_type'    => 'return_format',
		'returnFormat'   => 'return_format',
		'allow_multiple' => 'multiple',
		'placeholder_text' => 'placeholder',
	);

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,string> $map
	 * @return array<string,mixed>
	 */
	private static function apply( array $data, array $map ): array {
		$out = array();

		foreach ( $data as $key => $value ) {
			$canonical = $map[ (string) $key ] ?? (string) $key;

			// A canonical key already present always wins over an alias, so a
			// payload carrying both `label` and `title` keeps `label`.
			if ( array_key_exists( $canonical, $out ) && $canonical !== (string) $key ) {
				continue;
			}

			$out[ $canonical ] = $value;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function topLevel( array $data ): array {
		return self::apply( $data, self::TOP_LEVEL );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function target( array $data ): array {
		return self::apply( $data, self::TARGET );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function field( array $data ): array {
		return self::apply( $data, self::FIELD );
	}

	private function __construct() {}
}
