<?php
/**
 * Converts our exceptions into a stable, machine-readable HTTP response.
 *
 * The shape is fixed, documented, and identical across REST, CLI and the admin
 * AJAX layer. That matters more than usual here: the intended consumer is often an
 * AI regenerating its JSON, and a predictable error with suggestions turns a failed
 * import into a successful second attempt.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Rest;

use ACFJP\Exceptions\AcfjpException;
use ACFJP\Exceptions\ErrorCodes;

defined( 'ABSPATH' ) || exit;

final class ErrorEnvelope {

	public static function fromException( AcfjpException $e ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'error' => $e->jsonSerialize(),
			),
			$e->httpStatus()
		);
	}

	public static function fromThrowable( \Throwable $e ): \WP_REST_Response {
		if ( $e instanceof AcfjpException ) {
			return self::fromException( $e );
		}

		// Never leak internals to the client; the detail goes to the error log.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ACFJP: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore
		}

		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'error' => array(
					'code'    => ErrorCodes::WRITE_FAILED,
					'message' => __( 'Something went wrong. Nothing was changed.', 'wp-acf-json-pro' ),
				),
			),
			500
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function ok( array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'ok' => true ) + $data, $status );
	}
}
