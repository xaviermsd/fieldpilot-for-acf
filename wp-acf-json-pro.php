<?php
/**
 * Plugin Name:       WP ACF JSON Pro
 * Plugin URI:        https://example.com/wp-acf-json-pro
 * Description:       Build, import, update and manage ACF field structures with JSON. A safe, deterministic configuration patch engine for Advanced Custom Fields.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Harsh Prajapati
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-acf-json-pro
 * Domain Path:       /languages
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/*
 * This file contains no logic beyond wiring. It must never fatal the site:
 * if a requirement is unmet we register an admin notice and return. See
 * docs/ARCHITECTURE-REVIEW.md B.7 - "Requirements::assert() must not assert".
 */

const ACFJP_VERSION     = '1.0.1';
const ACFJP_MIN_PHP     = '8.1';
const ACFJP_MIN_WP      = '6.5';
const ACFJP_MIN_ACF     = '6.2';
const ACFJP_DB_VERSION  = 1;

define( 'ACFJP_FILE', __FILE__ );
define( 'ACFJP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACFJP_URL', plugin_dir_url( __FILE__ ) );

require_once ACFJP_DIR . 'includes/Core/Autoloader.php';
( new ACFJP\Core\Autoloader( 'ACFJP\\', ACFJP_DIR . 'includes/' ) )->register();

register_activation_hook( __FILE__, array( ACFJP\Core\Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( ACFJP\Core\Activation::class, 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded.
 *
 * ACF registers itself on `plugins_loaded` at priority 10 and finishes wiring its
 * field types on `init`. We boot at `plugins_loaded` priority 20 so that ACF's
 * functions exist, and defer anything needing field types to `init`.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		$requirements = new ACFJP\Core\Requirements();

		if ( ! $requirements->met() ) {
			$requirements->registerNotice();
			return;
		}

		ACFJP\Core\Plugin::instance()->boot();
	},
	20
);
