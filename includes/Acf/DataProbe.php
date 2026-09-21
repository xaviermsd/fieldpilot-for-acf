<?php
/**
 * Answers "does this field hold content anywhere on the site?"
 *
 * HOW IT WORKS. When ACF saves a value it also writes a hidden reference row whose
 * meta_key is the field name prefixed with an underscore and whose meta_value is
 * the FIELD KEY. acf_get_reference() reads it back. Verified in ACF 6.8.10,
 * includes/acf-meta-functions.php and acf-value-functions.php.
 *
 * Searching by key rather than by name is what makes this correct for nested data:
 * a repeater sub-value is stored as `team_0_member_name` - a name search would miss
 * it entirely, but its reference row still holds the same field key.
 *
 * COST. meta_value is not indexed, so each probe is a full scan bounded by LIMIT 1.
 * Results are memoised per request, and the whole probe can be disabled on very
 * large sites - in which case the preview says so rather than claiming "no data".
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Acf;

defined( 'ABSPATH' ) || exit;

final class DataProbe {

	/** @var array<string,bool> */
	private array $cache = array();

	private bool $enabled;

	public function __construct( ?bool $enabled = null ) {
		$this->enabled = $enabled ?? (bool) apply_filters( 'acfjp_data_probe_enabled', true );
	}

	public function isEnabled(): bool {
		return $this->enabled;
	}

	/**
	 * Whether any content anywhere references this field key.
	 *
	 * Returns false when probing is disabled - callers MUST check isEnabled() and
	 * present "unknown", never "safe to delete".
	 */
	public function hasContent( string $fieldKey ): bool {
		if ( ! $this->enabled || '' === $fieldKey ) {
			return false;
		}

		if ( isset( $this->cache[ $fieldKey ] ) ) {
			return $this->cache[ $fieldKey ];
		}

		global $wpdb;

		$tables = array( $wpdb->postmeta, $wpdb->termmeta, $wpdb->usermeta );

		$found = false;

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE meta_value = %s LIMIT 1", $fieldKey ) );

			if ( null !== $exists ) {
				$found = true;
				break;
			}
		}

		// Options-page values live in wp_options, not a meta table.
		if ( ! $found ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = null !== $wpdb->get_var(
				$wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_value = %s LIMIT 1", $fieldKey )
			);
		}

		$this->cache[ $fieldKey ] = $found;

		return $found;
	}

	/**
	 * Probe a whole subtree: true when the field or any descendant holds content.
	 *
	 * @param list<string> $fieldKeys
	 * @return array<string,bool> key => has content
	 */
	public function probeMany( array $fieldKeys ): array {
		$out = array();

		foreach ( $fieldKeys as $key ) {
			$out[ $key ] = $this->hasContent( $key );
		}

		return $out;
	}
}
