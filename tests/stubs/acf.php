<?php
/**
 * Minimal ACF stubs for static analysis only.
 *
 * PHPStan needs to know these functions exist and roughly what they return. This
 * file is never loaded at runtime - see phpstan.neon.dist `scanFiles`.
 *
 * Signatures mirror ACF 6.8.10. When bumping the supported ACF floor, re-read the
 * real source rather than trusting this file.
 *
 * @package ACFJP
 */

// phpcs:disable

const ACFJP_VERSION    = '1.0.1';
const ACFJP_MIN_PHP    = '8.1';
const ACFJP_MIN_WP     = '6.5';
const ACFJP_MIN_ACF    = '6.2';
const ACFJP_DB_VERSION = 1;
const ACFJP_DIR        = '';
const ACFJP_URL        = '';
const ACFJP_FILE       = '';

/** @return array<string,mixed>|false */
function acf_get_field( int|string $id = 0 ) {}
/** @return array<string,mixed>|false */
function acf_get_raw_field( int|string $id = 0 ) {}
/** @return list<array<string,mixed>> */
function acf_get_raw_fields( int $id = 0 ): array {}
/** @return list<array<string,mixed>> */
function acf_get_fields( array|string|int $parent ): array {}
/** @return array<string,mixed> */
function acf_update_field( array $field, array $specific = array() ) {}
function acf_delete_field( int|string $id = 0 ): bool {}
/** @return array<string,mixed>|false */
function acf_get_field_group( int|string $id = 0 ) {}
/** @return array<string,mixed>|false */
function acf_get_raw_field_group( int|string $id = 0 ) {}
/** @return list<array<string,mixed>> */
function acf_get_field_groups( array $filter = array() ): array {}
/** @return array<string,mixed> */
function acf_update_field_group( array $field_group ) {}
function acf_delete_field_group( int|string $id = 0 ): bool {}
/** @return array<string,mixed> */
function acf_import_field_group( array $field_group ) {}
/** @return array<string,mixed> */
function acf_prepare_field_group_for_export( array $field_group = array() ): array {}
/** @return list<array<string,mixed>> */
function acf_prepare_fields_for_import( array $fields = array() ): array {}
/** @return array<string,mixed> */
function acf_get_field_json_schema( string $field_type ): array {}
/** @return array<string,object> */
function acf_get_field_types( array $args = array() ): array {}
/** @return array<string,bool> */
function acf_disable_filters(): array {}
/** @param array<string,bool> $filters */
function acf_enable_filters( array $filters = array() ): void {}
function acf_is_local_field_group( string $key = '' ): bool {}
/** @return array<string,mixed>|false */
function acf_get_local_field_group( string $key = '' ) {}
/** @return array<string,string> */
function acf_get_local_json_files( string $post_type = 'acf-field-group' ): array {}
function acf_get_store( string $name ): ?ACF_Data {}
/** @return array<string,mixed> */
function acf_export_internal_post_type_as_php( array $post, string $post_type = 'acf-field-group' ) {}
/** @param array<string,mixed> $field_group */
function acf_add_local_field_group( array $field_group ): void {}

class ACF_Data {
	/** @return $this */
	public function reset() {}
	public function has( string $key ): bool {}
	public function get( ?string $key = null ): mixed {}
	/** @return $this */
	public function set( string $key, mixed $value ) {}
	/** @return $this */
	public function alias( string ...$aliases ) {}
}
