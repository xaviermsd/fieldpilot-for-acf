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
			'add_field'    => array(
				'label'   => __( 'Add New Field', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'  => 'custom_notes',
							'label' => 'Custom Notes',
							'type'  => 'textarea',
						),
					),
				),
			),
			'update_field' => array(
				'label'   => __( 'Update Existing Field Setting', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'update',
					'target'    => array( 'field_group' => $title ),
					'changes'   => array(
						'custom_notes' => array(
							'required'     => 1,
							'instructions' => 'Please enter notes.',
						),
					),
				),
			),
			'repeater'     => array(
				'label'   => __( 'Add Repeater with Sub-fields', 'wp-acf-json-pro' ),
				'payload' => array(
					'version'   => '1.0',
					'operation' => 'add',
					'target'    => array( 'field_group' => $title ),
					'add'       => array(
						array(
							'name'       => 'team_members',
							'label'      => 'Team Members',
							'type'       => 'repeater',
							'layout'     => 'table',
							'sub_fields' => array(
								array(
									'name'  => 'member_name',
									'label' => 'Full Name',
									'type'  => 'text',
								),
								array(
									'name'  => 'member_role',
									'label' => 'Role / Title',
									'type'  => 'text',
								),
							),
						),
					),
				),
			),
			'create_group' => array(
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
