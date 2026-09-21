<?php
/**
 * Base exception. Carries a machine code, context and actionable suggestions.
 *
 * Never let one of these reach the browser: the REST controller and the admin
 * controller each catch and convert. See docs/08-REST-API.md.
 *
 * @phpstan-consistent-constructor
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Exceptions;

defined( 'ABSPATH' ) || exit;

class AcfjpException extends \RuntimeException implements \JsonSerializable {

	/**
	 * @param string               $errorCode   One of ErrorCodes::*.
	 * @param string               $message     Translated, human-facing.
	 * @param array<string,mixed>  $context     Machine detail (keys, paths, values).
	 * @param list<string>         $suggestions Things the caller could try instead.
	 * @param string|null          $pointer     JSON pointer into the payload, e.g. '/add/0/type'.
	 */
	final public function __construct(
		private readonly string $errorCode,
		string $message,
		private readonly array $context = array(),
		private readonly array $suggestions = array(),
		private readonly ?string $pointer = null,
		?\Throwable $previous = null,
	) {
		parent::__construct( $message, 0, $previous );
	}

	/**
	 * @param string              $errorCode
	 * @param string              $message
	 * @param array<string,mixed> $context
	 * @param list<string>        $suggestions
	 * @param string|null         $pointer
	 * @return static
	 */
	public static function create(
		string $errorCode,
		string $message,
		array $context = array(),
		array $suggestions = array(),
		?string $pointer = null,
		?\Throwable $previous = null,
	): static {
		return new static( $errorCode, $message, $context, $suggestions, $pointer, $previous );
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	/** @return array<string,mixed> */
	public function context(): array {
		return $this->context;
	}

	/** @return list<string> */
	public function suggestions(): array {
		return $this->suggestions;
	}

	public function pointer(): ?string {
		return $this->pointer;
	}

	public function httpStatus(): int {
		return ErrorCodes::httpStatus( $this->errorCode );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array_filter(
			array(
				'code'        => $this->errorCode,
				'message'     => $this->getMessage(),
				'pointer'     => $this->pointer,
				'context'     => $this->context,
				'suggestions' => $this->suggestions,
			),
			static fn( $v ): bool => null !== $v && array() !== $v
		);
	}
}
