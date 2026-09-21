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

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

if ( ! get_option( 'acfjp_preserve_on_uninstall' ) ) {
	global $wpdb;

	// Drop custom tables.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acfjp_journal`" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acfjp_snapshots`" );

	delete_option( 'acfjp_db_version' );
	delete_option( 'acfjp_settings' );
	delete_option( 'acfjp_preserve_on_uninstall' );

	// Plan tokens live in transients.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acfjp_plan_%' OR option_name LIKE '_transient_timeout_acfjp_plan_%'" );
}

// phpcs:enable
