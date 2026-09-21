<?php
/**
 * Raised by the Validator for structurally valid JSON that violates the schema or semantics.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Exceptions;

defined( 'ABSPATH' ) || exit;

final class ValidationException extends AcfjpException {}
