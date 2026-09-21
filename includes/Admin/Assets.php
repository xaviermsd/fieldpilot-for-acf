<?php
/**
 * Admin assets.
 *
 * No build step. WordPress already ships CodeMirror via wp_enqueue_code_editor(),
 * and the diff view is a list - a bundler would cost every contributor a toolchain
 * and buy nothing here. If the conflict UI ever outgrows this,
 * @wordpress/interactivity is the WordPress-native next step, not a bundled SPA.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin;

use ACFJP\Rest\Controller;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Working payloads against field groups that actually exist on this site.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function examplePayloads(): array {
		$title = 'Sample Field Group';
		if ( function_exists( 'acf_get_field_groups' ) ) {
			foreach ( acf_get_field_groups() as $group ) {
				if ( ! empty( $group['title'] ) ) {
					$title = (string) $group['title'];
					break;
				}
			}
		}

		return array(
			// Common Operations
			'add_field'            => array(
				'label'   => __( 'Add New Field', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'         => 'custom_notes',
							'label'        => 'Custom Notes',
							'type'         => 'textarea',
							'instructions' => 'Enter notes here.',
							'wrapper'      => array( 'width' => '100' ),
						),
					),
				),
			),
			'update_field'         => array(
				'label'   => __( 'Update Existing Field Setting', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'update',
					'target'    => array( 'field_group' => $title ),
					'changes'   => array(
						'custom_notes' => array(
							'required'     => 1,
							'instructions' => 'Please enter mandatory notes.',
						),
					),
				),
			),
			'create_group'         => array(
				'label'   => __( 'Create Brand New Field Group', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'       => '1.0',
					'operation'     => 'create',
					'group_changes' => array(
						'title' => 'Project Details',
					),
					'add'           => array(
						array(
							'name'  => 'project_deadline',
							'label' => 'Project Deadline',
							'type'  => 'date_picker',
						),
						array(
							'name'  => 'project_budget',
							'label' => 'Budget ($)',
							'type'  => 'number',
						),
					),
				),
			),

			// 1. Basic & Text
			'tpl_text'             => array(
				'label'   => __( 'Text', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'custom_text',
							'label'         => 'Custom Text',
							'type'          => 'text',
							'placeholder'   => 'Enter text...',
							'default_value' => '',
							'maxlength'     => 100,
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_textarea'         => array(
				'label'   => __( 'Textarea', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'         => 'detailed_description',
							'label'        => 'Detailed Description',
							'type'         => 'textarea',
							'rows'         => 4,
							'new_lines'    => 'wpautop',
							'placeholder'  => 'Enter detailed description...',
							'instructions' => 'Supports multi-line text.',
						),
					),
				),
			),
			'tpl_number'           => array(
				'label'   => __( 'Number', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'    => 'item_price',
							'label'   => 'Price ($)',
							'type'    => 'number',
							'min'     => 0,
							'max'     => 10000,
							'step'    => 0.01,
							'prepend' => '$',
							'append'  => 'USD',
							'wrapper' => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_range'            => array(
				'label'   => __( 'Range', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'satisfaction_rating',
							'label'         => 'Rating (1-10)',
							'type'          => 'range',
							'min'           => 1,
							'max'           => 10,
							'step'          => 1,
							'default_value' => 5,
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_email'            => array(
				'label'   => __( 'Email', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'        => 'contact_email',
							'label'       => 'Contact Email',
							'type'        => 'email',
							'placeholder' => 'user@example.com',
							'required'    => 1,
							'wrapper'     => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_url'              => array(
				'label'   => __( 'URL', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'        => 'website_link',
							'label'       => 'Website Link',
							'type'        => 'url',
							'placeholder' => 'https://example.com',
							'wrapper'     => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_password'         => array(
				'label'   => __( 'Password', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'        => 'access_code',
							'label'       => 'Access Code',
							'type'        => 'password',
							'placeholder' => 'Enter password...',
							'wrapper'     => array( 'width' => '50' ),
						),
					),
				),
			),

			// 2. Content & Media
			'tpl_wysiwyg'          => array(
				'label'   => __( 'WYSIWYG Editor', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'         => 'body_content',
							'label'        => 'Body Content',
							'type'         => 'wysiwyg',
							'toolbar'      => 'full',
							'media_upload' => 1,
							'tabs'         => 'all',
						),
					),
				),
			),
			'tpl_image'            => array(
				'label'   => __( 'Image', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'hero_image',
							'label'         => 'Hero Image',
							'type'          => 'image',
							'return_format' => 'array',
							'preview_size'  => 'medium',
							'library'       => 'all',
							'mime_types'    => 'jpg, jpeg, png, webp',
							'instructions'  => 'Upload high resolution image.',
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_file'             => array(
				'label'   => __( 'File', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'brochure_pdf',
							'label'         => 'Brochure PDF',
							'type'          => 'file',
							'return_format' => 'array',
							'mime_types'    => 'pdf, docx, zip',
							'instructions'  => 'Upload document (PDF or DOCX).',
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_gallery'          => array(
				'label'   => __( 'Gallery', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'photo_gallery',
							'label'         => 'Photo Gallery',
							'type'          => 'gallery',
							'return_format' => 'array',
							'min'           => 1,
							'max'           => 10,
							'instructions'  => 'Add up to 10 gallery photos.',
						),
					),
				),
			),
			'tpl_oembed'           => array(
				'label'   => __( 'oEmbed Video', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'         => 'featured_video',
							'label'        => 'Featured Video',
							'type'         => 'oembed',
							'instructions' => 'Paste YouTube or Vimeo URL.',
							'wrapper'      => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_icon_picker'      => array(
				'label'   => __( 'Icon Picker', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'service_icon',
							'label'         => 'Service Icon',
							'type'          => 'icon_picker',
							'return_format' => 'string',
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),

			// 3. Choice Fields
			'tpl_select'           => array(
				'label'   => __( 'Select Dropdown', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'listing_status',
							'label'         => 'Status',
							'type'          => 'select',
							'choices'       => array(
								'draft'     => 'Draft',
								'published' => 'Published',
								'archived'  => 'Archived',
							),
							'default_value' => 'draft',
							'ui'            => 1,
							'allow_null'    => 0,
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_checkbox'         => array(
				'label'   => __( 'Checkbox', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'amenities',
							'label'         => 'Amenities',
							'type'          => 'checkbox',
							'choices'       => array(
								'wifi'    => 'Free Wi-Fi',
								'pool'    => 'Swimming Pool',
								'parking' => 'Free Parking',
								'gym'     => 'Fitness Center',
							),
							'default_value' => array( 'wifi' ),
							'layout'        => 'vertical',
							'toggle'        => 1,
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_radio'            => array(
				'label'   => __( 'Radio', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'color_theme',
							'label'         => 'Theme Mode',
							'type'          => 'radio',
							'choices'       => array(
								'light' => 'Light Mode',
								'dark'  => 'Dark Mode',
								'auto'  => 'System Default',
							),
							'default_value' => 'light',
							'layout'        => 'horizontal',
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_button_group'     => array(
				'label'   => __( 'Button Group', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'alignment',
							'label'         => 'Text Alignment',
							'type'          => 'button_group',
							'choices'       => array(
								'left'   => 'Left',
								'center' => 'Center',
								'right'  => 'Right',
							),
							'default_value' => 'left',
							'layout'        => 'horizontal',
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_true_false'       => array(
				'label'   => __( 'True / False', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'is_featured',
							'label'         => 'Featured Item',
							'type'          => 'true_false',
							'ui'            => 1,
							'ui_on_text'    => 'Yes',
							'ui_off_text'   => 'No',
							'default_value' => 0,
							'message'       => 'Display as highlighted featured card',
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),

			// 4. Relational & WP Objects
			'tpl_link'             => array(
				'label'   => __( 'Link (URL / Target)', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'call_to_action_link',
							'label'         => 'CTA Button Link',
							'type'          => 'link',
							'return_format' => 'array',
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_post_object'      => array(
				'label'   => __( 'Post Object', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'linked_project',
							'label'         => 'Linked Project',
							'type'          => 'post_object',
							'post_type'     => array( 'post', 'page' ),
							'return_format' => 'object',
							'multiple'      => 0,
							'allow_null'    => 1,
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_page_link'        => array(
				'label'   => __( 'Page Link', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'           => 'target_page',
							'label'          => 'Target Page',
							'type'           => 'page_link',
							'post_type'      => array( 'page' ),
							'allow_archives' => 1,
							'wrapper'        => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_relationship'     => array(
				'label'   => __( 'Relationship', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'related_articles',
							'label'         => 'Related Articles',
							'type'          => 'relationship',
							'post_type'     => array( 'post' ),
							'filters'       => array( 'search', 'post_type', 'taxonomy' ),
							'return_format' => 'object',
							'min'           => 0,
							'max'           => 5,
						),
					),
				),
			),
			'tpl_taxonomy'         => array(
				'label'   => __( 'Taxonomy Terms', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'assigned_categories',
							'label'         => 'Assigned Categories',
							'type'          => 'taxonomy',
							'taxonomy'      => 'category',
							'field_type'    => 'checkbox',
							'return_format' => 'object',
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_user'             => array(
				'label'   => __( 'User Selector', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'          => 'assigned_author',
							'label'         => 'Assigned Author',
							'type'          => 'user',
							'role'          => array( 'administrator', 'editor', 'author' ),
							'return_format' => 'array',
							'allow_null'    => 1,
							'wrapper'       => array( 'width' => '50' ),
						),
					),
				),
			),

			// 5. Layout & Structure
			'tpl_repeater'         => array(
				'label'   => __( 'Repeater', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'         => 'team_members',
							'label'        => 'Team Members',
							'type'         => 'repeater',
							'layout'       => 'table',
							'button_label' => 'Add Member',
							'sub_fields'   => array(
								array(
									'name'     => 'member_name',
									'label'    => 'Full Name',
									'type'     => 'text',
									'required' => 1,
								),
								array(
									'name'  => 'member_role',
									'label' => 'Role / Title',
									'type'  => 'text',
								),
								array(
									'name'          => 'member_photo',
									'label'         => 'Photo',
									'type'          => 'image',
									'return_format' => 'array',
								),
							),
						),
					),
				),
			),
			'tpl_group'            => array(
				'label'   => __( 'Group Container', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'       => 'company_contact',
							'label'      => 'Contact Information',
							'type'       => 'group',
							'layout'     => 'block',
							'sub_fields' => array(
								array(
									'name'    => 'phone_number',
									'label'   => 'Phone',
									'type'    => 'text',
									'wrapper' => array( 'width' => '50' ),
								),
								array(
									'name'    => 'email_address',
									'label'   => 'Email',
									'type'    => 'email',
									'wrapper' => array( 'width' => '50' ),
								),
								array(
									'name'  => 'physical_address',
									'label' => 'Address',
									'type'  => 'textarea',
									'rows'  => 3,
								),
							),
						),
					),
				),
			),
			'tpl_flexible_content' => array(
				'label'   => __( 'Flexible Content', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'         => 'page_builder_modules',
							'label'        => 'Page Sections',
							'type'         => 'flexible_content',
							'button_label' => 'Add Section',
							'layouts'      => array(
								array(
									'name'       => 'hero_banner',
									'label'      => 'Hero Banner',
									'display'    => 'block',
									'sub_fields' => array(
										array(
											'name'  => 'title',
											'label' => 'Headline',
											'type'  => 'text',
										),
										array(
											'name'          => 'background',
											'label'         => 'Background Image',
											'type'          => 'image',
											'return_format' => 'array',
										),
									),
								),
								array(
									'name'       => 'content_grid',
									'label'      => 'Content Grid',
									'display'    => 'block',
									'sub_fields' => array(
										array(
											'name'  => 'grid_content',
											'label' => 'Text Content',
											'type'  => 'wysiwyg',
										),
									),
								),
							),
						),
					),
				),
			),
			'tpl_accordion'        => array(
				'label'   => __( 'Accordion', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'label'        => 'Additional Specifications',
							'type'         => 'accordion',
							'open'         => 0,
							'multi_expand' => 1,
						),
					),
				),
			),
			'tpl_tab'              => array(
				'label'   => __( 'Tab', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'label'     => 'SEO Settings',
							'type'      => 'tab',
							'placement' => 'top',
						),
					),
				),
			),
			'tpl_message'          => array(
				'label'   => __( 'Message', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'label'     => 'Important Notice',
							'type'      => 'message',
							'message'   => 'Please ensure all required client details are filled out before submitting.',
							'new_lines' => 'wpautop',
						),
					),
				),
			),
			'tpl_clone'            => array(
				'label'   => __( 'Clone Field', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'    => 'cloned_hero',
							'label'   => 'Cloned Header Section',
							'type'    => 'clone',
							'display' => 'seamless',
						),
					),
				),
			),

			// 6. jQuery & Pickers
			'tpl_google_map'       => array(
				'label'   => __( 'Google Map', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'   => 'venue_location',
							'label'  => 'Venue Location',
							'type'   => 'google_map',
							'zoom'   => 14,
							'height' => 400,
						),
					),
				),
			),
			'tpl_date_picker'      => array(
				'label'   => __( 'Date Picker', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'           => 'event_date',
							'label'          => 'Event Date',
							'type'           => 'date_picker',
							'display_format' => 'd/m/Y',
							'return_format'  => 'Y-m-d',
							'first_day'      => 1,
							'wrapper'        => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_date_time_picker' => array(
				'label'   => __( 'Date Time Picker', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'           => 'booking_datetime',
							'label'          => 'Booking Date & Time',
							'type'           => 'date_time_picker',
							'display_format' => 'd/m/Y g:i a',
							'return_format'  => 'Y-m-d H:i:s',
							'first_day'      => 1,
							'wrapper'        => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_time_picker'      => array(
				'label'   => __( 'Time Picker', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'           => 'opening_hours',
							'label'          => 'Opening Time',
							'type'           => 'time_picker',
							'display_format' => 'g:i a',
							'return_format'  => 'H:i:s',
							'wrapper'        => array( 'width' => '50' ),
						),
					),
				),
			),
			'tpl_color_picker'     => array(
				'label'   => __( 'Color Picker', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'           => 'brand_accent_color',
							'label'          => 'Accent Color',
							'type'           => 'color_picker',
							'default_value'  => '#2271b1',
							'enable_opacity' => 0,
							'wrapper'        => array( 'width' => '50' ),
						),
					),
				),
			),
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, Menu::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'acfjp-admin',
			ACFJP_URL . 'assets/css/admin.css',
			array(),
			ACFJP_VERSION
		);

		$editor = wp_enqueue_code_editor(
			array(
				'type'       => 'application/json',
				'codemirror' => array(
					'lineNumbers'       => true,
					'lineWrapping'      => true,
					'indentUnit'        => 2,
					'tabSize'           => 2,
					'matchBrackets'     => true,
					'autoCloseBrackets' => true,
				),
			)
		);

		wp_enqueue_script(
			'acfjp-admin',
			ACFJP_URL . 'assets/js/admin.js',
			array( 'wp-i18n' ),
			ACFJP_VERSION,
			true
		);

		$examples = $this->examplePayloads();

		wp_localize_script(
			'acfjp-admin',
			'ACFJP',
			array(
				'root'     => esc_url_raw( rest_url( Controller::NAMESPACE ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'editor'   => false === $editor ? null : $editor,
				'example'  => $examples['add_field']['payload'] ?? null,
				'examples' => $examples,
				'strings'  => array(
					'validating'       => __( 'Validating...', 'wp-acf-json-pro' ),
					'planning'         => __( 'Calculating changes...', 'wp-acf-json-pro' ),
					'applying'         => __( 'Applying...', 'wp-acf-json-pro' ),
					'noChanges'        => __( 'No changes detected. The field group already matches this JSON.', 'wp-acf-json-pro' ),
					'valid'            => __( 'JSON is valid.', 'wp-acf-json-pro' ),
					'confirmTitle'     => __( 'This batch can remove or orphan content', 'wp-acf-json-pro' ),
					'applied'          => __( 'Changes applied.', 'wp-acf-json-pro' ),
					'rollbackHint'     => __( 'You can roll this back from the History screen.', 'wp-acf-json-pro' ),
					'genericError'     => __( 'Something went wrong. Nothing was changed.', 'wp-acf-json-pro' ),
					'unresolved'       => __( 'Resolve every conflict before applying.', 'wp-acf-json-pro' ),
					'copy'             => __( 'Copy', 'wp-acf-json-pro' ),
					'copied'           => __( 'Copied', 'wp-acf-json-pro' ),
					'selfTestRunning'  => __( 'Running the self-test...', 'wp-acf-json-pro' ),
					'selfTestPass'     => __( 'All checks passed.', 'wp-acf-json-pro' ),
					'selfTestPassHint' => __( 'The engine works correctly against this ACF installation.', 'wp-acf-json-pro' ),
					'selfTestCritical' => __( 'A check that protects your configuration failed. Do not use this on field groups you care about until it is fixed.', 'wp-acf-json-pro' ),
					'selfTestBlocking' => __( 'blocking', 'wp-acf-json-pro' ),
					'promptCopied'     => __( 'Prompt Copied! Now paste into your AI (ChatGPT / Claude / Gemini)', 'wp-acf-json-pro' ),
					'pasteSuccess'     => __( 'Pasted and formatted successfully!', 'wp-acf-json-pro' ),
					'pasteEmpty'       => __( 'Clipboard is empty or contains no JSON.', 'wp-acf-json-pro' ),
					'pasteError'       => __( 'Could not access clipboard directly. Please use Ctrl+V / Cmd+V.', 'wp-acf-json-pro' ),
					'noGroups'         => __( 'No field groups exist yet, so there is nothing to patch. Create one in ACF first, or use an "operation": "create" payload.', 'wp-acf-json-pro' ),
					'exportConfig'     => __( 'Export Current Configuration (JSON)', 'wp-acf-json-pro' ),
					'exporting'        => __( 'Exporting...', 'wp-acf-json-pro' ),
					'ackReview'        => __( 'I have reviewed the target scope and diff above.', 'wp-acf-json-pro' ),
					'ackModify'        => __( 'I understand this operation will write changes to the ACF database.', 'wp-acf-json-pro' ),
					'ackDestructive'   => __( 'I acknowledge that this operation contains destructive modifications or deletions.', 'wp-acf-json-pro' ),
					'scopeIsolated'    => __( 'Target Isolated - Unrelated branches are protected and untouched', 'wp-acf-json-pro' ),
					'rootTarget'       => __( 'Group Root', 'wp-acf-json-pro' ),
				),
			)
		);
	}
}
