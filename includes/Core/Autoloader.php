<?php
/**
 * Runtime PSR-4 autoloader.
 *
 * The plugin ships with no composer vendor directory (see CLAUDE.md). This is the
 * whole of our runtime autoloading: ~40 lines beats a bundled, unprefixed Composer
 * autoloader that can collide with another plugin's copy.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Core;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	/**
	 * @param string $prefix  Namespace prefix, trailing separator included. e.g. 'ACFJP\'.
	 * @param string $baseDir Absolute directory the prefix maps to, trailing slash included.
	 */
	public function __construct(
		private readonly string $prefix,
		private readonly string $baseDir
	) {}

	/**
	 * Register with the SPL autoload stack.
	 */
	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Resolve a fully qualified class name to a file and require it.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public function load( string $class ): void {
		if ( ! str_starts_with( $class, $this->prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $this->prefix ) );
		$path     = $this->baseDir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		// realpath() + prefix check guards against any future caller passing a
		// class name containing traversal sequences.
		$real = realpath( $path );
		if ( false === $real || ! str_starts_with( $real, realpath( $this->baseDir ) ?: $this->baseDir ) ) {
			return;
		}

		require_once $real;
	}
}
