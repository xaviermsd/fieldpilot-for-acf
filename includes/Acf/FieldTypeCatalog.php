<?php
/**
 * The 36 ACF field types FieldPilot knows, and which of them this site can use.
 *
 * One list feeds every place a type can be picked or suggested: the builder, the
 * quick-add chips and examples, the JSON templates, the type guide and the AI
 * prompt. Availability comes from ACF itself (acf_get_field_types()), so ACF free
 * offers only free types, ACF PRO offers all of them, and Icon Picker appears
 * from ACF 6.3. The engine refuses an unavailable type independently of this
 * list; this is what keeps the UI from offering one in the first place.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Acf;

use ACFJP\Json\FieldTypeSchemas;
use ACFJP\Model\FieldKind;

defined( 'ABSPATH' ) || exit;

final class FieldTypeCatalog {

	public const TOTAL = 36;

	public function __construct( private readonly FieldTypeSchemas $schemas ) {}

	/**
	 * Types by group, in the order ACF's own type picker uses.
	 *
	 * Each entry has a label, an example request for the AI prompt queue, a label
	 * for the JSON template picker, and a settings line for the AI prompt.
	 *
	 * @return array<string,array{label:string,types:array<string,array{label:string,example:string,template:string,prompt:string}>}>
	 */
	public function groups(): array {
		return array(
			'basic'      => array(
				'label' => __( 'Basic & Text', 'fieldpilot-for-acf' ),
				'types' => array(
					'text'     => $this->type( __( 'Text', 'fieldpilot-for-acf' ), '- Add a text field named Custom Title and make it required', __( 'Text Field', 'fieldpilot-for-acf' ), '{"type": "text", "default_value": "", "placeholder": "", "prepend": "", "append": "", "maxlength": 100}' ),
					'textarea' => $this->type( __( 'Textarea', 'fieldpilot-for-acf' ), '- Add a textarea field named Description with 4 rows', __( 'Textarea Field', 'fieldpilot-for-acf' ), '{"type": "textarea", "rows": 4, "placeholder": "", "new_lines": "wpautop|br|", "maxlength": 500}' ),
					'number'   => $this->type( __( 'Number', 'fieldpilot-for-acf' ), '- Add a number field named Price with min 0 and step 0.01', __( 'Number Field', 'fieldpilot-for-acf' ), '{"type": "number", "min": 0, "max": 1000, "step": 1, "default_value": "", "prepend": "$", "append": "USD"}' ),
					'range'    => $this->type( __( 'Range', 'fieldpilot-for-acf' ), '- Add a range field named Rating from 1 to 10', __( 'Range Field', 'fieldpilot-for-acf' ), '{"type": "range", "min": 0, "max": 100, "step": 1, "default_value": 50, "prepend": "", "append": "%"}' ),
					'email'    => $this->type( __( 'Email', 'fieldpilot-for-acf' ), '- Add an email field named Contact Email', __( 'Email Field', 'fieldpilot-for-acf' ), '{"type": "email", "placeholder": "user@example.com", "default_value": ""}' ),
					'url'      => $this->type( __( 'URL', 'fieldpilot-for-acf' ), '- Add a url field named External Link', __( 'URL Field', 'fieldpilot-for-acf' ), '{"type": "url", "placeholder": "https://example.com", "default_value": ""}' ),
					'password' => $this->type( __( 'Password', 'fieldpilot-for-acf' ), '- Add a password field named Secret Key', __( 'Password Field', 'fieldpilot-for-acf' ), '{"type": "password", "placeholder": ""}' ),
				),
			),
			'content'    => array(
				'label' => __( 'Content & Media', 'fieldpilot-for-acf' ),
				'types' => array(
					'image'       => $this->type( __( 'Image', 'fieldpilot-for-acf' ), '- Add an image field named Hero Image with return format array and min width 1200', __( 'Image Field (Array)', 'fieldpilot-for-acf' ), '{"type": "image", "return_format": "array|url|id", "preview_size": "medium", "library": "all|uploadedTo", "min_width": 0, "max_width": 0, "min_height": 0, "max_height": 0, "min_size": 0, "max_size": 0, "mime_types": "jpg,jpeg,png,webp"}' ),
					'file'        => $this->type( __( 'File', 'fieldpilot-for-acf' ), '- Add a file field named Brochure PDF with mime types pdf and max size 5MB', __( 'File Field', 'fieldpilot-for-acf' ), '{"type": "file", "return_format": "array|url|id", "library": "all|uploadedTo", "min_size": 0, "max_size": 0, "mime_types": "pdf,docx,zip"}' ),
					'wysiwyg'     => $this->type( __( 'WYSIWYG Editor', 'fieldpilot-for-acf' ), '- Add a wysiwyg field named Article Body with full toolbar', __( 'WYSIWYG Editor', 'fieldpilot-for-acf' ), '{"type": "wysiwyg", "toolbar": "full|basic", "media_upload": 1, "tabs": "all|visual|text", "delay": 0}' ),
					'oembed'      => $this->type( __( 'oEmbed', 'fieldpilot-for-acf' ), '- Add an oembed field named Featured Video', __( 'oEmbed Video', 'fieldpilot-for-acf' ), '{"type": "oembed", "width": "", "height": ""}' ),
					'gallery'     => $this->type( __( 'Gallery', 'fieldpilot-for-acf' ), '- Add a gallery field named Photo Gallery with min 3 images', __( 'Gallery Field', 'fieldpilot-for-acf' ), '{"type": "gallery", "return_format": "array|url|id", "library": "all|uploadedTo", "min": 0, "max": 10, "insert": "append"}' ),
					'icon_picker' => $this->type( __( 'Icon Picker', 'fieldpilot-for-acf' ), '- Add an icon_picker field named Social Icon', __( 'Icon Picker', 'fieldpilot-for-acf' ), '{"type": "icon_picker", "return_format": "string|array"}' ),
				),
			),
			'choice'     => array(
				'label' => __( 'Choices', 'fieldpilot-for-acf' ),
				'types' => array(
					'select'       => $this->type( __( 'Select', 'fieldpilot-for-acf' ), '- Add a select field named Publishing Status with choices: draft: Draft, review: Review, live: Live', __( 'Select Dropdown', 'fieldpilot-for-acf' ), '{"type": "select", "choices": {"key": "Value"}, "default_value": "", "allow_null": 0, "multiple": 0, "ui": 1, "ajax": 0, "placeholder": "Choose an option..."}' ),
					'checkbox'     => $this->type( __( 'Checkbox', 'fieldpilot-for-acf' ), '- Add a checkbox field named Features with choices: wifi: WiFi, pool: Swimming Pool, gym: Gym', __( 'Checkbox Group', 'fieldpilot-for-acf' ), '{"type": "checkbox", "choices": {"key": "Value"}, "default_value": ["key"], "layout": "vertical|horizontal", "toggle": 0, "allow_custom": 0, "save_custom": 0}' ),
					'radio'        => $this->type( __( 'Radio Button', 'fieldpilot-for-acf' ), '- Add a radio field named Layout Style with choices: grid: Grid, list: List and default grid', __( 'Radio Buttons', 'fieldpilot-for-acf' ), '{"type": "radio", "choices": {"key": "Value"}, "default_value": "key", "other_choice": 0, "save_other_choice": 0, "layout": "vertical|horizontal"}' ),
					'button_group' => $this->type( __( 'Button Group', 'fieldpilot-for-acf' ), '- Add a button_group field named Text Alignment with choices: left: Left, center: Center, right: Right', __( 'Button Group', 'fieldpilot-for-acf' ), '{"type": "button_group", "choices": {"key": "Value"}, "default_value": "key", "allow_null": 0, "layout": "horizontal"}' ),
					'true_false'   => $this->type( __( 'True / False', 'fieldpilot-for-acf' ), '- Add a true_false field named Is Featured with default false and ui enabled', __( 'True / False Switch', 'fieldpilot-for-acf' ), '{"type": "true_false", "ui": 1, "ui_on_text": "Yes", "ui_off_text": "No", "message": "", "default_value": 0}' ),
				),
			),
			'relational' => array(
				'label' => __( 'Relational', 'fieldpilot-for-acf' ),
				'types' => array(
					'link'         => $this->type( __( 'Link', 'fieldpilot-for-acf' ), '- Add a link field named Documentation Link', __( 'Link (URL & Title)', 'fieldpilot-for-acf' ), '{"type": "link", "return_format": "array|url"}' ),
					'post_object'  => $this->type( __( 'Post Object', 'fieldpilot-for-acf' ), '- Add a post_object field named Featured Article for post type post with return format object', __( 'Post Object', 'fieldpilot-for-acf' ), '{"type": "post_object", "post_type": ["post", "page"], "taxonomy": [], "return_format": "object|id", "multiple": 0, "allow_null": 0}' ),
					'page_link'    => $this->type( __( 'Page Link', 'fieldpilot-for-acf' ), '- Add a page_link field named Landing Page Link', __( 'Page Link', 'fieldpilot-for-acf' ), '{"type": "page_link", "post_type": ["page"], "allow_null": 0, "multiple": 0, "allow_archives": 1}' ),
					'relationship' => $this->type( __( 'Relationship', 'fieldpilot-for-acf' ), '- Add a relationship field named Related Case Studies for post type case_study with max 4', __( 'Relationship Field', 'fieldpilot-for-acf' ), '{"type": "relationship", "post_type": ["post"], "taxonomy": [], "filters": ["search", "post_type", "taxonomy"], "return_format": "object|id", "min": 0, "max": 5}' ),
					'taxonomy'     => $this->type( __( 'Taxonomy', 'fieldpilot-for-acf' ), '- Add a taxonomy field named Department for taxonomy category with return format id', __( 'Taxonomy Terms', 'fieldpilot-for-acf' ), '{"type": "taxonomy", "taxonomy": "category", "field_type": "checkbox|select|radio|multi_select", "add_term": 0, "save_terms": 0, "load_terms": 0, "return_format": "object|id"}' ),
					'user'         => $this->type( __( 'User', 'fieldpilot-for-acf' ), '- Add a user field named Author Profile with role author', __( 'User Selector', 'fieldpilot-for-acf' ), '{"type": "user", "role": ["administrator", "editor"], "return_format": "array|object|id", "multiple": 0, "allow_null": 0}' ),
				),
			),
			'layout'     => array(
				'label' => __( 'Layout & Structure', 'fieldpilot-for-acf' ),
				'types' => array(
					'group'            => $this->type( __( 'Group', 'fieldpilot-for-acf' ), '- Add a group field named Address Info with subfields street (text), city (text), and zip (text)', __( 'Group Container', 'fieldpilot-for-acf' ), '{"type": "group", "layout": "block|table|row", "sub_fields": [...]}' ),
					'repeater'         => $this->type( __( 'Repeater', 'fieldpilot-for-acf' ), '- Add a repeater field named Team Members with subfields name (text) and role (text)', __( 'Repeater with Subfields', 'fieldpilot-for-acf' ), '{"type": "repeater", "layout": "table|block|row", "button_label": "Add Row", "min": 0, "max": 10, "collapsed": "", "sub_fields": [...]}' ),
					'flexible_content' => $this->type( __( 'Flexible Content', 'fieldpilot-for-acf' ), '- Add a flexible_content field named Page Builder with Hero and Text layouts', __( 'Flexible Content (Layouts)', 'fieldpilot-for-acf' ), '{"type": "flexible_content", "button_label": "Add Section", "min": 0, "max": 20, "layouts": [{"name": "layout_slug", "label": "Layout Title", "display": "block|table|row", "sub_fields": [...]}]}' ),
					'clone'            => $this->type( __( 'Clone', 'fieldpilot-for-acf' ), '- Add a clone field named Reused Banner', __( 'Clone Field', 'fieldpilot-for-acf' ), '{"type": "clone", "clone": ["field_xxxxx"], "display": "seamless|group", "prefix_label": 0, "prefix_name": 0}' ),
					'tab'              => $this->type( __( 'Tab', 'fieldpilot-for-acf' ), '- Add a tab field named Advanced Options', __( 'Tab Divider', 'fieldpilot-for-acf' ), '{"type": "tab", "placement": "top|left", "endpoint": 0}' ),
					'accordion'        => $this->type( __( 'Accordion', 'fieldpilot-for-acf' ), '- Add an accordion field named Specifications', __( 'Accordion Section', 'fieldpilot-for-acf' ), '{"type": "accordion", "open": 0, "multi_expand": 0, "endpoint": 0}' ),
					'message'          => $this->type( __( 'Message', 'fieldpilot-for-acf' ), '- Add a message field named Important Note with message Please fill out all required items', __( 'Instructional Message', 'fieldpilot-for-acf' ), '{"type": "message", "message": "Instructional text or HTML guidance", "new_lines": "wpautop", "esc_html": 0}' ),
				),
			),
			'pickers'    => array(
				'label' => __( 'Pickers & Maps', 'fieldpilot-for-acf' ),
				'types' => array(
					'google_map'       => $this->type( __( 'Google Map', 'fieldpilot-for-acf' ), '- Add a google_map field named Headquarters Location with zoom 14', __( 'Google Map', 'fieldpilot-for-acf' ), '{"type": "google_map", "center_lat": "", "center_lng": "", "zoom": 14, "height": 400}' ),
					'date_picker'      => $this->type( __( 'Date Picker', 'fieldpilot-for-acf' ), '- Add a date_picker field named Release Date with return format Y-m-d', __( 'Date Picker', 'fieldpilot-for-acf' ), '{"type": "date_picker", "display_format": "d/m/Y", "return_format": "Y-m-d", "first_day": 1}' ),
					'date_time_picker' => $this->type( __( 'Date Time Picker', 'fieldpilot-for-acf' ), '- Add a date_time_picker field named Event Start with format Y-m-d H:i:s', __( 'Date Time Picker', 'fieldpilot-for-acf' ), '{"type": "date_time_picker", "display_format": "d/m/Y g:i a", "return_format": "Y-m-d H:i:s", "first_day": 1}' ),
					'time_picker'      => $this->type( __( 'Time Picker', 'fieldpilot-for-acf' ), '- Add a time_picker field named Opening Hours with format H:i:s', __( 'Time Picker', 'fieldpilot-for-acf' ), '{"type": "time_picker", "display_format": "g:i a", "return_format": "H:i:s"}' ),
					'color_picker'     => $this->type( __( 'Color Picker', 'fieldpilot-for-acf' ), '- Add a color_picker field named Brand Primary Color with default #2271b1', __( 'Color Picker', 'fieldpilot-for-acf' ), '{"type": "color_picker", "default_value": "#2271b1", "enable_opacity": 0}' ),
				),
			),
		);
	}

	/**
	 * Every type, flat: type => label.
	 *
	 * @return array<string,string>
	 */
	public function labels(): array {
		$out = array();

		foreach ( $this->groups() as $group ) {
			foreach ( $group['types'] as $type => $data ) {
				$out[ $type ] = $data['label'];
			}
		}

		return $out;
	}

	/**
	 * Whether this site's ACF can store the type.
	 */
	public function isAvailable( string $type ): bool {
		return $this->schemas->isInstalled( $type );
	}

	/**
	 * Why a type cannot be used here, as a short label for the UI.
	 */
	public function unavailableReason( string $type ): string {
		if ( FieldKind::requiresPro( $type ) ) {
			return __( 'Requires ACF PRO', 'fieldpilot-for-acf' );
		}

		if ( 'icon_picker' === $type ) {
			return __( 'Requires ACF 6.3 or newer', 'fieldpilot-for-acf' );
		}

		return __( 'Not available on this site', 'fieldpilot-for-acf' );
	}

	/**
	 * How many of the 36 types this site can use.
	 */
	public function availableCount(): int {
		return count( array_filter( array_keys( $this->labels() ), fn( string $type ): bool => $this->isAvailable( $type ) ) );
	}

	/**
	 * Field types other plugins registered with ACF: installed here, but not
	 * among ACF's own 36.
	 *
	 * @return list<string>
	 */
	public function otherInstalled(): array {
		return array_values( array_diff( $this->schemas->installedTypes(), array_keys( $this->labels() ) ) );
	}

	/**
	 * @return array{label:string,example:string,template:string,prompt:string}
	 */
	private function type( string $label, string $example, string $template, string $prompt ): array {
		return array(
			'label'    => $label,
			'example'  => $example,
			'template' => $template,
			'prompt'   => $prompt,
		);
	}
}
