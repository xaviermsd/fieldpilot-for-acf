<?php
/**
 * Uninstall routine.
 *
 * Destroys plugin-owned data only. ACF configuration is never touched: this plugin
 * does not own field groups, it only patches them, and uninstalling a tool must not
 * remove the work done with it.
 *
 * Snapshots ARE removed, because they are ours and they are large. The setting
 * `acfjp_preserve_on_uninstall` (default false) keeps everything for sites that
 * deactivate and reactivate as part of a deploy.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( get_option( 'acfjp_preserve_on_uninstall' ) ) {
	return;
}

global $wpdb;

foreach ( array( 'acfjp_journal', 'acfjp_snapshots' ) as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

foreach ( array( 'acfjp_db_version', 'acfjp_settings', 'acfjp_preserve_on_uninstall' ) as $option ) {
	delete_option( $option );
}

// Plan tokens live in transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acfjp_plan_%' OR option_name LIKE '_transient_timeout_acfjp_plan_%'" );
