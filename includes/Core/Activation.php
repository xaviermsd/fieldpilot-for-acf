<?php
/**
 * Activation, deactivation and schema migration.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Core;

defined( 'ABSPATH' ) || exit;

final class Activation {

	public const OPTION_DB_VERSION = 'acfjp_db_version';

	/**
	 * Runs on activation and, via maybeMigrate(), on any load where the stored
	 * schema version is behind the code's.
	 */
	public static function activate(): void {
		self::installTables();
		update_option( self::OPTION_DB_VERSION, ACFJP_DB_VERSION, false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'acfjp_retention_sweep' );
	}

	/**
	 * Cheap check on every request; real work only when the version moved.
	 */
	public static function maybeMigrate(): void {
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) === ACFJP_DB_VERSION ) {
			return;
		}

		self::installTables();
		update_option( self::OPTION_DB_VERSION, ACFJP_DB_VERSION, false );
	}

	/**
	 * Create or migrate the two plugin-owned tables.
	 *
	 * Design note (docs/ARCHITECTURE-REVIEW.md B.6): the journal row is small and
	 * listable; the large state blobs live once each in a content-addressed store,
	 * so an N-step editing session stores N+1 snapshots rather than N copies of
	 * near-identical JSON, and rollback is a hash lookup.
	 */
	private static function installTables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$journal = $wpdb->prefix . 'acfjp_journal';
		$snaps   = $wpdb->prefix . 'acfjp_snapshots';

		// dbDelta is whitespace- and format-sensitive; keep two spaces after PRIMARY KEY.
		$sql = array();

		$sql[] = "CREATE TABLE {$journal} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id char(36) NOT NULL,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(16) NOT NULL DEFAULT 'admin',
			operation varchar(16) NOT NULL,
			group_key varchar(64) NOT NULL,
			group_title varchar(255) NOT NULL DEFAULT '',
			before_hash char(64) NOT NULL,
			after_hash char(64) DEFAULT NULL,
			changeset longtext NOT NULL,
			change_count smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'applied',
			message text DEFAULT NULL,
			rolled_back_at datetime DEFAULT NULL,
			rolled_back_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY k_batch (batch_id),
			KEY k_group (group_key,id),
			KEY k_created (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$snaps} (
			hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			bytes mediumint(8) unsigned NOT NULL DEFAULT 0,
			encoding varchar(16) NOT NULL DEFAULT 'gz',
			payload longtext NOT NULL,
			refcount int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (hash),
			KEY k_created (created_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
