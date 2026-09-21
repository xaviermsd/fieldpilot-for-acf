<?php
/**
 * Where a field group physically lives, and therefore whether it can be patched.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Acf;

defined( 'ABSPATH' ) || exit;

enum Mutability: string {

	/** Stored as acf-field-group / acf-field posts. Patchable. */
	case Database = 'database';

	/** Loaded from an acf-json file and not yet synced into the database. Patchable only after sync. */
	case LocalJson = 'local_json';

	/** Registered at runtime by acf_add_local_field_group(). Not patchable, ever. */
	case LocalPhp = 'local_php';

	/** No such field group. */
	case Missing = 'missing';

	public function isPatchable(): bool {
		return self::Database === $this;
	}

	public function label(): string {
		return match ( $this ) {
			self::Database  => __( 'Database', 'fieldpilot-for-acf' ),
			self::LocalJson => __( 'Local JSON (not synced)', 'fieldpilot-for-acf' ),
			self::LocalPhp  => __( 'Registered in PHP', 'fieldpilot-for-acf' ),
			self::Missing   => __( 'Not found', 'fieldpilot-for-acf' ),
		};
	}
}
