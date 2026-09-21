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
		return __( 'Import JSON', 'wp-acf-json-pro' );
	}

	protected function body(): void {
		$prefill = $this->handleUpload();

		$groups = $this->container->get( MutabilityClassifier::class )->classifyAll();

		?>
		<div class="acfjp-wrap">

			<!-- Top Nav Mode Switcher -->
			<nav class="nav-tab-wrapper acfjp-nav-tab-wrapper" style="margin-bottom: 20px;" aria-label="<?php esc_attr_e( 'Import modes', 'wp-acf-json-pro' ); ?>">
				<a href="#editor" class="nav-tab nav-tab-active acfjp-tab" data-tab="editor">
					<span class="dashicons dashicons-editor-code"></span>
					<?php esc_html_e( '1. JSON Editor & Import', 'wp-acf-json-pro' ); ?>
				</a>
				<a href="#ai" class="nav-tab acfjp-tab" data-tab="ai">
					<span class="dashicons dashicons-superhero-alt"></span>
					<?php esc_html_e( '2. Generate with AI', 'wp-acf-json-pro' ); ?>
				</a>
				<a href="#guide" class="nav-tab acfjp-tab" data-tab="guide">
					<span class="dashicons dashicons-book"></span>
					<?php esc_html_e( '3. Schema & Operations Guide', 'wp-acf-json-pro' ); ?>
				</a>
			</nav>

			<!-- Tab 1: Direct JSON & File Import (Primary Default) -->
			<div class="acfjp-tab-content is-active" id="acfjp-tab-editor" role="tabpanel">
				<div class="acfjp-import" id="acfjp-import">

					<div class="acfjp-import__editor">
						<div class="acfjp-box-header">
							<h2 class="acfjp-step-heading">
								<span class="dashicons dashicons-edit"></span>
								<?php esc_html_e( 'Paste JSON or Upload File', 'wp-acf-json-pro' ); ?>
							</h2>
							<p class="description acfjp-step-hint">
								<?php esc_html_e( 'Accepts WP ACF JSON Pro patches or standard ACF export JSON.', 'wp-acf-json-pro' ); ?>
							</p>
						</div>

						<div class="acfjp-editor-toolbar">
							<div class="acfjp-editor-toolbar__left">
								<button type="button" class="button button-primary" id="acfjp-paste-clipboard">
									<span class="dashicons dashicons-clipboard"></span>
									<?php esc_html_e( 'Paste from Clipboard', 'wp-acf-json-pro' ); ?>
								</button>

								<div class="acfjp-template-picker">
									<select id="acfjp-template-select" class="button">
										<option value=""><?php esc_html_e( '✨ Insert Template...', 'wp-acf-json-pro' ); ?></option>
										<option value="add_field"><?php esc_html_e( 'Add New Field', 'wp-acf-json-pro' ); ?></option>
										<option value="update_field"><?php esc_html_e( 'Update Existing Field', 'wp-acf-json-pro' ); ?></option>
										<option value="repeater"><?php esc_html_e( 'Add Repeater with Subfields', 'wp-acf-json-pro' ); ?></option>
										<option value="create_group"><?php esc_html_e( 'Create New Field Group', 'wp-acf-json-pro' ); ?></option>
									</select>
								</div>
							</div>

							<div class="acfjp-editor-toolbar__right">
								<button type="button" class="button" id="acfjp-clear">
									<span class="dashicons dashicons-trash"></span>
									<?php esc_html_e( 'Clear', 'wp-acf-json-pro' ); ?>
								</button>
							</div>
						</div>

						<label for="acfjp-json" class="screen-reader-text">
							<?php esc_html_e( 'JSON payload', 'wp-acf-json-pro' ); ?>
						</label>
						<textarea id="acfjp-json" name="json" rows="18" spellcheck="false"
							placeholder="<?php esc_attr_e( 'Paste JSON here...', 'wp-acf-json-pro' ); ?>"><?php echo esc_textarea( $prefill ); ?></textarea>

						<form method="post" enctype="multipart/form-data" class="acfjp-upload">
							<?php wp_nonce_field( self::NONCE ); ?>
							<span class="description"><strong><?php esc_html_e( 'Or upload a .json file:', 'wp-acf-json-pro' ); ?></strong></span>
							<input type="file" name="acfjp_file" accept="application/json,.json" />
							<button type="submit" class="button"><?php esc_html_e( 'Load File', 'wp-acf-json-pro' ); ?></button>
						</form>
					</div>

					<div class="acfjp-import__controls">
						<div class="acfjp-box-header">
							<h2 class="acfjp-step-heading">
								<span class="dashicons dashicons-visibility"></span>
								<?php esc_html_e( 'Review & Apply', 'wp-acf-json-pro' ); ?>
							</h2>
							<p class="description">
								<?php esc_html_e( 'Preview calculates exact diffs in-memory. Nothing is written until confirmed.', 'wp-acf-json-pro' ); ?>
							</p>
						</div>

						<div class="acfjp-primary-actions">
							<button type="button" class="button button-primary button-hero" id="acfjp-preview">
								<span class="dashicons dashicons-visibility"></span>
								<?php esc_html_e( 'Preview Changes', 'wp-acf-json-pro' ); ?>
							</button>
							<button type="button" class="button button-large" id="acfjp-validate">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Validate Only', 'wp-acf-json-pro' ); ?>
							</button>
						</div>

						<hr />

						<div class="acfjp-field-row">
							<label for="acfjp-operation"><strong><?php esc_html_e( 'Import mode override', 'wp-acf-json-pro' ); ?></strong></label>
							<select id="acfjp-operation" class="widefat">
								<option value=""><?php esc_html_e( 'Auto - use what the JSON declares', 'wp-acf-json-pro' ); ?></option>
								<?php foreach ( Operation::cases() as $operation ) : ?>
									<option value="<?php echo esc_attr( $operation->value ); ?>">
										<?php echo esc_html( $this->describeOperation( $operation ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<span class="description"><?php esc_html_e( 'Optional. Only required for plain ACF exports which declare no operation.', 'wp-acf-json-pro' ); ?></span>
						</div>

						<?php if ( array() !== $groups ) : ?>
							<details class="acfjp-groups" open>
								<summary><strong><?php esc_html_e( 'Field groups on this site', 'wp-acf-json-pro' ); ?> (<?php echo count( $groups ); ?>)</strong></summary>
								<ul>
									<?php foreach ( $groups as $group ) : ?>
										<li>
											<div class="acfjp-group-item">
												<strong><?php echo esc_html( $group->groupTitle ); ?></strong>
												<button type="button" class="acfjp-key-badge" data-key="<?php echo esc_attr( $group->groupKey ); ?>" title="<?php esc_attr_e( 'Click to copy group key', 'wp-acf-json-pro' ); ?>">
													<code><?php echo esc_html( $group->groupKey ); ?></code>
												</button>
												<?php if ( ! $group->isPatchable() ) : ?>
													<span class="acfjp-pill acfjp-pill--warn"><?php esc_html_e( 'read only', 'wp-acf-json-pro' ); ?></span>
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

			<!-- Tab 2: AI Prompt Builder -->
			<div class="acfjp-tab-content" id="acfjp-tab-ai" role="tabpanel" hidden>
				<div class="acfjp-ai-card">
					<div class="acfjp-ai-card__header">
						<span class="dashicons dashicons-superhero-alt acfjp-ai-header-icon"></span>
						<div>
							<h2 class="acfjp-card-title"><?php esc_html_e( 'Let an AI write the JSON patch for you', 'wp-acf-json-pro' ); ?></h2>
							<p class="description acfjp-card-subtitle">
								<?php esc_html_e( 'This plugin does not require any paid API keys. We generate a ready-to-use prompt with your exact ACF structure. Paste it into your favorite AI, then paste the AI reply back here.', 'wp-acf-json-pro' ); ?>
							</p>
						</div>
					</div>

					<div class="acfjp-ai-steps">
						<!-- Step A: Build -->
						<div class="acfjp-ai-step">
							<div class="acfjp-ai-step__head">
								<span class="acfjp-badge">1</span>
								<h3><?php esc_html_e( 'Describe what you want to change', 'wp-acf-json-pro' ); ?></h3>
							</div>

							<div class="acfjp-field-row">
								<label for="acfjp-prompt-group"><strong><?php esc_html_e( 'Target Field Group', 'wp-acf-json-pro' ); ?></strong></label>
								<select id="acfjp-prompt-group" class="widefat">
									<option value=""><?php esc_html_e( '- None (new field group or generic schema) -', 'wp-acf-json-pro' ); ?></option>
									<?php foreach ( $groups as $group ) : ?>
										<option value="<?php echo esc_attr( $group->groupKey ); ?>">
											<?php echo esc_html( $group->groupTitle ); ?> (<?php echo esc_html( $group->groupKey ); ?>)
										</option>
									<?php endforeach; ?>
								</select>
								<span class="description"><?php esc_html_e( 'Its current field keys and types will be embedded in the prompt so the AI never hallucinates field names.', 'wp-acf-json-pro' ); ?></span>
							</div>

							<div class="acfjp-field-row">
								<div style="display: flex; justify-content: space-between; align-items: baseline;">
									<label for="acfjp-prompt-intent"><strong><?php esc_html_e( 'What change would you like to make?', 'wp-acf-json-pro' ); ?></strong></label>
									<button type="button" class="button-link acfjp-intent-clear" id="acfjp-intent-clear" style="font-size: 11px; color: #b32d2e; text-decoration: none;">
										<?php esc_html_e( '✕ Clear text', 'wp-acf-json-pro' ); ?>
									</button>
								</div>
								<textarea id="acfjp-prompt-intent" class="widefat" rows="4"
									placeholder="<?php esc_attr_e( 'Click any field type below or type your requirements to build your multi-field patch specification in bulk.', 'wp-acf-json-pro' ); ?>"></textarea>
								
								<div class="acfjp-quick-chips">
									<span class="acfjp-quick-chips__label"><?php esc_html_e( 'Quick add fields (click to append in bulk):', 'wp-acf-json-pro' ); ?></span>
									<button type="button" class="acfjp-chip" data-intent="- Add a repeater called Team Members with name (text), role (text), and photo (image) fields"><?php esc_html_e( '+ Repeater', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a text field called Custom Heading and make it required"><?php esc_html_e( '+ Required Text', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a wysiwyg editor field called Main Content"><?php esc_html_e( '+ WYSIWYG', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add an image field called Hero Banner with return format array"><?php esc_html_e( '+ Image', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a gallery field called Photo Gallery"><?php esc_html_e( '+ Gallery', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a select field called Status with choices: Draft, Review, Published"><?php esc_html_e( '+ Select Dropdown', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a true_false switch field called Is Featured with default false"><?php esc_html_e( '+ True/False', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a relationship field called Related Posts with max 3"><?php esc_html_e( '+ Relationship', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a flexible_content field called Page Builder with Hero and Features layouts"><?php esc_html_e( '+ Flexible Content', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a date_picker field called Event Date with format Y-m-d"><?php esc_html_e( '+ Date Picker', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="- Add a group container called Contact Info with phone, email, and address fields"><?php esc_html_e( '+ Group Container', 'wp-acf-json-pro' ); ?></button>
								</div>

								<!-- Custom Field Builder Row -->
								<div class="acfjp-builder-box" style="background: #f0f6fc; border: 1px solid #cce5ff; border-radius: 4px; padding: 10px 12px; margin-top: 10px;">
									<div style="font-size: 12px; font-weight: 600; color: #004b87; margin-bottom: 6px;">
										<?php esc_html_e( '🎯 Custom Field Builder (Specify your own exact field name & type):', 'wp-acf-json-pro' ); ?>
									</div>
									<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
										<input type="text" id="acfjp-custom-name" placeholder="<?php esc_attr_e( 'Enter field name / label (e.g. Director Bio, Client Logo)...', 'wp-acf-json-pro' ); ?>" style="flex: 1 1 200px; height: 32px; font-size: 13px;" />
										<select id="acfjp-custom-type" style="height: 32px; font-size: 13px;">
											<optgroup label="<?php esc_attr_e( 'Basic & Text', 'wp-acf-json-pro' ); ?>">
												<option value="text"><?php esc_html_e( 'Text', 'wp-acf-json-pro' ); ?></option>
												<option value="textarea"><?php esc_html_e( 'Textarea', 'wp-acf-json-pro' ); ?></option>
												<option value="number"><?php esc_html_e( 'Number', 'wp-acf-json-pro' ); ?></option>
												<option value="range"><?php esc_html_e( 'Range', 'wp-acf-json-pro' ); ?></option>
												<option value="email"><?php esc_html_e( 'Email', 'wp-acf-json-pro' ); ?></option>
												<option value="url"><?php esc_html_e( 'URL', 'wp-acf-json-pro' ); ?></option>
												<option value="password"><?php esc_html_e( 'Password', 'wp-acf-json-pro' ); ?></option>
											</optgroup>
											<optgroup label="<?php esc_attr_e( 'Content & Media', 'wp-acf-json-pro' ); ?>">
												<option value="wysiwyg"><?php esc_html_e( 'WYSIWYG Editor', 'wp-acf-json-pro' ); ?></option>
												<option value="image"><?php esc_html_e( 'Image', 'wp-acf-json-pro' ); ?></option>
												<option value="file"><?php esc_html_e( 'File', 'wp-acf-json-pro' ); ?></option>
												<option value="gallery"><?php esc_html_e( 'Gallery', 'wp-acf-json-pro' ); ?></option>
												<option value="oembed"><?php esc_html_e( 'oEmbed', 'wp-acf-json-pro' ); ?></option>
												<option value="icon_picker"><?php esc_html_e( 'Icon Picker', 'wp-acf-json-pro' ); ?></option>
											</optgroup>
											<optgroup label="<?php esc_attr_e( 'Choices', 'wp-acf-json-pro' ); ?>">
												<option value="select"><?php esc_html_e( 'Select Dropdown', 'wp-acf-json-pro' ); ?></option>
												<option value="checkbox"><?php esc_html_e( 'Checkbox', 'wp-acf-json-pro' ); ?></option>
												<option value="radio"><?php esc_html_e( 'Radio', 'wp-acf-json-pro' ); ?></option>
												<option value="button_group"><?php esc_html_e( 'Button Group', 'wp-acf-json-pro' ); ?></option>
												<option value="true_false"><?php esc_html_e( 'True / False', 'wp-acf-json-pro' ); ?></option>
											</optgroup>
											<optgroup label="<?php esc_attr_e( 'Relational & Objects', 'wp-acf-json-pro' ); ?>">
												<option value="link"><?php esc_html_e( 'Link', 'wp-acf-json-pro' ); ?></option>
												<option value="post_object"><?php esc_html_e( 'Post Object', 'wp-acf-json-pro' ); ?></option>
												<option value="page_link"><?php esc_html_e( 'Page Link', 'wp-acf-json-pro' ); ?></option>
												<option value="relationship"><?php esc_html_e( 'Relationship', 'wp-acf-json-pro' ); ?></option>
												<option value="taxonomy"><?php esc_html_e( 'Taxonomy', 'wp-acf-json-pro' ); ?></option>
												<option value="user"><?php esc_html_e( 'User', 'wp-acf-json-pro' ); ?></option>
											</optgroup>
											<optgroup label="<?php esc_attr_e( 'Layout & Structure', 'wp-acf-json-pro' ); ?>">
												<option value="repeater"><?php esc_html_e( 'Repeater', 'wp-acf-json-pro' ); ?></option>
												<option value="group"><?php esc_html_e( 'Group Container', 'wp-acf-json-pro' ); ?></option>
												<option value="flexible_content"><?php esc_html_e( 'Flexible Content', 'wp-acf-json-pro' ); ?></option>
												<option value="accordion"><?php esc_html_e( 'Accordion', 'wp-acf-json-pro' ); ?></option>
												<option value="tab"><?php esc_html_e( 'Tab', 'wp-acf-json-pro' ); ?></option>
												<option value="message"><?php esc_html_e( 'Message', 'wp-acf-json-pro' ); ?></option>
												<option value="clone"><?php esc_html_e( 'Clone', 'wp-acf-json-pro' ); ?></option>
											</optgroup>
											<optgroup label="<?php esc_attr_e( 'jQuery & Pickers', 'wp-acf-json-pro' ); ?>">
												<option value="google_map"><?php esc_html_e( 'Google Map', 'wp-acf-json-pro' ); ?></option>
												<option value="date_picker"><?php esc_html_e( 'Date Picker', 'wp-acf-json-pro' ); ?></option>
												<option value="date_time_picker"><?php esc_html_e( 'Date Time Picker', 'wp-acf-json-pro' ); ?></option>
												<option value="time_picker"><?php esc_html_e( 'Time Picker', 'wp-acf-json-pro' ); ?></option>
												<option value="color_picker"><?php esc_html_e( 'Color Picker', 'wp-acf-json-pro' ); ?></option>
											</optgroup>
										</select>
										<label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; cursor: pointer;">
											<input type="checkbox" id="acfjp-custom-required" /> <?php esc_html_e( 'Required', 'wp-acf-json-pro' ); ?>
										</label>
										<button type="button" class="button button-primary" id="acfjp-custom-add">
											<span class="dashicons dashicons-plus-alt2"></span>
											<?php esc_html_e( 'Append Field', 'wp-acf-json-pro' ); ?>
										</button>
									</div>
								</div>

								<div class="acfjp-quick-dropdown-row" style="margin-top: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
									<span class="acfjp-quick-chips__label"><?php esc_html_e( 'Or append pre-configured examples:', 'wp-acf-json-pro' ); ?></span>
									<select id="acfjp-quick-field-select" class="button" style="max-width: 300px; font-size: 12px; height: 30px; line-height: 28px;">
										<option value=""><?php esc_html_e( '⚡ Append example specs for all 36 ACF types...', 'wp-acf-json-pro' ); ?></option>
										<optgroup label="<?php esc_attr_e( 'Basic & Text', 'wp-acf-json-pro' ); ?>">
											<option value="- Add a text field named Custom Title and make it required"><?php esc_html_e( 'Text', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a textarea field named Description with 4 rows"><?php esc_html_e( 'Textarea', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a number field named Price with min 0 and step 0.01"><?php esc_html_e( 'Number', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a range field named Rating from 1 to 10"><?php esc_html_e( 'Range', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add an email field named Contact Email"><?php esc_html_e( 'Email', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a url field named External Link"><?php esc_html_e( 'URL', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a password field named Secret Key"><?php esc_html_e( 'Password', 'wp-acf-json-pro' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Content & Media', 'wp-acf-json-pro' ); ?>">
											<option value="- Add a wysiwyg editor named Body Text with full toolbar"><?php esc_html_e( 'WYSIWYG Editor', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add an image field named Thumbnail with array return format"><?php esc_html_e( 'Image', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a file field named Document Attachment with mime types pdf, docx"><?php esc_html_e( 'File', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a gallery field named Photo Album"><?php esc_html_e( 'Gallery', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add an oembed field named Video Player"><?php esc_html_e( 'oEmbed', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add an icon_picker field named Feature Icon"><?php esc_html_e( 'Icon Picker', 'wp-acf-json-pro' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Choices', 'wp-acf-json-pro' ); ?>">
											<option value="- Add a select dropdown named Priority with choices: Low, Medium, High"><?php esc_html_e( 'Select Dropdown', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a checkbox field named Amenities with choices: Wifi, Pool, Parking"><?php esc_html_e( 'Checkbox', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a radio button field named Color Mode with choices: Light, Dark"><?php esc_html_e( 'Radio', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a button_group field named Text Align with choices: Left, Center, Right"><?php esc_html_e( 'Button Group', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a true_false switch named Enable Notifications with default true"><?php esc_html_e( 'True / False', 'wp-acf-json-pro' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Relational & Objects', 'wp-acf-json-pro' ); ?>">
											<option value="- Add a link field named Call To Action with array return format"><?php esc_html_e( 'Link', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a post_object field named Featured Article filtered by post type post"><?php esc_html_e( 'Post Object', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a page_link field named Destination Page"><?php esc_html_e( 'Page Link', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a relationship field named Related Products with max 4"><?php esc_html_e( 'Relationship', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a taxonomy field named Category Selector with taxonomy category"><?php esc_html_e( 'Taxonomy', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a user field named Assigned Manager with role administrator"><?php esc_html_e( 'User', 'wp-acf-json-pro' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'Layout & Structure', 'wp-acf-json-pro' ); ?>">
											<option value="- Add a repeater named FAQ Items with question (text) and answer (wysiwyg) subfields"><?php esc_html_e( 'Repeater', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a group container named Metadata with author (text) and date (date_picker) subfields"><?php esc_html_e( 'Group', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a flexible_content field named Page Content with Hero, Grid, and CTA layouts"><?php esc_html_e( 'Flexible Content', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add an accordion section named Advanced Options"><?php esc_html_e( 'Accordion', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a tab divider named Settings Tab"><?php esc_html_e( 'Tab', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a message field named Helper Note with instructional guidance"><?php esc_html_e( 'Message', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a clone field named Reused Address Fields"><?php esc_html_e( 'Clone', 'wp-acf-json-pro' ); ?></option>
										</optgroup>
										<optgroup label="<?php esc_attr_e( 'jQuery & Pickers', 'wp-acf-json-pro' ); ?>">
											<option value="- Add a google_map field named Venue Location"><?php esc_html_e( 'Google Map', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a date_picker field named Start Date with display format d/m/Y"><?php esc_html_e( 'Date Picker', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a date_time_picker field named Appointment Time"><?php esc_html_e( 'Date Time Picker', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a time_picker field named Opening Time"><?php esc_html_e( 'Time Picker', 'wp-acf-json-pro' ); ?></option>
											<option value="- Add a color_picker field named Accent Color with default #2271b1"><?php esc_html_e( 'Color Picker', 'wp-acf-json-pro' ); ?></option>
										</optgroup>
									</select>
								</div>
							</div>

							<p>
								<button type="button" class="button button-primary button-large" id="acfjp-prompt-build">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Generate AI Prompt', 'wp-acf-json-pro' ); ?>
								</button>
							</p>
						</div>

						<!-- Step B: Copy & Send -->
						<div class="acfjp-ai-step is-highlighted" id="acfjp-prompt-result" hidden>
							<div class="acfjp-ai-step__head">
								<span class="acfjp-badge">2</span>
								<h3><?php esc_html_e( 'Send prompt to your AI & get the JSON reply', 'wp-acf-json-pro' ); ?></h3>
							</div>

							<p class="description">
								<?php esc_html_e( '1. Copy this prompt. 2. Open your preferred AI tool. 3. Paste the prompt and submit.', 'wp-acf-json-pro' ); ?>
							</p>

							<div class="acfjp-prompt-toolbar">
								<button type="button" class="button button-primary" id="acfjp-prompt-copy">
									<span class="dashicons dashicons-admin-page"></span>
									<?php esc_html_e( 'Copy Prompt', 'wp-acf-json-pro' ); ?>
								</button>

								<div class="acfjp-ai-links">
									<span class="acfjp-ai-links__label"><?php esc_html_e( 'Open AI in new tab:', 'wp-acf-json-pro' ); ?></span>
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
								aria-label="<?php esc_attr_e( 'Generated prompt', 'wp-acf-json-pro' ); ?>"></textarea>

							<div class="acfjp-ai-step__action">
								<h4><?php esc_html_e( 'Ready with the AI response?', 'wp-acf-json-pro' ); ?></h4>
								<p class="description">
									<?php esc_html_e( 'Copy the AI reply (even with markdown code fences), then click below to transfer directly to the JSON Editor:', 'wp-acf-json-pro' ); ?>
								</p>
								<p>
									<button type="button" class="button button-primary button-hero" id="acfjp-paste-and-preview">
										<span class="dashicons dashicons-clipboard"></span>
										<?php esc_html_e( 'Paste AI Response & Switch to Editor', 'wp-acf-json-pro' ); ?>
									</button>
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Tab 3: Schema & Guide -->
			<div class="acfjp-tab-content" id="acfjp-tab-guide" role="tabpanel" hidden>
				<div class="acfjp-guide-card">
					<h2><?php esc_html_e( 'Schema & Operations Reference', 'wp-acf-json-pro' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'WP ACF JSON Pro lets you apply safe declarative patches to ACF field groups. Here is how each operation behaves:', 'wp-acf-json-pro' ); ?>
					</p>

					<div class="acfjp-operations-grid">
						<div class="acfjp-op-card">
							<h3><code>add</code> <span class="acfjp-pill acfjp-pill--ok">Safe</span></h3>
							<p><?php esc_html_e( 'Inserts new fields only into the targeted group or sub-tree. Existing fields and settings remain untouched.', 'wp-acf-json-pro' ); ?></p>
						</div>

						<div class="acfjp-op-card">
							<h3><code>update</code> <span class="acfjp-pill acfjp-pill--ok">Safe</span></h3>
							<p><?php esc_html_e( 'Modifies ONLY the specific settings named (e.g. required, label). Omitting a setting preserves its current value.', 'wp-acf-json-pro' ); ?></p>
						</div>

						<div class="acfjp-op-card">
							<h3><code>create</code> <span class="acfjp-pill acfjp-pill--ok">Safe</span></h3>
							<p><?php esc_html_e( 'Creates a brand new field group from scratch with the supplied fields and settings.', 'wp-acf-json-pro' ); ?></p>
						</div>

						<div class="acfjp-op-card">
							<h3><code>move</code> <span class="acfjp-pill acfjp-pill--caution">Caution</span></h3>
							<p><?php esc_html_e( 'Relocates or reparents existing fields into new positions, groups, or repeater/flexible layouts.', 'wp-acf-json-pro' ); ?></p>
						</div>

						<div class="acfjp-op-card">
							<h3><code>delete</code> <span class="acfjp-pill acfjp-pill--destructive">Destructive</span></h3>
							<p><?php esc_html_e( 'Safely deletes specified fields. Always requires explicit user confirmation in the preview diff.', 'wp-acf-json-pro' ); ?></p>
						</div>

						<div class="acfjp-op-card">
							<h3><code>sync</code> <span class="acfjp-pill acfjp-pill--caution">Caution</span></h3>
							<p><?php esc_html_e( 'Brings the field group into 100% exact parity with the JSON payload, adding new fields, updating changed ones, and removing missing ones.', 'wp-acf-json-pro' ); ?></p>
						</div>
					</div>

					<div class="acfjp-guide-footer">
						<p>
							<strong><?php esc_html_e( '💡 Need instant rollback?', 'wp-acf-json-pro' ); ?></strong>
							<?php esc_html_e( 'Every applied change generates a snapshot. You can undo any change anytime from the History tab.', 'wp-acf-json-pro' ); ?>
						</p>
					</div>
				</div>
			</div>

		</div>

		<div id="acfjp-result" class="acfjp-result" aria-live="polite"></div>
		<?php
	}

	private function describeOperation( Operation $operation ): string {
		return match ( $operation ) {
			Operation::Create  => __( 'Create - a brand new field group', 'wp-acf-json-pro' ),
			Operation::Add     => __( 'Add - insert new fields only', 'wp-acf-json-pro' ),
			Operation::Update  => __( 'Update - change only the settings named', 'wp-acf-json-pro' ),
			Operation::Delete  => __( 'Delete - remove the fields named', 'wp-acf-json-pro' ),
			Operation::Move    => __( 'Move - relocate existing fields', 'wp-acf-json-pro' ),
			Operation::Merge   => __( 'Merge - add what is missing, delete nothing', 'wp-acf-json-pro' ),
			Operation::Sync    => __( 'Sync - make it match exactly, including deletions', 'wp-acf-json-pro' ),
			Operation::Replace => __( 'Replace - rebuild the group from scratch', 'wp-acf-json-pro' ),
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
			$this->notice( esc_html__( 'That request could not be verified. Please try again.', 'wp-acf-json-pro' ), 'error' );
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
