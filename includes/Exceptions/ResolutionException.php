<?php
/**
 * Raised when a target cannot be resolved to exactly one node.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Exceptions;

defined( 'ABSPATH' ) || exit;

final class ResolutionException extends AcfjpException {}
