<?php
/**
 * Diagnostics: prove the write path works on THIS install.
 *
 * Exists because the alternative is asking people to install Docker before they
 * can find out whether the plugin works on their site. It does not answer the same
 * question anyway: what matters is this ACF version, this PHP version, these
 * installed field types and whatever else filters `acf/update_field` here.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin\Screens;

use ACFJP\Apply\Guard;
use ACFJP\Diagnostics\Check;

defined( 'ABSPATH' ) || exit;

final class Diagnostics extends Screen {

	protected function title(): string {
		return __( 'Diagnostics', 'wp-acf-json-pro' );
	}

	protected function body(): void {
		?>
		<p class="acfjp-lede">
			<?php esc_html_e( 'Runs the engine end to end against your real ACF installation: a partial update, a rollback, conflict handling, nested structures and the safety refusals.', 'wp-acf-json-pro' ); ?>
		</p>

		<div class="acfjp-panel acfjp-panel--info">
			<h2><?php esc_html_e( 'What this does to your site', 'wp-acf-json-pro' ); ?></h2>
			<ul class="acfjp-bullets">
				<li><?php esc_html_e( 'Creates one temporary, inactive field group of its own, then deletes it.', 'wp-acf-json-pro' ); ?></li>
				<li><?php esc_html_e( 'Never reads, changes or deletes a field group it did not create - every one it makes is tagged, and only tagged groups are removed.', 'wp-acf-json-pro' ); ?></li>
				<li><?php esc_html_e( 'Touches no posts, no options and no content.', 'wp-acf-json-pro' ); ?></li>
				<li><?php esc_html_e( 'Removes its own history entries afterwards.', 'wp-acf-json-pro' ); ?></li>
			</ul>
		</div>

		<?php if ( Guard::isReadOnly() ) : ?>
			<div class="notice notice-warning">
				<p>
					<?php esc_html_e( 'Read-only mode is on, so the write checks cannot run. Turn it off to run the full self-test.', 'wp-acf-json-pro' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<button type="button" class="button button-primary button-hero" id="acfjp-selftest-run">
				<?php esc_html_e( 'Run self-test', 'wp-acf-json-pro' ); ?>
			</button>
		</p>

		<div id="acfjp-selftest-output" class="acfjp-selftest" aria-live="polite"></div>

		<h2><?php esc_html_e( 'From the command line', 'wp-acf-json-pro' ); ?></h2>
		<p><?php esc_html_e( 'If you have WP-CLI, the same checks run there and exit non-zero on failure, which makes them usable in a deployment script:', 'wp-acf-json-pro' ); ?></p>
		<pre class="acfjp-code">wp acfjp self-test</pre>
		<?php
	}

	/**
	 * Helper used by nothing yet, kept because the CLI renderer and this screen
	 * should agree on how a status reads.
	 */
	public static function statusLabel( string $status ): string {
		return match ( $status ) {
			Check::PASS => __( 'Passed', 'wp-acf-json-pro' ),
			Check::FAIL => __( 'Failed', 'wp-acf-json-pro' ),
			default     => __( 'Skipped', 'wp-acf-json-pro' ),
		};
	}
}
