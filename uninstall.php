<?php
/**
 * Uninstall routine for FieldPilot.
 *
 * Runs only when the plugin is deleted from the WordPress Plugins page.
 * Respects the site owner choice to wipe or keep FieldPilot data.
 *
 * @package FieldPilot
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

// 2. Read the setting. If unchecked: do nothing and exit. All data stays.
$fieldpilot_delete_data = false;
$fieldpilot_option_flag = get_option( 'fieldpilot_delete_on_uninstall', null );

if ( null !== $fieldpilot_option_flag ) {
	$fieldpilot_delete_data = (bool) $fieldpilot_option_flag;
} else {
	$fieldpilot_settings = get_option( 'acfjp_settings', array() );
	if ( is_array( $fieldpilot_settings ) && ! empty( $fieldpilot_settings['delete_on_uninstall'] ) ) {
		$fieldpilot_delete_data = true;
	}
}

if ( ! $fieldpilot_delete_data ) {
	return;
}

global $wpdb;

// 3. If checked, remove in this order:

// (a) Plugin options (settings) via delete_option for each FieldPilot option.
$fieldpilot_options = array(
	'fieldpilot_delete_on_uninstall',
	'fieldpilot_settings',
	'fieldpilot_db_version',
	'acfjp_settings',
	'acfjp_db_version',
	'acfjp_preserve_on_uninstall',
);

foreach ( $fieldpilot_options as $fieldpilot_option_name ) {
	delete_option( $fieldpilot_option_name );
}

// Clean up plan transients owned by FieldPilot.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acfjp_plan_%' OR option_name LIKE '_transient_timeout_acfjp_plan_%' OR option_name LIKE '_transient_fieldpilot_plan_%' OR option_name LIKE '_transient_timeout_fieldpilot_plan_%'"
);

// (b) History/snapshot storage: drop or empty the FieldPilot history table (use $wpdb->prefix properly, never hardcode wp_).
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acfjp_journal`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acfjp_snapshots`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}fieldpilot_journal`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}fieldpilot_snapshots`" );

// (c) User meta flags (e.g. the onboarding tour done flag) for all users.
delete_metadata( 'user', 0, 'fieldpilot_tour_done', '', true );
delete_metadata( 'user', 0, 'acfjp_tour_done', '', true );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
		'fieldpilot_%',
		'acfjp_%'
	)
);

// (d) The "FieldPilot Demo" group, but ONLY if it still carries the demo marker. Never delete a group the marker check fails on.
if ( ! function_exists( 'fieldpilot_uninstall_cleanup_demo_group' ) ) {
	/**
	 * Deletes the FieldPilot Demo group only if it carries the demo marker.
	 */
	function fieldpilot_uninstall_cleanup_demo_group(): void {
		global $wpdb;

		$fieldpilot_groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_excerpt FROM {$wpdb->posts} WHERE post_type = %s AND (post_title = %s OR post_name LIKE %s OR post_excerpt LIKE %s)",
				'acf-field-group',
				'FieldPilot Demo',
				'%fieldpilot_demo%',
				'%fieldpilot_demo%'
			)
		);

		if ( empty( $fieldpilot_groups ) ) {
			return;
		}

		foreach ( $fieldpilot_groups as $fieldpilot_group ) {
			$fieldpilot_post_id    = (int) $fieldpilot_group->ID;
			$fieldpilot_has_marker = false;

			// Check post meta markers.
			if (
				get_post_meta( $fieldpilot_post_id, '_fieldpilot_demo', true )
				|| get_post_meta( $fieldpilot_post_id, 'fieldpilot_demo', true )
				|| get_post_meta( $fieldpilot_post_id, '_acfjp_demo', true )
			) {
				$fieldpilot_has_marker = true;
			}

			// Check group key or slug in post_name or post_excerpt.
			if (
				str_contains( (string) $fieldpilot_group->post_name, 'fieldpilot_demo' )
				|| str_contains( (string) $fieldpilot_group->post_excerpt, 'fieldpilot_demo' )
				|| str_contains( (string) $fieldpilot_group->post_name, '_acfjp_demo' )
				|| str_contains( (string) $fieldpilot_group->post_excerpt, '_acfjp_demo' )
			) {
				$fieldpilot_has_marker = true;
			}

			// Check ACF raw field group configuration if ACF is loaded.
			if ( ! $fieldpilot_has_marker && function_exists( 'acf_get_raw_field_group' ) ) {
				$fieldpilot_raw = acf_get_raw_field_group( $fieldpilot_post_id );
				if ( is_array( $fieldpilot_raw ) ) {
					if (
						! empty( $fieldpilot_raw['_fieldpilot_demo'] )
						|| ! empty( $fieldpilot_raw['fieldpilot_demo'] )
						|| ( isset( $fieldpilot_raw['key'] ) && str_contains( (string) $fieldpilot_raw['key'], 'fieldpilot_demo' ) )
					) {
						$fieldpilot_has_marker = true;
					}
				}
			}

			// Never delete a group the marker check fails on.
			if ( ! $fieldpilot_has_marker ) {
				continue;
			}

			// Delete verified demo group and its child fields.
			if ( function_exists( 'acf_delete_field_group' ) ) {
				acf_delete_field_group( $fieldpilot_post_id );
			} else {
				$fieldpilot_field_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s",
						$fieldpilot_post_id,
						'acf-field'
					)
				);

				if ( ! empty( $fieldpilot_field_ids ) ) {
					foreach ( $fieldpilot_field_ids as $fieldpilot_fid ) {
						wp_delete_post( (int) $fieldpilot_fid, true );
					}
				}

				wp_delete_post( $fieldpilot_post_id, true );
			}
		}
	}
}

fieldpilot_uninstall_cleanup_demo_group();

// (e) Any scheduled cron hooks via wp_clear_scheduled_hook.
wp_clear_scheduled_hook( 'acfjp_retention_sweep' );
wp_clear_scheduled_hook( 'fieldpilot_retention_sweep' );

// phpcs:enable
