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

use ACFJP\Acf\GroupLocator;
use ACFJP\Acf\TreeReader;
use ACFJP\Json\FieldTypeSchemas;
use ACFJP\Model\Field;
use ACFJP\Model\Operation;

defined( 'ABSPATH' ) || exit;

final class PromptGenerator {

	public function __construct(
		private readonly TreeReader $reader,
		private readonly GroupLocator $groups,
		private readonly FieldTypeSchemas $schemas,
	) {}

	public function generate( ?string $groupReference = null, string $intent = '' ): string {
		$sections = array( $this->instructions() );

		if ( null !== $groupReference ) {
			$structure = $this->structureFor( $groupReference );

			if ( null !== $structure ) {
				$sections[] = $structure;
			}
		}

		$sections[] = $this->availableTypes();
		$sections[] = $this->examples();
		$sections[] = $this->task( $intent );

		return implode( "\n\n", $sections );
	}

	private function instructions(): string {
		return implode(
			"\n",
			array(
				'You are an expert WordPress & Advanced Custom Fields (ACF Free & PRO) Lead Architect generating a deterministic configuration patch for "FieldPilot for ACF".',
				'',
				'Reply with EXACTLY ONE raw JSON object and nothing else. No surrounding prose, no explanation, no markdown code fences.',
				'',
				'Patch Schema:',
				'{',
				'  "version": "1.0",',
				'  "operation": "create | add | update | delete | move | merge | sync | replace",',
				'  "target": {',
				'    "field_group": "Group title or group_xxxxx key",',
				'    "path": ["Parent Field", "Nested Field"],',
				'    "layout": "layout_name_for_flexible_content"',
				'  },',
				'  "changes": { "field_name": { "setting": value } },',
				'  "add":     [ { "name": "...", "label": "...", "type": "...", ...settings } ],',
				'  "delete":  [ "field_name" ],',
				'  "moves":   [ { "field": "...", "to": { "path": [...] }, "position": "first|last|before|after", "anchor": "..." } ],',
				'  "group_changes": { "title": "..." }',
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

	private function availableTypes(): string {
		$types = $this->schemas->installedTypes();

		$doc = array(
			'All 36 ACF Field Types & Full Settings Reference (General, Validation, Presentation & Logic):',
			'',
			'• Basic & Text:',
			'  - text: {"type": "text", "default_value": "", "placeholder": "", "prepend": "", "append": "", "maxlength": 100}',
			'  - textarea: {"type": "textarea", "rows": 4, "placeholder": "", "new_lines": "wpautop|br|", "maxlength": 500}',
			'  - number: {"type": "number", "min": 0, "max": 1000, "step": 1, "default_value": "", "prepend": "$", "append": "USD"}',
			'  - range: {"type": "range", "min": 0, "max": 100, "step": 1, "default_value": 50, "prepend": "", "append": "%"}',
			'  - email: {"type": "email", "placeholder": "user@example.com", "default_value": ""}',
			'  - url: {"type": "url", "placeholder": "https://example.com", "default_value": ""}',
			'  - password: {"type": "password", "placeholder": ""}',
			'',
			'• Content & Media:',
			'  - wysiwyg: {"type": "wysiwyg", "toolbar": "full|basic", "media_upload": 1, "tabs": "all|visual|text", "delay": 0}',
			'  - image: {"type": "image", "return_format": "array|url|id", "preview_size": "medium", "library": "all|uploadedTo", "min_width": 0, "max_width": 0, "min_height": 0, "max_height": 0, "min_size": 0, "max_size": 0, "mime_types": "jpg,jpeg,png,webp"}',
			'  - file: {"type": "file", "return_format": "array|url|id", "library": "all|uploadedTo", "min_size": 0, "max_size": 0, "mime_types": "pdf,docx,zip"}',
			'  - gallery: {"type": "gallery", "return_format": "array|url|id", "library": "all|uploadedTo", "min": 0, "max": 10, "insert": "append"}',
			'  - oembed: {"type": "oembed", "width": "", "height": ""}',
			'  - icon_picker: {"type": "icon_picker", "return_format": "string|array"}',
			'',
			'• Choice Fields:',
			'  - select: {"type": "select", "choices": {"key": "Value"}, "default_value": "", "allow_null": 0, "multiple": 0, "ui": 1, "ajax": 0, "placeholder": "Choose an option..."}',
			'  - checkbox: {"type": "checkbox", "choices": {"key": "Value"}, "default_value": ["key"], "layout": "vertical|horizontal", "toggle": 0, "allow_custom": 0, "save_custom": 0}',
			'  - radio: {"type": "radio", "choices": {"key": "Value"}, "default_value": "key", "other_choice": 0, "save_other_choice": 0, "layout": "vertical|horizontal"}',
			'  - button_group: {"type": "button_group", "choices": {"key": "Value"}, "default_value": "key", "allow_null": 0, "layout": "horizontal"}',
			'  - true_false: {"type": "true_false", "ui": 1, "ui_on_text": "Yes", "ui_off_text": "No", "message": "", "default_value": 0}',
			'',
			'• Relational & WP Objects:',
			'  - link: {"type": "link", "return_format": "array|url"}',
			'  - post_object: {"type": "post_object", "post_type": ["post", "page"], "taxonomy": [], "return_format": "object|id", "multiple": 0, "allow_null": 0}',
			'  - page_link: {"type": "page_link", "post_type": ["page"], "allow_null": 0, "multiple": 0, "allow_archives": 1}',
			'  - relationship: {"type": "relationship", "post_type": ["post"], "taxonomy": [], "filters": ["search", "post_type", "taxonomy"], "return_format": "object|id", "min": 0, "max": 5}',
			'  - taxonomy: {"type": "taxonomy", "taxonomy": "category", "field_type": "checkbox|select|radio|multi_select", "add_term": 0, "save_terms": 0, "load_terms": 0, "return_format": "object|id"}',
			'  - user: {"type": "user", "role": ["administrator", "editor"], "return_format": "array|object|id", "multiple": 0, "allow_null": 0}',
			'',
			'• Layout & Structure (Nested Trees):',
			'  - repeater: {"type": "repeater", "layout": "table|block|row", "button_label": "Add Row", "min": 0, "max": 10, "collapsed": "", "sub_fields": [...]}',
			'  - group: {"type": "group", "layout": "block|table|row", "sub_fields": [...]}',
			'  - flexible_content: {"type": "flexible_content", "button_label": "Add Section", "min": 0, "max": 20, "layouts": [{"name": "layout_slug", "label": "Layout Title", "display": "block|table|row", "sub_fields": [...]}]}',
			'  - accordion: {"type": "accordion", "open": 0, "multi_expand": 0, "endpoint": 0}',
			'  - tab: {"type": "tab", "placement": "top|left", "endpoint": 0}',
			'  - message: {"type": "message", "message": "Instructional text or HTML guidance", "new_lines": "wpautop", "esc_html": 0}',
			'  - clone: {"type": "clone", "clone": ["field_xxxxx"], "display": "seamless|group", "prefix_label": 0, "prefix_name": 0}',
			'',
			'• jQuery & Pickers:',
			'  - google_map: {"type": "google_map", "center_lat": "", "center_lng": "", "zoom": 14, "height": 400}',
			'  - date_picker: {"type": "date_picker", "display_format": "d/m/Y", "return_format": "Y-m-d", "first_day": 1}',
			'  - date_time_picker: {"type": "date_time_picker", "display_format": "d/m/Y g:i a", "return_format": "Y-m-d H:i:s", "first_day": 1}',
			'  - time_picker: {"type": "time_picker", "display_format": "g:i a", "return_format": "H:i:s"}',
			'  - color_picker: {"type": "color_picker", "default_value": "#2271b1", "enable_opacity": 0}',
			'',
			'• Presentation & Wrapper Settings (Supported on ALL field types):',
			'  - instructions: "Helpful guidance text shown below the field."',
			'  - wrapper: {"width": "50", "class": "custom-col", "id": "custom-id"}',
			'',
			'• Conditional Logic (Supported on ALL field types):',
			'  - conditional_logic: [ [ {"field": "status", "operator": "==", "value": "active"} ] ]',
			'',
			'Installed Types on Site:',
			implode( ', ', $types ),
		);

		return implode( "\n", $doc );
	}

	private function examples(): string {
		$ex = array(
			'Examples:',
			'',
			'1. Bulk Multi-Field Addition into an existing group:',
			'{',
			'  "version": "1.0",',
			'  "operation": "add",',
			'  "target": {',
			'    "field_group": "Property"',
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
			'      "name": "status",',
			'      "label": "Listing Status",',
			'      "type": "select",',
			'      "choices": {',
			'        "draft": "Draft",',
			'        "active": "Active",',
			'        "sold": "Sold"',
			'      },',
			'      "default_value": "active"',
			'    },',
			'    {',
			'      "name": "team_members",',
			'      "label": "Team Members",',
			'      "type": "repeater",',
			'      "layout": "table",',
			'      "sub_fields": [',
			'        { "name": "full_name", "label": "Full Name", "type": "text", "required": 1 },',
			'        { "name": "role", "label": "Role", "type": "text" },',
			'        { "name": "photo", "label": "Photo", "type": "image", "return_format": "array" }',
			'      ]',
			'    }',
			'  ]',
			'}',
			'',
			'2. Update specific settings of an existing field without restating the rest:',
			'{',
			'  "version": "1.0",',
			'  "operation": "update",',
			'  "target": {',
			'    "field_group": "Property",',
			'    "path": ["Agent"]',
			'  },',
			'  "changes": {',
			'    "email": {',
			'      "required": true,',
			'      "instructions": "Enter direct work email only."',
			'    }',
			'  }',
			'}',
			'',
			'3. Converting 4-Tab Custom Field Builder Multi-Field Intent into a Clean JSON Patch:',
			'Input Intent:',
			'- Add an image field named "Hero Banner" (return_format: array, mime_types: "jpg, png, webp", width: 50%, required, instructions: "Upload high-res banner")',
			'- Add a select field named "Listing Status" (choices: "draft: Draft, active: Active, sold: Sold", default: "active", width: 50%, required, conditional: status == "active")',
			'',
			'Output:',
			'{',
			'  "version": "1.0",',
			'  "operation": "add",',
			'  "target": {',
			'    "field_group": "Property"',
			'  },',
			'  "add": [',
			'    {',
			'      "name": "hero_banner",',
			'      "label": "Hero Banner",',
			'      "type": "image",',
			'      "return_format": "array",',
			'      "mime_types": "jpg, jpeg, png, webp",',
			'      "required": 1,',
			'      "instructions": "Upload high-res banner",',
			'      "wrapper": {',
			'        "width": "50"',
			'      }',
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
			'      "default_value": "active",',
			'      "required": 1,',
			'      "wrapper": {',
			'        "width": "50"',
			'      },',
			'      "conditional_logic": [',
			'        [',
			'          {',
			'            "field": "status",',
			'            "operator": "==",',
			'            "value": "active"',
			'          }',
			'        ]',
			'      ]',
			'    }',
			'  ]',
			'}',
		);

		return implode( "\n", $ex );
	}

	private function task( string $intent ): string {
		$intent = trim( $intent );

		return '' === $intent
			? 'Task: (describe the change or fields you want to create/update)'
			: "User Requirements & Task:\n" . $intent;
	}

	/**
	 * @return list<string>
	 */
	public static function operations(): array {
		return Operation::names();
	}
}
