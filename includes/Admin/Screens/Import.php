<?php
/**
 * Import screen: paste or upload JSON, preview, apply.
 *
 * Preview and apply happen over REST from the browser, so the UI and a deployment
 * script exercise exactly the same code path. The only server-side POST here is the
 * file upload, which needs a nonce and a capability check of its own.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin\Screens;

use ACFJP\Acf\FieldTypeCatalog;
use ACFJP\Acf\MutabilityClassifier;
use ACFJP\Apply\Guard;
use ACFJP\Json\Parser;
use ACFJP\Model\Operation;

defined( 'ABSPATH' ) || exit;

final class Import extends Screen {

	private const NONCE = 'acfjp_upload';

	protected function title(): string {
		return __( 'Import JSON', 'fieldpilot-for-acf' );
	}

	protected function body(): void {
		$prefill = $this->handleUpload();

		$allGroups = $this->container->get( MutabilityClassifier::class )->classifyAll();
		// The target pickers offer only groups FieldPilot can change. Groups registered
		// in PHP or waiting in acf-json are still listed further down, with the reason.
		$groups = array_values( array_filter( $allGroups, static fn ( $group ): bool => $group->isPatchable() ) );

		?>
		<div class="acfjp-wrap">

			<!-- Top Nav Mode Switcher -->
			<nav class="nav-tab-wrapper acfjp-nav-tab-wrapper" style="margin-bottom: 20px;" aria-label="<?php esc_attr_e( 'Import modes', 'fieldpilot-for-acf' ); ?>">
				<a href="#ai" class="nav-tab nav-tab-active acfjp-tab" data-tab="ai">
					<span class="dashicons dashicons-superhero-alt"></span>
					<?php esc_html_e( '1. Generate with AI', 'fieldpilot-for-acf' ); ?>
				</a>
				<a href="#editor" class="nav-tab acfjp-tab" data-tab="editor">
					<span class="dashicons dashicons-editor-code"></span>
					<?php esc_html_e( '2. JSON Editor & Import', 'fieldpilot-for-acf' ); ?>
				</a>
				<a href="#guide" class="nav-tab acfjp-tab" data-tab="guide">
					<span class="dashicons dashicons-book"></span>
					<?php esc_html_e( '3. Schema & Operations Guide', 'fieldpilot-for-acf' ); ?>
				</a>
			</nav>

			<!-- Tab 1: AI Prompt Builder & Interactive Custom Field Builder (Primary Default) -->
			<div class="acfjp-tab-content is-active" id="acfjp-tab-ai" role="tabpanel">
				<div class="acfjp-ai-card">
					<div class="acfjp-ai-card__header">
						<span class="dashicons dashicons-superhero-alt acfjp-ai-header-icon"></span>
						<div>
							<h2 class="acfjp-card-title"><?php esc_html_e( 'Let an AI write the JSON patch for you', 'fieldpilot-for-acf' ); ?></h2>
							<p class="description acfjp-card-subtitle">
								<?php esc_html_e( 'This plugin does not require any paid API keys. We generate a ready-to-use prompt with your exact ACF structure. Paste it into your favorite AI, then paste the AI reply back here.', 'fieldpilot-for-acf' ); ?>
							</p>
						</div>
					</div>

					<div class="acfjp-ai-steps">
						<!-- Step 1: Build Specifications -->
						<div class="acfjp-ai-step" id="acfjp-step-build">
							<div class="acfjp-ai-step__head">
								<span class="acfjp-badge">1</span>
								<h3><?php esc_html_e( 'Describe what you want to change', 'fieldpilot-for-acf' ); ?></h3>
							</div>

							<!-- Target Field Group -->
							<div class="acfjp-field-row" data-tour="target-group">
								<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
									<label for="acfjp-prompt-group" style="margin: 0;"><strong><?php esc_html_e( 'Target Field Group', 'fieldpilot-for-acf' ); ?></strong></label>
									<button type="button" class="button button-secondary button-small" id="acfjp-btn-toggle-new-group" style="<?php echo empty( $groups ) ? 'display: none;' : 'display: inline-flex;'; ?> align-items: center; gap: 4px;">
										<span class="dashicons dashicons-plus-alt2" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span>
										<span class="acfjp-btn-text"><?php esc_html_e( 'Add New Field Group', 'fieldpilot-for-acf' ); ?></span>
									</button>
								</div>
								<select id="acfjp-prompt-group" class="widefat">
									<?php if ( empty( $groups ) ) : ?>
										<option value="" disabled selected><?php esc_html_e( 'No field groups found (create one below)', 'fieldpilot-for-acf' ); ?></option>
									<?php else : ?>
										<?php foreach ( $groups as $i => $group ) : ?>
											<option value="<?php echo esc_attr( $group->groupKey ); ?>" data-title="<?php echo esc_attr( $group->groupTitle ); ?>" <?php selected( 0 === $i, true ); ?>>
												<?php echo esc_html( $group->groupTitle ); ?> (<?php echo esc_html( $group->groupKey ); ?>)
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>

								<div id="acfjp-new-group-container" style="<?php echo empty( $groups ) ? 'margin-top: 10px; display: block;' : 'margin-top: 10px; display: none;'; ?> background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px 12px;">
									<label for="acfjp-new-group-title" id="acfjp-new-group-label" style="display:block; font-size: 12px; font-weight: 600; color: #1d2327; margin-bottom: 6px;">
										<?php echo empty( $groups ) ? esc_html__( 'Create Your First ACF Field Group:', 'fieldpilot-for-acf' ) : esc_html__( 'Create New ACF Field Group:', 'fieldpilot-for-acf' ); ?>
									</label>
									<div style="display: flex; gap: 8px; align-items: center;">
										<input type="text" id="acfjp-new-group-title" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Property Details, Team Settings, Page Hero...', 'fieldpilot-for-acf' ); ?>" style="height: 32px; font-size: 13px;" />
										<button type="button" id="acfjp-btn-create-group" class="button button-primary" style="white-space: nowrap; height: 32px;">
											<?php esc_html_e( 'Create & Select', 'fieldpilot-for-acf' ); ?>
										</button>
										<button type="button" id="acfjp-btn-cancel-new-group" class="button" style="white-space: nowrap; height: 32px; <?php echo empty( $groups ) ? 'display: none;' : ''; ?>">
											<?php esc_html_e( 'Cancel', 'fieldpilot-for-acf' ); ?>
										</button>
									</div>
									<div id="acfjp-create-group-feedback" style="display: none; margin-top: 6px; font-size: 12px;"></div>
								</div>

								<span class="description" id="acfjp-prompt-group-desc" style="display: block; margin-top: 4px;">
									<?php esc_html_e( 'Select the field group you want to modify, or click "Add New Field Group" to create one.', 'fieldpilot-for-acf' ); ?>
								</span>
							</div>

							<!-- Mandatory Step 1 Gate Notice (visible when no group is selected) -->
							<div id="acfjp-builder-gate-banner" class="acfjp-gate-banner" style="<?php echo empty( $groups ) ? 'display: flex;' : 'display: none;'; ?>">
								<span class="dashicons dashicons-lock acfjp-gate-banner__icon"></span>
								<p class="acfjp-gate-banner__text">
									<strong><?php esc_html_e( 'Target Field Group Required:', 'fieldpilot-for-acf' ); ?></strong>
									<?php esc_html_e( 'Please select an existing Field Group above or click "Add New Field Group" to unlock the custom field builder and AI prompt generator.', 'fieldpilot-for-acf' ); ?>
								</p>
							</div>

							<!-- Custom Field Builder Box (4 Tabs: General, Validation, Presentation, Conditional Logic) -->
							<div class="acfjp-builder-box <?php echo empty( $groups ) ? 'is-locked' : ''; ?>" id="acfjp-builder-box" data-tour="builder">
								<div class="acfjp-builder-header">
									<div class="acfjp-builder-title">
										<span class="dashicons dashicons-forms"></span>
										<?php esc_html_e( 'Interactive Custom Field Builder (All 4 ACF Tabs)', 'fieldpilot-for-acf' ); ?>
									</div>
									<span class="description" style="font-size: 11px; margin: 0;">
										<?php esc_html_e( 'Select type to auto-configure General, Validation, Presentation & Logic settings.', 'fieldpilot-for-acf' ); ?>
									</span>
								</div>

								<!-- Top Primary Row -->
								<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 4px;">
									<input type="text" id="acfjp-custom-name" placeholder="<?php esc_attr_e( 'Field Label / Name (e.g. Director Bio, Hero Banner)...', 'fieldpilot-for-acf' ); ?>" style="flex: 1 1 200px; height: 32px; font-size: 13px;" />
									<select id="acfjp-custom-type" style="height: 32px; font-size: 13px;">
										<?php $this->typeOptions( 'type' ); ?>
									</select>
									<select id="acfjp-custom-width" style="height: 32px; font-size: 12px;">
										<option value=""><?php esc_html_e( 'Width: 100%', 'fieldpilot-for-acf' ); ?></option>
										<option value="50%"><?php esc_html_e( 'Width: 50%', 'fieldpilot-for-acf' ); ?></option>
										<option value="33%"><?php esc_html_e( 'Width: 33%', 'fieldpilot-for-acf' ); ?></option>
										<option value="25%"><?php esc_html_e( 'Width: 25%', 'fieldpilot-for-acf' ); ?></option>
									</select>
									<label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; cursor: pointer;">
										<input type="checkbox" id="acfjp-custom-required" /> <?php esc_html_e( 'Required', 'fieldpilot-for-acf' ); ?>
									</label>
									<button type="button" class="button button-primary" id="acfjp-custom-add">
										<span class="dashicons dashicons-plus-alt2"></span>
										<?php esc_html_e( 'Append Field', 'fieldpilot-for-acf' ); ?>
									</button>
								</div>
								<div id="acfjp-custom-name-error" class="acfjp-error-text" style="display: none; margin-bottom: 8px;"></div>

								<!-- 4 ACF Tabs Bar -->
								<div class="acfjp-builder-subtabs">
									<button type="button" class="acfjp-builder-tab is-active" data-builder-tab="general">
										<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
										<?php esc_html_e( 'General', 'fieldpilot-for-acf' ); ?>
									</button>
									<button type="button" class="acfjp-builder-tab" data-builder-tab="validation">
										<span class="dashicons dashicons-shield" aria-hidden="true"></span>
										<?php esc_html_e( 'Validation', 'fieldpilot-for-acf' ); ?>
									</button>
									<button type="button" class="acfjp-builder-tab" data-builder-tab="presentation">
										<span class="dashicons dashicons-art" aria-hidden="true"></span>
										<?php esc_html_e( 'Presentation', 'fieldpilot-for-acf' ); ?>
									</button>
									<button type="button" class="acfjp-builder-tab" data-builder-tab="logic">
										<span class="dashicons dashicons-randomize" aria-hidden="true"></span>
										<?php esc_html_e( 'Conditional Logic', 'fieldpilot-for-acf' ); ?>
									</button>
								</div>

								<!-- 4 ACF Tab Panels -->
								<div class="acfjp-builder-panels">
									<!-- Tab 1: General Settings -->
									<div class="acfjp-builder-panel is-active" id="acfjp-bpanel-general">
										<div class="acfjp-builder-grid">
											<div class="acfjp-builder-field" id="acfjp-bwrap-return-format">
												<label for="acfjp-b-return-format"><?php esc_html_e( 'Return Format', 'fieldpilot-for-acf' ); ?></label>
												<select id="acfjp-b-return-format">
													<option value="array"><?php esc_html_e( 'Array (recommended)', 'fieldpilot-for-acf' ); ?></option>
													<option value="url"><?php esc_html_e( 'URL', 'fieldpilot-for-acf' ); ?></option>
													<option value="id"><?php esc_html_e( 'ID', 'fieldpilot-for-acf' ); ?></option>
													<option value="object"><?php esc_html_e( 'Object', 'fieldpilot-for-acf' ); ?></option>
												</select>
											</div>
											<div class="acfjp-builder-field" id="acfjp-bwrap-choices">
												<label for="acfjp-b-choices"><?php esc_html_e( 'Choices (key: Value or comma-separated)', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-choices" placeholder="<?php esc_attr_e( 'draft: Draft, active: Active, sold: Sold', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field" id="acfjp-bwrap-default">
												<label for="acfjp-b-default"><?php esc_html_e( 'Default Value', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-default" placeholder="<?php esc_attr_e( 'e.g. active or 1', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field" id="acfjp-bwrap-subfields">
												<label for="acfjp-b-subfields"><?php esc_html_e( 'Sub Fields (comma-separated)', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-subfields" placeholder="<?php esc_attr_e( 'title (text), image (image), link (url)', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field" id="acfjp-bwrap-layouts">
												<label for="acfjp-b-layouts"><?php esc_html_e( 'Flexible Layouts (comma-separated)', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-layouts" placeholder="<?php esc_attr_e( 'hero_banner, features_grid, call_to_action', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field" id="acfjp-bwrap-posttypes">
												<label for="acfjp-b-posttypes"><?php esc_html_e( 'Post Types', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-posttypes" placeholder="<?php esc_attr_e( 'post, page, property', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field" id="acfjp-bwrap-taxonomy">
												<label for="acfjp-b-taxonomy"><?php esc_html_e( 'Taxonomy Slug', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-taxonomy" placeholder="<?php esc_attr_e( 'category, post_tag, genre', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field" id="acfjp-bwrap-toolbar">
												<label for="acfjp-b-toolbar"><?php esc_html_e( 'WYSIWYG Toolbar', 'fieldpilot-for-acf' ); ?></label>
												<select id="acfjp-b-toolbar">
													<option value="full"><?php esc_html_e( 'Full Toolbar', 'fieldpilot-for-acf' ); ?></option>
													<option value="basic"><?php esc_html_e( 'Basic Toolbar', 'fieldpilot-for-acf' ); ?></option>
												</select>
											</div>
											<div class="acfjp-builder-field" id="acfjp-bwrap-btnlabel">
												<label for="acfjp-b-btnlabel"><?php esc_html_e( 'Button Label', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-btnlabel" placeholder="<?php esc_attr_e( 'e.g. Add Row, Add Slide', 'fieldpilot-for-acf' ); ?>" />
											</div>
										</div>
									</div>

									<!-- Tab 2: Validation Settings -->
									<div class="acfjp-builder-panel" id="acfjp-bpanel-validation">
										<div class="acfjp-builder-grid">
											<div class="acfjp-builder-field">
												<label for="acfjp-b-min"><?php esc_html_e( 'Minimum (Value / Items / Width)', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-min" placeholder="<?php esc_attr_e( 'e.g. 0, 1, or 800', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-max"><?php esc_html_e( 'Maximum (Value / Items / Width)', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-max" placeholder="<?php esc_attr_e( 'e.g. 10, 100, or 2500', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-step"><?php esc_html_e( 'Step Size', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-step" placeholder="<?php esc_attr_e( 'e.g. 1 or 0.01', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-maxlength"><?php esc_html_e( 'Character Limit (Maxlength)', 'fieldpilot-for-acf' ); ?></label>
												<input type="number" id="acfjp-b-maxlength" placeholder="<?php esc_attr_e( 'e.g. 150', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-mimes"><?php esc_html_e( 'Allowed MIME Types', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-mimes" placeholder="<?php esc_attr_e( 'jpg, jpeg, png, webp, pdf', 'fieldpilot-for-acf' ); ?>" />
											</div>
										</div>
									</div>

									<!-- Tab 3: Presentation Settings -->
									<div class="acfjp-builder-panel" id="acfjp-bpanel-presentation">
										<div class="acfjp-builder-grid">
											<div class="acfjp-builder-field" style="grid-column: 1 / -1;">
												<label for="acfjp-custom-instructions"><?php esc_html_e( 'Instructions (Help copy shown below field)', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-custom-instructions" placeholder="<?php esc_attr_e( 'e.g. Upload JPG/PNG minimum 1200x800px...', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-placeholder"><?php esc_html_e( 'Placeholder Text', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-placeholder" placeholder="<?php esc_attr_e( 'e.g. Enter full title...', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-prepend"><?php esc_html_e( 'Prepend Text', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-prepend" placeholder="<?php esc_attr_e( 'e.g. $ or https://', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-append"><?php esc_html_e( 'Append Text', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-append" placeholder="<?php esc_attr_e( 'e.g. USD, %, or px', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-rows"><?php esc_html_e( 'Textarea Rows', 'fieldpilot-for-acf' ); ?></label>
												<input type="number" id="acfjp-b-rows" placeholder="<?php esc_attr_e( '4', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-class"><?php esc_html_e( 'Wrapper Class / ID', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-class" placeholder="<?php esc_attr_e( 'e.g. custom-col highlight', 'fieldpilot-for-acf' ); ?>" />
											</div>
										</div>
									</div>

									<!-- Tab 4: Conditional Logic -->
									<div class="acfjp-builder-panel" id="acfjp-bpanel-logic">
										<div class="acfjp-builder-grid">
											<div class="acfjp-builder-field">
												<label for="acfjp-b-cond-field"><?php esc_html_e( 'Conditional Parent Field Name', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-cond-field" placeholder="<?php esc_attr_e( 'e.g. status, has_hero, is_featured', 'fieldpilot-for-acf' ); ?>" />
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-cond-op"><?php esc_html_e( 'Operator', 'fieldpilot-for-acf' ); ?></label>
												<select id="acfjp-b-cond-op">
													<option value="=="><?php esc_html_e( '== (Equal to)', 'fieldpilot-for-acf' ); ?></option>
													<option value="!="><?php esc_html_e( '!= (Not equal to)', 'fieldpilot-for-acf' ); ?></option>
												</select>
											</div>
											<div class="acfjp-builder-field">
												<label for="acfjp-b-cond-val"><?php esc_html_e( 'Expected Value', 'fieldpilot-for-acf' ); ?></label>
												<input type="text" id="acfjp-b-cond-val" placeholder="<?php esc_attr_e( 'e.g. active, 1, or custom', 'fieldpilot-for-acf' ); ?>" />
											</div>
										</div>
									</div>
								</div>

								<!-- Builder Append Action Bar -->
								<div class="acfjp-builder-actions">
									<button type="button" class="button button-primary button-large acfjp-custom-add-btn" id="acfjp-custom-add-main">
										<span class="dashicons dashicons-plus-alt2"></span>
										<?php esc_html_e( 'Append Field to Queue', 'fieldpilot-for-acf' ); ?>
									</button>
									<span class="acfjp-builder-hint">
										<span class="dashicons dashicons-lightbulb acfjp-tip-icon" aria-hidden="true"></span>
										<?php esc_html_e( 'Press Enter or click above to append this field specification to your queue below.', 'fieldpilot-for-acf' ); ?>
									</span>
									<span class="acfjp-added-indicator" id="acfjp-added-indicator" style="display: none;">
										<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Appended to Queue!', 'fieldpilot-for-acf' ); ?>
									</span>
								</div>
							</div>

							<!-- Quick Ideas & All 36 Types Dropdown -->
							<div class="acfjp-field-row" style="margin-top: 14px;">
								<div class="acfjp-quick-chips <?php echo empty( $groups ) ? 'is-locked' : ''; ?>" id="acfjp-quick-chips">
									<span class="acfjp-quick-chips__label"><?php esc_html_e( 'Quick add fields (click to append in bulk):', 'fieldpilot-for-acf' ); ?></span>
									<?php $this->chips(); ?>
								</div>

								<div class="acfjp-quick-dropdown-row <?php echo empty( $groups ) ? 'is-locked' : ''; ?>" id="acfjp-quick-dropdown-row" style="margin-top: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
									<span class="acfjp-quick-chips__label"><?php esc_html_e( 'Or append pre-configured examples:', 'fieldpilot-for-acf' ); ?></span>
									<select id="acfjp-quick-field-select" class="button" style="max-width: 320px; font-size: 12px; height: 32px; line-height: 30px;">
										<option value=""><?php esc_html_e( 'Append an example for any field type...', 'fieldpilot-for-acf' ); ?></option>
										<?php $this->typeOptions( 'example' ); ?>
									</select>
								</div>
							</div>

							<!-- Queued Specifications Textarea -->
							<div class="acfjp-field-row" style="margin-top: 16px;">
								<div style="padding: 14px 16px; background: #fdfefe; border: 1px solid #d0e2ff; border-radius: 6px;">
									<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
										<label for="acfjp-prompt-intent" style="font-size: 13px; font-weight: 600; color: #004b87;">
											<span class="dashicons dashicons-list-view" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span>
											<?php esc_html_e( 'Queued Field Specifications & Intent', 'fieldpilot-for-acf' ); ?>
										</label>
										<button type="button" class="button-link acfjp-intent-clear" id="acfjp-intent-clear" style="font-size: 11px; color: #b32d2e; text-decoration: none; cursor: pointer;">
											<?php esc_html_e( 'Clear all', 'fieldpilot-for-acf' ); ?>
										</button>
									</div>
									<textarea id="acfjp-prompt-intent" class="widefat" rows="5"
										placeholder="<?php esc_attr_e( 'All fields added from above appear here line-by-line. You can also type custom instructions directly.', 'fieldpilot-for-acf' ); ?>"></textarea>
									<span class="description" style="font-size: 11px; color: #646970; margin-top: 6px; display: block;">
										<span class="dashicons dashicons-lightbulb acfjp-tip-icon" aria-hidden="true"></span>
										<?php esc_html_e( 'Each field will be automatically compiled into your final AI prompt.', 'fieldpilot-for-acf' ); ?>
									</span>
								</div>
							</div>

							<!-- Step 1 Action Bar -->
							<div style="margin-top: 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;" data-tour="prompt">
								<button type="button" class="button button-primary button-hero acfjp-btn-generate" id="acfjp-prompt-build">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Generate AI Prompt', 'fieldpilot-for-acf' ); ?>
								</button>
								<span class="acfjp-step-next-hint" id="acfjp-gen-hint" style="font-size: 12px; color: #005a9c; font-weight: 600; display: none;">
									<?php esc_html_e( 'Fields queued. Click above to generate your prompt.', 'fieldpilot-for-acf' ); ?>
								</span>
							</div>
						</div>

						<!-- Step 2: Send prompt to your AI & get JSON reply -->
						<div class="acfjp-ai-step is-highlighted" id="acfjp-prompt-result" hidden>
							<div class="acfjp-ai-step__head">
								<span class="acfjp-badge">2</span>
								<h3><?php esc_html_e( 'Send prompt to your AI & get the JSON reply', 'fieldpilot-for-acf' ); ?></h3>
							</div>

							<!-- Sub-step 2A: Copy & Open AI -->
							<div class="acfjp-ai-substep">
								<div class="acfjp-ai-substep__header">
									<strong><span class="dashicons dashicons-arrow-right-alt"></span> <?php esc_html_e( 'Step 2A: Copy this prompt & paste into your AI', 'fieldpilot-for-acf' ); ?></strong>
								</div>

								<div class="acfjp-prompt-toolbar">
									<button type="button" class="button button-primary button-large" id="acfjp-prompt-copy">
										<span class="dashicons dashicons-admin-page"></span>
										<?php esc_html_e( '1. Copy Prompt to Clipboard', 'fieldpilot-for-acf' ); ?>
									</button>

									<div class="acfjp-ai-links">
										<span class="acfjp-ai-links__label"><?php esc_html_e( 'Open in new tab:', 'fieldpilot-for-acf' ); ?></span>
										<a href="https://chatgpt.com" target="_blank" rel="noopener noreferrer" class="acfjp-ai-btn">
											ChatGPT ↗
										</a>
										<a href="https://claude.ai" target="_blank" rel="noopener noreferrer" class="acfjp-ai-btn">
											Claude ↗
										</a>
										<a href="https://gemini.google.com" target="_blank" rel="noopener noreferrer" class="acfjp-ai-btn">
											Gemini ↗
										</a>
										<a href="https://cursor.com" target="_blank" rel="noopener noreferrer" class="acfjp-ai-btn">
											Cursor ↗
										</a>
									</div>
								</div>

								<textarea id="acfjp-prompt-output" class="acfjp-prompt-output" rows="9" readonly
									aria-label="<?php esc_attr_e( 'Generated prompt', 'fieldpilot-for-acf' ); ?>"></textarea>
							</div>

							<!-- Sub-step 2B: Paste Response & Switch -->
							<div class="acfjp-ai-step__action" id="acfjp-prompt-step2b">
								<div class="acfjp-ai-substep__header">
									<strong><span class="dashicons dashicons-arrow-right-alt"></span> <?php esc_html_e( 'Step 2B: When your AI replies with the JSON patch', 'fieldpilot-for-acf' ); ?></strong>
								</div>
								<p class="description" style="margin: 4px 0 10px;">
									<?php esc_html_e( 'Copy the AI\'s JSON reply (with or without markdown code fences), then click below to transfer directly into the JSON Editor:', 'fieldpilot-for-acf' ); ?>
								</p>
								<p style="margin: 0;">
									<button type="button" class="button button-primary button-hero acfjp-pulse-btn" id="acfjp-paste-and-preview">
										<span class="dashicons dashicons-clipboard"></span>
										<?php esc_html_e( '2. Paste AI Response & Switch to Editor', 'fieldpilot-for-acf' ); ?>
									</button>
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Tab 2: Direct JSON & File Import -->
			<div class="acfjp-tab-content" id="acfjp-tab-editor" role="tabpanel" hidden>
				<div class="acfjp-import" id="acfjp-import">

					<div class="acfjp-import__editor" data-tour="editor">
						<div class="acfjp-box-header">
							<h2 class="acfjp-step-heading">
								<span class="dashicons dashicons-edit"></span>
								<?php esc_html_e( 'Paste JSON Configuration or Upload File', 'fieldpilot-for-acf' ); ?>
							</h2>
							<p class="description acfjp-step-hint">
								<?php esc_html_e( 'Accepts FieldPilot patches or standard native ACF export JSON.', 'fieldpilot-for-acf' ); ?>
							</p>
						</div>

						<div class="acfjp-editor-toolbar">
							<div class="acfjp-editor-toolbar__left">
								<button type="button" class="button button-primary" id="acfjp-paste-clipboard">
									<span class="dashicons dashicons-clipboard"></span>
									<?php esc_html_e( 'Paste from Clipboard', 'fieldpilot-for-acf' ); ?>
								</button>

								<select id="acfjp-template-group-select" class="button" title="<?php esc_attr_e( 'Target Field Group', 'fieldpilot-for-acf' ); ?>" style="max-width: 250px; font-size: 12px; height: 32px; line-height: 30px;">
									<?php if ( empty( $groups ) ) : ?>
										<option value="" disabled selected><?php esc_html_e( 'No field groups found', 'fieldpilot-for-acf' ); ?></option>
									<?php else : ?>
										<?php foreach ( $groups as $index => $group ) : ?>
											<option value="<?php echo esc_attr( $group->groupTitle ); ?>" data-key="<?php echo esc_attr( $group->groupKey ); ?>" <?php selected( 0 === $index, true ); ?>>
												<?php echo esc_html( $group->groupTitle ); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>

								<div class="acfjp-template-picker">
									<select id="acfjp-template-select" class="button" style="max-width: 260px; font-size: 12px; height: 30px; line-height: 28px;">
										<option value=""><?php esc_html_e( 'Insert a template...', 'fieldpilot-for-acf' ); ?></option>
										<optgroup label="<?php esc_attr_e( 'Common Operations', 'fieldpilot-for-acf' ); ?>">
											<option value="add_field"><?php esc_html_e( 'Add New Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="update_field"><?php esc_html_e( 'Update Existing Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="create_group"><?php esc_html_e( 'Create New Field Group', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<?php $this->typeOptions( 'template' ); ?>
									</select>
								</div>
							</div>

							<div class="acfjp-editor-toolbar__right">
								<button type="button" class="button" id="acfjp-clear">
									<span class="dashicons dashicons-trash"></span>
									<?php esc_html_e( 'Clear', 'fieldpilot-for-acf' ); ?>
								</button>
							</div>
						</div>

						<label for="acfjp-json" class="screen-reader-text">
							<?php esc_html_e( 'JSON payload', 'fieldpilot-for-acf' ); ?>
						</label>
						<textarea id="acfjp-json" name="json" rows="18" spellcheck="false"
							placeholder="<?php esc_attr_e( 'Paste JSON here...', 'fieldpilot-for-acf' ); ?>"><?php echo esc_textarea( $prefill ); ?></textarea>

						<!-- Editor Bottom Bar: Primary Actions & File Uploader -->
						<div class="acfjp-editor-bottom-bar">
							<div class="acfjp-primary-actions">
								<button type="button" class="button button-primary button-hero acfjp-preview-btn" id="acfjp-preview">
									<span class="dashicons dashicons-visibility"></span>
									<?php esc_html_e( 'Preview Changes & Review Diff', 'fieldpilot-for-acf' ); ?>
								</button>
								<button type="button" class="button button-secondary" id="acfjp-validate">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'Validate Only', 'fieldpilot-for-acf' ); ?>
								</button>
							</div>

							<p class="description" style="margin: 6px 0 12px; font-size: 12px; color: #50575e;">
								<span class="dashicons dashicons-shield" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; color: #008a20;"></span>
								<?php esc_html_e( 'Calculates exact diffs in-memory. Sibling branches are protected. Nothing is written until confirmed.', 'fieldpilot-for-acf' ); ?>
							</p>

							<form method="post" enctype="multipart/form-data" class="acfjp-upload">
								<?php wp_nonce_field( self::NONCE ); ?>
								<span class="description"><strong><?php esc_html_e( 'Or upload a .json file:', 'fieldpilot-for-acf' ); ?></strong></span>
								<input type="file" name="acfjp_file" accept="application/json,.json" />
								<button type="submit" class="button"><?php esc_html_e( 'Load File', 'fieldpilot-for-acf' ); ?></button>
							</form>
						</div>
					</div>

					<!-- Right Column: Live Diff Review Workbench & Sidebar -->
					<div class="acfjp-import__controls" id="acfjp-import-controls" data-tour="diff-preview">
						<!-- Live Diff Preview / Plan Panel Container -->
						<div id="acfjp-result" class="acfjp-result" aria-live="polite"></div>

						<!-- Initial Quick Workflow Guide, Override & Groups -->
						<div id="acfjp-sidebar-guide">
							<div class="acfjp-box-header">
								<h2 class="acfjp-step-heading">
									<span class="dashicons dashicons-info-outline"></span>
									<?php esc_html_e( 'How to Apply (3 Steps)', 'fieldpilot-for-acf' ); ?>
								</h2>
							</div>

							<div class="acfjp-sidebar-steps">
								<div class="acfjp-sidebar-step">
									<span class="acfjp-s-badge">1</span>
									<div>
										<strong><?php esc_html_e( 'Paste JSON', 'fieldpilot-for-acf' ); ?></strong>
										<p><?php esc_html_e( 'Paste payload on left or select a template.', 'fieldpilot-for-acf' ); ?></p>
									</div>
								</div>
								<div class="acfjp-sidebar-step">
									<span class="acfjp-s-badge">2</span>
									<div>
										<strong><?php esc_html_e( 'Preview Diff', 'fieldpilot-for-acf' ); ?></strong>
										<p><?php esc_html_e( 'Click Preview Changes to simulate in-memory.', 'fieldpilot-for-acf' ); ?></p>
									</div>
								</div>
								<div class="acfjp-sidebar-step">
									<span class="acfjp-s-badge">3</span>
									<div>
										<strong><?php esc_html_e( 'Confirm & Apply', 'fieldpilot-for-acf' ); ?></strong>
										<p><?php esc_html_e( 'Review changes & apply safely to database.', 'fieldpilot-for-acf' ); ?></p>
									</div>
								</div>
							</div>

							<hr style="margin: 16px 0; border-top: 1px solid #f0f0f1;" />

							<div class="acfjp-field-row">
								<label for="acfjp-operation"><strong><?php esc_html_e( 'Import mode override', 'fieldpilot-for-acf' ); ?></strong></label>
								<select id="acfjp-operation" class="widefat">
									<option value=""><?php esc_html_e( 'Auto - use what the JSON declares', 'fieldpilot-for-acf' ); ?></option>
									<?php foreach ( Operation::cases() as $operation ) : ?>
										<option value="<?php echo esc_attr( $operation->value ); ?>">
											<?php echo esc_html( $this->describeOperation( $operation ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<span class="description"><?php esc_html_e( 'Optional. Only required for plain ACF exports which declare no operation.', 'fieldpilot-for-acf' ); ?></span>
							</div>

							<?php if ( array() !== $allGroups ) : ?>
								<details class="acfjp-groups" open>
									<summary><strong><?php esc_html_e( 'Field groups on this site', 'fieldpilot-for-acf' ); ?> (<?php echo count( $allGroups ); ?>)</strong></summary>
									<ul>
										<?php foreach ( $allGroups as $group ) : ?>
											<li>
												<div class="acfjp-group-item">
													<strong><?php echo esc_html( $group->groupTitle ); ?></strong>
													<button type="button" class="acfjp-key-badge" data-key="<?php echo esc_attr( $group->groupKey ); ?>" data-title="<?php echo esc_attr( $group->groupTitle ); ?>" title="<?php esc_attr_e( 'Click to copy group key', 'fieldpilot-for-acf' ); ?>">
														<code><?php echo esc_html( $group->groupKey ); ?></code>
													</button>
													<?php if ( ! $group->isPatchable() ) : ?>
														<span class="acfjp-pill acfjp-pill--warn"><?php esc_html_e( 'read only', 'fieldpilot-for-acf' ); ?></span>
													<?php endif; ?>
												</div>
											</li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Tab 3: Schema & Guide -->
			<div class="acfjp-tab-content" id="acfjp-tab-guide" role="tabpanel" hidden>
				<div class="acfjp-guide-card">
					<div class="acfjp-guide-header">
						<h2>
							<span class="dashicons dashicons-book"></span>
							<?php esc_html_e( 'Schema & Operations Developer Guide', 'fieldpilot-for-acf' ); ?>
						</h2>
						<p class="description">
							<?php esc_html_e( 'FieldPilot is a declarative, target-scoped configuration patch engine for Advanced Custom Fields. Below is the complete developer reference for operations, scope targeting, the 4 ACF settings tabs, and all 36 ACF field types.', 'fieldpilot-for-acf' ); ?>
						</p>
					</div>

					<!-- Section 1: 8 Supported Operations -->
					<div class="acfjp-guide-section">
						<h3 class="acfjp-guide-section__title">
							<span class="dashicons dashicons-admin-generic"></span>
							<?php esc_html_e( '1. Supported JSON Patch Operations (8 Total)', 'fieldpilot-for-acf' ); ?>
						</h3>
						<p class="description">
							<?php esc_html_e( 'Every JSON payload declares an operation that determines how the engine plans and applies mutations:', 'fieldpilot-for-acf' ); ?>
						</p>

						<div class="acfjp-operations-grid">
							<div class="acfjp-op-card">
								<h3><code>add</code> <span class="acfjp-pill acfjp-pill--ok">Safe</span></h3>
								<p><?php esc_html_e( 'Inserts new fields only into the targeted group or sub-tree. Existing fields and settings remain untouched.', 'fieldpilot-for-acf' ); ?></p>
							</div>

							<div class="acfjp-op-card">
								<h3><code>update</code> <span class="acfjp-pill acfjp-pill--ok">Safe</span></h3>
								<p><?php esc_html_e( 'Modifies ONLY the specific settings named (e.g. required, label, instructions). Omitting a setting preserves its current value.', 'fieldpilot-for-acf' ); ?></p>
							</div>

							<div class="acfjp-op-card">
								<h3><code>create</code> <span class="acfjp-pill acfjp-pill--ok">Safe</span></h3>
								<p><?php esc_html_e( 'Creates a brand new field group from scratch with title, location rules, and fields.', 'fieldpilot-for-acf' ); ?></p>
							</div>

							<div class="acfjp-op-card">
								<h3><code>merge</code> <span class="acfjp-pill acfjp-pill--ok">Safe</span></h3>
								<p><?php esc_html_e( 'Adds any fields present in the JSON that do not yet exist on site. Never deletes or overwrites existing fields.', 'fieldpilot-for-acf' ); ?></p>
							</div>

							<div class="acfjp-op-card">
								<h3><code>move</code> <span class="acfjp-pill acfjp-pill--caution">Caution</span></h3>
								<p><?php esc_html_e( 'Relocates or reparents existing fields into new positions, groups, or repeater/flexible layouts while preserving canonical keys.', 'fieldpilot-for-acf' ); ?></p>
							</div>

							<div class="acfjp-op-card">
								<h3><code>sync</code> <span class="acfjp-pill acfjp-pill--caution">Caution</span></h3>
								<p><?php esc_html_e( 'Makes the field group match the JSON payload: adds new fields, updates changed ones and removes the ones the payload leaves out.', 'fieldpilot-for-acf' ); ?></p>
							</div>

							<div class="acfjp-op-card">
								<h3><code>delete</code> <span class="acfjp-pill acfjp-pill--destructive">Destructive</span></h3>
								<p><?php esc_html_e( 'Safely deletes specified fields after probing for stored database postmeta. Always requires explicit user confirmation in the preview diff.', 'fieldpilot-for-acf' ); ?></p>
							</div>

							<div class="acfjp-op-card">
								<h3><code>replace</code> <span class="acfjp-pill acfjp-pill--destructive">Destructive</span></h3>
								<p><?php esc_html_e( 'Rebuilds the entire field group configuration from the provided schema, replacing all prior field structures.', 'fieldpilot-for-acf' ); ?></p>
							</div>
						</div>
					</div>

					<!-- Section 2: Target Scope & Sub-Tree Resolution -->
					<div class="acfjp-guide-section">
						<h3 class="acfjp-guide-section__title">
							<span class="dashicons dashicons-networking"></span>
							<?php esc_html_e( '2. Target Scope & Sub-Tree Resolution Guide', 'fieldpilot-for-acf' ); ?>
						</h3>
						<p class="description">
							<?php esc_html_e( 'The "target" object tells the engine exactly where to apply mutations. Fields outside the target are left alone, and FieldPilot checks that after every write.', 'fieldpilot-for-acf' ); ?>
						</p>

						<div class="acfjp-target-grid">
							<div class="acfjp-target-card">
								<h4><span class="dashicons dashicons-admin-home"></span> <?php esc_html_e( 'Root Field Group Target', 'fieldpilot-for-acf' ); ?></h4>
								<p><?php esc_html_e( 'Target an entire field group by its title, key, or post name.', 'fieldpilot-for-acf' ); ?></p>
								<pre><code>"target": {
	"field_group": "Company Profile"
}</code></pre>
							</div>

							<div class="acfjp-target-card">
								<h4><span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Nested Sub-Field Path Target', 'fieldpilot-for-acf' ); ?></h4>
								<p><?php esc_html_e( 'Target a sub-field deep inside a Repeater, Group, or Flexible Layout.', 'fieldpilot-for-acf' ); ?></p>
								<pre><code>"target": {
	"field_group": "Company Profile",
	"path": "team_members.social_links"
}</code></pre>
							</div>

							<div class="acfjp-target-card">
								<h4><span class="dashicons dashicons-key"></span> <?php esc_html_e( 'Exact Canonical Key Target', 'fieldpilot-for-acf' ); ?></h4>
								<p><?php esc_html_e( 'Target a specific field directly by its unique ACF field key.', 'fieldpilot-for-acf' ); ?></p>
								<pre><code>"target": {
	"field_key": "field_65a123bc45678"
}</code></pre>
							</div>
						</div>

						<div class="acfjp-isolation-banner">
							<span class="dashicons dashicons-shield"></span>
							<div>
								<strong><?php esc_html_e( 'Target isolation:', 'fieldpilot-for-acf' ); ?></strong>
								<?php esc_html_e( 'Fields outside your target are not changed, and the check after every write confirms it. Existing field keys are kept, so the content stored against them stays connected.', 'fieldpilot-for-acf' ); ?>
							</div>
						</div>
					</div>

					<!-- Section 3: The 4 ACF Tabs & JSON Property Mapping -->
					<div class="acfjp-guide-section">
						<h3 class="acfjp-guide-section__title">
							<span class="dashicons dashicons-index-card"></span>
							<?php esc_html_e( '3. The 4 ACF Tabs & JSON Property Mapping', 'fieldpilot-for-acf' ); ?>
						</h3>
						<p class="description">
							<?php esc_html_e( 'Every ACF field setting maps 1-to-1 into declarative JSON properties corresponding to native ACF tabs:', 'fieldpilot-for-acf' ); ?>
						</p>

						<div class="acfjp-guide-tabs-grid">
							<div class="acfjp-guide-tab-card">
								<h4><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'General Tab', 'fieldpilot-for-acf' ); ?></h4>
								<ul class="acfjp-prop-list">
									<li><code>label</code> <em>(string)</em>: <?php esc_html_e( 'Admin display label', 'fieldpilot-for-acf' ); ?></li>
									<li><code>name</code> <em>(string)</em>: <?php esc_html_e( 'Programmatic meta key', 'fieldpilot-for-acf' ); ?></li>
									<li><code>type</code> <em>(string)</em>: <?php esc_html_e( 'ACF field type slug', 'fieldpilot-for-acf' ); ?></li>
									<li><code>default_value</code> <em>(mixed)</em>: <?php esc_html_e( 'Initial value', 'fieldpilot-for-acf' ); ?></li>
									<li><code>return_format</code> <em>(string)</em>: <code>array</code>, <code>url</code>, <code>id</code>, <code>object</code></li>
									<li><code>choices</code> <em>(object)</em>: <code>{"val": "Label"}</code></li>
									<li><code>sub_fields</code> <em>(array)</em>: <?php esc_html_e( 'Nested fields for repeater/group', 'fieldpilot-for-acf' ); ?></li>
									<li><code>layouts</code> <em>(array)</em>: <?php esc_html_e( 'Flexible content layout items', 'fieldpilot-for-acf' ); ?></li>
									<li><code>button_label</code> <em>(string)</em>: <?php esc_html_e( 'Button text (e.g. "Add Slide")', 'fieldpilot-for-acf' ); ?></li>
								</ul>
							</div>

							<div class="acfjp-guide-tab-card">
								<h4><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Validation Tab', 'fieldpilot-for-acf' ); ?></h4>
								<ul class="acfjp-prop-list">
									<li><code>required</code> <em>(int / bool)</em>: <code>1</code> <?php esc_html_e( 'or', 'fieldpilot-for-acf' ); ?> <code>0</code></li>
									<li><code>maxlength</code> <em>(int)</em>: <?php esc_html_e( 'Character limit', 'fieldpilot-for-acf' ); ?></li>
									<li><code>min</code> / <code>max</code> <em>(int/float)</em>: <?php esc_html_e( 'Min/max boundary', 'fieldpilot-for-acf' ); ?></li>
									<li><code>step</code> <em>(int/float)</em>: <?php esc_html_e( 'Increment step', 'fieldpilot-for-acf' ); ?></li>
									<li><code>mime_types</code> <em>(string)</em>: <code>"jpg,png,pdf,webp"</code></li>
									<li><code>min_width</code> / <code>max_width</code>: <?php esc_html_e( 'Image dimensions (px)', 'fieldpilot-for-acf' ); ?></li>
									<li><code>min_size</code> / <code>max_size</code>: <code>"2MB"</code>, <code>"500KB"</code></li>
								</ul>
							</div>

							<div class="acfjp-guide-tab-card">
								<h4><span class="dashicons dashicons-art"></span> <?php esc_html_e( 'Presentation Tab', 'fieldpilot-for-acf' ); ?></h4>
								<ul class="acfjp-prop-list">
									<li><code>instructions</code> <em>(string)</em>: <?php esc_html_e( 'Guidance text for editors', 'fieldpilot-for-acf' ); ?></li>
									<li><code>placeholder</code> <em>(string)</em>: <?php esc_html_e( 'Input placeholder', 'fieldpilot-for-acf' ); ?></li>
									<li><code>prepend</code> <em>(string)</em>: <?php esc_html_e( 'Visual prefix (e.g. "$", "https://")', 'fieldpilot-for-acf' ); ?></li>
									<li><code>append</code> <em>(string)</em>: <?php esc_html_e( 'Visual suffix (e.g. "USD", "px")', 'fieldpilot-for-acf' ); ?></li>
									<li><code>rows</code> <em>(int)</em>: <?php esc_html_e( 'Textarea line height (e.g. 4, 8)', 'fieldpilot-for-acf' ); ?></li>
									<li><code>new_lines</code> <em>(string)</em>: <code>wpautop</code>, <code>br</code>, <code>""</code></li>
									<li><code>wrapper</code> <em>(object)</em>: <code>{"width": "50", "class": "col"}</code></li>
								</ul>
							</div>

							<div class="acfjp-guide-tab-card">
								<h4><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Conditional Logic Tab', 'fieldpilot-for-acf' ); ?></h4>
								<ul class="acfjp-prop-list">
									<li><code>conditional_logic</code> <em>(array)</em>: <?php esc_html_e( '2D array of rule groups', 'fieldpilot-for-acf' ); ?></li>
									<li><code>field</code> <em>(string)</em>: <?php esc_html_e( 'Trigger field name or key', 'fieldpilot-for-acf' ); ?></li>
									<li><code>operator</code> <em>(string)</em>: <code>==</code>, <code>!=</code>, <code>patternMatch</code></li>
									<li><code>value</code> <em>(string)</em>: <?php esc_html_e( 'Comparison target value', 'fieldpilot-for-acf' ); ?></li>
								</ul>
								<pre><code>"conditional_logic": [
	[
	{
		"field": "enable_feature",
		"operator": "==",
		"value": "1"
	}
	]
]</code></pre>
							</div>
						</div>
					</div>

					<!-- Section 4: ACF Field Types Overview -->
					<div class="acfjp-guide-section">
						<h3 class="acfjp-guide-section__title">
							<span class="dashicons dashicons-screenoptions"></span>
							<?php esc_html_e( '4. ACF Field Types Reference', 'fieldpilot-for-acf' ); ?>
						</h3>
						<p class="description">
							<?php $this->typeGuideIntro(); ?>
						</p>

						<?php $this->typeGuide(); ?>
					</div>

					<!-- Section 5: Interactive Example JSON Templates -->
					<div class="acfjp-guide-section">
						<h3 class="acfjp-guide-section__title">
							<span class="dashicons dashicons-media-code"></span>
							<?php esc_html_e( '5. Quick Copy & Load Example Patch Templates', 'fieldpilot-for-acf' ); ?>
						</h3>
						<p class="description">
							<?php esc_html_e( 'Click "Load into Editor" on any example below to jump directly into the JSON Editor & Import tab with the template pre-populated:', 'fieldpilot-for-acf' ); ?>
						</p>

						<div class="acfjp-examples-list">
							<!-- Example 1: Add Field -->
							<div class="acfjp-example-card">
								<div class="acfjp-example-card__header">
									<strong><?php esc_html_e( 'Example 1: Add New Fields (Text & Image with 50% width)', 'fieldpilot-for-acf' ); ?></strong>
									<div class="acfjp-example-card__actions">
										<button type="button" class="button button-small button-primary acfjp-guide-load" data-example-target="acfjp-code-ex1">
											<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Load into Editor', 'fieldpilot-for-acf' ); ?>
										</button>
										<button type="button" class="button button-small acfjp-guide-copy" data-example-target="acfjp-code-ex1">
											<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy', 'fieldpilot-for-acf' ); ?>
										</button>
									</div>
								</div>
								<pre><code id="acfjp-code-ex1">{
	"version": "1.0",
	"operation": "add",
	"target": {
	"field_group": "Company Profile"
	},
	"add": [
	{
		"name": "director_name",
		"label": "Director Name",
		"type": "text",
		"required": 1,
		"instructions": "Enter director full name.",
		"wrapper": { "width": "50" }
	},
	{
		"name": "director_photo",
		"label": "Director Photo",
		"type": "image",
		"return_format": "array",
		"instructions": "Upload high resolution portrait.",
		"wrapper": { "width": "50" }
	}
	]
}</code></pre>
							</div>

							<!-- Example 2: Update Field -->
							<div class="acfjp-example-card">
								<div class="acfjp-example-card__header">
									<strong><?php esc_html_e( 'Example 2: Partial Field Update (Safe - Only touches named settings)', 'fieldpilot-for-acf' ); ?></strong>
									<div class="acfjp-example-card__actions">
										<button type="button" class="button button-small button-primary acfjp-guide-load" data-example-target="acfjp-code-ex2">
											<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Load into Editor', 'fieldpilot-for-acf' ); ?>
										</button>
										<button type="button" class="button button-small acfjp-guide-copy" data-example-target="acfjp-code-ex2">
											<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy', 'fieldpilot-for-acf' ); ?>
										</button>
									</div>
								</div>
								<pre><code id="acfjp-code-ex2">{
	"version": "1.0",
	"operation": "update",
	"target": {
	"field_group": "Company Profile",
	"path": "director_name"
	},
	"changes": {
	"required": 1,
	"instructions": "Official legal name of the executive director.",
	"placeholder": "e.g. Jane Doe"
	}
}</code></pre>
							</div>

							<!-- Example 3: Nested Repeater -->
							<div class="acfjp-example-card">
								<div class="acfjp-example-card__header">
									<strong><?php esc_html_e( 'Example 3: Add Repeater with Nested Sub-Fields', 'fieldpilot-for-acf' ); ?></strong>
									<div class="acfjp-example-card__actions">
										<button type="button" class="button button-small button-primary acfjp-guide-load" data-example-target="acfjp-code-ex3">
											<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Load into Editor', 'fieldpilot-for-acf' ); ?>
										</button>
										<button type="button" class="button button-small acfjp-guide-copy" data-example-target="acfjp-code-ex3">
											<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy', 'fieldpilot-for-acf' ); ?>
										</button>
									</div>
								</div>
								<pre><code id="acfjp-code-ex3">{
	"version": "1.0",
	"operation": "add",
	"target": {
	"field_group": "Company Profile"
	},
	"add": [
	{
		"name": "timeline_events",
		"label": "Company Milestones",
		"type": "repeater",
		"button_label": "Add Milestone",
		"sub_fields": [
		{
			"name": "year",
			"label": "Year",
			"type": "number",
			"required": 1,
			"wrapper": { "width": "25" }
		},
		{
			"name": "title",
			"label": "Milestone Title",
			"type": "text",
			"required": 1,
			"wrapper": { "width": "75" }
		},
		{
			"name": "details",
			"label": "Details",
			"type": "textarea",
			"rows": 3,
			"wrapper": { "width": "100" }
		}
		]
	}
	]
}</code></pre>
							</div>

							<!-- Example 4: Create Field Group -->
							<div class="acfjp-example-card">
								<div class="acfjp-example-card__header">
									<strong><?php esc_html_e( 'Example 4: Create Brand New Field Group with Location Rules', 'fieldpilot-for-acf' ); ?></strong>
									<div class="acfjp-example-card__actions">
										<button type="button" class="button button-small button-primary acfjp-guide-load" data-example-target="acfjp-code-ex4">
											<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Load into Editor', 'fieldpilot-for-acf' ); ?>
										</button>
										<button type="button" class="button button-small acfjp-guide-copy" data-example-target="acfjp-code-ex4">
											<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy', 'fieldpilot-for-acf' ); ?>
										</button>
									</div>
								</div>
								<pre><code id="acfjp-code-ex4">{
	"version": "1.0",
	"operation": "create",
	"group_changes": {
	"title": "Case Studies Settings",
	"location": [
		[
		{
			"param": "post_type",
			"operator": "==",
			"value": "post"
		}
		]
	]
	},
	"add": [
	{
		"name": "client_name",
		"label": "Client Organization",
		"type": "text",
		"required": 1,
		"wrapper": { "width": "50" }
	},
	{
		"name": "project_industry",
		"label": "Industry Sector",
		"type": "select",
		"choices": {
		"fintech": "FinTech & Banking",
		"healthcare": "Healthcare & MedTech",
		"ecommerce": "E-Commerce & Retail"
		},
		"wrapper": { "width": "50" }
	}
	]
}</code></pre>
							</div>
						</div>
					</div>

					<div class="acfjp-guide-footer">
						<p>
							<span class="dashicons dashicons-lightbulb acfjp-tip-icon" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Need to undo a change?', 'fieldpilot-for-acf' ); ?></strong>
							<?php esc_html_e( 'Every applied change saves a snapshot first. Roll changes back from the History screen, newest first.', 'fieldpilot-for-acf' ); ?>
						</p>
					</div>
				</div>
			</div>

		</div>
		<?php
	}

	private function catalog(): FieldTypeCatalog {
		return $this->container->get( FieldTypeCatalog::class );
	}

	/**
	 * Option groups for the 36 field types. Types this site's ACF lacks stay in
	 * the list, disabled and labelled with the reason, so they can be seen but
	 * not picked.
	 *
	 * @param string $value "type" for the builder, "example" for the prompt queue,
	 *                      "template" for the JSON template picker.
	 */
	private function typeOptions( string $value ): void {
		$catalog = $this->catalog();

		foreach ( $catalog->groups() as $group ) {
			printf( '<optgroup label="%s">', esc_attr( $group['label'] ) );

			foreach ( $group['types'] as $type => $data ) {
				$available = $catalog->isAvailable( $type );
				$label     = 'template' === $value ? $data['template'] : $data['label'];

				if ( ! $available ) {
					$label = sprintf(
						/* translators: 1: field type, 2: why it cannot be used, such as "Requires ACF PRO" */
						__( '%1$s - %2$s', 'fieldpilot-for-acf' ),
						$label,
						$catalog->unavailableReason( $type )
					);
				}

				$optionValue = match ( $value ) {
					'example'  => $data['example'],
					'template' => 'tpl_' . $type,
					default    => $type,
				};

				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $optionValue ),
					disabled( ! $available, true, false ),
					esc_html( $label )
				);
			}

			echo '</optgroup>';
		}
	}

	/**
	 * One-click requests for the prompt queue. A chip for a type this site
	 * cannot use is shown disabled, with the reason as its tooltip.
	 */
	private function chips(): void {
		$catalog = $this->catalog();

		$chips = array(
			array( 'repeater', '- Add a repeater called Team Members with name (text), role (text), and photo (image) fields', __( '+ Repeater', 'fieldpilot-for-acf' ) ),
			array( 'text', '- Add a text field called Custom Heading and make it required', __( '+ Required Text', 'fieldpilot-for-acf' ) ),
			array( 'wysiwyg', '- Add a wysiwyg editor field called Main Content', __( '+ WYSIWYG', 'fieldpilot-for-acf' ) ),
			array( 'image', '- Add an image field called Hero Banner with return format array', __( '+ Image', 'fieldpilot-for-acf' ) ),
			array( 'gallery', '- Add a gallery field called Photo Gallery', __( '+ Gallery', 'fieldpilot-for-acf' ) ),
			array( 'select', '- Add a select field called Status with choices: Draft, Review, Published', __( '+ Select Dropdown', 'fieldpilot-for-acf' ) ),
			array( 'true_false', '- Add a true_false switch field called Is Featured with default false', __( '+ True/False', 'fieldpilot-for-acf' ) ),
			array( 'relationship', '- Add a relationship field called Related Posts with max 3', __( '+ Relationship', 'fieldpilot-for-acf' ) ),
			array( 'flexible_content', '- Add a flexible_content field called Page Builder with Hero and Features layouts', __( '+ Flexible Content', 'fieldpilot-for-acf' ) ),
			array( 'date_picker', '- Add a date_picker field called Event Date with format Y-m-d', __( '+ Date Picker', 'fieldpilot-for-acf' ) ),
			array( 'group', '- Add a group container called Contact Info with phone, email, and address fields', __( '+ Group Container', 'fieldpilot-for-acf' ) ),
		);

		foreach ( $chips as [ $type, $intent, $label ] ) {
			$available = $catalog->isAvailable( $type );

			printf(
				'<button type="button" class="acfjp-chip" data-intent="%1$s"%2$s%3$s>%4$s</button>',
				esc_attr( $intent ),
				disabled( ! $available, true, false ),
				$available ? '' : ' title="' . esc_attr( $catalog->unavailableReason( $type ) ) . '"',
				esc_html( $label )
			);
		}
	}

	private function typeGuideIntro(): void {
		$catalog   = $this->catalog();
		$available = $catalog->availableCount();

		if ( FieldTypeCatalog::TOTAL === $available ) {
			esc_html_e( 'All 36 ACF field types are available on this site and can be used in payloads and templates.', 'fieldpilot-for-acf' );

			return;
		}

		echo esc_html(
			sprintf(
				/* translators: 1: number of field types this site can use, 2: total number of field types */
				__( '%1$d of %2$d ACF field types are available on this site. The others are shown with what they need, and FieldPilot will not add them here.', 'fieldpilot-for-acf' ),
				$available,
				FieldTypeCatalog::TOTAL
			)
		);
	}

	private function typeGuide(): void {
		$catalog = $this->catalog();
		$number  = 0;

		echo '<div class="acfjp-fieldtypes-grid">';

		foreach ( $catalog->groups() as $group ) {
			++$number;

			echo '<div class="acfjp-ft-category">';
			printf(
				'<h4>%s</h4>',
				esc_html(
					sprintf(
						/* translators: 1: group number, 2: group name, 3: number of types in the group */
						_n( '%1$d. %2$s (%3$d type)', '%1$d. %2$s (%3$d types)', count( $group['types'] ), 'fieldpilot-for-acf' ),
						$number,
						$group['label'],
						count( $group['types'] )
					)
				)
			);
			echo '<div class="acfjp-ft-tags">';

			foreach ( $group['types'] as $type => $data ) {
				$available = $catalog->isAvailable( $type );

				printf(
					'<span class="%1$s"><code>%2$s</code> %3$s%4$s</span>',
					$available ? 'acfjp-ft-tag' : 'acfjp-ft-tag is-unavailable',
					esc_html( $type ),
					esc_html( $data['label'] ),
					$available ? '' : ' <em>' . esc_html( $catalog->unavailableReason( $type ) ) . '</em>'
				);
			}

			echo '</div></div>';
		}

		echo '</div>';
	}

	private function describeOperation( Operation $operation ): string {
		return match ( $operation ) {
			Operation::Create  => __( 'Create - a brand new field group', 'fieldpilot-for-acf' ),
			Operation::Add     => __( 'Add - insert new fields only', 'fieldpilot-for-acf' ),
			Operation::Update  => __( 'Update - change only the settings named', 'fieldpilot-for-acf' ),
			Operation::Delete  => __( 'Delete - remove the fields named', 'fieldpilot-for-acf' ),
			Operation::Move    => __( 'Move - relocate existing fields', 'fieldpilot-for-acf' ),
			Operation::Merge   => __( 'Merge - add what is missing, delete nothing', 'fieldpilot-for-acf' ),
			Operation::Sync    => __( 'Sync - make it match exactly, including deletions', 'fieldpilot-for-acf' ),
			Operation::Replace => __( 'Replace - rebuild the group from scratch', 'fieldpilot-for-acf' ),
		};
	}

	/**
	 * Handle the optional file upload. Contents are validated as JSON before they
	 * are echoed back into the editor; the extension is never trusted.
	 */
	private function handleUpload(): string {
		if ( ! isset( $_POST['_wpnonce'], $_FILES['acfjp_file'] ) ) {
			return '';
		}

		if ( ! current_user_can( (string) apply_filters( 'acfjp_capability', Guard::CAPABILITY ) ) ) {
			$this->notice( esc_html__( 'You do not have permission to upload files.', 'fieldpilot-for-acf' ), 'error' );
			return '';
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			$this->notice( esc_html__( 'That request could not be verified. Please try again.', 'fieldpilot-for-acf' ), 'error' );
			return '';
		}

		if ( ! is_array( $_FILES['acfjp_file'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw array accessed to validate and sanitize individual elements below.
		$rawFile = $_FILES['acfjp_file'];

		// Validate upload error code.
		$errorCode = isset( $rawFile['error'] ) ? absint( $rawFile['error'] ) : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $errorCode ) {
			return '';
		}

		if ( UPLOAD_ERR_OK !== $errorCode ) {
			$this->notice( esc_html__( 'File upload failed. Please try again.', 'fieldpilot-for-acf' ), 'error' );
			return '';
		}

		// Sanitize each component of the uploaded file array.
		$file = array(
			'name'     => isset( $rawFile['name'] ) ? sanitize_file_name( wp_unslash( (string) $rawFile['name'] ) ) : '',
			'type'     => isset( $rawFile['type'] ) ? sanitize_mime_type( wp_unslash( (string) $rawFile['type'] ) ) : '',
			// WordPress does not slash $_FILES, so no wp_unslash() here: it would strip
			// the backslashes from a Windows temp path. Parser::parseUpload() only
			// reads it after is_uploaded_file() has vouched for it.
			'tmp_name' => isset( $rawFile['tmp_name'] ) && is_string( $rawFile['tmp_name'] ) ? $rawFile['tmp_name'] : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'error'    => $errorCode,
			'size'     => isset( $rawFile['size'] ) ? absint( $rawFile['size'] ) : 0,
		);

		// Validate file extension.
		if ( '' !== $file['name'] ) {
			$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
			if ( 'json' !== $extension ) {
				$this->notice( esc_html__( 'Please upload a valid .json file.', 'fieldpilot-for-acf' ), 'error' );
				return '';
			}
		}

		try {
			$decoded = $this->container->get( Parser::class )->parseUpload( $file );

			return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		} catch ( \Throwable $e ) {
			$this->notice( esc_html( $e->getMessage() ), 'error' );

			return '';
		}
	}
}
