<?php
/**
 * Settings. Kept deliberately small - a configuration tool should not itself need
 * much configuration.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Admin\Screens;

use ACFJP\Apply\Guard;
use ACFJP\Journal\SnapshotStore;

defined( 'ABSPATH' ) || exit;

final class Settings extends Screen {

	private const NONCE  = 'acfjp_settings';
	private const OPTION = 'acfjp_settings';

	protected function title(): string {
		return __( 'Settings', 'fieldpilot-for-acf' );
	}

	protected function body(): void {
		$this->maybeSave();

		$settings = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'keep_per_group'  => 50,
				'keep_days'       => 90,
				'data_probe'      => 1,
				'preserve_uninstall' => 0,
				'read_only'          => 0,
			)
		);

		$stats = $this->container->get( SnapshotStore::class )->stats();

		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Read-only mode', 'fieldpilot-for-acf' ); ?></th>
					<td>
						<?php if ( defined( 'ACFJP_READ_ONLY' ) && ACFJP_READ_ONLY ) : ?>
							<p>
								<strong><?php esc_html_e( 'On, set in wp-config.php.', 'fieldpilot-for-acf' ); ?></strong>
								<?php esc_html_e( 'Remove the ACFJP_READ_ONLY constant to change it.', 'fieldpilot-for-acf' ); ?>
							</p>
						<?php else : ?>
							<label>
								<input type="checkbox" name="read_only" value="1" <?php checked( (int) $settings['read_only'], 1 ); ?> />
								<?php esc_html_e( 'Previews only - refuse every write', 'fieldpilot-for-acf' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Validation, previews and exports keep working; applying and rolling back are refused. Useful for looking at what the plugin would do on a site before letting it change anything. For a setting a UI slip cannot reach, define ACFJP_READ_ONLY as true in wp-config.php instead.', 'fieldpilot-for-acf' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Content detection', 'fieldpilot-for-acf' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="data_probe" value="1" <?php checked( (int) $settings['data_probe'], 1 ); ?> />
							<?php esc_html_e( 'Check whether a field holds content before deleting it', 'fieldpilot-for-acf' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Scans post, term, user and option tables for references to the field. Accurate, including inside repeaters, but unindexed - turn it off on very large sites. Previews then say the check was skipped rather than reporting no content.', 'fieldpilot-for-acf' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acfjp-keep-per-group"><?php esc_html_e( 'History per field group', 'fieldpilot-for-acf' ); ?></label></th>
					<td>
						<input type="number" min="1" max="1000" id="acfjp-keep-per-group" name="keep_per_group"
							value="<?php echo esc_attr( (string) $settings['keep_per_group'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Entries kept for each field group before older ones are pruned.', 'fieldpilot-for-acf' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acfjp-keep-days"><?php esc_html_e( 'History age limit', 'fieldpilot-for-acf' ); ?></label></th>
					<td>
						<input type="number" min="1" max="3650" id="acfjp-keep-days" name="keep_days"
							value="<?php echo esc_attr( (string) $settings['keep_days'] ); ?>" class="small-text" />
						<?php esc_html_e( 'days', 'fieldpilot-for-acf' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'On uninstall', 'fieldpilot-for-acf' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="preserve_uninstall" value="1" <?php checked( (int) $settings['preserve_uninstall'], 1 ); ?> />
							<?php esc_html_e( 'Keep history and snapshots when the plugin is deleted', 'fieldpilot-for-acf' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Your ACF field groups are never removed by this plugin, whichever way this is set.', 'fieldpilot-for-acf' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Snapshot storage', 'fieldpilot-for-acf' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: 1: number of snapshots, 2: formatted size */
							esc_html__( '%1$d snapshots, about %2$s before compression.', 'fieldpilot-for-acf' ),
							(int) $stats['count'],
							esc_html( size_format( (int) $stats['bytes'] ) )
						);
						?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function maybeSave(): void {
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			$this->notice( esc_html__( 'That request could not be verified.', 'fieldpilot-for-acf' ), 'error' );
			return;
		}

		$settings = array(
			'keep_per_group'     => max( 1, min( 1000, (int) ( $_POST['keep_per_group'] ?? 50 ) ) ),
			'keep_days'          => max( 1, min( 3650, (int) ( $_POST['keep_days'] ?? 90 ) ) ),
			'data_probe'         => isset( $_POST['data_probe'] ) ? 1 : 0,
			'preserve_uninstall' => isset( $_POST['preserve_uninstall'] ) ? 1 : 0,
			'read_only'          => isset( $_POST['read_only'] ) ? 1 : 0,
		);

		update_option( self::OPTION, $settings, false );
		update_option( 'acfjp_preserve_on_uninstall', $settings['preserve_uninstall'], false );

		$this->notice( esc_html__( 'Settings saved.', 'fieldpilot-for-acf' ), 'success' );
	}
}
