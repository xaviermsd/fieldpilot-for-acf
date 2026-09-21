<?php
/**
 * Thin WP-CLI wrapper around the built-in self-test.
 *
 * Kept so that `wp eval-file tools/smoke.php` still works from a checkout, and so
 * the test can be run before the plugin's own CLI command is registered. The checks
 * themselves live in includes/Diagnostics/SelfTest.php - one implementation, shared
 * by WP-CLI, the REST API and the Diagnostics screen.
 *
 * Prefer:  wp acfjp self-test
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this through WP-CLI: wp eval-file tools/smoke.php\n" );
}

if ( ! class_exists( \ACFJP\Core\Plugin::class ) ) {
	WP_CLI::error( 'WP ACF JSON Pro is not active.' );
}

wp_set_current_user( 1 );

if ( ! current_user_can( 'manage_options' ) ) {
	WP_CLI::error( 'User 1 lacks manage_options. Run as an administrator.' );
}

WP_CLI::runcommand( 'acfjp self-test', array( 'launch' => false, 'return' => false ) );
