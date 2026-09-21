<?php
/**
 * Raised when a batch is refused before any write occurs.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Exceptions;

defined( 'ABSPATH' ) || exit;

final class GuardException extends AcfjpException {}
