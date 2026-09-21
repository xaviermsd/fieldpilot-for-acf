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

use ACFJP\Acf\MutabilityClassifier;
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

		$groups = $this->container->get( MutabilityClassifier::class )->classifyAll();

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
							<div class="acfjp-field-row">
								<label for="acfjp-prompt-group"><strong><?php esc_html_e( 'Target Field Group', 'fieldpilot-for-acf' ); ?></strong></label>
								<select id="acfjp-prompt-group" class="widefat">
									<option value=""><?php esc_html_e( '- None (new field group or generic schema) -', 'fieldpilot-for-acf' ); ?></option>
									<?php foreach ( $groups as $group ) : ?>
										<option value="<?php echo esc_attr( $group->groupKey ); ?>">
											<?php echo esc_html( $group->groupTitle ); ?> (<?php echo esc_html( $group->groupKey ); ?>)
										</option>
									<?php endforeach; ?>
								</select>
								<span class="description"><?php esc_html_e( 'Its current field keys and types will be embedded in the prompt so the AI never hallucinates field names.', 'fieldpilot-for-acf' ); ?></span>
							</div>

							<!-- Custom Field Builder Box (4 Tabs: General, Validation, Presentation, Conditional Logic) -->
							<div class="acfjp-builder-box">
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
								<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
									<input type="text" id="acfjp-custom-name" placeholder="<?php esc_attr_e( 'Field Label / Name (e.g. Director Bio, Hero Banner)...', 'fieldpilot-for-acf' ); ?>" style="flex: 1 1 200px; height: 32px; font-size: 13px;" />
									<select id="acfjp-custom-type" style="height: 32px; font-size: 13px;">
										<optgroup label="<?php esc_attr_e( 'Basic & Text', 'fieldpilot-for-acf' ); ?>">
											<option value="text"><?php esc_html_e( 'Text', 'fieldpilot-for-acf' ); ?></option>
											<option value="textarea"><?php esc_html_e( 'Textarea', 'fieldpilot-for-acf' ); ?></option>
											<option value="number"><?php esc_html_e( 'Number', 'fieldpilot-for-acf' ); ?></option>
											<option value="range"><?php esc_html_e( 'Range', 'fieldpilot-for-acf' ); ?></option>
											<option value="email"><?php esc_html_e( 'Email', 'fieldpilot-for-acf' ); ?></option>
											<option value="url"><?php esc_html_e( 'URL', 'fieldpilot-for-acf' ); ?></option>
											<option value="password"><?php esc_html_e( 'Password', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Content & Media', 'fieldpilot-for-acf' ); ?>">
											<option value="wysiwyg"><?php esc_html_e( 'WYSIWYG Editor', 'fieldpilot-for-acf' ); ?></option>
											<option value="image"><?php esc_html_e( 'Image', 'fieldpilot-for-acf' ); ?></option>
											<option value="file"><?php esc_html_e( 'File', 'fieldpilot-for-acf' ); ?></option>
											<option value="gallery"><?php esc_html_e( 'Gallery', 'fieldpilot-for-acf' ); ?></option>
											<option value="oembed"><?php esc_html_e( 'oEmbed', 'fieldpilot-for-acf' ); ?></option>
											<option value="icon_picker"><?php esc_html_e( 'Icon Picker', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Choices', 'fieldpilot-for-acf' ); ?>">
											<option value="select"><?php esc_html_e( 'Select Dropdown', 'fieldpilot-for-acf' ); ?></option>
											<option value="checkbox"><?php esc_html_e( 'Checkbox', 'fieldpilot-for-acf' ); ?></option>
											<option value="radio"><?php esc_html_e( 'Radio', 'fieldpilot-for-acf' ); ?></option>
											<option value="button_group"><?php esc_html_e( 'Button Group', 'fieldpilot-for-acf' ); ?></option>
											<option value="true_false"><?php esc_html_e( 'True / False', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Relational & Objects', 'fieldpilot-for-acf' ); ?>">
											<option value="link"><?php esc_html_e( 'Link', 'fieldpilot-for-acf' ); ?></option>
											<option value="post_object"><?php esc_html_e( 'Post Object', 'fieldpilot-for-acf' ); ?></option>
											<option value="page_link"><?php esc_html_e( 'Page Link', 'fieldpilot-for-acf' ); ?></option>
											<option value="relationship"><?php esc_html_e( 'Relationship', 'fieldpilot-for-acf' ); ?></option>
											<option value="taxonomy"><?php esc_html_e( 'Taxonomy', 'fieldpilot-for-acf' ); ?></option>
											<option value="user"><?php esc_html_e( 'User', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Layout & Structure', 'fieldpilot-for-acf' ); ?>">
											<option value="repeater"><?php esc_html_e( 'Repeater', 'fieldpilot-for-acf' ); ?></option>
											<option value="group"><?php esc_html_e( 'Group Container', 'fieldpilot-for-acf' ); ?></option>
											<option value="flexible_content"><?php esc_html_e( 'Flexible Content', 'fieldpilot-for-acf' ); ?></option>
											<option value="accordion"><?php esc_html_e( 'Accordion', 'fieldpilot-for-acf' ); ?></option>
											<option value="tab"><?php esc_html_e( 'Tab', 'fieldpilot-for-acf' ); ?></option>
											<option value="message"><?php esc_html_e( 'Message', 'fieldpilot-for-acf' ); ?></option>
											<option value="clone"><?php esc_html_e( 'Clone', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'jQuery & Pickers', 'fieldpilot-for-acf' ); ?>">
											<option value="google_map"><?php esc_html_e( 'Google Map', 'fieldpilot-for-acf' ); ?></option>
											<option value="date_picker"><?php esc_html_e( 'Date Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="date_time_picker"><?php esc_html_e( 'Date Time Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="time_picker"><?php esc_html_e( 'Time Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="color_picker"><?php esc_html_e( 'Color Picker', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
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

								<!-- 4 ACF Tabs Bar -->
								<div class="acfjp-builder-subtabs">
									<button type="button" class="acfjp-builder-tab is-active" data-builder-tab="general">
										<?php esc_html_e( '⚙️ General', 'fieldpilot-for-acf' ); ?>
									</button>
									<button type="button" class="acfjp-builder-tab" data-builder-tab="validation">
										<?php esc_html_e( '🛡️ Validation', 'fieldpilot-for-acf' ); ?>
									</button>
									<button type="button" class="acfjp-builder-tab" data-builder-tab="presentation">
										<?php esc_html_e( '🎨 Presentation', 'fieldpilot-for-acf' ); ?>
									</button>
									<button type="button" class="acfjp-builder-tab" data-builder-tab="logic">
										<?php esc_html_e( '🔀 Conditional Logic', 'fieldpilot-for-acf' ); ?>
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
										<?php esc_html_e( 'Append Field to Queue ➔', 'fieldpilot-for-acf' ); ?>
									</button>
									<span class="acfjp-builder-hint">
										<?php esc_html_e( '💡 Press Enter or click above to append this field specification to your queue below.', 'fieldpilot-for-acf' ); ?>
									</span>
									<span class="acfjp-added-indicator" id="acfjp-added-indicator" style="display: none;">
										<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Appended to Queue!', 'fieldpilot-for-acf' ); ?>
									</span>
								</div>
							</div>

							<!-- Quick Ideas & All 36 Types Dropdown -->
							<div class="acfjp-field-row" style="margin-top: 14px;">
								<div class="acfjp-quick-chips">
									<span class="acfjp-quick-chips__label"><?php esc_html_e( 'Quick add fields (click to append in bulk):', 'fieldpilot-for-acf' ); ?></span>
									<button type="button" class="acfjp-chip" data-intent="- Add a repeater called Team Members with name (text), role (text), and photo (image) fields"><?php esc_html_e( '+ Repeater', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a text field called Custom Heading and make it required"><?php esc_html_e( '+ Required Text', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a wysiwyg editor field called Main Content"><?php esc_html_e( '+ WYSIWYG', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add an image field called Hero Banner with return format array"><?php esc_html_e( '+ Image', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a gallery field called Photo Gallery"><?php esc_html_e( '+ Gallery', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a select field called Status with choices: Draft, Review, Published"><?php esc_html_e( '+ Select Dropdown', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a true_false switch field called Is Featured with default false"><?php esc_html_e( '+ True/False', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a relationship field called Related Posts with max 3"><?php esc_html_e( '+ Relationship', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a flexible_content field called Page Builder with Hero and Features layouts"><?php esc_html_e( '+ Flexible Content', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a date_picker field called Event Date with format Y-m-d"><?php esc_html_e( '+ Date Picker', 'fieldpilot-for-acf' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a group container called Contact Info with phone, email, and address fields"><?php esc_html_e( '+ Group Container', 'fieldpilot-for-acf' ); ?></button>
								</div>

								<div class="acfjp-quick-dropdown-row" style="margin-top: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
									<span class="acfjp-quick-chips__label"><?php esc_html_e( 'Or append pre-configured examples:', 'fieldpilot-for-acf' ); ?></span>
									<select id="acfjp-quick-field-select" class="button" style="max-width: 320px; font-size: 12px; height: 32px; line-height: 30px;">
										<option value=""><?php esc_html_e( '⚡ Append example specs for all 36 ACF types...', 'fieldpilot-for-acf' ); ?></option>
										<optgroup label="<?php esc_attr_e( 'Basic & Text', 'fieldpilot-for-acf' ); ?>">
											<option value="- Add a text field named Custom Title and make it required"><?php esc_html_e( 'Text', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a textarea field named Description with 4 rows"><?php esc_html_e( 'Textarea', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a number field named Price with min 0 and step 0.01"><?php esc_html_e( 'Number', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a range field named Rating from 1 to 10"><?php esc_html_e( 'Range', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add an email field named Contact Email"><?php esc_html_e( 'Email', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a url field named External Link"><?php esc_html_e( 'URL', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a password field named Secret Key"><?php esc_html_e( 'Password', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Content & Media', 'fieldpilot-for-acf' ); ?>">
											<option value="- Add an image field named Hero Image with return format array and min width 1200"><?php esc_html_e( 'Image', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a file field named Brochure PDF with mime types pdf and max size 5MB"><?php esc_html_e( 'File', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a gallery field named Photo Gallery with min 3 images"><?php esc_html_e( 'Gallery (PRO)', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a wysiwyg field named Article Body with full toolbar"><?php esc_html_e( 'WYSIWYG Editor', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add an oembed field named Featured Video"><?php esc_html_e( 'oEmbed Video', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add an icon_picker field named Social Icon"><?php esc_html_e( 'Icon Picker', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Choices', 'fieldpilot-for-acf' ); ?>">
											<option value="- Add a select field named Publishing Status with choices: draft: Draft, review: Review, live: Live"><?php esc_html_e( 'Select Dropdown', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a checkbox field named Features with choices: wifi: WiFi, pool: Swimming Pool, gym: Gym"><?php esc_html_e( 'Checkbox', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a radio field named Layout Style with choices: grid: Grid, list: List and default grid"><?php esc_html_e( 'Radio', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a button_group field named Text Alignment with choices: left: Left, center: Center, right: Right"><?php esc_html_e( 'Button Group', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a true_false field named Is Featured with default false and ui enabled"><?php esc_html_e( 'True / False', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Relational & Objects', 'fieldpilot-for-acf' ); ?>">
											<option value="- Add a post_object field named Featured Article for post type post with return format object"><?php esc_html_e( 'Post Object', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a relationship field named Related Case Studies for post type case_study with max 4"><?php esc_html_e( 'Relationship (PRO)', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a taxonomy field named Department for taxonomy category with return format id"><?php esc_html_e( 'Taxonomy', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a user field named Author Profile with role author"><?php esc_html_e( 'User', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a page_link field named Landing Page Link"><?php esc_html_e( 'Page Link', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a link field named Documentation Link"><?php esc_html_e( 'Link', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Layout & Structure', 'fieldpilot-for-acf' ); ?>">
											<option value="- Add a repeater field named Team Members with subfields name (text) and role (text)"><?php esc_html_e( 'Repeater (PRO)', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a group field named Address Info with subfields street (text), city (text), and zip (text)"><?php esc_html_e( 'Group Container', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a flexible_content field named Page Builder with Hero and Text layouts"><?php esc_html_e( 'Flexible Content (PRO)', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add an accordion field named Specifications"><?php esc_html_e( 'Accordion', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a tab field named Advanced Options"><?php esc_html_e( 'Tab Divider', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a message field named Important Note with message Please fill out all required items"><?php esc_html_e( 'Message', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a clone field named Reused Banner"><?php esc_html_e( 'Clone', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'jQuery & Pickers', 'fieldpilot-for-acf' ); ?>">
											<option value="- Add a google_map field named Headquarters Location with zoom 14"><?php esc_html_e( 'Google Map', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a date_picker field named Release Date with return format Y-m-d"><?php esc_html_e( 'Date Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a date_time_picker field named Event Start with format Y-m-d H:i:s"><?php esc_html_e( 'Date Time Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a time_picker field named Opening Hours with format H:i:s"><?php esc_html_e( 'Time Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="- Add a color_picker field named Brand Primary Color with default #2271b1"><?php esc_html_e( 'Color Picker', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
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
											<?php esc_html_e( '✕ Clear all', 'fieldpilot-for-acf' ); ?>
										</button>
									</div>
									<textarea id="acfjp-prompt-intent" class="widefat" rows="5"
										placeholder="<?php esc_attr_e( 'All fields added from above appear here line-by-line. You can also type custom instructions directly.', 'fieldpilot-for-acf' ); ?>"></textarea>
									<span class="description" style="font-size: 11px; color: #646970; margin-top: 6px; display: block;">
										<?php esc_html_e( '💡 Each field will be automatically compiled into your final AI prompt.', 'fieldpilot-for-acf' ); ?>
									</span>
								</div>
							</div>

							<!-- Step 1 Action Bar -->
							<div style="margin-top: 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
								<button type="button" class="button button-primary button-hero acfjp-btn-generate" id="acfjp-prompt-build">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Generate AI Prompt ➔', 'fieldpilot-for-acf' ); ?>
								</button>
								<span class="acfjp-step-next-hint" id="acfjp-gen-hint" style="font-size: 12px; color: #005a9c; font-weight: 600; display: none;">
									<?php esc_html_e( '✨ Fields queued! Click above to generate your prompt.', 'fieldpilot-for-acf' ); ?>
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
										<?php esc_html_e( '2. Paste AI Response & Switch to Editor ➔', 'fieldpilot-for-acf' ); ?>
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

					<div class="acfjp-import__editor">
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

								<select id="acfjp-template-group-select" class="button" title="<?php esc_attr_e( 'Target Field Group for templates', 'fieldpilot-for-acf' ); ?>" style="max-width: 220px; font-size: 12px; height: 30px; line-height: 28px;">
									<option value=""><?php esc_html_e( '🎯 Target: (Auto / First Group)', 'fieldpilot-for-acf' ); ?></option>
									<?php foreach ( $groups as $group ) : ?>
										<option value="<?php echo esc_attr( $group->groupTitle ); ?>" data-key="<?php echo esc_attr( $group->groupKey ); ?>">
											<?php echo esc_html( $group->groupTitle ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<div class="acfjp-template-picker">
									<select id="acfjp-template-select" class="button" style="max-width: 260px; font-size: 12px; height: 30px; line-height: 28px;">
										<option value=""><?php esc_html_e( '✨ Insert Template (All 36 Types)...', 'fieldpilot-for-acf' ); ?></option>
										<optgroup label="<?php esc_attr_e( 'Common Operations', 'fieldpilot-for-acf' ); ?>">
											<option value="add_field"><?php esc_html_e( 'Add New Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="update_field"><?php esc_html_e( 'Update Existing Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="create_group"><?php esc_html_e( 'Create New Field Group', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( '1. Basic & Text (7 Types)', 'fieldpilot-for-acf' ); ?>">
											<option value="tpl_text"><?php esc_html_e( 'Text Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_textarea"><?php esc_html_e( 'Textarea Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_number"><?php esc_html_e( 'Number Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_range"><?php esc_html_e( 'Range Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_email"><?php esc_html_e( 'Email Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_url"><?php esc_html_e( 'URL Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_password"><?php esc_html_e( 'Password Field', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( '2. Content & Media (6 Types)', 'fieldpilot-for-acf' ); ?>">
											<option value="tpl_wysiwyg"><?php esc_html_e( 'WYSIWYG Editor', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_image"><?php esc_html_e( 'Image Field (Array)', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_file"><?php esc_html_e( 'File Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_gallery"><?php esc_html_e( 'Gallery Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_oembed"><?php esc_html_e( 'oEmbed Video', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_icon_picker"><?php esc_html_e( 'Icon Picker', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( '3. Choice Fields (5 Types)', 'fieldpilot-for-acf' ); ?>">
											<option value="tpl_select"><?php esc_html_e( 'Select Dropdown', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_checkbox"><?php esc_html_e( 'Checkbox Group', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_radio"><?php esc_html_e( 'Radio Buttons', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_button_group"><?php esc_html_e( 'Button Group', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_true_false"><?php esc_html_e( 'True / False Switch', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( '4. Relational & Objects (6 Types)', 'fieldpilot-for-acf' ); ?>">
											<option value="tpl_link"><?php esc_html_e( 'Link (URL & Title)', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_post_object"><?php esc_html_e( 'Post Object', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_page_link"><?php esc_html_e( 'Page Link', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_relationship"><?php esc_html_e( 'Relationship Field', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_taxonomy"><?php esc_html_e( 'Taxonomy Terms', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_user"><?php esc_html_e( 'User Selector', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( '5. Layout & Structure (7 Types)', 'fieldpilot-for-acf' ); ?>">
											<option value="tpl_repeater"><?php esc_html_e( 'Repeater with Subfields', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_group"><?php esc_html_e( 'Group Container', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_flexible_content"><?php esc_html_e( 'Flexible Content (Layouts)', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_accordion"><?php esc_html_e( 'Accordion Section', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_tab"><?php esc_html_e( 'Tab Divider', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_message"><?php esc_html_e( 'Instructional Message', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_clone"><?php esc_html_e( 'Clone Field', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( '6. jQuery & Pickers (5 Types)', 'fieldpilot-for-acf' ); ?>">
											<option value="tpl_google_map"><?php esc_html_e( 'Google Map', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_date_picker"><?php esc_html_e( 'Date Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_date_time_picker"><?php esc_html_e( 'Date Time Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_time_picker"><?php esc_html_e( 'Time Picker', 'fieldpilot-for-acf' ); ?></option>
											<option value="tpl_color_picker"><?php esc_html_e( 'Color Picker', 'fieldpilot-for-acf' ); ?></option>
										</optgroup>
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
									<?php esc_html_e( 'Preview Changes & Review Diff ➔', 'fieldpilot-for-acf' ); ?>
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
					<div class="acfjp-import__controls" id="acfjp-import-controls">
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

							<?php if ( array() !== $groups ) : ?>
								<details class="acfjp-groups" open>
									<summary><strong><?php esc_html_e( 'Field groups on this site', 'fieldpilot-for-acf' ); ?> (<?php echo count( $groups ); ?>)</strong></summary>
									<ul>
										<?php foreach ( $groups as $group ) : ?>
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
								<p><?php esc_html_e( 'Brings the field group into 100% exact parity with the JSON payload, adding new fields, updating changed ones, and removing missing ones.', 'fieldpilot-for-acf' ); ?></p>
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
							<?php esc_html_e( 'The "target" object tells the engine exactly where to apply mutations. Unrelated sibling fields and groups are guaranteed to stay protected.', 'fieldpilot-for-acf' ); ?>
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
								<strong><?php esc_html_e( 'Target Isolation Guarantee:', 'fieldpilot-for-acf' ); ?></strong>
								<?php esc_html_e( 'All sibling fields outside your specified target scope remain 100% untouched and byte-identical. Existing field keys are preserved to protect your postmeta relationships.', 'fieldpilot-for-acf' ); ?>
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

					<!-- Section 4: All 36 ACF Field Types Overview -->
					<div class="acfjp-guide-section">
						<h3 class="acfjp-guide-section__title">
							<span class="dashicons dashicons-screenoptions"></span>
							<?php esc_html_e( '4. Complete 36 ACF Field Types Reference', 'fieldpilot-for-acf' ); ?>
						</h3>
						<p class="description">
							<?php esc_html_e( 'All 36 ACF field types across 6 official categories are supported in patch payloads and templates:', 'fieldpilot-for-acf' ); ?>
						</p>

						<div class="acfjp-fieldtypes-grid">
							<div class="acfjp-ft-category">
								<h4><?php esc_html_e( '1. Basic & Text (7 Types)', 'fieldpilot-for-acf' ); ?></h4>
								<div class="acfjp-ft-tags">
									<span><code>text</code> Text</span>
									<span><code>textarea</code> Text Area</span>
									<span><code>number</code> Number</span>
									<span><code>range</code> Range Slider</span>
									<span><code>email</code> Email</span>
									<span><code>url</code> URL</span>
									<span><code>password</code> Password</span>
								</div>
							</div>

							<div class="acfjp-ft-category">
								<h4><?php esc_html_e( '2. Content & Media (5 Types)', 'fieldpilot-for-acf' ); ?></h4>
								<div class="acfjp-ft-tags">
									<span><code>image</code> Image</span>
									<span><code>file</code> File</span>
									<span><code>wysiwyg</code> WYSIWYG Editor</span>
									<span><code>oembed</code> oEmbed</span>
									<span><code>gallery</code> Gallery (PRO)</span>
								</div>
							</div>

							<div class="acfjp-ft-category">
								<h4><?php esc_html_e( '3. Choice Fields (5 Types)', 'fieldpilot-for-acf' ); ?></h4>
								<div class="acfjp-ft-tags">
									<span><code>select</code> Select Dropdown</span>
									<span><code>checkbox</code> Checkbox</span>
									<span><code>radio</code> Radio Buttons</span>
									<span><code>button_group</code> Button Group</span>
									<span><code>true_false</code> True / False</span>
								</div>
							</div>

							<div class="acfjp-ft-category">
								<h4><?php esc_html_e( '4. Relational & Objects (6 Types)', 'fieldpilot-for-acf' ); ?></h4>
								<div class="acfjp-ft-tags">
									<span><code>post_object</code> Post Object</span>
									<span><code>relationship</code> Relationship (PRO)</span>
									<span><code>taxonomy</code> Taxonomy</span>
									<span><code>user</code> User</span>
									<span><code>page_link</code> Page Link</span>
									<span><code>link</code> Link</span>
								</div>
							</div>

							<div class="acfjp-ft-category">
								<h4><?php esc_html_e( '5. Layout & Structure (7 Types)', 'fieldpilot-for-acf' ); ?></h4>
								<div class="acfjp-ft-tags">
									<span><code>group</code> Group</span>
									<span><code>repeater</code> Repeater (PRO)</span>
									<span><code>flexible_content</code> Flexible Content (PRO)</span>
									<span><code>clone</code> Clone (PRO)</span>
									<span><code>tab</code> Tab Divider</span>
									<span><code>accordion</code> Accordion</span>
									<span><code>message</code> Message</span>
								</div>
							</div>

							<div class="acfjp-ft-category">
								<h4><?php esc_html_e( '6. jQuery & Pickers (5 Types)', 'fieldpilot-for-acf' ); ?></h4>
								<div class="acfjp-ft-tags">
									<span><code>google_map</code> Google Map</span>
									<span><code>date_picker</code> Date Picker</span>
									<span><code>date_time_picker</code> Date Time Picker</span>
									<span><code>time_picker</code> Time Picker</span>
									<span><code>color_picker</code> Color Picker</span>
								</div>
							</div>
						</div>
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
											<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Load into Editor ➔', 'fieldpilot-for-acf' ); ?>
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
											<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Load into Editor ➔', 'fieldpilot-for-acf' ); ?>
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
											<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Load into Editor ➔', 'fieldpilot-for-acf' ); ?>
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
											<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Load into Editor ➔', 'fieldpilot-for-acf' ); ?>
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
							<strong><?php esc_html_e( '💡 Need instant rollback?', 'fieldpilot-for-acf' ); ?></strong>
							<?php esc_html_e( 'Every applied change generates a snapshot. You can undo any change anytime from the History tab.', 'fieldpilot-for-acf' ); ?>
						</p>
					</div>
				</div>
			</div>

		</div>
		<?php
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

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			$this->notice( esc_html__( 'That request could not be verified. Please try again.', 'fieldpilot-for-acf' ), 'error' );
			return '';
		}

		try {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- handed to a validating parser.
			$decoded = $this->container->get( Parser::class )->parseUpload( (array) $_FILES['acfjp_file'] );

			return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		} catch ( \Throwable $e ) {
			$this->notice( esc_html( $e->getMessage() ), 'error' );

			return '';
		}
	}
}
