<?php
/**
 * Unit-test bootstrap.
 *
 * The pure layers (Json, Model, Resolve, Diff) are designed not to need WordPress,
 * but they do call a handful of WordPress functions for translation, filters and
 * JSON encoding. Stubbing those - rather than booting WordPress - keeps the unit
 * suite fast enough to run on every save.
 *
 * Integration tests boot the real thing; see tests/Integration/.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ACFJP_DIR', dirname( __DIR__ ) . '/' );
define( 'ACFJP_URL', 'https://example.test/wp-content/plugins/wp-acf-json-pro/' );
define( 'ACFJP_VERSION', '1.0.0' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

require_once dirname( __DIR__ ) . '/includes/Core/Autoloader.php';
( new ACFJP\Core\Autoloader( 'ACFJP\\', dirname( __DIR__ ) . '/includes/' ) )->register();

// ---- WordPress stubs -------------------------------------------------------

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		// Just enough of the filter system for tests that exercise a public hook.
		if ( 'acfjp/field_type/schema' === $hook && isset( $GLOBALS['acfjp_test_schema'] ) ) {
			return $GLOBALS['acfjp_test_schema'];
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted = 1 ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted = 1 ): void {}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'remove_accents' ) ) {
	function remove_accents( string $string ): string {
		if ( function_exists( 'transliterator_transliterate' ) ) {
			$trans = transliterator_transliterate( 'Any-Latin; Latin-ASCII;', $string );
			if ( false !== $trans ) {
				return $trans;
			}
		}

		static $chars = array(
			'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE',
			'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I',
			'Î' => 'I', 'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O',
			'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
			'Ý' => 'Y', 'ß' => 's', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
			'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
			'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o',
			'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u',
			'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',
		);

		return strtr( $string, $chars );
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( int|float $bytes, int $decimals = 0 ): string {
		return number_format( (float) $bytes / 1024, $decimals ) . ' KB';
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( int $min = 0, int $max = 0 ): int {
		return random_int( $min, $max );
	}
}
