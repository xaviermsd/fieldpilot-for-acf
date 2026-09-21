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
You are generating a configuration patch for the WordPress plugin "WP ACF JSON Pro",
which applies JSON changes to Advanced Custom Fields field groups.

Reply with ONE JSON object and nothing else. No prose, no explanation, no Markdown
code fence.

Schema:

{
  "version": "1.0",
  "operation": "create | add | update | delete | move | merge | sync | replace",
  "target": {
    "field_group": "Group title or group_xxxxx key",
    "path": ["Parent Field", "Nested Field"],
    "layout": "layout_name_for_flexible_content"
  },
  "changes": { "field_name": { "setting": value } },
  "add":     [ { "name": "...", "label": "...", "type": "..." } ],
  "delete":  [ "field_name" ],
  "moves":   [ { "field": "...", "to": { "path": [...] }, "position": "first|last|before|after", "anchor": "..." } ],
  "group_changes": { "title": "..." }
}

Rules:

1. "update" changes ONLY the settings you name. Never restate settings you are not
   changing; omitting a setting leaves it exactly as it is.
2. Never invent field names. Use only names that appear in the current structure
   below, or names you are explicitly adding in this same payload.
3. Never supply a "key". Keys are generated and existing keys must not change.
4. Field names must match ^[a-z_][a-z0-9_]*$ - lowercase, digits, underscores.
5. Nest with "sub_fields" for group and repeater; use "layouts" for flexible_content,
   each layout having "name", "label" and "sub_fields".
6. Use "delete" only when removal is clearly requested. It is destructive.
7. Prefer the smallest operation that does the job: "update" and "add" over "sync",
   and never "replace" unless a full rebuild is asked for.
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
			'Current structure of the target field group:',
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

		return "Field types available on this site (use only these):\n\n" . implode( ', ', $types );
	}

	private function examples(): string {
		return <<<'TXT'
Example - make one field required and add another beside it:

{"version":"1.0","operation":"update","target":{"field_group":"Property","path":["Agent"]},"changes":{"email":{"required":true}},"add":[{"name":"whatsapp","label":"WhatsApp","type":"text"}]}

Example - add a repeater with two sub-fields:

{"version":"1.0","operation":"add","target":{"field_group":"Property"},"add":[{"name":"gallery_items","label":"Gallery Items","type":"repeater","sub_fields":[{"name":"image","label":"Image","type":"image"},{"name":"caption","label":"Caption","type":"text"}]}]}
TXT;
	}

	private function task( string $intent ): string {
		$intent = trim( $intent );

		return '' === $intent
			? 'Task: (describe the change you want here)'
			: 'Task: ' . $intent;
	}

	/**
	 * @return list<string>
	 */
	public static function operations(): array {
		return Operation::names();
	}
}
