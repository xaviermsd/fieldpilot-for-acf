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
		return <<<'TXT'
You are an expert WordPress & Advanced Custom Fields (ACF) architect generating a configuration patch for "WP ACF JSON Pro".

Reply with EXACTLY ONE raw JSON object and nothing else. No surrounding prose, no explanation, no markdown code fences.

Patch Schema:
{
  "version": "1.0",
  "operation": "create | add | update | delete | move | merge | sync | replace",
  "target": {
    "field_group": "Group title or group_xxxxx key",
    "path": ["Parent Field", "Nested Field"],
    "layout": "layout_name_for_flexible_content"
  },
  "changes": { "field_name": { "setting": value } },
  "add":     [ { "name": "...", "label": "...", "type": "...", ...settings } ],
  "delete":  [ "field_name" ],
  "moves":   [ { "field": "...", "to": { "path": [...] }, "position": "first|last|before|after", "anchor": "..." } ],
  "group_changes": { "title": "..." }
}

Core Rules:
1. Field Names: MUST match ^[a-z_][a-z0-9_]*$ (lowercase, digits, underscores).
2. Field Keys: NEVER generate or include "key" or "ID". The engine automatically mints and preserves cryptographically valid keys.
3. Operation Selection:
   - "add": appends new fields in bulk to the target group or container. Existing fields are untouched.
   - "update": modifies ONLY the specific settings named (e.g. required, label, choices, return_format). Omitting settings preserves current values.
   - "create": builds a brand new field group from scratch.
   - "delete": removes specified field names. Use only when explicitly requested.
   - "sync": brings the group into 100% exact parity with the JSON payload.
4. Nested Field Trees:
   - For "repeater" and "group": place nested subfields in the "sub_fields" array.
   - For "flexible_content": define "layouts", where each layout has "name", "label", and its own "sub_fields" array.
   - Structural fields (accordion, tab, message) do not require a "name".
TXT;
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
			'' !== $field->name ? $field->name : '(none)',
			$field->type,
			! empty( $field->settings['required'] ) ? '  required' : ''
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

		return <<<TXT
All 36 ACF Field Types & Standard Settings Reference:

• Basic & Text:
  - text: {"type": "text", "default_value": "", "placeholder": "", "maxlength": ""}
  - textarea: {"type": "textarea", "rows": 4, "new_lines": "wpautop|br|"}
  - number: {"type": "number", "min": 0, "max": 100, "step": 1}
  - range: {"type": "range", "min": 0, "max": 100, "step": 1}
  - email: {"type": "email", "placeholder": ""}
  - url: {"type": "url", "placeholder": ""}
  - password: {"type": "password"}

• Content & Media:
  - wysiwyg: {"type": "wysiwyg", "toolbar": "full|basic", "media_upload": 1, "tabs": "all|visual|text"}
  - image: {"type": "image", "return_format": "array|url|id", "preview_size": "medium", "library": "all"}
  - file: {"type": "file", "return_format": "array|url|id", "mime_types": "pdf,docx"}
  - gallery: {"type": "gallery", "return_format": "array|url|id", "min": 0, "max": 10}
  - oembed: {"type": "oembed", "width": "", "height": ""}
  - icon_picker: {"type": "icon_picker"}

• Choice Fields:
  - select: {"type": "select", "choices": {"key": "Value"}, "allow_null": 0, "multiple": 0, "ui": 1}
  - checkbox: {"type": "checkbox", "choices": {"key": "Value"}, "layout": "vertical|horizontal"}
  - radio: {"type": "radio", "choices": {"key": "Value"}, "other_choice": 0, "layout": "vertical|horizontal"}
  - button_group: {"type": "button_group", "choices": {"key": "Value"}, "allow_null": 0}
  - true_false: {"type": "true_false", "ui": 1, "ui_on_text": "Yes", "ui_off_text": "No", "default_value": 0}

• Relational & WP Objects:
  - link: {"type": "link", "return_format": "array|url"}
  - post_object: {"type": "post_object", "post_type": ["post", "page"], "return_format": "object|id", "multiple": 0}
  - page_link: {"type": "page_link", "post_type": ["page"], "allow_null": 0, "multiple": 0}
  - relationship: {"type": "relationship", "post_type": ["post"], "filters": ["search", "post_type"], "return_format": "object|id", "min": 0, "max": 5}
  - taxonomy: {"type": "taxonomy", "taxonomy": "category", "field_type": "checkbox|select|radio", "return_format": "object|id"}
  - user: {"type": "user", "role": ["administrator", "editor"], "return_format": "array|object|id", "multiple": 0}

• Layout & Structure:
  - repeater: {"type": "repeater", "layout": "table|block|row", "button_label": "Add Row", "min": 0, "max": 0, "sub_fields": [...]}
  - group: {"type": "group", "layout": "block|table|row", "sub_fields": [...]}
  - flexible_content: {"type": "flexible_content", "button_label": "Add Section", "layouts": [{"name": "layout_slug", "label": "Layout Title", "sub_fields": [...]}]}
  - accordion: {"type": "accordion", "open": 0, "multi_expand": 0, "endpoint": 0}
  - tab: {"type": "tab", "placement": "top|left", "endpoint": 0}
  - message: {"type": "message", "message": "Instructions text or HTML", "new_lines": "wpautop"}
  - clone: {"type": "clone", "clone": ["field_xxxxx"], "display": "seamless|group"}

• jQuery & Pickers:
  - google_map: {"type": "google_map", "center_lat": "", "center_lng": "", "zoom": 14}
  - date_picker: {"type": "date_picker", "display_format": "d/m/Y", "return_format": "Y-m-d", "first_day": 1}
  - date_time_picker: {"type": "date_time_picker", "display_format": "d/m/Y g:i a", "return_format": "Y-m-d H:i:s", "first_day": 1}
  - time_picker: {"type": "time_picker", "display_format": "g:i a", "return_format": "H:i:s"}
  - color_picker: {"type": "color_picker", "default_value": "#2271b1", "enable_opacity": 0}

Installed Types on Site:
TXT . "\n" . implode( ', ', $types );
	}

	private function examples(): string {
		return <<<'TXT'
Examples:

1. Bulk Multi-Field Addition into an existing group:
{
  "version": "1.0",
  "operation": "add",
  "target": {
    "field_group": "Property"
  },
  "add": [
    {
      "name": "hero_banner",
      "label": "Hero Banner",
      "type": "image",
      "return_format": "array",
      "required": 1
    },
    {
      "name": "status",
      "label": "Listing Status",
      "type": "select",
      "choices": {
        "draft": "Draft",
        "active": "Active",
        "sold": "Sold"
      },
      "default_value": "active"
    },
    {
      "name": "team_members",
      "label": "Team Members",
      "type": "repeater",
      "layout": "table",
      "sub_fields": [
        { "name": "full_name", "label": "Full Name", "type": "text", "required": 1 },
        { "name": "role", "label": "Role", "type": "text" },
        { "name": "photo", "label": "Photo", "type": "image", "return_format": "array" }
      ]
    }
  ]
}

2. Update specific settings of an existing field without restating the rest:
{
  "version": "1.0",
  "operation": "update",
  "target": {
    "field_group": "Property",
    "path": ["Agent"]
  },
  "changes": {
    "email": {
      "required": true,
      "instructions": "Enter direct work email only."
    }
  }
}
TXT;
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
