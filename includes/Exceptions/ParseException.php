<?php
/**
 * Raised by the Parser for anything that is not well-formed JSON.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Exceptions;

defined( 'ABSPATH' ) || exit;

final class ParseException extends AcfjpException {}
