<?php
/**
 * Uninstall routine for FieldPilot.
 *
 * Runs only when the plugin is deleted from the Plugins screen, and removes data
 * only where Settings > "On uninstall" is checked (it is off by default). On
 * multisite each site's own setting decides for that site. Field groups and
 * fields belong to ACF and are never touched.
 *
 * @package FieldPilot
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Whether the current site asked for its FieldPilot data to be removed.
 */
$fieldpilot_wants_cleanup = static function (): bool {
	$flag = get_option( 'fieldpilot_delete_on_uninstall', null );

	if ( null !== $flag ) {
		return (bool) $flag;
	}

	$settings = get_option( 'acfjp_settings', array() );

	return is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] );
};

/**
 * Removes the current site's options, saved previews, history tables and cron event.
 */
$fieldpilot_clean_site = static function (): void {
	global $wpdb;

	$options = array(
		'acfjp_settings',
		'acfjp_db_version',
		'acfjp_apply_lock',
		'fieldpilot_delete_on_uninstall',
		// Written by versions before 1.0.29.
		'acfjp_preserve_on_uninstall',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Saved previews are transients; remove them and their timeouts.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_acfjp_plan_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_acfjp_plan_' ) . '%'
		)
	);

	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acfjp_journal`" );
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acfjp_snapshots`" );

	wp_clear_scheduled_hook( 'acfjp_retention_sweep' );
};

$fieldpilot_cleaned = false;

if ( is_multisite() ) {
	$fieldpilot_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $fieldpilot_sites as $fieldpilot_site_id ) {
		switch_to_blog( (int) $fieldpilot_site_id );

		if ( $fieldpilot_wants_cleanup() ) {
			$fieldpilot_clean_site();
			$fieldpilot_cleaned = true;
		}

		restore_current_blog();
	}
} elseif ( $fieldpilot_wants_cleanup() ) {
	$fieldpilot_clean_site();
	$fieldpilot_cleaned = true;
}

// User meta is shared by every site on a network: the guided tour's "done" flag.
if ( $fieldpilot_cleaned ) {
	delete_metadata( 'user', 0, 'fieldpilot_tour_done', '', true );
}

// phpcs:enable
