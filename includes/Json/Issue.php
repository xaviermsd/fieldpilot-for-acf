<?php
/**
 * One validation finding.
 *
 * @package ACFJP
 */

declare( strict_types = 1 );

namespace ACFJP\Json;

defined( 'ABSPATH' ) || exit;

final class Issue implements \JsonSerializable {

	public const ERROR   = 'error';
	public const WARNING = 'warning';

	/**
	 * @param list<string> $suggestions
	 */
	public function __construct(
		public readonly string $severity,
		public readonly string $code,
		public readonly string $message,
		public readonly ?string $pointer = null,
		public readonly array $suggestions = array(),
	) {}

	/**
	 * @param list<string> $suggestions
	 */
	public static function error( string $code, string $message, ?string $pointer = null, array $suggestions = array() ): self {
		return new self( self::ERROR, $code, $message, $pointer, $suggestions );
	}

	/**
	 * @param list<string> $suggestions
	 */
	public static function warning( string $code, string $message, ?string $pointer = null, array $suggestions = array() ): self {
		return new self( self::WARNING, $code, $message, $pointer, $suggestions );
	}

	public function isError(): bool {
		return self::ERROR === $this->severity;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return array_filter(
			array(
				'severity'    => $this->severity,
				'code'        => $this->code,
				'message'     => $this->message,
				'pointer'     => $this->pointer,
				'suggestions' => $this->suggestions,
			),
			static fn( $v ): bool => null !== $v && array() !== $v
		);
	}
}
