<?php
/**
 * Turns raw text into a PHP array. Syntax only - no meaning is assigned here.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\ParseException;

defined( 'ABSPATH' ) || exit;

final class Parser {

	/** 8 MB. A 500-field group exports to well under 1 MB. */
	private const MAX_BYTES = 8388608;

	/**
	 * json_decode's own recursion limit, kept well below the engine's max depth so
	 * that a hostile payload is refused by the decoder rather than by our stack.
	 */
	private const MAX_JSON_DEPTH = 64;

	/**
	 * @return array<string,mixed>
	 * @throws ParseException
	 */
	public function parse( string $raw ): array {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			$e = new ParseException(
				ErrorCodes::EMPTY_PAYLOAD,
				__( 'No JSON was supplied.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		if ( strlen( $raw ) > $this->maxBytes() ) {
			$e = new ParseException(
				ErrorCodes::PAYLOAD_TOO_LARGE,
				sprintf(
					/* translators: 1: payload size, 2: maximum size */
					__( 'The payload is %1$s, which exceeds the %2$s limit.', 'fieldpilot-for-acf' ),
					size_format( strlen( $raw ) ),
					size_format( $this->maxBytes() )
				),
				array( 'bytes' => strlen( $raw ), 'max_bytes' => $this->maxBytes() )
			);
			throw $e;
		}

		$raw = $this->stripBom( $raw );

		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$e = new ParseException(
				ErrorCodes::BAD_ENCODING,
				__( 'The payload is not valid UTF-8. Re-save the file as UTF-8 and try again.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		$decoded = json_decode( $raw, true, self::MAX_JSON_DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$e = new ParseException(
				ErrorCodes::INVALID_JSON,
				sprintf(
					/* translators: %s: JSON parser error message */
					__( 'The JSON could not be parsed: %s', 'fieldpilot-for-acf' ),
					json_last_error_msg()
				),
				array( 'json_error' => json_last_error_msg() ),
				$this->hintsFor( $raw )
			);
			throw $e;
		}

		if ( ! is_array( $decoded ) ) {
			$e = new ParseException(
				ErrorCodes::NOT_AN_OBJECT,
				__( 'The payload must be a JSON object or array of field groups.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		return $decoded;
	}

	/**
	 * Read an uploaded .json file.
	 *
	 * @param array{tmp_name?:string,size?:int,name?:string,type?:string,error?:int} $file Sanitized upload entry.
	 * @return array<string,mixed>
	 * @throws ParseException
	 */
	public function parseUpload( array $file ): array {
		$tmp = $file['tmp_name'] ?? '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$e = new ParseException(
				ErrorCodes::EMPTY_PAYLOAD,
				__( 'No file was uploaded.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		if ( ( $file['size'] ?? 0 ) > $this->maxBytes() ) {
			$e = new ParseException(
				ErrorCodes::PAYLOAD_TOO_LARGE,
				__( 'That file is too large.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		// Trust the contents, not the extension or the browser-supplied MIME type.
		$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $contents ) {
			$e = new ParseException(
				ErrorCodes::EMPTY_PAYLOAD,
				__( 'The uploaded file could not be read.', 'fieldpilot-for-acf' )
			);
			throw $e;
		}

		return $this->parse( $contents );
	}

	/**
	 * Common, mechanically detectable mistakes - worth naming because an AI given
	 * the hint usually fixes it on the next attempt.
	 *
	 * @return list<string>
	 */
	private function hintsFor( string $raw ): array {
		$hints = array();

		if ( 1 === preg_match( '/,\s*[}\]]/', $raw ) ) {
			$hints[] = __( 'There appears to be a trailing comma before a closing brace or bracket.', 'fieldpilot-for-acf' );
		}

		if ( str_contains( $raw, '//' ) || str_contains( $raw, '/*' ) ) {
			$hints[] = __( 'Comments are not valid JSON. Remove // and /* */ sections.', 'fieldpilot-for-acf' );
		}

		if ( 1 === preg_match( '/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', $raw ) ) {
			$hints[] = __( 'Smart quotes were found. JSON requires straight double quotes.', 'fieldpilot-for-acf' );
		}

		if ( str_starts_with( $raw, '```' ) ) {
			$hints[] = __( 'The payload starts with a Markdown code fence. Paste only the JSON itself.', 'fieldpilot-for-acf' );
		}

		return $hints;
	}

	private function stripBom( string $raw ): string {
		return str_starts_with( $raw, "\xEF\xBB\xBF" ) ? substr( $raw, 3 ) : $raw;
	}

	private function maxBytes(): int {
		/**
		 * Maximum accepted payload size in bytes.
		 *
		 * @param int $bytes Default 8 MB.
		 */
		return (int) apply_filters( 'acfjp_max_payload_bytes', self::MAX_BYTES );
	}
}
