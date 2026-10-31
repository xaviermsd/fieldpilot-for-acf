<?php
/**
 * Builds a ready-to-paste prompt for whichever AI the developer already uses.
 *
 * This is the whole of our "AI integration", and deliberately so: no API key, no
 * per-seat model cost, no site data leaving the server, no vendor to be locked to,
 * and the plugin is fully functional with zero configuration. The product promise
 * is "works with your AI", which is stronger and cheaper than "has an AI in it".
 *
 * The prompt embeds the CURRENT structure of the target group, because the most
 * common AI failure is inventing field names that do not exist. Give the model the
 * real tree and that failure mostly disappears.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Rest;

use ACFJP\Acf\FieldTypeCatalog;
use ACFJP\Acf\GroupLocator;
use ACFJP\Acf\TreeReader;
use ACFJP\Model\Field;
use ACFJP\Model\Operation;

defined( 'ABSPATH' ) || exit;

final class PromptGenerator {

	public function __construct(
		private readonly TreeReader $reader,
		private readonly GroupLocator $groups,
		private readonly FieldTypeCatalog $catalog,
	) {}

	public function generate( ?string $groupReference = null, string $intent = '' ): string {
		$targetTitle = null;

		if ( ! empty( $groupReference ) && '__new__' !== $groupReference ) {
			try {
				$key         = $this->groups->locate( $groupReference );
				$tree        = $this->reader->readRaw( $key );
				$targetTitle = $tree->group->title;
			} catch ( \Throwable ) {
				$targetTitle = $groupReference;
			}
		}

		$sections = array( $this->instructions( $targetTitle ) );

		if ( ! empty( $groupReference ) && '__new__' !== $groupReference ) {
			$structure = $this->structureFor( $groupReference );

			if ( null !== $structure ) {
				$sections[] = $structure;
			}
		}

		$sections[] = $this->availableTypes();
		$sections[] = $this->examples( $targetTitle );
		$sections[] = $this->task( $intent, $targetTitle );

		return implode( "\n\n", $sections );
	}

	private function instructions( ?string $targetTitle = null ): string {
		$targetModeRule = null !== $targetTitle
			? sprintf( 'TARGET MODE: You are updating the EXISTING field group "%s". Always use "operation": "add" (or "update") and include "target": { "field_group": "%s" }.', $targetTitle, $targetTitle )
			: 'TARGET MODE: You are CREATING A BRAND NEW field group. Always use "operation": "create", "group_changes": { "title": "Field Group Title" }, and "add": [ ... ]. Do NOT include a "target" object!';

		return implode(
			"\n",
			array(
				'You are an expert WordPress & Advanced Custom Fields (ACF Free & PRO) Lead Architect generating a deterministic configuration patch for "FieldPilot for ACF".',
				'',
				'Reply with EXACTLY ONE raw JSON object and nothing else. No surrounding prose, no explanation, no markdown code fences.',
				'',
				$targetModeRule,
				'',
				'Patch Schema:',
				'{',
				'  "version": "1.0",',
				'  "operation": "create | add | update | delete | move | merge | sync | replace",',
				'  "target": {',
				'    "field_group": "Group title or group_xxxxx key (REQUIRED for add/update/delete; OMIT for create)",',
				'    "path": ["Parent Field", "Nested Field"],',
				'    "layout": "layout_name_for_flexible_content"',
				'  },',
				'  "group_changes": { "title": "Field Group Title (REQUIRED for create)" },',
				'  "changes": { "field_name": { "setting": value } },',
				'  "add":     [ { "name": "...", "label": "...", "type": "...", ...settings } ],',
				'  "delete":  [ "field_name" ],',
				'  "moves":   [ { "field": "...", "to": { "path": [...] }, "position": "first|last|before|after", "anchor": "..." } ]',
				'}',
				'',
				'Core Architectural Rules:',
				'1. Field Names & Keys:',
				'   - "name": MUST be lowercase slug matching ^[a-z_][a-z0-9_]*$ (e.g. "hero_banner", "director_bio").',
				'   - "label": Clear, human-readable title-cased name (e.g. "Hero Banner", "Director Bio").',
				'   - NEVER generate or include "key" or "ID". The engine automatically mints and preserves cryptographically valid keys.',
				'',
				'2. Interactive 4-Tab Builder Syntax Parsing:',
				'   When the user\'s task contains lines from the "Interactive Custom Field Builder (All 4 ACF Tabs)", parse every token into its exact ACF JSON counterpart:',
				'   • General Tab:',
				'     - \'named "..."\' -> derive valid "name" (slug) and "label" (title).',
				'     - \'return_format: array|url|id|object\' -> "return_format": "array" | "url" | "id" | "object".',
				'     - \'choices: "draft: Draft, active: Active"\' -> "choices": {"draft": "Draft", "active": "Active"}. If comma-separated strings without colons (e.g. "Draft, Active"), generate {"draft": "Draft", "active": "Active"}.',
				'     - \'default: "..."\' -> "default_value": "...".',
				'     - \'post_types: [post, page]\' -> "post_type": ["post", "page"].',
				'     - \'taxonomy: "..."\' -> "taxonomy": "...".',
				'     - \'toolbar: full|basic\' -> "toolbar": "full" | "basic".',
				'     - \'button_label: "..."\' -> "button_label": "...".',
				'     - \'sub_fields: [...]\' -> parse nested subfields into "sub_fields" array of objects.',
				'     - \'layouts: [hero: Hero Banner, ...]\' -> parse into "layouts": [{"name": "hero", "label": "Hero Banner", "display": "block", "sub_fields": []}].',
				'   • Validation Tab:',
				'     - \'required\' -> "required": 1 (or true).',
				'     - \'min: X, max: Y, step: Z\' -> "min": X, "max": Y, "step": Z.',
				'     - \'maxlength: N\' -> "maxlength": N.',
				'     - \'mime_types: "jpg, png, webp"\' -> "mime_types": "jpg, jpeg, png, webp".',
				'   • Presentation Tab:',
				'     - \'width: 50%\' -> "wrapper": { "width": "50" }.',
				'     - \'wrapper_class: "..."\' -> "wrapper": { "class": "..." }.',
				'     - \'instructions: "..."\' -> "instructions": "...".',
				'     - \'placeholder: "..."\' -> "placeholder": "...".',
				'     - \'prepend: "$", append: "USD"\' -> "prepend": "$", "append": "USD".',
				'     - \'rows: 4\' -> "rows": 4.',
				'   • Conditional Logic Tab:',
				'     - \'conditional: master_field == "value"\' -> "conditional_logic": [ [ { "field": "master_field", "operator": "==", "value": "value" } ] ].',
				'',
				'3. Multi-Field Bulk Requests:',
				'   When multiple "- Add ..." lines are provided, combine ALL of them into a single "operation": "add" payload containing the full list of fields in the "add": [...] array in the requested order.',
			)
		);
	}

	private function structureFor( string $reference ): ?string {
		try {
			$key  = $this->groups->locate( $reference );
			$tree = $this->reader->readRaw( $key );
		} catch ( \Throwable ) {
			return null;
		}

		$lines = array(
			'Current structure of the target field group in database:',
			'',
			sprintf( '%s  (%s)', $tree->group->title, $key ),
		);

		foreach ( $tree->group->fields as $field ) {
			$this->describeField( $field, 1, $lines );
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param list<string> $lines
	 */
	private function describeField( Field $field, int $depth, array &$lines ): void {
		$indent = str_repeat( '  ', $depth );

		$lines[] = sprintf(
			'%s- %s  name: %s  type: %s%s',
			$indent,
			$field->label,
			$field->name,
			$field->type,
			! empty( $field->settings['required'] ) ? '  [required]' : ''
		);

		foreach ( $field->children as $child ) {
			$this->describeField( $child, $depth + 1, $lines );
		}

		foreach ( $field->layouts as $layout ) {
			$lines[] = sprintf( '%s  [layout] %s  name: %s', $indent, $layout->label, $layout->name );

			foreach ( $layout->subFields as $sub ) {
				$this->describeField( $sub, $depth + 2, $lines );
			}
		}
	}

	/**
	 * The field types this site can use, with their main settings. Types the
	 * installed ACF lacks are named once with the reason so the AI leaves them
	 * out; the engine refuses them either way.
	 */
	private function availableTypes(): string {
		$doc         = array( 'ACF Field Types Available on This Site & Settings Reference (General, Validation, Presentation & Logic):' );
		$unavailable = array();

		foreach ( $this->catalog->groups() as $group ) {
			$lines = array();

			foreach ( $group['types'] as $type => $data ) {
				if ( $this->catalog->isAvailable( $type ) ) {
					$lines[] = sprintf( '  - %s: %s', $type, $data['prompt'] );
				} else {
					$unavailable[] = sprintf( '%s (%s)', $type, $this->catalog->unavailableReason( $type ) );
				}
			}

			if ( array() !== $lines ) {
				array_push( $doc, '', '• ' . $group['label'] . ':', ...$lines );
			}
		}

		array_push(
			$doc,
			'',
			'• Presentation & Wrapper Settings (Supported on ALL field types):',
			'  - instructions: "Helpful guidance text shown below the field."',
			'  - wrapper: {"width": "50", "class": "custom-col", "id": "custom-id"}',
			'',
			'• Conditional Logic (Supported on ALL field types):',
			'  - conditional_logic: [ [ {"field": "status", "operator": "==", "value": "active"} ] ]'
		);

		$others = $this->catalog->otherInstalled();

		if ( array() !== $others ) {
			array_push( $doc, '', 'Other field types installed on this site by other plugins: ' . implode( ', ', $others ) );
		}

		if ( array() !== $unavailable ) {
			array_push( $doc, '', 'Not available on this site, so never use them: ' . implode( ', ', $unavailable ) . '. FieldPilot refuses these types here.' );
		}

		return implode( "\n", $doc );
	}

	private function examples( ?string $targetTitle = null ): string {
		$targetName = $targetTitle ?? 'Example Field Group';

		$ex = array(
			'Examples:',
			'',
			'1. Create a Brand New Field Group (operation: "create"):',
			'{',
			'  "version": "1.0",',
			'  "operation": "create",',
			'  "group_changes": {',
			'    "title": "Property Details"',
			'  },',
			'  "add": [',
			'    {',
			'      "name": "hero_banner",',
			'      "label": "Hero Banner",',
			'      "type": "image",',
			'      "return_format": "array",',
			'      "required": 1',
			'    },',
			'    {',
			'      "name": "listing_status",',
			'      "label": "Listing Status",',
			'      "type": "select",',
			'      "choices": {',
			'        "draft": "Draft",',
			'        "active": "Active",',
			'        "sold": "Sold"',
			'      },',
			'      "default_value": "active"',
			'    }',
			'  ]',
			'}',
			'',
			'2. Add Fields to an Existing Group (operation: "add"):',
			'{',
			'  "version": "1.0",',
			'  "operation": "add",',
			'  "target": {',
			'    "field_group": "' . $targetName . '"',
			'  },',
			'  "add": [',
			'    {',
			'      "name": "custom_notes",',
			'      "label": "Custom Notes",',
			'      "type": "textarea",',
			'      "rows": 4',
			'    }',
			'  ]',
			'}',
			'',
			'3. Update specific settings of an existing field without restating the rest:',
			'{',
			'  "version": "1.0",',
			'  "operation": "update",',
			'  "target": {',
			'    "field_group": "' . $targetName . '",',
			'    "path": ["Agent"]',
			'  },',
			'  "changes": {',
			'    "email": {',
			'      "required": true,',
			'      "instructions": "Enter direct work email only."',
			'    }',
			'  }',
			'}',
		);

		return implode( "\n", $ex );
	}

	private function task( string $intent, ?string $targetTitle = null ): string {
		$intent = trim( $intent );

		$goal = null !== $targetTitle
			? sprintf( 'Goal: Add or update fields in existing group "%s".', $targetTitle )
			: 'Goal: Create a new field group with the requested fields (use "operation": "create").';

		return '' === $intent
			? $goal . "\nTask: (describe the change or fields you want to create/update)"
			: $goal . "\nUser Requirements & Task:\n" . $intent;
	}

	/**
	 * @return list<string>
	 */
	public static function operations(): array {
		return Operation::names();
	}
}
