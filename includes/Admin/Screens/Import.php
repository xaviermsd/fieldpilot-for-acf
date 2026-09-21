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
								<label for="acfjp-prompt-intent"><strong><?php esc_html_e( 'What change would you like to make?', 'wp-acf-json-pro' ); ?></strong></label>
								<textarea id="acfjp-prompt-intent" class="widefat" rows="3"
									placeholder="<?php esc_attr_e( 'e.g. Add a repeater named "Team Members" with fields for Full Name (text), Role (text), and Photo (image).', 'wp-acf-json-pro' ); ?>"></textarea>
								
								<div class="acfjp-quick-chips">
									<span class="acfjp-quick-chips__label"><?php esc_html_e( 'Quick ideas:', 'wp-acf-json-pro' ); ?></span>
									<button type="button" class="acfjp-chip" data-intent="Add a repeater called Team Members with name, role, and photo fields"><?php esc_html_e( '+ Repeater Field', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="Add a text field called Custom Heading and make it required"><?php esc_html_e( '+ Required Text Field', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="Add an image field called Hero Banner with return format array"><?php esc_html_e( '+ Image Field', 'wp-acf-json-pro' ); ?></button>
									<button type="button" class="acfjp-chip" data-intent="Add a select field called Status with choices: Draft, Review, Published"><?php esc_html_e( '+ Select Dropdown', 'wp-acf-json-pro' ); ?></button>
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
									<a href="https://chatgpt.com" target="_blank" rel="noopener noreferrer" class="button button-secondary">
										ChatGPT ↗
									</a>
									<a href="https://claude.ai" target="_blank" rel="noopener noreferrer" class="button button-secondary">
										Claude ↗
									</a>
									<a href="https://gemini.google.com" target="_blank" rel="noopener noreferrer" class="button button-secondary">
										Gemini ↗
									</a>
									<a href="https://cursor.com" target="_blank" rel="noopener noreferrer" class="button button-secondary">
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
