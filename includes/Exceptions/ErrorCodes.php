<?php
/**
 * The complete catalogue of machine-readable error codes.
 *
 * Every failure this plugin can produce has a code here. Callers (UI, REST, CLI, and
 * any AI regenerating its JSON) branch on the code, never on the message. Messages
 * are translated and may change; codes are a contract and may not.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Exceptions;

defined( 'ABSPATH' ) || exit;

final class ErrorCodes {

	// ---- Parse (1xx) ---------------------------------------------------------
	public const INVALID_JSON        = 'INVALID_JSON';
	public const EMPTY_PAYLOAD       = 'EMPTY_PAYLOAD';
	public const PAYLOAD_TOO_LARGE   = 'PAYLOAD_TOO_LARGE';
	public const BAD_ENCODING        = 'BAD_ENCODING';
	public const NOT_AN_OBJECT       = 'NOT_AN_OBJECT';

	// ---- Schema / semantics (2xx) --------------------------------------------
	public const MISSING_VERSION     = 'MISSING_VERSION';
	public const UNSUPPORTED_VERSION = 'UNSUPPORTED_VERSION';
	public const MISSING_OPERATION   = 'MISSING_OPERATION';
	public const UNKNOWN_OPERATION   = 'UNKNOWN_OPERATION';
	public const MISSING_TARGET      = 'MISSING_TARGET';
	public const MISSING_FIELDS      = 'MISSING_FIELDS';
	public const INVALID_FIELD       = 'INVALID_FIELD';
	public const INVALID_SETTING     = 'INVALID_SETTING';
	public const INVALID_FIELD_NAME  = 'INVALID_FIELD_NAME';
	public const INVALID_FIELD_KEY   = 'INVALID_FIELD_KEY';
	public const UNKNOWN_FIELD_TYPE  = 'UNKNOWN_FIELD_TYPE';
	public const DUPLICATE_NAME      = 'DUPLICATE_NAME';
	public const DUPLICATE_KEY       = 'DUPLICATE_KEY';
	public const MAX_DEPTH_EXCEEDED  = 'MAX_DEPTH_EXCEEDED';
	public const CIRCULAR_REFERENCE  = 'CIRCULAR_REFERENCE';
	public const NOT_A_CONTAINER     = 'NOT_A_CONTAINER';

	// ---- Resolution (3xx) ----------------------------------------------------
	public const GROUP_NOT_FOUND     = 'GROUP_NOT_FOUND';
	public const FIELD_NOT_FOUND     = 'FIELD_NOT_FOUND';
	public const PATH_NOT_FOUND      = 'PATH_NOT_FOUND';
	public const AMBIGUOUS_TARGET    = 'AMBIGUOUS_TARGET';
	public const LAYOUT_NOT_FOUND    = 'LAYOUT_NOT_FOUND';
	public const TRAVERSES_CLONE     = 'TRAVERSES_CLONE';

	// ---- Guard (4xx) ---------------------------------------------------------
	public const GROUP_NOT_PATCHABLE     = 'GROUP_NOT_PATCHABLE';
	public const GROUP_REGISTERED_IN_PHP = 'GROUP_REGISTERED_IN_PHP';
	public const GROUP_NOT_SYNCED        = 'GROUP_NOT_SYNCED';
	public const FIELD_TYPE_REQUIRES_PRO = 'FIELD_TYPE_REQUIRES_PRO';
	public const INSUFFICIENT_CAPABILITY = 'INSUFFICIENT_CAPABILITY';
	public const READ_ONLY_MODE         = 'READ_ONLY_MODE';
	public const CONFIRMATION_REQUIRED   = 'CONFIRMATION_REQUIRED';
	public const UNRESOLVED_CONFLICTS    = 'UNRESOLVED_CONFLICTS';

	// ---- Apply (5xx) ---------------------------------------------------------
	public const PLAN_NOT_FOUND      = 'PLAN_NOT_FOUND';
	public const PLAN_EXPIRED        = 'PLAN_EXPIRED';
	public const STATE_CHANGED       = 'STATE_CHANGED';
	public const WRITE_FAILED        = 'WRITE_FAILED';
	public const VERIFY_FAILED       = 'VERIFY_FAILED';
	public const ROLLED_BACK         = 'ROLLED_BACK';
	public const SNAPSHOT_FAILED     = 'SNAPSHOT_FAILED';
	public const SNAPSHOT_NOT_FOUND  = 'SNAPSHOT_NOT_FOUND';
	public const RESTORE_FAILED      = 'RESTORE_FAILED';
	public const ALREADY_ROLLED_BACK = 'ALREADY_ROLLED_BACK';

	/**
	 * HTTP status for each code, used by the REST error envelope.
	 */
	public static function httpStatus( string $code ): int {
		return match ( $code ) {
			self::INSUFFICIENT_CAPABILITY,
			self::READ_ONLY_MODE          => 403,
			self::GROUP_NOT_FOUND,
			self::FIELD_NOT_FOUND,
			self::PATH_NOT_FOUND,
			self::LAYOUT_NOT_FOUND,
			self::PLAN_NOT_FOUND,
			self::SNAPSHOT_NOT_FOUND   => 404,
			self::STATE_CHANGED,
			self::PLAN_EXPIRED,
			self::ALREADY_ROLLED_BACK  => 409,
			self::PAYLOAD_TOO_LARGE    => 413,
			self::WRITE_FAILED,
			self::VERIFY_FAILED,
			self::SNAPSHOT_FAILED,
			self::RESTORE_FAILED,
			self::ROLLED_BACK          => 500,
			default                    => 400,
		};
	}

	private function __construct() {}
}
