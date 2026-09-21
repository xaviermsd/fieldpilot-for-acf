<?php
/**
 * Raised when a write, verification or restore fails.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Exceptions;

defined( 'ABSPATH' ) || exit;

final class ApplyException extends AcfjpException {}
